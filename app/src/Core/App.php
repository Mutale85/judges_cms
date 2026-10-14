<?php

declare(strict_types=1);

namespace JC\Core;

use JC\Security\AuditLog;
use JC\Security\Crypto;
use JC\Security\FieldCipher;
use JC\Security\KeyRing;
use JC\Security\ServiceAuth;
use JC\Security\TransportSecurity;
use JC\Service\Auth;

/**
 * Application container and HTTP kernel.
 */
final class App
{
    private ?Database $db = null;
    private ?Crypto $crypto = null;
    private ?FieldCipher $cipher = null;
    private ?AuditLog $audit = null;
    private ?ServiceAuth $serviceAuth = null;
    private ?Session $session = null;
    private ?Auth $auth = null;
    private ?View $view = null;
    private ?Request $request = null;

    /** @var array<class-string,object> */
    private array $services = [];

    public function __construct(?Database $db = null, ?KeyRing $ring = null)
    {
        $this->db = $db;
        if ($ring !== null) {
            $this->crypto = new Crypto($ring);
        }
    }

    public function db(): Database
    {
        return $this->db ??= Database::fromConfig();
    }

    public function crypto(): Crypto
    {
        return $this->crypto ??= new Crypto(KeyRing::fromConfig());
    }

    public function cipher(): FieldCipher
    {
        return $this->cipher ??= new FieldCipher($this->crypto());
    }

    public function audit(): AuditLog
    {
        return $this->audit ??= new AuditLog($this->db(), $this->cipher());
    }

    public function serviceAuth(): ServiceAuth
    {
        return $this->serviceAuth ??= ServiceAuth::fromConfig();
    }

    public function session(): Session
    {
        return $this->session ??= new Session($this->crypto());
    }

    public function auth(): Auth
    {
        return $this->auth ??= new Auth($this);
    }

    public function view(): View
    {
        return $this->view ??= new View($this);
    }

    public function request(): ?Request
    {
        return $this->request;
    }

    /**
     * Shared service instances (JC\Service\*), constructed with the App.
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    public function service(string $class): object
    {
        /** @var T */
        return $this->services[$class] ??= new $class($this);
    }

    public function storagePath(string $sub = ''): string
    {
        $base = rtrim(Config::get('STORAGE_PATH', JC_ROOT . '/storage') ?? JC_ROOT . '/storage', '/');
        return $sub === '' ? $base : $base . '/' . ltrim($sub, '/');
    }

    /** Records an audit event attributed to the current user and chambers. */
    public function log(string $action, ?string $entityType = null, ?int $entityId = null, array $details = [], ?int $chambersId = null): void
    {
        $auth = $this->auth();
        $this->audit()->record(
            $action,
            $auth->check() ? $auth->id() : $auth->pendingUserId(),
            $chambersId ?? ($auth->check() ? $auth->chambersId() : null),
            $entityType,
            $entityId,
            $details,
            $this->request?->ip(),
        );
    }

    public function handle(Request $request, Router $router): Response
    {
        $this->request = $request;
        try {
            $response = $this->dispatch($request, $router);
        } catch (HttpException $e) {
            $response = $this->errorResponse($request, $e->status, $e->getMessage());
        } catch (\Throwable $e) {
            error_log('[judicial-chambers] ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $message = Config::bool('APP_DEBUG') ? $e::class . ': ' . $e->getMessage() : 'An unexpected error occurred. It has been logged.';
            $response = $this->errorResponse($request, 500, $message);
        }
        // Every response, including redirects and errors, carries the security headers.
        TransportSecurity::applyHeaders($request, $response);
        return $response;
    }

    private function dispatch(Request $request, Router $router): Response
    {
        if ($redirect = TransportSecurity::enforce($request)) {
            return $redirect;
        }
        $route = $router->match($request);
        $request->params = $route['params'];
        $opts = $route['opts'];

        if ($opts['auth'] !== 'service') {
            $this->session()->start($request);
            if ($opts['csrf'] && !$this->session()->validCsrf($request->input('_csrf') ?? $request->header('X-CSRF-Token'))) {
                throw new HttpException(419);
            }
            $this->auth()->load();
            if ($opts['auth'] === 'full' && !$this->auth()->check()) {
                if ($this->auth()->pendingUserId() !== null) {
                    return Response::redirect($this->auth()->pendingStageUrl());
                }
                return Response::redirect('/login');
            }
            if ($opts['auth'] === 'full' && $this->auth()->mustChangePassword() && $request->path !== '/account/password') {
                return Response::redirect('/account/password');
            }
            if (!empty($opts['admin']) && !$this->auth()->isAdmin()) {
                throw new HttpException(403);
            }
            if ($opts['auth'] === 'full' && $request->method !== 'GET' && !$this->writableRequest($request)) {
                throw new HttpException(402, 'This workspace\'s subscription has lapsed, so it is read-only. You can still view and export everything; the workspace owner can renew it from Billing.');
            }
        }

        [$class, $method] = $route['handler'];
        $controller = new $class($this);
        return $controller->{$method}($request);
    }

    /**
     * A lapsed subscription makes the workspace read-only. Billing, account
     * and sign-in routes stay usable so the owner can renew.
     */
    private function writableRequest(Request $request): bool
    {
        foreach (['/billing', '/logout', '/account', '/mfa', '/chambers/switch', '/admin'] as $prefix) {
            if ($request->path === $prefix || str_starts_with($request->path, $prefix . '/')) {
                return true;
            }
        }
        $chambers = $this->auth()->chambers();
        return $chambers === null || $this->service(\JC\Service\SubscriptionService::class)->isWritable($chambers);
    }

    private function errorResponse(Request $request, int $status, string $message): Response
    {
        if (str_starts_with($request->path, '/internal/') || str_starts_with($request->path, '/api/')) {
            return Response::json(['error' => $message], $status);
        }
        if ($request->wantsJson()) {
            return Response::json(['ok' => false, 'type' => 'error', 'status' => $status, 'message' => $message], $status);
        }
        try {
            $layout = $this->auth()->check() ? 'layout/main' : 'layout/guest';
            return Response::html($this->view()->render('errors/error', ['status' => $status, 'message' => $message, 'title' => 'Error'], $layout), $status);
        } catch (\Throwable) {
            return new Response(e($message), $status);
        }
    }
}
