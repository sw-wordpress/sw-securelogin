"""Real fixtures: CI 43849906607's failing report and CI 43852016000's packaged configs."""
import copy
import hashlib
import importlib.util
import json
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('plugin_check', ROOT / 'bin/check-plugin-check.py')
gate = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(gate)
REPORT = json.loads((Path(__file__).parent / 'fixtures/plugin-check-2.1.0.json').read_text())
PACKAGED = json.loads((Path(__file__).parent / 'fixtures/packaged-updater-config-43852016000.json').read_text())


class PluginCheckTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='swsl-plugin-check-')
        self.addCleanup(self.temp.cleanup)
        self.plugin = Path(self.temp.name)
        self.main = self.plugin / 'sw-securelogin.php'
        self.main.write_text((ROOT / 'sw-securelogin.php').read_text())
        self.source = self.plugin / 'src/Update/Config/source.php'
        self.source.parent.mkdir(parents=True)
        self.source.write_text(gate.SOURCE)
        self.descriptors = self.source.with_name('descriptors.php')
        self.descriptors.write_text(gate.DESCRIPTORS)
        self.clean = [row for row in copy.deepcopy(REPORT)
                      if row['code'] != 'missing_direct_file_access_protection']

    def check(self, items):
        return gate.validate_report(json.dumps(items), self.plugin)

    def test_real_failed_report_still_fails_on_direct_access(self):
        with self.assertRaisesRegex(ValueError, 'missing_direct_file_access_protection'):
            self.check(REPORT)

    def test_actual_packaged_configs_match_distribution_policy(self):
        for filename, fixture in PACKAGED['files'].items():
            with self.subTest(filename=filename):
                self.assertEqual(fixture['sha256'], hashlib.sha256(fixture['source'].encode()).hexdigest())
                (self.source.parent / filename).write_text(fixture['source'])
                gate.validate_distribution(self.plugin)
            (self.source.parent / filename).write_text(
                gate.SOURCE if filename == 'source.php' else gate.DESCRIPTORS)
        for filename, fixture in PACKAGED['files'].items():
            (self.source.parent / filename).write_text(fixture['source'])
        self.assertEqual((7, 2), self.check(self.clean))
        with self.assertRaisesRegex(ValueError, 'missing_direct_file_access_protection'):
            self.check(REPORT)

    def test_optional_trailing_commas_only_are_normalized(self):
        for pinned in (gate.SOURCE, gate.DESCRIPTORS):
            compact = re.sub(r',\s*\)', ')', pinned)
            for formatted in (compact, compact.replace(')', ', /* trailing */ )')):
                with self.subTest(formatted=formatted):
                    self.assertEqual(gate.php_tokens(pinned), gate.php_tokens(formatted))
        self.assertEqual(["'a,)b'", ',', "'c'"], gate.php_tokens("'a,)b', 'c'"))
        self.assertEqual(['(', 'a', ',', ',', 'b', ')', ';'], gate.php_tokens('(a,,b);'))

    def test_packaged_config_mutations_remain_fatal(self):
        for filename, fixture in PACKAGED['files'].items():
            source = fixture['source']
            path = self.source.parent / filename
            # Every required separator stays required, in both real packaged files.
            mutations = [source[:i] + source[i + 1:] for i, char in enumerate(source)
                         if char == ',' and not source[i + 1:].lstrip().startswith(')')]
            mutations += [
                source.replace("'sw-securelogin'", "'sw-securelogin,)'"),
                source.replace("defined('ABSPATH') || exit;", ''),
                source.replace('return array(', "echo 'unexpected'; return array(", 1),
                source.replace('=>', '=', 1),
                source.replace("'sw-securelogin'", "'other'"),
                source.replace('return array(', 'return array(,', 1),
                source.replace(');', ',,);', 1),
                source.replace(');', '),;', 1),
                source.replace(');', '); unexpected();', 1),
            ]
            for mutated in mutations:
                with self.subTest(filename=filename, mutated=mutated):
                    self.assertNotEqual(source, mutated)
                    path.write_text(mutated)
                    with self.assertRaisesRegex(ValueError, filename):
                        gate.validate_distribution(self.plugin)
            path.write_text(gate.SOURCE if filename == 'source.php' else gate.DESCRIPTORS)

    def test_only_fixed_report_passes_with_two_explicit_policy_findings(self):
        self.assertEqual((7, 2), self.check(self.clean))
        self.assertEqual((0, 0), self.check([]))
        self.assertEqual(gate.php_tokens(gate.SOURCE), gate.php_tokens(
            (ROOT / 'src/Update/Config/source.php').read_text()))
        self.assertEqual(gate.php_tokens(gate.DESCRIPTORS), gate.php_tokens(
            (ROOT / 'src/Update/Config/descriptors.php').read_text()))

    def test_descriptor_configuration_must_match_the_single_plugin_identity(self):
        for original, replacement in (
            ('sw-securelogin/sw-securelogin.php', 'other/other.php'),
            ("'slug' => 'sw-securelogin'", "'slug' => 'other'"),
            ('plugin_basename( SWSL_FILE )', "'other/other.php'"),
            ("defined( 'ABSPATH' ) || exit;", ''),
        ):
            self.descriptors.write_text(gate.DESCRIPTORS.replace(original, replacement))
            with self.subTest(original=original), self.assertRaises(ValueError):
                self.check(self.clean)

    def test_every_unexpected_error_remains_fatal(self):
        for code in ('missing_direct_file_access_protection', 'security_error', 'new_check'):
            row = {**self.clean[0], 'code': code}
            with self.subTest(code=code), self.assertRaises(ValueError):
                self.check(self.clean + [row])

    def test_policy_findings_require_exact_schema_identity_and_no_duplicates(self):
        for index in (0, 1):
            for key, value in (('message', 'new updater'), ('code', 'other'), ('line', 1),
                               ('column', 1), ('docs', 'other'), ('type', 'WARNING')):
                items = copy.deepcopy(self.clean)
                items[index][key] = value
                with self.subTest(index=index, key=key), self.assertRaises(ValueError):
                    self.check(items)
            with self.assertRaises(ValueError):
                self.check(self.clean + [self.clean[index]])

    def test_new_updater_warning_fails_but_other_warnings_remain_visible(self):
        warning = copy.deepcopy(self.clean[2])
        warning['message'] += '_new'
        with self.assertRaises(ValueError):
            self.check(self.clean + [warning])
        self.assertEqual((1, 0), self.check([{**warning, 'code': 'other_warning'}]))

    def test_malformed_reports_fail_closed(self):
        for raw in ('', 'null', '{}', 'true', '[] trailing', '[NaN]', '[Infinity]',
                    '[{"type":"WARNING","type":"ERROR"}]', '[[[]]]', '[1]'):
            with self.subTest(raw=raw), self.assertRaises(ValueError):
                gate.validate_report(raw, self.plugin)
        for key in self.clean[0]:
            items = copy.deepcopy(self.clean)
            del items[0][key]
            with self.subTest(missing=key), self.assertRaises(ValueError):
                self.check(items)
        for key, value in (('line', True), ('line', -1), ('column', '0'), ('type', 'error'),
                           ('message', None), ('code', ''), ('extra', 'unreviewed')):
            with self.subTest(key=key), self.assertRaises(ValueError):
                self.check([{**self.clean[0], key: value}])

    def test_wrong_or_duplicate_headers_fail_even_with_no_findings(self):
        original = self.main.read_text()
        for replacement in ('', 'https://wordpress.org/plugins/sw-securelogin',
                            'https://github.com/other/repo',
                            'https://github.com/sw-wordpress/sw-securelogin/',
                            'https://github.com/sw-wordpress/sw-securelogin\n * Update URI: https://github.com/sw-wordpress/sw-securelogin'):
            self.main.write_text(original.replace('https://github.com/sw-wordpress/sw-securelogin', replacement))
            with self.subTest(replacement=replacement), self.assertRaises(ValueError):
                self.check([])
        self.main.write_text(original.replace('Text Domain: sw-securelogin', 'Text Domain: other'))
        with self.assertRaises(ValueError):
            self.check([])

    def test_changed_config_or_executable_php_is_never_accepted(self):
        for original, replacement in (
            ('1439006733', '1439006734'), ("'sw-wordpress/sw-securelogin'", "'other/repo'"),
            ("'main_slug' => 'sw-securelogin'", "'main_slug' => 'other'"),
            ("array( 'sw-securelogin' )", "array( 'sw-securelogin', 'other' )"),
            ("'swsl_verified_github_updates_v1'", "'other'"),
            ("'swsl_verified_github_updates_lock_v1'", "'other'"),
            ("'sw-wordpress/sw-securelogin'", "'sw-wordpress/ sw-securelogin'"),
            ("defined( 'ABSPATH' ) || exit;", ''),
            ('return array(', "echo 'unexpected'; return array("),
            ('return array(', 'returnarray('),
            ('return array(', 'ret/**/urn array('),
            ('1439006733', '1439 006733'),
            ('<?php\n', '<?php'),
        ):
            self.source.write_text(gate.SOURCE.replace(original, replacement))
            with self.subTest(original=original), self.assertRaises(ValueError):
                self.check(self.clean)
        self.source.unlink()
        with self.assertRaises(OSError):
            self.check([])

    def test_cli_requires_explicit_distribution_and_preserves_raw_report(self):
        report = self.plugin / 'report.json'
        report.write_text(json.dumps(self.clean))
        before = report.read_bytes()
        args = [sys.executable, str(ROOT / 'bin/check-plugin-check.py')]
        def run(options):
            return subprocess.run(args + options + [str(report), str(self.plugin)], capture_output=True)
        self.assertEqual(2, run([]).returncode)
        self.assertEqual(2, run(['--distribution', 'wordpress-org']).returncode)
        self.assertEqual(0, run(['--distribution', 'github']).returncode)
        report.write_text(json.dumps(REPORT))
        failed = run(['--distribution', 'github'])
        self.assertEqual(1, failed.returncode)
        self.assertIn(b'missing_direct_file_access_protection', failed.stderr)
        report.write_bytes(before)
        run(['--distribution', 'github'])
        self.assertEqual(before, report.read_bytes())


if __name__ == '__main__':
    unittest.main()
