<?php

/*
 * Loads every class of a package (but its tests, its harness and this
 * directory) with Composer's autoloader, then prints what was loaded, as
 * JSON: Testing\BareTestCase's child process. Arguments: the autoloader, the
 * package's directory, its namespace, and classes to load besides (the check
 * that the report is not blind).
 */
[, $autoload, $root, $namespace] = $argv;
require $autoload;
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
    'symbols' => [...get_declared_classes(), ...get_declared_interfaces(), ...get_declared_traits()],
    'files' => array_map(static fn (string $file) => realpath($file), get_included_files()),
    'eager' => array_map(static fn (mixed $file) => realpath((string) (is_string($file) ? $file : '')), is_file($f = dirname($autoload).'/composer/autoload_files.php') && is_array($files = require $f) ? array_values($files) : []),
], JSON_UNESCAPED_SLASHES);
