<?php

declare(strict_types=1);

/*
 * Dependency-free test runner:  php tests/run.php
 * Uses an in-memory SQLite database and a throwaway storage directory.
 */

require dirname(__DIR__) . '/bootstrap.php';

use JC\Core\Config;

$tmp = sys_get_temp_dir() . '/jc-tests-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
Config::set('APP_ENV', 'testing');
Config::set('STORAGE_PATH', $tmp);
Config::set('KEYRING', 'test1:' . base64_encode(str_repeat("\x01", 32)) . ',test2:' . base64_encode(str_repeat("\x02", 32)));
Config::set('ACTIVE_KEY_ID', 'test1');
Config::set('SERVICE_HMAC_SECRET', str_repeat('s', 40));

final class TestFailure extends RuntimeException
{
}

function check(bool $condition, string $message = 'assertion failed'): void
{
    if (!$condition) {
        throw new TestFailure($message);
    }
}

function same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new TestFailure(($message !== '' ? $message . ': ' : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function throws(callable $fn, string $class = Throwable::class, string $message = 'expected an exception'): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof $class) {
            return;
        }
        throw new TestFailure("{$message}: got " . $e::class . ' ' . $e->getMessage());
    }
    throw new TestFailure($message);
}

$tests = [];
function test(string $name, callable $fn): void
{
    $GLOBALS['tests'][$name] = $fn;
}

foreach (glob(__DIR__ . '/*Test.php') ?: [] as $file) {
    require $file;
}

$passed = 0;
$failed = 0;
$filter = $argv[1] ?? null;
foreach ($tests as $name => $fn) {
    if ($filter !== null && !str_contains($name, $filter)) {
        continue;
    }
    try {
        $fn($tmp);
        $passed++;
        echo "  \033[32m✓\033[0m {$name}\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  \033[31m✗ {$name}\033[0m\n      " . $e::class . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n";
    }
}

exec('rm -rf ' . escapeshellarg($tmp));
echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
