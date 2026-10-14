<?php

declare(strict_types=1);

namespace JC\Service;

use JC\Core\App;
use JC\Core\Config;

/**
 * Outgoing e-mail (account confirmation, password resets, billing notices).
 *
 * MAIL_DRIVER=smtp speaks SMTP directly, over implicit TLS (MAIL_ENCRYPTION=ssl,
 * usually port 465) or STARTTLS (MAIL_ENCRYPTION=tls, usually 587), and
 * refuses to send credentials over an unencrypted connection.
 * MAIL_DRIVER=log writes messages to the PHP error log instead and is meant
 * for development only.
 *
 * E-mails never contain case data: only account links and notices.
 */
final class Mailer
{
    /** @var list<array{to:string,subject:string,text:string}> messages sent in this process (tests, dev) */
    public array $sent = [];

    public function __construct(private readonly App $app)
    {
    }

    public function driver(): string
    {
        return strtolower((string) Config::get('MAIL_DRIVER', 'log'));
    }

    /** Sends a message rendered from views/emails/{template}.php. */
    public function sendTemplate(string $to, string $subject, string $template, array $data = []): bool
    {
        $text = $this->app->view()->partial('emails/' . $template, $data + ['subject' => $subject]);
        return $this->send($to, $subject, $text);
    }

    public function send(string $to, string $subject, string $text): bool
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $subject)) {
            throw new \InvalidArgumentException('Invalid e-mail recipient or subject.');
        }
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'text' => $text];
        try {
            return match ($this->driver()) {
                'smtp' => $this->smtp($to, $subject, $text),
                'null' => true,
                default => $this->log($to, $subject, $text),
            };
        } catch (\Throwable $e) {
            error_log('[judicial-chambers] mail to ' . self::mask($to) . ' failed: ' . $e->getMessage());
            return false;
        }
    }

    private function log(string $to, string $subject, string $text): bool
    {
        if (Config::get('APP_ENV') !== 'testing') {
            error_log("[judicial-chambers] MAIL_DRIVER=log — e-mail to {$to}\nSubject: {$subject}\n\n{$text}");
        }
        return true;
    }

    private function smtp(string $to, string $subject, string $text): bool
    {
        $host = Config::require('MAIL_HOST');
        $port = Config::int('MAIL_PORT', 587);
        $encryption = strtolower((string) Config::get('MAIL_ENCRYPTION', 'tls'));
        $user = (string) Config::get('MAIL_USERNAME', '');
        $pass = (string) Config::get('MAIL_PASSWORD', '');
        $from = Config::require('MAIL_FROM');
        $fromName = (string) Config::get('MAIL_FROM_NAME', app_name());

        $context = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $host,
        ]]);
        $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $socket = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new \RuntimeException("cannot connect to {$host}:{$port} ({$errstr})");
        }
        stream_set_timeout($socket, 15);
        try {
            $this->expect($socket, 220);
            $ehlo = 'EHLO ' . (parse_url((string) Config::get('APP_URL', ''), PHP_URL_HOST) ?: 'localhost');
            $this->command($socket, $ehlo, 250);
            if ($encryption === 'tls') {
                $this->command($socket, 'STARTTLS', 220);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                    throw new \RuntimeException('STARTTLS negotiation failed');
                }
                $this->command($socket, $ehlo, 250);
            } elseif ($encryption !== 'ssl' && $user !== '') {
                throw new \RuntimeException('refusing to send SMTP credentials without TLS (set MAIL_ENCRYPTION=tls or ssl)');
            }
            if ($user !== '') {
                $this->command($socket, 'AUTH LOGIN', 334);
                $this->command($socket, base64_encode($user), 334);
                $this->command($socket, base64_encode($pass), 235);
            }
            $this->command($socket, 'MAIL FROM:<' . $from . '>', 250);
            $this->command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
            $this->command($socket, 'DATA', 354);

            $domain = substr((string) strrchr($from, '@'), 1) ?: 'localhost';
            $headers = [
                'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
                'From: ' . self::encodeHeader($fromName) . ' <' . $from . '>',
                'To: <' . $to . '>',
                'Subject: ' . self::encodeHeader($subject),
                'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: base64',
                'Auto-Submitted: auto-generated',
            ];
            $body = implode("\r\n", $headers) . "\r\n\r\n" . rtrim(chunk_split(base64_encode($text), 76, "\r\n"));
            fwrite($socket, $body . "\r\n.\r\n");
            $this->expect($socket, 250);
            $this->command($socket, 'QUIT', 221);
        } finally {
            fclose($socket);
        }
        return true;
    }

    /** @param resource $socket @param int|list<int> $expected */
    private function command($socket, string $line, int|array $expected): void
    {
        fwrite($socket, $line . "\r\n");
        $this->expect($socket, $expected);
    }

    /** @param resource $socket @param int|list<int> $expected */
    private function expect($socket, int|array $expected): void
    {
        $response = '';
        while (($line = fgets($socket, 1024)) !== false) {
            $response .= $line;
            // Multi-line replies use "250-"; the last line has a space after the code.
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, (array) $expected, true)) {
            throw new \RuntimeException('SMTP error: ' . trim($response ?: 'no response'));
        }
    }

    private static function encodeHeader(string $value): string
    {
        return preg_match('/[^\x20-\x7e]/', $value) ? '=?UTF-8?B?' . base64_encode($value) . '?=' : $value;
    }

    private static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        return mb_substr($local, 0, 2) . '…@' . $domain;
    }
}
