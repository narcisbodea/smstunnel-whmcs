<?php
/**
 * A very small test runner (no PHPUnit, so the same tests run on PHP 7.2 to 8.5).
 *
 * @license MIT
 */

final class Tests
{
    /** @var array<int, array{0: string, 1: callable, 2: string}> */
    private static $tests = array();

    /** @var int */
    public static $checks = 0;

    /** @var array<int, string> */
    private static $failures = array();

    /** @var string */
    private static $current = '';

    /** @var string */
    private static $group = '';

    public static function group(string $name)
    {
        self::$group = $name;
    }

    public static function add(string $name, callable $fn)
    {
        self::$tests[] = array($name, $fn, self::$group);
    }

    /**
     * Runs every test (or those whose name contains $filter). Each test starts from a fresh database.
     *
     * @return int the number of failed tests
     */
    public static function run(string $filter = ''): int
    {
        $failedTests = 0;
        $ran = 0;
        $group = null;
        foreach (self::$tests as $test) {
            list($name, $fn, $testGroup) = $test;
            if ($filter !== '' && stripos($testGroup . ' ' . $name, $filter) === false) {
                continue;
            }
            if ($testGroup !== $group) {
                $group = $testGroup;
                echo '-- ', $group, "\n";
            }
            self::$current = $name;
            $before = count(self::$failures);
            Harness::wipe();
            try {
                $fn();
            } catch (\Throwable $e) {
                self::$failures[] = $name . ': ' . get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n" . self::trace($e);
            }
            ++$ran;
            if (count(self::$failures) > $before) {
                ++$failedTests;
                foreach (array_slice(self::$failures, $before) as $failure) {
                    echo 'FAIL ', $failure, "\n";
                }
            }
        }
        echo sprintf("\n%d tests, %d checks, %d failed (PHP %s)\n", $ran, self::$checks, $failedTests, PHP_VERSION);

        return $failedTests;
    }

    private static function trace(\Throwable $e): string
    {
        $lines = array();
        foreach (array_slice($e->getTrace(), 0, 6) as $frame) {
            $lines[] = '    at ' . (isset($frame['class']) ? $frame['class'] . '::' : '') . $frame['function']
                . (isset($frame['file']) ? ' (' . basename($frame['file']) . ':' . $frame['line'] . ')' : '');
        }

        return implode("\n", $lines);
    }

    public static function fail(string $message)
    {
        self::$failures[] = self::$current . ': ' . $message;
    }

    public static function pass()
    {
        ++self::$checks;
    }
}

/**
 * @param mixed $actual
 * @param mixed $expected
 */
function eq($actual, $expected, string $label = '')
{
    Tests::pass();
    if ($actual !== $expected) {
        Tests::fail(($label !== '' ? $label . ': ' : '') . "\n    expected " . var_export($expected, true) . "\n    actual   " . var_export($actual, true));
    }
}

/**
 * @param mixed $value
 */
function ok($value, string $label)
{
    Tests::pass();
    if ($value !== true) {
        Tests::fail($label . ' (got ' . var_export($value, true) . ')');
    }
}

function has(string $haystack, string $needle, string $label = '')
{
    Tests::pass();
    if (strpos($haystack, $needle) === false) {
        Tests::fail(($label !== '' ? $label . ': ' : '') . var_export($needle, true) . ' not found in ' . var_export(strlen($haystack) > 600 ? substr($haystack, 0, 600) . '...' : $haystack, true));
    }
}

function lacks(string $haystack, string $needle, string $label = '')
{
    Tests::pass();
    if (strpos($haystack, $needle) !== false) {
        Tests::fail(($label !== '' ? $label . ': ' : '') . var_export($needle, true) . ' should not be in the output');
    }
}

/**
 * Checks that $fn throws; returns the exception.
 */
function throws(callable $fn, string $class, string $label = ''): \Throwable
{
    Tests::pass();
    try {
        $fn();
    } catch (\Throwable $e) {
        if (!($e instanceof $class)) {
            Tests::fail(($label !== '' ? $label . ': ' : '') . 'expected ' . $class . ', got ' . get_class($e) . ': ' . $e->getMessage());
        }

        return $e;
    }
    Tests::fail(($label !== '' ? $label . ': ' : '') . 'expected ' . $class . ', nothing was thrown');

    return new \RuntimeException('nothing thrown');
}

function test(string $name, callable $fn)
{
    Tests::add($name, $fn);
}

function group(string $name)
{
    Tests::group($name);
}
