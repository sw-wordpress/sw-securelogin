<?php
/** Missing runtime extensions never authorize unsigned installation. */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class UpdateDependenciesTest extends TestCase {
    public function testMissingSodiumAndZipFailClosed(): void {
        $root = dirname(__DIR__, 2);
        foreach (['sodium', 'zip'] as $mode) {
            $code = <<<'PHP'
define('ABSPATH', '/swsl-extension-test/');
class WP_Error { public function __construct(public string $code, public string $message, public mixed $data = null) {} }
function __(string $text, string $domain): string { return $text; }
require $argv[1] . '/src/Update/Manifest.php';
require $argv[1] . '/src/Update/Package.php';
if ($argv[2] === 'sodium') {
    if (function_exists('sodium_crypto_sign_verify_detached')) exit(2);
    $result = SW\Securelogin\Update\Manifest::verify('{}', '{}', [], []);
} else {
    if (class_exists('ZipArchive')) exit(3);
    $file = tempnam(sys_get_temp_dir(), 'swsl-no-zip-');
    file_put_contents($file, 'archive');
    $result = SW\Securelogin\Update\Package::verify($file, ['bytes'=>7, 'sha256'=>hash_file('sha256', $file)]);
    unlink($file);
}
exit($result instanceof WP_Error ? 0 : 1);
PHP;
            $process = proc_open([PHP_BINARY, '-n', '-d', 'disable_functions=sodium_crypto_sign_verify_detached', '-r', $code, $root, $mode], [1=>['pipe','w'],2=>['pipe','w']], $pipes);
            $this->assertIsResource($process);
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $output);
        }
    }
}
