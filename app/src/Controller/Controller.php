<?php

declare(strict_types=1);

namespace JC\Controller;

use JC\Core\App;
use JC\Core\Response;
use JC\Service\Auth;

abstract class Controller
{
    public function __construct(protected readonly App $app)
    {
    }

    protected function auth(): Auth
    {
        return $this->app->auth();
    }

    /** @param array<string,mixed> $data */
    protected function render(string $template, array $data = [], string $layout = 'layout/main'): Response
    {
        return Response::html($this->app->view()->render($template, $data, $layout));
    }

    protected function redirect(string $to, ?string $message = null, string $type = 'success'): Response
    {
        if ($message !== null) {
            $this->app->session()->flash($type, $message);
        }
        return Response::redirect($to);
    }

    /**
     * Runs a state-changing action, turning validation errors into a flash
     * message on the page the user came from.
     *
     * @param callable():Response $action
     */
    protected function attempt(string $back, callable $action): Response
    {
        try {
            return $action();
        } catch (\InvalidArgumentException $e) {
            return $this->redirect($back, $e->getMessage(), 'danger');
        } catch (\PDOException $e) {
            error_log('[judicial-chambers] ' . $e->getMessage());
            return $this->redirect($back, 'That change conflicts with existing records (for example a duplicate case number).', 'danger');
        }
    }
}
