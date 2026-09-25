<?php

declare(strict_types=1);

namespace JC\Core;

final class Response
{
    /** @var array<string,string> */
    public array $headers = [];

    /** @var (callable():void)|null */
    public $stream = null;

    public function __construct(public string $body = '', public int $status = 200)
    {
    }

    public static function html(string $body, int $status = 200): self
    {
        $r = new self($body, $status);
        $r->headers['Content-Type'] = 'text/html; charset=utf-8';
        return $r;
    }

    /** @param array<mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        $r = new self(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $status);
        $r->headers['Content-Type'] = 'application/json';
        return $r;
    }

    public static function redirect(string $to, int $status = 303): self
    {
        $r = new self('', $status);
        $r->headers['Location'] = $to;
        return $r;
    }

    /** @param callable():void $writer */
    public static function stream(callable $writer, int $status = 200): self
    {
        $r = new self('', $status);
        $r->stream = $writer;
        return $r;
    }

    public function send(): void
    {
        header_remove('X-Powered-By');
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        if ($this->stream !== null) {
            ($this->stream)();
            return;
        }
        echo $this->body;
    }
}
