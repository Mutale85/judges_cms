<?php

declare(strict_types=1);

namespace JC\Core;

/**
 * Reads configuration from real environment variables first, then from app/.env.
 * Secrets (key ring, service secret, DB password) should come from the process
 * environment or a secrets manager in production, never from a committed file.
 */
final class Config
{
    /** @var array<string,string> */
    private static array $values = [];

    /** @var array<string,string> */
    private static array $overrides = [];

    public static function load(string $root): void
    {
        $file = $root . '/.env';
        if (!is_file($file)) {
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }
            self::$values[$key] = $value;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, self::$overrides)) {
            return self::$overrides[$key];
        }
        $env = getenv($key);
        if ($env !== false) {
            return $env;
        }
        return self::$values[$key] ?? $default;
    }

    public static function require(string $key): string
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            throw new \RuntimeException("Missing required configuration value: {$key}");
        }
        return $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            return $default;
        }
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default): int
    {
        $value = self::get($key);
        return ($value === null || $value === '') ? $default : (int) $value;
    }

    public static function isProduction(): bool
    {
        return self::get('APP_ENV', 'production') === 'production';
    }

    /** Test hook: force a value regardless of environment. */
    public static function set(string $key, string $value): void
    {
        self::$overrides[$key] = $value;
    }
}
