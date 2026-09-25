<?php

declare(strict_types=1);

namespace JC\Security;

use JC\Core\Config;
use JC\Core\Request;
use JC\Core\Response;

/**
 * Data in transit: HTTPS is mandatory in production. Plain-HTTP requests are
 * redirected (GET) or refused (anything else), and every response carries
 * HSTS plus a strict Content Security Policy.
 */
final class TransportSecurity
{
    public static function enforce(Request $request): ?Response
    {
        if (!Config::isProduction() || $request->isSecure()) {
            return null;
        }
        if ($request->method === 'GET' || $request->method === 'HEAD') {
            $host = parse_url((string) Config::get('APP_URL', ''), PHP_URL_HOST) ?: 'localhost';
            $uri = (string) ($request->server['REQUEST_URI'] ?? '/');
            return Response::redirect('https://' . $host . $uri, 301);
        }
        return new Response('HTTPS is required.', 403);
    }

    public static function applyHeaders(Request $request, Response $response): void
    {
        $wsOrigin = self::origin((string) Config::get('TRANSCRIPTION_PUBLIC_URL', ''));
        $connect = trim("'self' " . $wsOrigin);

        $csp = [
            "default-src 'self'",
            "script-src 'self'",
            "style-src 'self'",
            "img-src 'self' data:",
            "font-src 'self'",
            "media-src 'self' blob:",
            "connect-src {$connect}",
            "worker-src 'self' blob:",
            "object-src 'none'",
            "base-uri 'none'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ];
        if (Config::isProduction()) {
            $csp[] = 'upgrade-insecure-requests';
        }

        $headers = [
            'Content-Security-Policy' => implode('; ', $csp),
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Permissions-Policy' => 'microphone=(self), camera=(), geolocation=(), payment=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];
        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=63072000; includeSubDomains; preload';
        }
        // Authenticated pages hold case data: never cache them in shared or browser caches.
        if (!str_starts_with($request->path, '/assets/')) {
            $headers['Cache-Control'] = 'no-store, max-age=0';
            $headers['Pragma'] = 'no-cache';
        }
        foreach ($headers as $name => $value) {
            $response->headers[$name] ??= $value;
        }
    }

    private static function origin(string $url): string
    {
        $parts = parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
