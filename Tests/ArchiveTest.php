<?php

namespace Omnisense\Contracts\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The release's source archive (and what Composer installs from it) holds
 * the package and what it runs from - bin/ (the bare check's child process,
 * omnisense-policies), Testing\, the schema - and none of the tests, the CI
 * or the analysis configuration: .gitattributes, read as git archive reads it.
 */
final class ArchiveTest extends TestCase
{
    /** Folders as git archive leaves them out: whole (check-attr reads the folder's own path, not its files'). */
    private const LEFT_OUT = ['Tests', '.github', 'docker', 'phpunit.xml.dist', 'phpstan.neon', 'phpstan-baseline.neon', '.gitattributes', '.gitignore'];

    private const SHIPPED = ['composer.json', 'LICENSE', 'README.md', 'CHANGELOG.md', 'bin/load-all.php', 'bin/omnisense-policies', 'Testing/BareTestCase.php', 'Policy/PolicyGuard.php', 'resources/usage-policy.schema.json', 'docs/families.md'];

    public function testTheArchiveShipsThePackageAndNotItsTests(): void
    {
        $attributes = $this->exportIgnore([...self::LEFT_OUT, ...self::SHIPPED]);

        foreach (self::LEFT_OUT as $path) {
            self::assertSame('set', $attributes[$path] ?? null, $path.' is left out of the archive');
        }
        foreach (self::SHIPPED as $path) {
            self::assertSame('unspecified', $attributes[$path] ?? null, $path.' is in the archive');
        }
    }

    /**
     * git check-attr's answer for each path: set, unset or unspecified.
     *
     * @param list<string> $paths
     *
     * @return array<string, string>
     */
    private function exportIgnore(array $paths): array
    {
        $root = \dirname(__DIR__);
        if (!is_dir($root.'/.git') && !is_file($root.'/.git')) {
            self::markTestSkipped('Not a git checkout: no archive to check.');
        }
        $process = proc_open(['git', '-C', $root, 'check-attr', 'export-ignore', '--', ...$paths], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!\is_resource($process)) {
            self::markTestSkipped('git cannot be run here.');
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        if (0 !== proc_close($process)) {
            self::markTestSkipped('git check-attr failed: '.$err);
        }
        $attributes = [];
        foreach (explode("\n", trim($out)) as $line) {
            if (1 === preg_match('~^(.+): export-ignore: (\w+)$~', $line, $m)) {
                $attributes[$m[1]] = $m[2];
            }
        }

        return $attributes;
    }
}
