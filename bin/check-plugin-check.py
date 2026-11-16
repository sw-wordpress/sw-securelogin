"""Validate Plugin Check 2.1.0 strict-json for the ADR 009 GitHub distribution.

Never executes packaged PHP. Only the two exact WordPress.org updater policy
errors below are inapplicable, and only for the pinned installed configuration.
"""
import argparse
import json
from pathlib import Path
import re
import sys

DOCS = 'https://developer.wordpress.org/plugins/wordpress-org/common-issues/#update-checker'
POLICY_ERRORS = (
    ('Including An Update Checker / Changing Updates functionality. Plugin Updater detected. '
     'Use of the Update URI header is not allowed in plugins hosted on WordPress.org.', DOCS),
    ('Plugin Updater detected. These are not permitted in WordPress.org hosted plugins. '
     'Detected: site_transient_update_plugins', ''),
)
UPDATE_WARNINGS = {
    'Plugin Updater detected. Detected code which may be altering WordPress update routines. Detected: ' + hook
    for hook in ('auto_update_plugin', 'pre_set_site_transient_update_plugins', '_site_transient_update_plugins')
}
# Literal configuration only: additions/changes require policy review. Whitespace
# and comments may vary; string values and all executable tokens must match.
SOURCE = """<?php
defined( 'ABSPATH' ) || exit;
return array(
    'repository' => 'sw-wordpress/sw-securelogin',
    'repository_id' => 1439006733,
    'allowed_slugs' => array( 'sw-securelogin' ),
    'main_slug' => 'sw-securelogin',
    'state_key' => 'swsl_verified_github_updates_v1',
    'lock_key' => 'swsl_verified_github_updates_lock_v1',
);
"""
DESCRIPTORS = """<?php
defined( 'ABSPATH' ) || exit;
return array(
    defined( 'SWSL_FILE' ) ? plugin_basename( SWSL_FILE ) : 'sw-securelogin/sw-securelogin.php' => array(
        'slug' => 'sw-securelogin',
        'version' => defined( 'SWSL_VERSION' ) ? SWSL_VERSION : '0',
    ),
);
"""


def php_tokens(source):
    """Compare literal PHP without dropping whitespace/comments inside strings."""
    tokens = re.findall(
        r"'(?:\\.|[^'\\])*'|/\*.*?\*/|//[^\n]*|\s+|<\?php(?=\s)|=>|\|\||[A-Za-z_][A-Za-z0-9_]*|[0-9]+|.",
        source, re.S,
    )
    return [t for t in tokens if not t.isspace() and not t.startswith(('/*', '//'))]


def validate_distribution(plugin):
    main = (plugin / 'sw-securelogin.php').read_text(encoding='utf-8')[:8192]
    for header, expected in (
        ('Update URI', 'https://github.com/sw-wordpress/sw-securelogin'),
        ('Text Domain', 'sw-securelogin'),
    ):
        values = re.findall(r'^[ \t/*#@]*' + re.escape(header) + r':[ \t]*(.*)$', main, re.M)
        if values != [expected]:
            raise ValueError('Missing, duplicate or unexpected ' + header + ' header')
    for filename, expected in (('source.php', SOURCE), ('descriptors.php', DESCRIPTORS)):
        source = (plugin / 'src/Update/Config' / filename).read_text(encoding='utf-8')
        if php_tokens(source) != php_tokens(expected):
            raise ValueError('Installed updater configuration differs from ADR 009 policy: ' + filename)


def unique_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError('Duplicate JSON key: ' + key)
        result[key] = value
    return result


def reject_constant(value):
    raise ValueError('Invalid JSON constant: ' + value)


def validate_report(raw, plugin):
    validate_distribution(plugin)
    items = json.loads(raw, object_pairs_hook=unique_object, parse_constant=reject_constant)
    if not isinstance(items, list):
        raise ValueError('Plugin Check report must be an array')
    accepted = set()
    errors = []
    for item in items:
        if (not isinstance(item, dict)
                or set(item) != {'line', 'column', 'type', 'code', 'message', 'docs'}
                or any(type(item[k]) is not int or item[k] < 0 for k in ('line', 'column'))
                or any(not isinstance(item[k], str) for k in ('type', 'code', 'message', 'docs'))
                or item['type'] not in ('ERROR', 'WARNING')
                or not item['code'] or not item['message']):
            raise ValueError('Malformed Plugin Check finding')
        policy = (item['message'], item['docs'])
        if (item['type'] == 'ERROR' and item['code'] == 'plugin_updater_detected'
                and item['line'] == 0 and item['column'] == 0
                and policy in POLICY_ERRORS and policy not in accepted):
            accepted.add(policy)
        elif item['type'] == 'ERROR' or item['code'] == 'plugin_updater_detected':
            errors.append(item['code'] + ': ' + item['message'])
        elif item['code'] == 'update_modification_detected' and (
                item['message'] not in UPDATE_WARNINGS or item['docs'] != DOCS
                or item['line'] != 0 or item['column'] != 0):
            errors.append('Unreviewed updater warning: ' + item['message'])
    if errors:
        raise ValueError('\n'.join(errors))
    return len(items), len(accepted)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--distribution', required=True, choices=['github'])
    parser.add_argument('report', type=Path)
    parser.add_argument('installed_plugin', type=Path)
    args = parser.parse_args()
    try:
        total, policy = validate_report(args.report.read_text(encoding='utf-8'), args.installed_plugin)
    except (OSError, ValueError, RecursionError) as error:
        print('Plugin Check gate failed: ' + str(error), file=sys.stderr)
        return 1
    print(f'Plugin Check: {total} findings; {policy} exact WordPress.org policy errors '
          'accepted for validated ADR 009 GitHub distribution. Raw report retained.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
