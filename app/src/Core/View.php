<?php

declare(strict_types=1);

namespace JC\Core;

final class View
{
    /** @var list<string> */
    private array $scripts = [];

    public function __construct(private readonly App $app)
    {
    }

    /** @param array<string,mixed> $data */
    public function render(string $template, array $data = [], ?string $layout = 'layout/main'): string
    {
        $content = $this->partial($template, $data);
        if ($layout === null) {
            return $content;
        }
        return $this->partial($layout, $data + ['content' => $content]);
    }

    /** @param array<string,mixed> $data */
    public function partial(string $template, array $data = []): string
    {
        $file = JC_ROOT . '/views/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: {$template}");
        }
        $app = $this->app;
        $auth = $this->app->auth();
        $view = $this;
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            include $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }

    /** Lets a page template ask the layout to load an extra script. */
    public function addScript(string $src): void
    {
        if (!in_array($src, $this->scripts, true)) {
            $this->scripts[] = $src;
        }
    }

    /** @return list<string> */
    public function scripts(): array
    {
        return $this->scripts;
    }

    public function csrfField(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e($this->app->session()->csrfToken()) . '">';
    }
}
