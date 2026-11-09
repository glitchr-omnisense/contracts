<?php

namespace Omnisense\Contracts\Testing;

use Composer\Autoload\ClassLoader;
use Omnisense\Contracts\Provider\Registry;
use PHPUnit\Framework\TestCase;

/**
 * The check every omnisense package runs: each of its classes loaded in a
 * PHP process of its own (this one has loaded PHPUnit), with nothing from
 * outside but omnisense/contracts, its family's core (coreDirs()) and what
 * the package requires - no framework, no HTTP client, no application,
 * even in a harness where they are installed beside it. A package names its
 * entry class and the Composer packages it requires; FOREIGN lists what may
 * never load, allowedNamespaces() the exceptions a package's requirements
 * need. A class over a suggested package says so ("@suggested <package>")
 * and is not loaded.
 *
 * @suggested phpunit/phpunit (test support: loaded by a package's tests only)
 */
abstract class BareTestCase extends TestCase
{
    /** @var non-empty-string */
    protected const FOREIGN = '~^(?:Symfony\\\\(?!Contracts\\\\|Polyfill\\\\)|(?:Illuminate|Laravel|Doctrine|Twig|League|GuzzleHttp|Nyholm|Http|Omnibridge|Base|App)\\\\)~';

    /** The environment variable the child process finds the package's autoloader in (a family's harness script reads its own). */
    /** @var non-empty-string */
    protected const AUTOLOAD_ENV = 'OMNISENSE_AUTOLOAD';

    /**
     * A class of the package: where it is, and which autoloader knows it.
     *
     * @return class-string
     */
    abstract protected static function entryClass(): string;

    /** @return list<string> the Composer packages (vendor/name) the package requires, whose files may load */
    abstract protected static function requiredPackages(): array;

    /** @return list<string> namespaces matched by FOREIGN that the package's requirements may load nonetheless */
    protected static function allowedNamespaces(): array
    {
        return [];
    }

    /**
     * The folders whose files may load besides the package's own, wherever
     * they are installed (a path repository's checkout): omnisense/contracts;
     * a family adds its core.
     *
     * @return list<string>
     */
    protected static function coreDirs(): array
    {
        return [self::dirOf(Registry::class)];
    }

    public function testEveryClassLoadsWithTheRequiredPackagesAlone(): void
    {
        $report = static::loadAll();

        self::assertContains(static::entryClass(), $report['loaded']);
        self::assertSame([], static::foreign($report['symbols']), 'no class of a framework, an HTTP client or an application');
        self::assertSame([], static::outside($report['files'], $report['eager']), 'no file but the package and what it requires');
    }

    /** The check is not blind: a foreign class, once loaded, is seen. */
    public function testTheReportSeesWhatIsLoaded(): void
    {
        $foreign = 'Symfony\Component\HttpClient\MockHttpClient';
        if (!class_exists($foreign)) {
            self::markTestSkipped('symfony/http-client is not installed.');
        }
        $report = static::loadAll([$foreign]);

        self::assertContains($foreign, static::foreign($report['symbols']));
        self::assertNotEmpty(preg_grep('~/symfony/http-client/~', static::outside($report['files'], $report['eager'])));
    }

    /**
     * @param list<string> $also classes to load besides
     *
     * @return array{loaded: list<string>, symbols: list<string>, files: list<string>, eager: list<string>}
     */
    protected static function loadAll(array $also = []): array
    {
        $class = new \ReflectionClass(static::entryClass());
        $root = static::packageDir();
        $namespace = substr($class->getName(), 0, (int) strrpos($class->getName(), '\\'));
        while ('' !== $namespace && !is_file($root.'/'.str_replace('\\', '/', substr($class->getName(), \strlen($namespace) + 1)).'.php')) {
            $namespace = substr($namespace, 0, (int) strrpos($namespace, '\\'));
        }
        $report = static::php([self::dirOf(Registry::class).'/bin/load-all.php', static::autoload(), $root, $namespace, ...$also]);

        return ['loaded' => self::strings($report, 'loaded'), 'symbols' => self::strings($report, 'symbols'), 'files' => self::strings($report, 'files'), 'eager' => self::strings($report, 'eager')];
    }

    /**
     * @param list<string> $symbols
     *
     * @return list<string>
     */
    protected static function foreign(array $symbols): array
    {
        $allowed = static::allowedNamespaces();

        $foreign = [];
        foreach ($symbols as $symbol) {
            if (preg_match(static::FOREIGN, $symbol) && [] === array_filter($allowed, static fn (string $ns) => str_starts_with($symbol, $ns))) {
                $foreign[] = $symbol;
            }
        }

        return $foreign;
    }

    /**
     * The files loaded from outside the package, the core folders and the
     * packages required - and those Composer includes for every package at
     * start (autoload "files").
     *
     * @param list<string> $files
     * @param list<string> $eager
     *
     * @return list<string>
     */
    protected static function outside(array $files, array $eager): array
    {
        $roots = array_map(static fn (string $dir) => $dir.'/', [static::packageDir(), ...static::coreDirs()]);
        $eager = array_map('dirname', $eager);
        $packages = array_map(static fn (string $package) => '/vendor/'.$package.'/', [...static::requiredPackages(), 'omnisense/contracts', 'composer']);

        return array_values(array_filter($files, static fn (string $file) => !\in_array(\dirname($file), $eager, true)
            && !str_ends_with($file, '/vendor/autoload.php')
            && [] === array_filter($packages, static fn (string $package) => str_contains($file, $package))
            && [] === array_filter($roots, static fn (string $root) => str_starts_with($file, $root) && !str_starts_with($file, $root.'vendor/'))));
    }

    protected static function packageDir(): string
    {
        return self::dirOf(static::entryClass());
    }

    /**
     * The folder of the package that holds a class: the nearest one up with
     * a composer.json, its real path.
     *
     * @param class-string $class
     */
    protected static function dirOf(string $class): string
    {
        $dir = \dirname((string) realpath((string) (new \ReflectionClass($class))->getFileName()));
        while (!is_file($dir.'/composer.json') && \dirname($dir) !== $dir) {
            $dir = \dirname($dir);
        }

        return (string) realpath($dir);
    }

    /**
     * The autoloader that knows the package and its tests - its own or its
     * harness's. Installed in an application, whose vendor/ loads what the
     * application needs at start, the check says nothing of the package.
     */
    protected static function autoload(): string
    {
        foreach (ClassLoader::getRegisteredLoaders() as $vendor => $loader) {
            if ($loader->findFile(static::entryClass()) && $loader->findFile(static::class)) {
                if (str_starts_with(static::packageDir().'/', (string) realpath($vendor).'/')) {
                    self::markTestSkipped('The package is installed in an application here: the bare check is the package\'s own.');
                }

                return $vendor.'/autoload.php';
            }
        }
        self::markTestSkipped('No autoloader knows the package\'s tests.');
    }

    /**
     * Runs PHP on the arguments, with the package's autoloader in
     * AUTOLOAD_ENV (and OMNISENSE_AUTOLOAD), and decodes what it prints.
     *
     * @param list<string> $arguments
     *
     * @return array<mixed>
     */
    protected static function php(array $arguments): array
    {
        $autoload = static::autoload();
        $process = proc_open([\PHP_BINARY, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, [static::AUTOLOAD_ENV => $autoload, 'OMNISENSE_AUTOLOAD' => $autoload] + getenv());
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        $status = proc_close($process);
        $report = json_decode($out, true);
        self::assertSame(0, $status, $err.$out);
        self::assertIsArray($report, $err.$out);

        return $report;
    }

    /**
     * @param array<mixed> $report
     *
     * @return list<string>
     */
    private static function strings(array $report, string $key): array
    {
        return array_values(array_filter(\is_array($report[$key] ?? null) ? $report[$key] : [], 'is_string'));
    }
}
