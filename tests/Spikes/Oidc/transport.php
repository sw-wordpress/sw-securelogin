<?php
/** Direct cURL capability probe; test-only loopback pin, not a production transport. */
declare(strict_types=1);

[$script, $port, $certificate] = $argv;

function transportCheck(bool $condition, string $message): void {
	if (!$condition) throw new RuntimeException($message);
}

function requestProbe(string $host, string $path, int $port, string $ca): array {
	$handle = curl_init('https://' . $host . ':' . $port . $path);
	transportCheck(false !== $handle, 'Unable to initialize cURL.');
	$body = '';
	$options = [
		CURLOPT_RESOLVE => [$host . ':' . $port . ':127.0.0.1'],
		CURLOPT_PROXY => '', // A proxy must not resolve the host instead of the pinned connection.
		CURLOPT_FOLLOWLOCATION => false,
		CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
		CURLOPT_SSL_VERIFYPEER => true,
		CURLOPT_SSL_VERIFYHOST => 2,
		CURLOPT_CAINFO => $ca,
		CURLOPT_CONNECTTIMEOUT_MS => 500,
		CURLOPT_TIMEOUT_MS => 500,
		CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
			if (strlen($body) + strlen($chunk) > 1024) return 0;
			$body .= $chunk;
			return strlen($chunk);
		},
	];
	transportCheck(curl_setopt_array($handle, $options), 'Unable to configure cURL.');
	$result = curl_exec($handle);
	return [$result, curl_errno($handle), curl_getinfo($handle), $body];
}

$port = (int) $port;
[$result, $error, $info, $body] = requestProbe('oidc-spike.invalid', '/ok', $port, $certificate);
transportCheck(true === $result && 0 === $error && '127.0.0.1' === $info['primary_ip'] && 'ok' === $body, 'Pinned HTTPS failed: ' . json_encode([$result, $error, $info['primary_ip'], $body], JSON_THROW_ON_ERROR));
echo "PASS: .invalid hostname reaches only pinned IP with certificate verification.\n";

[$result, $error] = requestProbe('wrong-spike.invalid', '/ok', $port, $certificate);
transportCheck(false === $result && CURLE_SSL_PEER_CERTIFICATE === $error, 'Wrong TLS hostname was accepted.');
echo "PASS: TLS hostname mismatch rejected.\n";

[$result, $error, $info, $body] = requestProbe('oidc-spike.invalid', '/redirect', $port, $certificate);
transportCheck(true === $result && 0 === $error && 302 === $info['http_code'] && 0 === $info['redirect_count'] && '' === $body, 'Redirect was followed.');
echo "PASS: redirect returned without following its target.\n";

[$result, $error, $info, $body] = requestProbe('oidc-spike.invalid', '/large', $port, $certificate);
transportCheck(false === $result && CURLE_WRITE_ERROR === $error && strlen($body) <= 1024, 'Response byte limit was not enforced.');
echo "PASS: oversized streamed response aborted.\n";

[$result, $error] = requestProbe('oidc-spike.invalid', '/slow', $port, $certificate);
transportCheck(false === $result && CURLE_OPERATION_TIMEDOUT === $error, 'Total timeout was not enforced.');
echo "PASS: slow response aborted.\n";

[$result, $error] = requestProbe('oidc-spike.invalid', '/ok', $port, '/etc/ssl/certs/ca-certificates.crt');
transportCheck(false === $result && CURLE_SSL_PEER_CERTIFICATE === $error, 'Untrusted CA was accepted.');
echo "PASS: untrusted test certificate rejected.\n";
