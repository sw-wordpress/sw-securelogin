#!/usr/bin/env python3
"""Independent oracle for StrictResponseGuard differential tests (test-only).

Reads lines "mode<TAB>base64(arg)" and prints one verdict per line. Python's JSON parser
and base64 implementation are deliberately unrelated to the PHP code under test.
Run with `python3 -I`.
"""
import base64
import binascii
import json
import re
import sys


class Duplicate(Exception):
    pass


def hook(pairs):
    seen = set()
    for key, _ in pairs:
        if key in seen:
            raise Duplicate()
        seen.add(key)
    return dict(pairs)


def refuse_constant(_name):
    raise ValueError('non-standard constant')


class Pairs(list):
    """All members of an object, duplicates included (a dict would silently drop them)."""


def keep_pairs(pairs):
    return Pairs(pairs)


def strings_ok(value):
    """PHP's json_decode refuses lone surrogates; model that, recursively."""
    if isinstance(value, str):
        try:
            value.encode('utf-8')
        except UnicodeEncodeError:
            return False
        return True
    if isinstance(value, Pairs):
        return all(strings_ok(key) and strings_ok(item) for key, item in value)
    if isinstance(value, list):
        return all(strings_ok(item) for item in value)
    return True


def parse(raw):
    """Return ('invalid'|'scalar'|'dup'|'ok', value)."""
    try:
        text = raw.decode('utf-8')
        value = json.loads(text, object_pairs_hook=keep_pairs, parse_constant=refuse_constant)
    except (UnicodeDecodeError, ValueError, RecursionError):
        return 'invalid', None
    if not strings_ok(value):
        return 'invalid', None
    if not isinstance(value, list):
        return 'scalar', None
    try:
        value = json.loads(text, object_pairs_hook=hook, parse_constant=refuse_constant)
    except Duplicate:
        return 'dup', None
    return 'ok', value


def segment(text):
    """Strict base64url without padding, like the guard's contract."""
    if not re.fullmatch(r'[A-Za-z0-9_-]+', text):
        return None
    try:
        return base64.b64decode(text + '=' * (-len(text) % 4), altchars=b'-_', validate=True)
    except (binascii.Error, ValueError):
        return None


def token_verdict(token):
    parts = token.split('.')
    if len(parts) != 3:
        return 'reject'
    docs = []
    for part in parts[:2]:
        raw = segment(part)
        if raw is None:
            return 'reject'
        status, value = parse(raw)
        if status in ('invalid', 'scalar', 'dup'):
            return 'reject'
        docs.append(value)
    payload = docs[1]
    if isinstance(payload, dict):
        for name in ('nonce', 'azp', 'sub'):
            if name in payload and not isinstance(payload[name], str):
                return 'reject'
        if 'aud' in payload and not isinstance(payload['aud'], (str, list)):
            return 'reject'
    return 'accept'


def jwks_verdict(raw):
    status, value = parse(raw)
    if status == 'dup':
        return 'reject'
    if status != 'ok' or not isinstance(value, dict):
        return 'accept'
    keys = value.get('keys')
    if keys is None or not isinstance(keys, (list, dict)):
        return 'accept'
    seen = set()
    for key in (keys.values() if isinstance(keys, dict) else keys):
        if isinstance(key, dict) and isinstance(key.get('kid'), str):
            if key['kid'] in seen:
                return 'reject'
            seen.add(key['kid'])
    return 'accept'


def main():
    out = []
    for line in sys.stdin.read().splitlines():
        mode, _, encoded = line.partition('\t')
        raw = base64.b64decode(encoded)
        if mode == 'doc':
            out.append(parse(raw)[0])
        elif mode == 'token':
            out.append(token_verdict(raw.decode('latin-1')))
        elif mode == 'jwks':
            out.append(jwks_verdict(raw))
        else:
            out.append('error')
    sys.stdout.write('\n'.join(out) + '\n')


if __name__ == '__main__':
    main()
