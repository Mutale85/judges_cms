<?php

declare(strict_types=1);

use JC\Core\Config;

function now_utc(int $offsetSeconds = 0): string
{
    return gmdate('Y-m-d H:i:s', time() + $offsetSeconds);
}

/** HTML-escape for templates. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_timezone(): DateTimeZone
{
    static $tz = null;
    return $tz ??= new DateTimeZone(Config::get('APP_TIMEZONE', 'Africa/Lusaka') ?? 'Africa/Lusaka');
}

/** Formats a UTC database timestamp in the court's local time zone. */
function local_time(?string $utc, string $format = 'D j M Y, H:i'): string
{
    if ($utc === null || $utc === '') {
        return '';
    }
    $dt = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    return $dt->setTimezone(app_timezone())->format($format);
}

/** Converts a local "Y-m-d\TH:i" form value into a UTC database timestamp. */
function local_to_utc(string $local): ?string
{
    $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $local, app_timezone());
    if ($dt === false) {
        return null;
    }
    return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function format_duration_ms(int|string|null $ms): string
{
    $s = intdiv((int) $ms, 1000);
    return sprintf('%02d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60);
}

function format_bytes(int|string|null $bytes): string
{
    $bytes = (float) $bytes;
    foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
        if ($bytes < 1024 || $unit === 'GB') {
            return ($unit === 'B' ? (int) $bytes : number_format($bytes, 1)) . ' ' . $unit;
        }
        $bytes /= 1024;
    }
    return '';
}

function label(string $value): string
{
    return ucfirst(str_replace('_', ' ', $value));
}

/** Mode-aware wording: term('Case') is "Reference" in an arbitration workspace. */
function term(string $word): string
{
    return JC\Core\Terminology::term($word);
}

/** Formats a money amount, e.g. "ZMW 1,250.00". */
function money(int|float|string|null $amount, string $currency = 'ZMW'): string
{
    return $currency . ' ' . number_format((float) $amount, 2);
}

/** Absolute URL for links that leave the site (e-mails). */
function absolute_url(string $path): string
{
    return rtrim((string) JC\Core\Config::get('APP_URL', 'http://127.0.0.1:8080'), '/') . $path;
}

function app_name(): string
{
    return (string) JC\Core\Config::get('APP_NAME', 'Judges.co.zm');
}
