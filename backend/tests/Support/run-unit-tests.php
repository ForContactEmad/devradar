<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pest shim + standalone runner - VERIFICATION HARNESS, NOT PRODUCTION CODE
|--------------------------------------------------------------------------
|
| WHY THIS EXISTS
| Composer cannot reach Packagist in the environment where this layer was
| authored, so Pest could not be installed. This implements the small subset
| of the Pest API the X unit tests use -- it(), expect() and a handful of
| matchers -- so the SAME test files run here and, unchanged, under real Pest
| once dependencies are installed.
|
| CAVEAT
| This is not Pest. It approximates the matchers used. Once `composer install`
| succeeds, run the suite under real Pest and DELETE this file along with
| schema-transpiler.php and verify-schema.sh.
|
| Usage: php tests/Support/run-unit-tests.php
|
*/

// ---------------------------------------------------------------- autoload
spl_autoload_register(static function (string $class): void {
    $roots = [
        'DevRadar\\' => __DIR__ . '/../../src/',
        'Tests\\' => __DIR__ . '/../',
    ];

    foreach ($roots as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $path = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

            if (is_file($path)) {
                require_once $path;
            }

            return;
        }
    }
});

// PSR-3 is a Laravel dependency and therefore absent here. Declaring the
// interface keeps the production code honest about depending on the standard
// rather than on a bespoke logger contract.
if (! interface_exists(\Psr\Log\LoggerInterface::class)) {
    eval('
        namespace Psr\Log;
        interface LoggerInterface {
            public function emergency(\Stringable|string $message, array $context = []): void;
            public function alert(\Stringable|string $message, array $context = []): void;
            public function critical(\Stringable|string $message, array $context = []): void;
            public function error(\Stringable|string $message, array $context = []): void;
            public function warning(\Stringable|string $message, array $context = []): void;
            public function notice(\Stringable|string $message, array $context = []): void;
            public function info(\Stringable|string $message, array $context = []): void;
            public function debug(\Stringable|string $message, array $context = []): void;
            public function log($level, \Stringable|string $message, array $context = []): void;
        }
    ');
}

// Laravel's env() helper, absent outside the framework. Config files call it
// for every overridable value, so a test that loads real configuration needs
// it. Returns the default, which is what an unset environment would do.
if (! function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);

        return $value === false ? $default : $value;
    }
}

// ------------------------------------------------------------- expectations
final class ExpectationFailed extends \Exception {}

final class Expectation
{
    public function __construct(public mixed $value, private bool $negated = false) {}

    public function __get(string $name): self
    {
        if ($name === 'not') {
            return new self($this->value, ! $this->negated);
        }

        throw new \RuntimeException("Unknown expectation property: {$name}");
    }

    /** Pest's chaining helper: ->and($other) starts a new expectation. */
    public function and(mixed $value): self
    {
        return new self($value);
    }

    private function check(bool $passed, string $description): self
    {
        if ($passed === $this->negated) {
            $rendered = is_scalar($this->value) || $this->value === null
                ? var_export($this->value, true)
                : get_debug_type($this->value);

            throw new ExpectationFailed(
                ($this->negated ? 'expected NOT ' : 'expected ') . $description . ', got ' . $rendered
            );
        }

        return $this;
    }

    public function toBe(mixed $expected): self
    {
        return $this->check($this->value === $expected, 'to be ' . var_export($expected, true));
    }

    public function toEqual(mixed $expected): self
    {
        return $this->check($this->value == $expected, 'to equal ' . var_export($expected, true));
    }

    public function toBeTrue(): self { return $this->check($this->value === true, 'to be true'); }
    public function toBeFalse(): self { return $this->check($this->value === false, 'to be false'); }
    public function toBeNull(): self { return $this->check($this->value === null, 'to be null'); }
    public function toBeEmpty(): self { return $this->check(empty($this->value), 'to be empty'); }

    public function toHaveCount(int $count): self
    {
        $actual = is_countable($this->value) ? count($this->value) : -1;

        return $this->check($actual === $count, "to have {$count} items, has {$actual}");
    }

    public function toContain(mixed $needle): self
    {
        $passed = is_string($this->value)
            ? str_contains($this->value, (string) $needle)
            : (is_array($this->value) && in_array($needle, $this->value, true));

        return $this->check($passed, 'to contain ' . var_export($needle, true));
    }

    // Pest accepts int|string here; the shim must not be stricter than the
    // runner it stands in for, or a test that passes under Pest fails here.
    public function toHaveKey(int|string $key): self
    {
        return $this->check(is_array($this->value) && array_key_exists($key, $this->value), "to have key {$key}");
    }

    // Pest provides this; the shim must not be a narrower dialect, or a test
    // that passes under the real runner fails here for no reason.
    // Pest provides these; the shim must not be a narrower dialect.
    public function toStartWith(string $prefix): self
    {
        return $this->check(is_string($this->value) && str_starts_with($this->value, $prefix), "to start with {$prefix}");
    }

    public function toEndWith(string $suffix): self
    {
        return $this->check(is_string($this->value) && str_ends_with($this->value, $suffix), "to end with {$suffix}");
    }

    public function toBeLessThan(mixed $other): self
    {
        return $this->check(is_numeric($this->value) && $this->value < $other, "to be less than {$other}");
    }

    public function toBeGreaterThan(mixed $other): self
    {
        return $this->check($this->value > $other, 'to be greater than ' . var_export($other, true));
    }

    public function toBeInstanceOf(string $class): self
    {
        return $this->check($this->value instanceof $class, "to be instance of {$class}");
    }

    public function toThrow(string $class, ?string $messageContains = null): self
    {
        if (! $this->value instanceof \Closure) {
            throw new ExpectationFailed('toThrow expects a closure');
        }

        try {
            ($this->value)();
        } catch (\Throwable $e) {
            if (! $e instanceof $class) {
                return $this->check(false, "to throw {$class}, threw " . $e::class . ': ' . $e->getMessage());
            }

            if ($messageContains !== null && ! str_contains($e->getMessage(), $messageContains)) {
                return $this->check(false, "throw message to contain '{$messageContains}', got '{$e->getMessage()}'");
            }

            return $this->check(true, "to throw {$class}");
        }

        return $this->check(false, "to throw {$class}, nothing thrown");
    }
}

function expect(mixed $value): Expectation
{
    return new Expectation($value);
}

// ------------------------------------------------------------------ runner
final class Runner
{
    /** @var array<string, list<array{name: string, fn: callable}>> */
    public static array $tests = [];

    public static string $currentFile = '';

    public static function add(string $name, callable $fn): void
    {
        self::$tests[self::$currentFile][] = ['name' => $name, 'fn' => $fn];
    }
}

function it(string $name, callable $fn): void { Runner::add('it ' . $name, $fn); }
function test(string $name, callable $fn): void { Runner::add($name, $fn); }

// -------------------------------------------------------------------- main
$dir = $argv[1] ?? __DIR__ . '/../Unit';
$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

foreach ($iterator as $file) {
    if ($file->isFile() && str_ends_with($file->getFilename(), 'Test.php')) {
        $files[] = $file->getPathname();
    }
}

sort($files);

foreach ($files as $file) {
    Runner::$currentFile = basename($file);
    require_once $file;
}

$passed = 0;
$failed = 0;
$failures = [];

foreach (Runner::$tests as $file => $tests) {
    echo "\n\033[1m{$file}\033[0m\n";

    foreach ($tests as $test) {
        try {
            ($test['fn'])();
            echo "  \033[32mPASS\033[0m  {$test['name']}\n";
            $passed++;
        } catch (Throwable $e) {
            echo "  \033[31mFAIL\033[0m  {$test['name']}\n";
            echo "          " . $e->getMessage() . "\n";
            $failed++;
            $failures[] = $file . ' :: ' . $test['name'];
        }
    }
}

echo "\n" . str_repeat('-', 60) . "\n";
echo "  Tests: {$passed} passed, {$failed} failed\n";
echo str_repeat('-', 60) . "\n";

exit($failed === 0 ? 0 : 1);
