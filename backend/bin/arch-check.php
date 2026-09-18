<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Architecture guard
|--------------------------------------------------------------------------
|
| Enforces the dependency rule agreed in the architecture phase:
|
|     delivery -> application -> domain <- infrastructure
|
| Dependencies point inward. The domain depends on nothing. Infrastructure
| is depended upon by no one; it only implements ports declared inward.
|
| This script deliberately has ZERO dependencies -- no Composer, no
| framework, no test runner. That is the point: the pure core must be
| verifiable before anything is installed, and this guard must be runnable
| in CI before `composer install` has a chance to fail.
|
| Run:  php bin/arch-check.php
| Exit: 0 clean, 1 violations found.
|
*/

$root = dirname(__DIR__);
$src = $root . '/src';

/**
 * Layers that must not be referenced from a given directory.
 * Key   = directory scanned.
 * Value = list of namespace fragments that must not appear inside it.
 */
$rules = [
    'Domain' => [
        'Illuminate\\' => 'the domain must not depend on the framework',
        'App\\' => 'the domain must not depend on the delivery layer',
        'DevRadar\\Application' => 'the domain must not depend on the application layer',
        'DevRadar\\Infrastructure' => 'the domain must not depend on infrastructure',
    ],
    'Application' => [
        'DevRadar\\Infrastructure' => 'the application layer must depend on ports, not adapters',
        'App\\' => 'the application layer must not depend on the delivery layer',
    ],
];

/**
 * Only these directories may reference a paid external data source.
 * Everything else must go through a port.
 */
$paidHostNeedles = ['api.x.com', 'api.twitter.com'];
$paidHostAllowedPrefix = $src . '/Infrastructure/X';

$violations = [];

function phpFilesIn(string $dir): array
{
    if (! is_dir($dir)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

// ---------------------------------------------------------------- rule 1
// Layer dependency direction.
foreach ($rules as $layer => $forbidden) {
    foreach (phpFilesIn($src . '/' . $layer) as $file) {
        $contents = file_get_contents($file);
        $relative = str_replace($root . '/', '', $file);

        foreach ($forbidden as $needle => $reason) {
            if (str_contains($contents, $needle)) {
                $violations[] = sprintf('%s references "%s" - %s', $relative, $needle, $reason);
            }
        }
    }
}

// ---------------------------------------------------------------- rule 2
// Only the metered adapter may name a paid data source.
foreach (phpFilesIn($src) as $file) {
    if (str_starts_with($file, $paidHostAllowedPrefix)) {
        continue;
    }

    $contents = file_get_contents($file);
    $relative = str_replace($root . '/', '', $file);

    foreach ($paidHostNeedles as $needle) {
        if (str_contains($contents, $needle)) {
            $violations[] = sprintf(
                '%s names paid host "%s" - only src/Infrastructure/X may spend money',
                $relative,
                $needle
            );
        }
    }
}

// ---------------------------------------------------------------- rule 3
// Every declared port must exist and must be an interface.
spl_autoload_register(static function (string $class) use ($src): void {
    $prefix = 'DevRadar\\';

    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $path = $src . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($path)) {
        require_once $path;
    }
});

$expectedPorts = [
    'DevRadar\\Domain\\Port\\PostProviderInterface',
    'DevRadar\\Domain\\Port\\ClassifierInterface',
    'DevRadar\\Domain\\Port\\LlmProviderInterface',
    'DevRadar\\Domain\\Port\\RepositoryProviderInterface',
    'DevRadar\\Domain\\Port\\ClockInterface',
];

foreach ($expectedPorts as $port) {
    if (! interface_exists($port)) {
        $violations[] = sprintf('missing or invalid port: %s must exist and be an interface', $port);
    }
}

// ---------------------------------------------------------------- report
$checked = count(phpFilesIn($src));

if ($violations === []) {
    echo "architecture check: PASS\n";
    echo sprintf("  %d source file(s) scanned\n", $checked);
    echo sprintf("  %d port(s) verified\n", count($expectedPorts));
    echo "  dependency direction: inward only\n";
    exit(0);
}

echo "architecture check: FAIL\n";
foreach ($violations as $violation) {
    echo '  - ' . $violation . "\n";
}
exit(1);
