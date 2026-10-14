<?php

declare(strict_types=1);

namespace JC\Core;

final class Request
{
    /** @var array<string,string> */
    public array $params = [];

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     * @param array<string,mixed> $server
     * @param array<string,mixed> $files
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        public readonly array $server,
        public readonly array $files,
        private readonly ?string $rawBody = null,
    ) {
    }

    public static function fromGlobals(): self
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $path = '/' . trim(rawurldecode($path), '/');
        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $path,
            $_GET,
            $_POST,
            $_SERVER,
            $_FILES,
        );
    }

    public function input(string $key, ?string $default = null): ?string
    {
        $value = $this->post[$key] ?? $this->query[$key] ?? $default;
        return is_string($value) ? trim($value) : $default;
    }

    /** @return list<string> */
    public function inputList(string $key): array
    {
        $value = $this->post[$key] ?? [];
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    public function int(string $key): ?int
    {
        $value = $this->input($key);
        return ($value !== null && ctype_digit($value)) ? (int) $value : null;
    }

    public function param(string $key): string
    {
        return $this->params[$key] ?? '';
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $this->server[$key] ?? null;
        return is_string($value) ? $value : null;
    }

    /** True for requests made by the page's own JavaScript (fetch/XHR), which expect JSON back. */
    public function wantsJson(): bool
    {
        return strtolower((string) $this->header('X-Requested-With')) === 'xmlhttprequest'
            || str_contains(strtolower((string) $this->header('Accept')), 'application/json');
    }

    /** Path plus query string, for "return to this page" links. */
    public function uri(): string
    {
        $query = http_build_query($this->query);
        return $this->path . ($query !== '' ? '?' . $query : '');
    }

    public function body(): string
    {
        return $this->rawBody ?? (string) file_get_contents('php://input');
    }

    /** Honours X-Forwarded-Proto only from configured trusted proxies. */
    public function isSecure(): bool
    {
        if (!empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off') {
            return true;
        }
        if ($this->fromTrustedProxy()) {
            return strtolower((string) ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        }
        return false;
    }

    public function ip(): string
    {
        $remote = (string) ($this->server['REMOTE_ADDR'] ?? '');
        if ($this->fromTrustedProxy() && !empty($this->server['HTTP_X_FORWARDED_FOR'])) {
            $chain = array_map('trim', explode(',', (string) $this->server['HTTP_X_FORWARDED_FOR']));
            return (string) end($chain);
        }
        return $remote;
    }

    public function userAgent(): string
    {
        return substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    private function fromTrustedProxy(): bool
    {
        $trusted = array_filter(array_map('trim', explode(',', Config::get('TRUSTED_PROXIES', '') ?? '')));
        return in_array((string) ($this->server['REMOTE_ADDR'] ?? ''), $trusted, true);
    }
}
