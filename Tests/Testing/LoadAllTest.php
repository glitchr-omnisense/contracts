<?php

namespace Omnisense\Contracts\Tests\Testing;

use Omnisense\Contracts\Provider\Registry;
use PHPUnit\Framework\TestCase;

/**
 * bin/load-all.php reports what loading the package brings in, not what
 * Composer's autoloader loads at start: in a harness where another package
 * autoloads files eagerly (google/apiclient's aliases, Guzzle's functions),
 * those are not the package's.
 */
final class LoadAllTest extends TestCase
{
    public function testWhatTheAutoloaderPreloadsIsNotReported(): void
    {
        $dir = sys_get_temp_dir().'/omnisense-load-all-'.bin2hex(random_bytes(4));
        mkdir($dir.'/composer', 0o777, true);
        $vendor = \dirname((string) (new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName(), 2);
        file_put_contents($dir.'/eager.php', "<?php\nnamespace GuzzleHttp;\nfinal class EagerlyLoaded {}\n");
        file_put_contents($dir.'/autoload.php', "<?php\nrequire ".var_export($vendor.'/autoload.php', true).";\nrequire __DIR__.'/eager.php';\n");
        file_put_contents($dir.'/composer/autoload_files.php', "<?php\nreturn [".var_export($dir.'/eager.php', true)."];\n");
        $root = \dirname((string) (new \ReflectionClass(Registry::class))->getFileName(), 2);
        try {
            $out = (string) shell_exec(escapeshellarg(\PHP_BINARY).' '.escapeshellarg($root.'/bin/load-all.php').' '.escapeshellarg($dir.'/autoload.php').' '.escapeshellarg($root).' '.escapeshellarg('Omnisense\\Contracts').' 2>&1');
            $report = json_decode($out, true);
            self::assertIsArray($report, $out);
            [$loaded, $symbols, $files, $eager] = [(array) $report['loaded'], (array) $report['symbols'], (array) $report['files'], (array) $report['eager']];
            self::assertContains(Registry::class, $loaded);
            self::assertNotContains('GuzzleHttp\EagerlyLoaded', $symbols);
            self::assertNotContains(realpath($dir.'/eager.php'), $files);
            self::assertContains(realpath($dir.'/eager.php'), $eager);
            self::assertContains(realpath($root.'/Provider/Registry.php'), $files, 'what the package loads is reported');
        } finally {
            array_map('unlink', [$dir.'/eager.php', $dir.'/autoload.php', $dir.'/composer/autoload_files.php']);
            rmdir($dir.'/composer');
            rmdir($dir);
        }
    }
}
