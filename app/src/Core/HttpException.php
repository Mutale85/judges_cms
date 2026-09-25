<?php

declare(strict_types=1);

namespace JC\Core;

final class HttpException extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message = '')
    {
        parent::__construct($message !== '' ? $message : match ($status) {
            400 => 'Bad request',
            403 => 'You do not have permission to do that.',
            404 => 'Not found',
            405 => 'Method not allowed',
            419 => 'Your session form token expired. Please go back and try again.',
            default => 'Error',
        });
    }
}
