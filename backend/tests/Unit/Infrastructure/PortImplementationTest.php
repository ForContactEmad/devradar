<?php

declare(strict_types=1);

/**
 * Every port must have a production implementation.
 *
 * THE GUARD THAT WAS MISSING. `ClockInterface` sat in src/Domain/Port/ for
 * twenty phases with its only implementation in tests/Fake/FixedClock. Five
 * hundred and seventy-five tests passed while the collection stage -- the
 * first stage of the pipeline -- could not be constructed outside a test.
 *
 * No unit test catches that, because a unit test's whole job is to supply the
 * fake. This scans the source tree instead and asserts the structural
 * property: a contract the application depends on must be implemented by
 * something the application ships.
 *
 * It is deliberately a source scan rather than a container test, so it runs
 * without Composer, which is the environment where the original gap was
 * introduced.
 */

/** @return list<string> */
function portNames(): array
{
    $names = [];

    foreach (glob(__DIR__ . '/../../../src/Domain/Port/*Interface.php') ?: [] as $file) {
        $names[] = basename($file, '.php');
    }

    return $names;
}

/** @return array{production: list<string>, test: list<string>} */
function implementationsOf(string $port): array
{
    $roots = [
        'production' => [__DIR__ . '/../../../src', __DIR__ . '/../../../app'],
        'test' => [__DIR__ . '/../../../tests'],
    ];

    $found = ['production' => [], 'test' => []];

    foreach ($roots as $kind => $dirs) {
        foreach ($dirs as $dir) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));

            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());

                // `implements A, B` and `implements \Ns\B` both count.
                if (preg_match('/implements\s+[\w\s,\\\\]*\b' . preg_quote($port, '/') . '\b/', $source) === 1) {
                    $found[$kind][] = $file->getFilename();
                }
            }
        }
    }

    return $found;
}

it('finds the ports to check', function () {
    // Guards the guard: a broken glob would make every assertion below vacuous.
    expect(count(portNames()))->toBeGreaterThan(15);
});

it('gives every port a production implementation, not only a test double', function () {
    $testDoubleOnly = [];
    $unimplemented = [];

    foreach (portNames() as $port) {
        $found = implementationsOf($port);

        if ($found['production'] !== []) {
            continue;
        }

        // A port nothing injects is dead weight, not a runtime failure, so it
        // is reported separately from one that only a fake satisfies.
        if ($found['test'] !== []) {
            $testDoubleOnly[] = $port;
        } else {
            $unimplemented[] = $port;
        }
    }

    expect($testDoubleOnly)->toBe(
        [],
        'These ports are satisfied ONLY by a test double. Production resolution will throw: '
        . implode(', ', $testDoubleOnly),
    );

    // ClassifierInterface is knowingly unused -- declared early, never injected
    // anywhere, and therefore not a runtime risk. It is listed explicitly so
    // that a NEW unimplemented port fails this test rather than joining it.
    expect($unimplemented)->toBe(
        ['ClassifierInterface'],
        'A port has no implementation at all: ' . implode(', ', $unimplemented),
    );
});
