<?php

/*
 * Loads every class of a package (but its tests, its harness and this
 * directory) with Composer's autoloader, then prints what was loaded, as
 * JSON: Testing\BareTestCase's child process. Arguments: the autoloader, the
 * package's directory, its namespace, and classes to load besides (the check
 * that the report is not blind).
 *
 * The files and classes reported are those loading the package brought in:
 * what Composer's autoloader had already loaded when it was required (every
 * installed package's autoload "files", and what they load - google/apiclient's
 * aliases, Guzzle's functions in a harness that installs them) is not the
 * package's doing, and is left out. "eager" still lists Composer's files.
 */
[, $autoload, $root, $namespace] = $argv;
require $autoload;
$preloaded = array_flip(get_included_files());
$predeclared = array_flip([...get_declared_classes(), ...get_declared_interfaces(), ...get_declared_traits()]);
$root = (string) realpath($root);
$loaded = [];
$iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    static fn (SplFileInfo $file) => !in_array($file->getFilename(), ['Tests', 'docker', 'vendor', 'docs', 'bin', 'assets', '.git', '.github'], true),
));
foreach ($iterator as $file) {
    // Not a class over a suggested package (marked "@suggested <package>"): it loads that package.
    $source = $file instanceof SplFileInfo && 'php' === $file->getExtension() ? (string) file_get_contents($file->getPathname()) : '';
    if ('' === $source || str_contains($source, '@suggested ')) {
        continue;
    }
    $class = rtrim($namespace, '\\').'\\'.str_replace('/', '\\', substr($file->getPathname(), strlen($root) + 1, -4));
    if (class_exists($class) || interface_exists($class) || enum_exists($class) || trait_exists($class)) {
        $loaded[] = $class;
    }
}
foreach (array_slice($argv, 4) as $class) {
    class_exists($class);
}
echo json_encode([
    'loaded' => $loaded,
    'symbols' => array_values(array_filter([...get_declared_classes(), ...get_declared_interfaces(), ...get_declared_traits()], static fn (string $symbol) => !isset($predeclared[$symbol]))),
    'files' => array_values(array_map(static fn (string $file) => realpath($file), array_filter(get_included_files(), static fn (string $file) => !isset($preloaded[$file])))),
    'eager' => array_map(static fn (mixed $file) => realpath((string) (is_string($file) ? $file : '')), is_file($f = dirname($autoload).'/composer/autoload_files.php') && is_array($files = require $f) ? array_values($files) : []),
], JSON_UNESCAPED_SLASHES);
