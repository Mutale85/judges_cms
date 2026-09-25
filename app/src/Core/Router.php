<?php

declare(strict_types=1);

namespace JC\Core;

final class Router
{
    /** @var list<array{method:string,regex:string,handler:array{0:class-string,1:string},opts:array<string,mixed>}> */
    private array $routes = [];

    /**
     * @param array{0:class-string,1:string} $handler
     * @param array<string,mixed> $opts  auth: 'full'|'partial'|'none', csrf: bool
     */
    public function add(string $method, string $pattern, array $handler, array $opts = []): void
    {
        $regex = preg_replace_callback('/\{(\w+)\}/', static fn ($m) => '(?P<' . $m[1] . '>\d+)', $pattern);
        $this->routes[] = [
            'method' => $method,
            'regex' => '#^' . $regex . '$#',
            'handler' => $handler,
            'opts' => $opts + ['auth' => 'full', 'csrf' => $method !== 'GET'],
        ];
    }

    public function get(string $pattern, array $handler, array $opts = []): void
    {
        $this->add('GET', $pattern, $handler, $opts);
    }

    public function post(string $pattern, array $handler, array $opts = []): void
    {
        $this->add('POST', $pattern, $handler, $opts);
    }

    /** @return array{handler:array{0:class-string,1:string},opts:array<string,mixed>,params:array<string,string>} */
    public function match(Request $request): array
    {
        $allowed = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $m)) {
                continue;
            }
            $method = $request->method === 'HEAD' ? 'GET' : $request->method;
            if ($route['method'] !== $method) {
                $allowed = true;
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            return ['handler' => $route['handler'], 'opts' => $route['opts'], 'params' => $params];
        }
        throw new HttpException($allowed ? 405 : 404);
    }
}
