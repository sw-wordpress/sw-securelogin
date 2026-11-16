<?php
/** Guard shipped executable config and preserve offline release/operator bootstraps. */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class UpdateConfigTest extends TestCase {
    private function runPhp(string $code, array $arguments = [], ?array $environment = null): array {
        $process = proc_open(array_merge([PHP_BINARY, '-r', $code], $arguments), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
        self::assertIsResource($process);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $out, $err];
    }

    public function testEveryShippedConfigStopsDirectAccessInFreshProcess(): void {
        foreach (glob(dirname(__DIR__, 2) . '/src/Update/Config/*.php') as $file) {
            // Reaching the marker means require returned instead of exiting.
            self::assertSame([0, '', ''], $this->runPhp('require $argv[1]; echo "unguarded";', [$file]), $file);
        }
    }

    public function testConfigsStillReturnLocalDataInBootstrappedContext(): void {
        $config = dirname(__DIR__, 2) . '/src/Update/Config/';
        $source = require $config . 'source.php';
        self::assertSame('sw-wordpress/sw-securelogin', $source['repository']);
        self::assertSame(1439006733, $source['repository_id']);
        self::assertSame(['sw-securelogin'], $source['allowed_slugs']);
        $descriptors = require $config . 'descriptors.php';
        self::assertSame('sw-securelogin', $descriptors['sw-securelogin/sw-securelogin.php']['slug']);
        $keys = require $config . 'trusted-keys.php';
        self::assertNotEmpty($keys);
        foreach ($keys as $key) {
            self::assertSame(32, strlen(base64_decode($key, true)));
        }
    }

    public function testSignerPreservesGuardAndKeysAndRejectsConflictingKeyId(): void {
        $directory = sys_get_temp_dir() . '/swsl-keyring-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $ring = $directory . '/trusted-keys.php';
        $signer = dirname(__DIR__, 2) . '/bin/release-manifest.php';
        copy(dirname(__DIR__, 2) . '/src/Update/Config/trusted-keys.php', $ring);
        $existing = require $ring;
        $pair = sodium_crypto_sign_keypair();
        $public = base64_encode(sodium_crypto_sign_publickey($pair));
        $environment = [
            'SW_PLUGIN_RELEASE_SIGNING_KEY' => base64_encode(sodium_crypto_sign_secretkey($pair)),
            'SW_PLUGIN_RELEASE_KEY_ID' => 'disposable-regression',
        ];
        // The actual CLI must establish its own context, before reading guarded config.
        // release-manifest.php expects its own CLI argv; require via -r adapts it explicitly.
        $command = '$argv = array_slice($argv, 1); require $argv[0];';
        try {
            self::assertSame([0, '', ''], $this->runPhp($command, [$signer, 'public-key', $ring], $environment));
            $actual = require $ring;
            self::assertSame($existing + ['disposable-regression' => $public], $actual);
            self::assertSame([0, '', ''], $this->runPhp('require $argv[1]; echo "unguarded";', [$ring]));
            $saved = file_get_contents($ring);
            self::assertSame([0, '', ''], $this->runPhp($command, [$signer, 'public-key', $ring], $environment));
            self::assertSame($saved, file_get_contents($ring));
            $environment['SW_PLUGIN_RELEASE_SIGNING_KEY'] = base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
            [$exit, $out, $err] = $this->runPhp($command, [$signer, 'public-key', $ring], $environment);
            self::assertSame(1, $exit);
            self::assertSame('', $out);
            self::assertStringContainsString('Key ID is already used', $err);
            self::assertSame($saved, file_get_contents($ring));
        } finally {
            unlink($ring);
            rmdir($directory);
        }
    }
}
