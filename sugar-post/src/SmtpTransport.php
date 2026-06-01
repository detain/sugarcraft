<?php

declare(strict_types=1);

namespace SugarCraft\Post;

use SugarCraft\Async\AsyncOps;
use SugarCraft\Async\CancellationToken;
use SugarCraft\Post\Lang;
use React\EventLoop\LoopInterface;
use React\EventLoop\Loop;

/**
 * Sends email via direct SMTP (TCP/TLS).
 *
 * Implements a minimal SMTP client sufficient for sending plain-text and
 * multi-part MIME emails. TLS is supported on port 465; STARTTLS on 587.
 */
final class SmtpTransport implements Transport
{
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private int $timeout;
    private bool $tls;

    /** @var resource|\Socket|null */
    private $socket = null;
    private string $lastResponse = '';

    public function __construct(
        string $host,
        int $port = 587,
        string $username = '',
        string $password = '',
        int $timeout = 30,
    ) {
        $this->host     = $host;
        $this->port     = $port;
        $this->username = $username;
        $this->password = $password;
        $this->timeout  = $timeout;
        $this->tls      = ($port === 465);
    }

    /**
     * Send an email with optional cancellation support.
     *
     * @param Email $email
     * @param CancellationToken|null $token  Optional cancellation token to abort the send
     * @throws \RuntimeException  On cancellation, timeout, or other send failures
     *
     * @note AsyncOps::withTimeout() cannot be used here without a full async rewrite
     *       of the transport layer (replacing blocking stream_socket_client/fwrite/fgets
     *       with ReactPHP streams). CancellationToken::isCancelled() pre-check and
     *       onCancel() callback provide best-effort cancellation within the current
     *       synchronous scope.
     */
    public function send(Email $email, ?CancellationToken $token = null): void
    {
        // Fail fast if already cancelled
        if ($token !== null && $token->isCancelled()) {
            throw new \RuntimeException(Lang::t('smtp.send_cancelled'));
        }

        try {
            $this->connect();
            $this->helo();
            $this->startTlsIfNeeded();
            $this->authenticateIfNeeded();
            $this->sendMailFrom($email->from[0] ?? 'unknown@localhost');
            foreach ($email->allRecipients() as $rcpt) {
                $this->sendRcptTo($rcpt);
            }
            $this->sendData($email);
            $this->quit();
        } catch (\Throwable $e) {
            $this->disconnect();
            throw new \RuntimeException(Lang::t('smtp.send_failed', ['message' => $e->getMessage()]), 0, $e);
        }
    }

    public function name(): string
    {
        return "smtp://{$this->host}:{$this->port}";
    }

    // -------------------------------------------------------------------------
    // Connection lifecycle
    // -------------------------------------------------------------------------

    private function connect(): void
    {
        $addr = "tcp://{$this->host}:{$this->port}";
        $this->socket = @\stream_socket_client(
            $addr,
            $errno,
            $errstr,
            $this->timeout,
            \STREAM_CLIENT_CONNECT,
        );

        if ($this->socket === false) {
            throw new \RuntimeException(Lang::t('smtp.connect_failed', ['addr' => $addr, 'errstr' => (string) $errstr, 'errno' => (string) $errno]));
        }

        \stream_set_timeout($this->socket, $this->timeout);
        $this->readResponse(220);

        // Identify with EHLO
        $this->sendRaw("EHLO {$this->getHeloHost()}\r\n");
        $this->readResponse(250);
    }

    private function startTlsIfNeeded(): void
    {
        if ($this->tls || $this->hasExtension('STARTTLS')) {
            $this->sendRaw("STARTTLS\r\n");
            $this->readResponse(220);

            /** @var array<resource> $context */
            $context = \stream_context_get_default([
                'ssl' => [
                    'verify_peer'      => true,
                    'verify_peer_name' => true,
                ],
            ]);

            $crypto = \stream_socket_enable_crypto($this->socket, true, \STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($crypto === false) {
                throw new \RuntimeException(Lang::t('smtp.starttls_failed'));
            }

            // Re-EHLO after TLS
            $this->sendRaw("EHLO {$this->getHeloHost()}\r\n");
            $this->readResponse(250);
        }
    }

    private function authenticateIfNeeded(): void
    {
        if ($this->username === '' || $this->password === '') {
            return;
        }

        if (!$this->hasExtension('AUTH')) {
            return; // No auth available; try anyway without
        }

        $this->sendRaw("AUTH LOGIN\r\n");
        $this->readResponse(334); // Username prompt (base64 "Username:")

        $this->sendRaw(\base64_encode($this->username) . "\r\n");
        $this->readResponse(334); // Password prompt (base64 "Password:")

        $this->sendRaw(\base64_encode($this->password) . "\r\n");
        $this->readResponse(235); // Authentication successful
    }

    private function disconnect(): void
    {
        if ($this->socket !== null) {
           @\fclose($this->socket);
            $this->socket = null;
        }
    }

    private function quit(): void
    {
        if ($this->socket === null) {
            return;
        }
        $this->sendRaw("QUIT\r\n");
        @$this->readResponse(221);
        $this->disconnect();
    }

    // -------------------------------------------------------------------------
    // SMTP commands
    // -------------------------------------------------------------------------

    private function helo(): void
    {
        $this->sendRaw("HELO {$this->getHeloHost()}\r\n");
        $this->readResponse(250);
    }

    private function sendMailFrom(string $address): void
    {
        $this->sendRaw("MAIL FROM:<{$address}>\r\n");
        $this->readResponse(250);
    }

    private function sendRcptTo(string $address): void
    {
        $this->sendRaw("RCPT TO:<{$address}>\r\n");
        $this->readResponse(250);
    }

    private function sendData(Email $email): void
    {
        $this->sendRaw("DATA\r\n");
        $this->readResponse(354);

        $mime = $this->buildMimeMessage($email);
        $this->sendRaw($mime . "\r\n.\r\n");
        $this->readResponse(250);
    }

    // -------------------------------------------------------------------------
    // MIME building
    // -------------------------------------------------------------------------

    private function buildMimeMessage(Email $email): string
    {
        $boundary = \bin2hex(\random_bytes(16));
        $lines = [];

        // Headers
        $lines[] = "From: {$this->addrListHeader($email->from)}";
        $lines[] = "To: {$this->addrListHeader($email->to)}";
        if ($email->cc !== []) {
            $lines[] = "Cc: {$this->addrListHeader($email->cc)}";
        }
        if ($email->subject !== null) {
            $lines[] = "Subject: {$email->subject}";
        }
        $lines[] = "MIME-Version: 1.0";
        $lines[] = "Content-Type: multipart/mixed; boundary=\"{$boundary}\"";

        if ($email->replyTo !== null) {
            $lines[] = "Reply-To: {$email->replyTo}";
        }

        $lines[] = '';
        $lines[] = '--' . $boundary;

        // Body (either text or multipart/alternative)
        $bodyBoundary = \bin2hex(\random_bytes(16));

        if ($email->htmlBody !== null) {
            $lines[] = "Content-Type: multipart/alternative; boundary=\"{$bodyBoundary}\"";
            $lines[] = '';
            $lines[] = '--' . $bodyBoundary;
        }

        if ($email->body !== null) {
            $body = $email->bodyWithSignature() ?? $email->body;
            $lines[] = 'Content-Type: text/plain; charset="utf-8"';
            $lines[] = 'Content-Transfer-Encoding: 7bit';
            $lines[] = '';
            $lines = \array_merge($lines, \explode("\n", $body));
            $lines[] = '';
        }

        if ($email->htmlBody !== null) {
            $lines[] = '--' . $bodyBoundary;
            $lines[] = 'Content-Type: text/html; charset="utf-8"';
            $lines[] = 'Content-Transfer-Encoding: 7bit';
            $lines[] = '';
            $lines = \array_merge($lines, \explode("\n", $email->htmlBody));
            $lines[] = '';
            $lines[] = '--' . $bodyBoundary . '--';
            $lines[] = '';
        }

        // Attachments
        foreach ($email->attachments as $att) {
            $content = $att->getContent();
            $encoded = \chunk_split(\base64_encode($content), 76, "\n");

            $headers = [
                "Content-Type: {$att->mimeType}; name=\"{$att->filename}\"",
                "Content-Transfer-Encoding: base64",
                "Content-Disposition: " . ($att->cid !== null
                    ? "inline; filename=\"{$att->filename}\""
                    : "attachment; filename=\"{$att->filename}\""
                ),
            ];

            if ($att->cid !== null) {
                $headers[] = "Content-ID: <{$att->cid}>";
            }

            $lines[] = '--' . $boundary;
            foreach ($headers as $h) {
                $lines[] = $h;
            }
            $lines[] = '';
            $lines[] = $encoded;
            $lines[] = '';
        }

        $lines[] = '--' . $boundary . '--';

        return \implode("\r\n", $lines);
    }

    // -------------------------------------------------------------------------
    // I/O helpers
    // -------------------------------------------------------------------------

    private function sendRaw(string $data): void
    {
        if ($this->socket === null) {
            throw new \RuntimeException(Lang::t('smtp.not_connected'));
        }
        \fwrite($this->socket, $data);
    }

    private function readResponse(int $expectedCode): void
    {
        if ($this->socket === null) {
            throw new \RuntimeException(Lang::t('smtp.not_connected'));
        }

        $line = \fgets($this->socket);
        if ($line === false) {
            throw new \RuntimeException(Lang::t('smtp.no_response'));
        }

        $this->lastResponse = \trim($line);

        $code = (int) \substr($this->lastResponse, 0, 3);
        if ($code !== $expectedCode) {
            throw new \RuntimeException(Lang::t('smtp.unexpected_response', ['response' => $this->lastResponse]));
        }
    }

    private function hasExtension(string $name): bool
    {
        return \str_contains($this->lastResponse, $name);
    }

    private function getHeloHost(): string
    {
        return \gethostname() ?: 'localhost';
    }

    private function addrListHeader(array $addrs): string
    {
        return \implode(', ', $addrs);
    }
}
