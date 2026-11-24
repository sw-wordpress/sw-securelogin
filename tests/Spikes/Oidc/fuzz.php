<?php
/**
 * Differential and edge-case tests for StrictResponseGuard (test-only evidence).
 *
 * Usage: php fuzz.php <autoload.php> <work-dir>
 * Env:   SWSL_FUZZ_ITERATIONS (per category, default 30000), SWSL_FUZZ_SEED (default fixed).
 *
 * The guard's verdicts are compared with an unrelated oracle (fuzz_oracle.py: Python's JSON and
 * base64). A disagreement, a PHP diagnostic or an unexpected exception fails the run.
 */
declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SW\Securelogin\Spikes\Oidc\AmbiguousResponse;
use SW\Securelogin\Spikes\Oidc\StrictResponseGuard;

require $argv[1];
require __DIR__ . '/AmbiguousResponse.php';
require __DIR__ . '/StrictResponseGuard.php';

$work = $argv[2] ?? sys_get_temp_dir();
$iterations = (int) (getenv('SWSL_FUZZ_ITERATIONS') ?: 30000);
$seed = (int) (getenv('SWSL_FUZZ_SEED') ?: 20261124);
mt_srand($seed);

$diagnostics = [];
set_error_handler(static function (int $level, string $message) use (&$diagnostics): bool {
	$diagnostics[] = $message;
	return true;
});

$factory = new Psr17Factory();
$stub = new class implements ClientInterface {
	public string $body = '';
	public int $calls = 0;
	public function sendRequest(RequestInterface $request): ResponseInterface {
		++$this->calls;
		return new Response(200, ['X-Fixture' => 'kept'], $this->body);
	}
};
$guard = new StrictResponseGuard($stub, $factory);
$request = $factory->createRequest('GET', 'https://oidc.invalid/');

$failures = [];
$fail = static function (string $category, string $detail) use (&$failures): void {
	$failures[] = $category . ': ' . $detail;
};
$show = static fn (string $value): string => substr(json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES) ?: '?', 0, 300);

/** Guard verdict for a body: 'accept' or 'reject'; checks that accepted bodies survive unchanged. */
$verdict = static function (string $body) use ($guard, $stub, $request, $fail, $show): string {
	$stub->body = $body;
	try {
		$response = $guard->sendRequest($request);
	} catch (AmbiguousResponse $error) {
		if ('OIDC response guard rejected the response.' !== $error->getMessage() || null !== $error->getPrevious()) {
			$fail('exception-content', $show($body));
		}
		return 'reject';
	} catch (Throwable $error) {
		$fail('unexpected-' . get_class($error), $show($body));
		return 'error';
	}
	if ((string) $response->getBody() !== $body || 'kept' !== $response->getHeaderLine('X-Fixture') || 200 !== $response->getStatusCode()) {
		$fail('response-altered', $show($body));
	}
	return 'accept';
};

/* ---------- Generators ---------- */
$pool = ['a', 'b', 'c', 'sub', 'aud', 'nonce', 'azp', 'iss', 'exp', '', 'é', '日本', '😀', 'a"b', 'a\\b', 'a/b', "a\nb", '0', '00', 'A', 'ab', 'a b', "\u{0}", "\u{7f}", 'ß', "\u{2028}"];
$ws = static fn (): string => ['', '', '', '', ' ', "\n", "\t", "\r\n  "][mt_rand(0, 7)];
$encodeString = static function (string $text) use (&$encodeString): string {
	$out = '"';
	foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
		$code = mb_ord($char, 'UTF-8');
		$roll = mt_rand(0, 9);
		if ('"' === $char) {
			$out .= '\\"';
		} elseif ('\\' === $char) {
			$out .= '\\\\';
		} elseif ('/' === $char) {
			$out .= $roll < 5 ? '/' : '\\/';
		} elseif ($code < 0x20) {
			$out .= ("\n" === $char && $roll < 5) ? '\\n' : sprintf('\\u%04' . ($roll % 2 ? 'x' : 'X'), $code);
		} elseif ($code < 0x80) {
			$out .= $roll < 7 ? $char : sprintf('\\u%04x', $code);
		} elseif ($code < 0x10000) {
			$out .= $roll < 5 ? $char : sprintf('\\u%04x', $code);
		} else {
			$offset = $code - 0x10000;
			$out .= $roll < 5 ? $char : sprintf('\\u%04x\\u%04x', 0xD800 + ($offset >> 10), 0xDC00 + ($offset & 0x3FF));
		}
	}
	return $out . '"';
};
$scalar = static function () use ($pool, $encodeString): string {
	return match (mt_rand(0, 7)) {
		0 => '0', 1 => '-1', 2 => '1.5e3', 3 => '123456789012345678901234567890', 4 => 'true', 5 => 'false', 6 => 'null',
		default => $encodeString($pool[array_rand($pool)]),
	};
};
$value = static function (int $depth, array $keys) use (&$value, $ws, $scalar, $encodeString): string {
	$roll = mt_rand(0, 9);
	if ($depth <= 0 || $roll < 4) {
		return $scalar();
	}
	if ($roll < 6) {
		$items = [];
		for ($n = mt_rand(0, 4); $n > 0; --$n) {
			$items[] = $ws() . $value($depth - 1, $keys) . $ws();
		}
		return '[' . implode(',', $items) . ']';
	}
	$subset = array_map(static fn ($k) => $keys[$k], (array) array_rand($keys, min(count($keys), mt_rand(2, 6))));
	$unique = mt_rand(0, 1) === 1;
	$members = [];
	for ($n = mt_rand(0, 6); $n > 0; --$n) {
		$key = $subset[array_rand($subset)];
		if ($unique) {
			if (in_array($key, array_column($members, 0), true)) {
				continue;
			}
		}
		$members[] = [$key, $ws() . $encodeString($key) . $ws() . ':' . $ws() . $value($depth - 1, $keys) . $ws()];
	}
	return '{' . implode(',', array_column($members, 1)) . '}';
};
$mutate = static function (string $text): string {
	$length = strlen($text);
	if ($length < 2) {
		return $text;
	}
	$position = mt_rand(0, $length - 1);
	switch (mt_rand(0, 6)) {
		case 0:
			return substr($text, 0, $position) . substr($text, $position + 1);
		case 1:
			return substr($text, 0, $position) . ['{', '}', '[', ']', '"', ',', ':', '\\', ' ', '0', "\x00", "\xff"][mt_rand(0, 11)] . substr($text, $position);
		case 2:
			return substr($text, 0, $position);
		case 3:
			$slice = substr($text, $position, mt_rand(1, 20));
			return substr($text, 0, $position) . $slice . substr($text, $position);
		case 4:
			$copy = $text;
			$copy[$position] = $text[($position + 1) % $length];
			return $copy;
		case 5:
			return substr($text, 0, $position) . "\xc3" . substr($text, $position);
		default:
			return $text . $text;
	}
};
$rootDocument = static function () use ($value, $pool, $mutate, $ws): string {
	$keys = array_values(array_diff($pool, ['id_token', 'keys']));
	$roll = mt_rand(0, 19);
	$doc = $roll < 16 ? $value(4, $keys) : ($roll < 19 ? '[' . $value(3, $keys) . ',' . $value(3, $keys) . ']' : $value(0, $keys));
	if ($roll < 16 && '{' !== ($doc[0] ?? '')) {
		$doc = '{"a":' . $doc . ',"b":' . $value(2, $keys) . '}';
	}
	$doc = $ws() . $doc . $ws();
	return mt_rand(0, 3) === 0 ? $mutate($doc) : $doc;
};

/* ---------- Differential execution ---------- */
$oracle = static function (array $lines) use ($work): array {
	$input = $work . '/swsl-fuzz-' . getmypid() . '.txt';
	file_put_contents($input, implode("\n", $lines) . "\n");
	$output = [];
	$status = 0;
	exec('python3 -I ' . escapeshellarg(__DIR__ . '/fuzz_oracle.py') . ' < ' . escapeshellarg($input), $output, $status);
	unlink($input);
	if (0 !== $status || count($output) !== count($lines)) {
		fwrite(STDERR, "Oracle failed.\n");
		exit(1);
	}
	return $output;
};
$line = static fn (string $mode, string $arg): string => $mode . "\t" . base64_encode($arg);
$stats = [];
$count = static function (string $category, string $label) use (&$stats): void {
	$stats[$category][$label] = ($stats[$category][$label] ?? 0) + 1;
};

// 1. Generic documents: duplicate object names at any depth, escapes and invalid mutations.
$docs = [];
for ($i = 0; $i < $iterations; ++$i) {
	$docs[] = $rootDocument();
}
$expected = $oracle(array_map(static fn ($doc) => $line('doc', $doc), $docs));
$disagree = 0;
foreach ($docs as $index => $doc) {
	$guardVerdict = $verdict($doc);
	$oracleVerdict = $expected[$index];
	$phpValid = is_array(json_decode($doc, true));
	$count('documents', $oracleVerdict);
	$count('documents-php', $phpValid ? 'valid' : 'not-array');
	if ($phpValid !== in_array($oracleVerdict, ['ok', 'dup'], true)) {
		++$disagree; // Parser disagreement: the oracle cannot judge the duplicate verdict here.
		json_decode($doc, true);
		$count('disagreement-reason', json_last_error_msg());
		$count('documents', 'parser-disagreement');
		if ($disagree <= 5) {
			echo 'NOTE: PHP/Python validity differs for ' . $show($doc) . ' (php ' . ($phpValid ? 'valid' : 'invalid') . ', python ' . $oracleVerdict . ").\n";
		}
		$fail('parser-disagreement', $show($doc) . ' php ' . ($phpValid ? 'valid' : json_last_error_msg()) . ', oracle ' . $oracleVerdict);
		continue;
	}
	$want = 'dup' === $oracleVerdict && $phpValid ? 'reject' : 'accept';
	if ($guardVerdict !== $want) {
		$fail('document-mismatch', 'guard ' . $guardVerdict . ' oracle ' . $oracleVerdict . ' ' . $show($doc));
	}
}

// 2. Tokens: corrupted compact serialization plus claim/header type and duplicate matrix.
$b64 = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
$claimKeys = ['sub', 'aud', 'nonce', 'azp', 'iss', 'exp', 'iat', 'x'];
$headerKeys = ['alg', 'kid', 'typ', 'jku'];
$claimValue = static function (string $key) use ($encodeString, $value, $claimKeys): string {
	$choice = mt_rand(0, 11);
	if ($choice < 9) {
		return $encodeString(['fixture', 'client', 'é', 'x', 'n'][mt_rand(0, 4)]);
	}
	if ('aud' === $key && $choice < 11) {
		return '[' . $encodeString('client') . ',' . $encodeString('other') . ']';
	}
	return $value(2, $claimKeys);
};
$object = static function (array $keys) use ($claimValue, $encodeString, $ws): string {
	$members = [];
	$unique = mt_rand(0, 3) > 0;
	$chosen = $keys;
	shuffle($chosen);
	for ($n = mt_rand(1, 7); $n > 0; --$n) {
		$key = $unique ? array_pop($chosen) : $keys[array_rand($keys)];
		if (null === $key) {
			break;
		}
		$members[] = $ws() . $encodeString($key) . ':' . $claimValue($key) . $ws();
	}
	return '{' . implode(',', $members) . '}';
};
$corrupt = static function (string $segment) use ($b64): string {
	return match (mt_rand(0, 59)) {
		0 => $segment . '=',
		1 => $segment . '==',
		2 => strtr($segment, '-_', '+/') . '+',
		3 => substr($segment, 0, -1),
		4 => $segment . 'A',
		5 => '',
		6 => ' ' . $segment,
		7 => $segment . "\n",
		8 => substr($segment, 0, intdiv(strlen($segment), 2)) . '.' . substr($segment, intdiv(strlen($segment), 2)),
		9 => $b64("\xff\xfe" . $segment),
		default => $segment,
	};
};
$tokens = [];
for ($i = 0; $i < $iterations; ++$i) {
	$header = mt_rand(0, 19) === 0 ? $value(2, $headerKeys) : $object($headerKeys);
	$payload = mt_rand(0, 19) === 0 ? $value(2, $claimKeys) : $object($claimKeys);
	if (mt_rand(0, 30) === 0) {
		$payload = $mutate($payload);
	}
	$tokens[] = $corrupt($b64($header)) . '.' . $corrupt($b64($payload)) . '.' . ($b64('signature') . (mt_rand(0, 30) === 0 ? '.extra' : ''));
}
$expected = $oracle(array_map(static fn ($token) => $line('token', $token), $tokens));
foreach ($tokens as $index => $token) {
	$body = json_encode(['id_token' => $token, 'access_token' => 'fixture'], JSON_THROW_ON_ERROR);
	$guardVerdict = $verdict($body);
	$count('tokens', $expected[$index]);
	if ($guardVerdict !== $expected[$index]) {
		$fail('token-mismatch', 'guard ' . $guardVerdict . ' oracle ' . $expected[$index] . ' ' . $show($token));
	}
}

// 3. JWKS documents: kid duplicates, non-string kids, object-shaped keys and raw duplicate names.
$jwksDocs = [];
$kids = ['k1', 'k2', 'k3', '1', '01', '', 'é'];
for ($i = 0; $i < $iterations; ++$i) {
	$members = [];
	for ($n = mt_rand(0, 5); $n > 0; --$n) {
		$kid = mt_rand(0, 9) < 8 ? $encodeString($kids[array_rand($kids)]) : $scalar();
		$parts = [];
		if (mt_rand(0, 9) < 9) {
			$parts[] = '"kid":' . $kid;
		}
		$parts[] = '"kty":"RSA"';
		if (mt_rand(0, 14) === 0) {
			$parts[] = '"kid":' . $encodeString('k1');
		}
		$members[] = '{' . implode(',', $parts) . '}';
	}
	$keys = match (mt_rand(0, 9)) {
		0 => '{"x":' . implode(',"y":', $members ?: ['{}']) . '}',
		1 => 'null',
		2 => '"text"',
		default => '[' . implode(',', $members) . ']',
	};
	$doc = '{"keys":' . $keys . (mt_rand(0, 9) === 0 ? ',"keys":[]' : '') . '}';
	$jwksDocs[] = mt_rand(0, 12) === 0 ? $mutate($doc) : $doc;
}
$expected = $oracle(array_map(static fn ($doc) => $line('jwks', $doc), $jwksDocs));
foreach ($jwksDocs as $index => $doc) {
	$guardVerdict = $verdict($doc);
	$count('jwks', $expected[$index]);
	$phpValid = is_array(json_decode($doc, true));
	$pythonValid = 'reject' === $expected[$index] || 'accept' === $expected[$index];
	if ($guardVerdict !== $expected[$index]) {
		// Documents only one parser accepts cannot be judged by the oracle; reject is the safe side.
		if ('reject' === $guardVerdict && 'accept' === $expected[$index] && !$phpValid) {
			continue;
		}
		$fail('jwks-mismatch', 'guard ' . $guardVerdict . ' oracle ' . $expected[$index] . ' ' . $show($doc));
	}
	unset($pythonValid);
}

/* ---------- Targeted edge cases ---------- */
$edge = static function (string $name, string $body, string $want) use ($verdict, $fail, $show): void {
	$got = $verdict($body);
	if ($got !== $want) {
		$fail('edge-' . $name, 'expected ' . $want . ', got ' . $got . ' for ' . $show($body));
	}
};
$cases = [
	// Duplicates, including escapes and depth.
	['plain-duplicate', '{"a":1,"a":2}', 'reject'],
	['duplicate-with-whitespace', "{ \"a\" \n:\t1 ,\r\n \"a\" : 2 }", 'reject'],
	['escaped-duplicate', '{"a":1,"\\u0061":2}', 'reject'],
	['upper-hex-escape-duplicate', '{"\\u00E9":1,"\\u00e9":2}', 'reject'],
	['literal-vs-escaped-unicode', "{\"\u{e9}\":1,\"\\u00e9\":2}", 'reject'],
	['surrogate-pair-vs-literal', "{\"\u{1F600}\":1,\"\\ud83d\\ude00\":2}", 'reject'],
	['solidus-escape-duplicate', '{"a/b":1,"a\\/b":2}', 'reject'],
	['escaped-quote-duplicate', '{"a\\"":1,"a\\"":2}', 'reject'],
	['escaped-backslash-then-quote', '{"a\\\\":1,"a":2}', 'accept'],
	['escaped-control-duplicate', '{"a\\nb":1,"a\\u000ab":2}', 'reject'],
	['nul-key-duplicate', '{"\\u0000":1,"\\u0000":2}', 'reject'],
	['empty-key-duplicate', '{"":1,"":2}', 'reject'],
	['numeric-looking-distinct', '{"1":1,"01":2,"1.0":3}', 'accept'],
	['numeric-duplicate', '{"1":1,"1":2}', 'reject'],
	['case-differs', '{"a":1,"A":2}', 'accept'],
	['nested-object-duplicate', '{"o":{"a":1,"a":2}}', 'reject'],
	['array-of-objects-duplicate', '[{"a":1},{"b":1,"b":2}]', 'reject'],
	['same-key-in-siblings', '{"x":{"a":1},"y":{"a":2},"z":[{"a":3},{"a":4}]}', 'accept'],
	['same-key-in-parent-and-child', '{"a":{"a":{"a":1}}}', 'accept'],
	['repeated-string-values', '{"a":"a","b":"a","c":["a","a","a"]}', 'accept'],
	['value-equals-key-text', '{"a":"a","b":":","c":",\\"a\\":"}', 'accept'],
	['key-like-text-in-value', '{"a":"\\"x\\":1,\\"x\\":2","b":"{\\"y\\":1,\\"y\\":2}"}', 'accept'],
	['braces-in-strings', '{"a":"{","b":"}","c":"[","d":"]"}', 'accept'],
	['array-repeated-numbers', '[1,1,1,{"a":1},{"a":1}]', 'accept'],
	['duplicate-after-long-prefix', '{' . implode(',', array_map(static fn ($n) => '"k' . $n . '":' . $n, range(1, 3000))) . ',"k1500":0}', 'reject'],
	['many-unique-keys', '{' . implode(',', array_map(static fn ($n) => '"k' . $n . '":' . $n, range(1, 5000))) . '}', 'accept'],
	['dup-nested-500-arrays', str_repeat('[', 500) . '{"a":1,"a":2}' . str_repeat(']', 500), 'reject'],
	['unique-nested-500-arrays', str_repeat('[', 500) . '{"a":1,"b":2}' . str_repeat(']', 500), 'accept'],
	['dup-nested-300-objects', str_repeat('{"x":', 300) . '{"a":1,"a":2}' . str_repeat('}', 300), 'reject'],
	// Invalid or non-array JSON is not interpreted by the candidate either; it passes unchanged.
	['too-deep-passes-and-fails-decode', str_repeat('[', 600) . str_repeat(']', 600), 'accept'],
	['invalid-utf8-passes', "{\"a\":1,\"a\":\"\xff\"}", 'accept'],
	['lone-surrogate-passes', '{"a":1,"a":"\\ud800"}', 'accept'],
	['truncated-passes', '{"a":1,"a":2', 'accept'],
	['trailing-garbage-passes', '{"a":1,"a":2} x', 'accept'],
	['bom-passes', "\xEF\xBB\xBF{\"a\":1,\"a\":2}", 'accept'],
	['nan-passes', '{"a":NaN,"a":1}', 'accept'],
	['scalar-string', '"text"', 'accept'],
	['scalar-number', '12', 'accept'],
	['null-literal', 'null', 'accept'],
	['empty-body', '', 'accept'],
	['plain-text', 'Service unavailable', 'accept'],
	['empty-object', '{}', 'accept'],
	['empty-array', '[]', 'accept'],
	// Token shape.
	['id-token-array', '{"id_token":["a.b.c"]}', 'reject'],
	['id-token-number', '{"id_token":12}', 'reject'],
	['id-token-empty', '{"id_token":""}', 'reject'],
	['id-token-two-parts', '{"id_token":"abc.def"}', 'reject'],
	['id-token-four-parts', '{"id_token":"a.b.c.d"}', 'reject'],
	['id-token-null-ignored', '{"id_token":null,"access_token":"x"}', 'accept'],
	['id-token-duplicate-name', '{"id_token":"x","id_token":"y"}', 'reject'],
	['no-id-token', '{"access_token":"x","token_type":"Bearer"}', 'accept'],
];
$tokenOf = static fn (string $header, string $payload): string => '{"id_token":"' . $b64($header) . '.' . $b64($payload) . '.c2ln"}';
$validHeader = '{"alg":"RS256","kid":"k"}';
$validPayload = '{"iss":"i","sub":"s","aud":"c","nonce":"n","exp":1,"iat":1}';
$cases = array_merge($cases, [
	['token-valid', $tokenOf($validHeader, $validPayload), 'accept'],
	['token-valid-aud-list', $tokenOf($validHeader, '{"sub":"s","aud":["c","d"],"azp":"c","nonce":"n"}'), 'accept'],
	['token-aud-object', $tokenOf($validHeader, '{"sub":"s","aud":{"0":"c"},"nonce":"n"}'), 'reject'],
	['token-aud-number', $tokenOf($validHeader, '{"sub":"s","aud":7,"nonce":"n"}'), 'reject'],
	['token-aud-null', $tokenOf($validHeader, '{"sub":"s","aud":null,"nonce":"n"}'), 'reject'],
	['token-aud-bool', $tokenOf($validHeader, '{"sub":"s","aud":true,"nonce":"n"}'), 'reject'],
	['token-nonce-array', $tokenOf($validHeader, '{"sub":"s","aud":"c","nonce":["n"]}'), 'reject'],
	['token-nonce-object', $tokenOf($validHeader, '{"sub":"s","aud":"c","nonce":{"a":1}}'), 'reject'],
	['token-nonce-null', $tokenOf($validHeader, '{"sub":"s","aud":"c","nonce":null}'), 'reject'],
	['token-azp-array', $tokenOf($validHeader, '{"sub":"s","aud":"c","nonce":"n","azp":[]}'), 'reject'],
	['token-sub-number', $tokenOf($validHeader, '{"sub":1,"aud":"c","nonce":"n"}'), 'reject'],
	['token-sub-object', $tokenOf($validHeader, '{"sub":{},"aud":"c","nonce":"n"}'), 'reject'],
	['token-missing-claims-left-to-library', $tokenOf($validHeader, '{"iss":"i"}'), 'accept'],
	['token-duplicate-sub', $tokenOf($validHeader, '{"sub":"a","sub":"b","aud":"c","nonce":"n"}'), 'reject'],
	['token-escaped-duplicate-sub', $tokenOf($validHeader, '{"su\\u0062":"a","sub":"b","aud":"c","nonce":"n"}'), 'reject'],
	['token-duplicate-nonce', $tokenOf($validHeader, '{"nonce":"x","aud":"c","nonce":"n","sub":"s"}'), 'reject'],
	['token-nested-duplicate', $tokenOf($validHeader, '{"sub":"s","aud":"c","nonce":"n","cnf":{"a":1,"a":2}}'), 'reject'],
	['token-duplicate-in-aud-array-element', $tokenOf($validHeader, '{"sub":"s","aud":[{"a":1,"a":2}],"nonce":"n"}'), 'reject'],
	['token-header-duplicate-alg', $tokenOf('{"alg":"none","alg":"RS256"}', $validPayload), 'reject'],
	['token-header-duplicate-kid', $tokenOf('{"kid":"a","kid":"b","alg":"RS256"}', $validPayload), 'reject'],
	['token-header-escaped-duplicate', $tokenOf('{"a\\u006cg":"none","alg":"RS256"}', $validPayload), 'reject'],
	['token-header-not-object', $tokenOf('"RS256"', $validPayload), 'reject'],
	['token-payload-scalar', $tokenOf($validHeader, '12'), 'reject'],
	['token-payload-array-left-to-library', $tokenOf($validHeader, '["a"]'), 'accept'],
	['token-payload-invalid-json', $tokenOf($validHeader, '{"sub":'), 'reject'],
	['token-payload-lone-surrogate', $tokenOf($validHeader, '{"sub":"\\ud800","aud":"c","nonce":"n"}'), 'reject'],
	['token-payload-bom', '{"id_token":"' . $b64($validHeader) . '.' . $b64("\xEF\xBB\xBF" . $validPayload) . '.c2ln"}', 'reject'],
	['token-segment-padding', '{"id_token":"' . $b64($validHeader) . '=.' . $b64($validPayload) . '.c2ln"}', 'reject'],
	['token-segment-standard-alphabet', '{"id_token":"' . strtr(base64_encode("{\"a\":\"\xfb\xff\"}"), '=', '') . '.' . $b64($validPayload) . '.c2ln"}', 'reject'],
	['token-segment-whitespace', '{"id_token":"' . $b64($validHeader) . ' .' . $b64($validPayload) . '.c2ln"}', 'reject'],
	['token-segment-empty-header', '{"id_token":".' . $b64($validPayload) . '.c2ln"}', 'reject'],
	['token-segment-empty-payload', '{"id_token":"' . $b64($validHeader) . '..c2ln"}', 'reject'],
	['token-segment-length-mod-4-is-1', '{"id_token":"AAAAA.' . $b64($validPayload) . '.c2ln"}', 'reject'],
	['token-compact-with-newline', '{"id_token":"' . $b64($validHeader) . '.' . $b64($validPayload) . '.c2ln\\n"}', 'accept'],
	['token-signature-not-inspected', '{"id_token":"' . $b64($validHeader) . '.' . $b64($validPayload) . '.!!!"}', 'accept'],
	// JWKS.
	['jwks-valid', '{"keys":[{"kid":"a","kty":"RSA"},{"kid":"b","kty":"RSA"}]}', 'accept'],
	['jwks-duplicate-kid', '{"keys":[{"kid":"a"},{"kid":"a"}]}', 'reject'],
	['jwks-three-with-duplicate', '{"keys":[{"kid":"a"},{"kid":"b"},{"kid":"a"}]}', 'reject'],
	['jwks-duplicate-name-in-key', '{"keys":[{"kid":"a","kid":"b"}]}', 'reject'],
	['jwks-keys-as-object-duplicate-kid', '{"keys":{"x":{"kid":"a"},"y":{"kid":"a"}}}', 'reject'],
	['jwks-kid-numbers-not-compared', '{"keys":[{"kid":1},{"kid":1}]}', 'accept'],
	['jwks-kid-numeric-strings-distinct', '{"keys":[{"kid":"1"},{"kid":"01"}]}', 'accept'],
	['jwks-keys-without-kid', '{"keys":[{"kty":"RSA"},{"kty":"RSA"}]}', 'accept'],
	['jwks-keys-empty', '{"keys":[]}', 'accept'],
	['jwks-keys-null', '{"keys":null}', 'accept'],
	['jwks-keys-scalar', '{"keys":"x"}', 'accept'],
	['jwks-keys-with-scalars', '{"keys":["a",1,null,{"kid":"a"},{"kid":"b"}]}', 'accept'],
	['jwks-duplicate-keys-member', '{"keys":[],"keys":[]}', 'reject'],
]);
foreach ($cases as [$name, $body, $want]) {
	$edge($name, $body, $want);
}
// The deep-nesting pass-through is only safe if the candidate's decoder refuses it as well.
if (null !== json_decode(str_repeat('[', 600) . str_repeat(']', 600), true)) {
	$fail('edge-depth-assumption', 'json_decode accepted 600 levels; pass-through is no longer safe.');
}

// Size boundary and performance (linear behavior).
$padded = static fn (int $total): string => '{"p":"' . str_repeat('a', $total - 8) . '"}';
$edge('size-exactly-limit', $padded(262144), 'accept');
$edge('size-limit-plus-one', $padded(262145), 'reject');
$edge('size-limit-plus-one-invalid-json', str_repeat('x', 262145), 'reject');
foreach ([
	'perf-flat-keys' => '{' . implode(',', array_map(static fn ($n) => '"k' . $n . '":1', range(1, 18000))) . '}',
	'perf-brackets' => str_repeat('[]', 130000),
	'perf-long-escapes' => '{"a":"' . str_repeat('\\"', 130000) . '"}',
	'perf-many-colons-in-string' => '{"a":"' . str_repeat(':', 262000) . '"}',
] as $name => $body) {
	$started = microtime(true);
	$verdict($body);
	$elapsed = microtime(true) - $started;
	if ($elapsed > 3.0) {
		$fail('performance', $name . ' took ' . round($elapsed, 2) . 's');
	}
}

// Transport errors pass through unchanged and the request is untouched.
$throwing = new class implements ClientInterface {
	public function sendRequest(RequestInterface $request): ResponseInterface {
		throw new class('transport down') extends RuntimeException implements Psr\Http\Client\ClientExceptionInterface {};
	}
};
try {
	(new StrictResponseGuard($throwing, $factory))->sendRequest($request);
	$fail('transport-error', 'Transport exception was swallowed.');
} catch (Psr\Http\Client\ClientExceptionInterface $error) {
	if ('transport down' !== $error->getMessage()) {
		$fail('transport-error', 'Transport exception was altered.');
	}
}

restore_error_handler();
if ([] !== $diagnostics) {
	$fail('php-diagnostics', implode('; ', array_slice(array_unique($diagnostics), 0, 3)));
}
// Coverage of the corpus itself: every verdict class must really be exercised.
foreach ([['documents', 'dup'], ['documents', 'ok'], ['documents', 'invalid'], ['tokens', 'accept'], ['tokens', 'reject'], ['jwks', 'accept'], ['jwks', 'reject']] as [$category, $label]) {
	if (($stats[$category][$label] ?? 0) < max(20, intdiv($iterations, 50))) {
		$fail('corpus-coverage', $category . '/' . $label . ' only ' . ($stats[$category][$label] ?? 0));
	}
}

echo 'Guard fuzz seed ' . $seed . ', ' . $iterations . " iterations per category.\n";
foreach ($stats as $category => $labels) {
	ksort($labels);
	echo '  ' . $category . ': ' . implode(', ', array_map(static fn ($k, $v) => $k . '=' . $v, array_keys($labels), $labels)) . "\n";
}
echo '  edge cases: ' . count($cases) . " plus size/performance/transport checks.\n";
if ([] !== $failures) {
	echo count($failures) . " FAILURES:\n";
	foreach (array_slice($failures, 0, 25) as $failure) {
		echo '  - ' . $failure . "\n";
	}
	exit(1);
}
echo "PASS: StrictResponseGuard agrees with the independent oracle on all generated and edge cases.\n";
