<?php
/** Strict coverage boundary and complete-source inventory regression tests. */
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CoverageGateTest extends TestCase {
	public static function boundaries(): array {
		return array('exactly80'=>array(0,false,false),'above80'=>array(1,false,true),'missingFile'=>array(1,true,false));
	}

	#[DataProvider('boundaries')]
	public function testCoverageRequiresMoreThan80AndAllProductionFiles(int $extra,bool $missing,bool $passes): void {
		$root=dirname(__DIR__,2);
		$names=array('sw-securelogin.php','uninstall.php');
		foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src',FilesystemIterator::SKIP_DOTS)) as $file) {
			if ($file->getExtension()==='php') $names[]=substr($file->getPathname(),strlen($root)+1);
		}
		$covered=count($names)*4+$extra;
		if ($missing) array_pop($names);
		$xml=new DOMDocument();$project=$xml->appendChild($xml->createElement('coverage'))->appendChild($xml->createElement('project'));
		foreach($names as $name) {
			$file=$project->appendChild($xml->createElement('file'));$file->setAttribute('name',$name);
			for($i=1;$i<=5;++$i) {
				$line=$file->appendChild($xml->createElement('line'));$line->setAttribute('num',(string)$i);$line->setAttribute('type','stmt');$line->setAttribute('count',$covered-- > 0 ? '1':'0');
			}
		}
		$path=tempnam(sys_get_temp_dir(),'swsl-gate-');
		try {
			$xml->save($path);
			$process=proc_open(array(PHP_BINARY,$root.'/tests/coverage/check.php',$path),array(1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
			$output=stream_get_contents($pipes[1]);fclose($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[2]);
			$this->assertSame($passes,proc_close($process)===0,$output);
			if (!$extra) $this->assertStringContainsString('80.00%',$output);
		} finally { unlink($path); }
	}
}
