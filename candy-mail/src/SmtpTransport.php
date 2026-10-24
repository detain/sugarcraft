<?php

declare(strict_types=1);

namespace SugarCraft\Mail;

use SugarCraft\Async\AsyncOps;
use SugarCraft\Async\CancellationToken;

/**
 * Sends email via direct SMTP (TCP/TLS).
 *
 * Implements a minimal SMTP client sufficient for sending plain-text and
 * multi-part MIME emails.
 *
 * Two TLS modes are supported:
 *  - **Implicit TLS / SMTPS** (port 465, or {@see withImplicitTls()}): the
 *    socket is wrapped in TLS at connect time, before any SMTP bytes are
 *    exchanged. STARTTLS is never sent on such a connection.
 *  - **STARTTLS** (e.g. port 587): plaintext connect, then the channel is
 *    upgraded via STARTTLS. TLS is REQUIRED by default — if the server does
 *    not advertise STARTTLS (or the upgrade fails), the send throws instead
 *    of falling back to plaintext. {@see withRequireTls(false)} restores the
 *    historical opportunistic behaviour for trusted networks only.
 *
 * Peer certificates are verified in both modes; {@see withCaFile()} selects a
 * custom CA bundle. Credentials are never sent over an unencrypted channel:
 * AUTH LOGIN refuses to start on a plaintext connection in every mode.
 *
 * Mirrors charmbracelet/pop SMTP transport.
 */
final class SmtpTransport implements Transport
{
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private int $timeout;
    /** True for SMTPS/implicit-TLS ports: wrap the socket in TLS at connect. */
    private bool $implicitTls;
    /** When true, a send that cannot establish TLS throws instead of proceeding in plaintext. */
    private bool $requireTls;
    private string $heloHost;
    /** Optional custom CA bundle for peer verification (null = system trust store). */
    private ?string $caFile = null;

    /** @var resource|null */
    private $socket = null;
    private string $lastResponse = '';
    /** Runtime flag: is the live channel currently encrypted? */
    private bool $encrypted = false;

    public function __construct(
        string $host,
        int $port = 587,
        string $username = '',
        string $password = '',
        int $timeout = 30,
        string $heloHost = '',
    ) {
        $this->host        = $host;
        $this->port        = $port;
        $this->username    = $username;
        $this->password    = $password;
        $this->timeout     = $timeout;
        $this->implicitTls = ($port === 465);
        // Secure by default (docs-fix-1023 S-1): a send that cannot reach an
        // encrypted channel throws rather than leaking mail/credentials in
        // plaintext. Opt out explicitly with withRequireTls(false).
        $this->requireTls  = true;
        $this->heloHost    = $heloHost;
    }

    /**
     * Control whether the send fails when the channel cannot be encrypted.
     *
     * REQUIRED TLS is the constructor default (docs-fix-1023 S-1): with the
     * flag on, a server that does not offer STARTTLS (or a failed upgrade)
     * throws instead of sending credentials/mail in the clear. Pass false to
     * opt back into opportunistic behaviour — trusted networks only.
     * Immutable per repo convention: yields a fresh, unconnected clone.
     */
    public function withRequireTls(bool $require = true): self
    {
        $clone = clone $this;
        $clone->requireTls   = $require;
        $clone->socket       = null;
        $clone->lastResponse = '';
        $clone->encrypted    = false;
        return $clone;
    }

    /**
     * Return a copy that connects with implicit TLS (SMTPS) regardless of port.
     *
     * Use for servers that speak TLS-from-connect on a non-465 port. Immutable:
     * yields a fresh, unconnected clone.
     */
    public function withImplicitTls(bool $implicit = true): self
    {
        $clone = clone $this;
        $clone->implicitTls  = $implicit;
        $clone->socket       = null;
        $clone->lastResponse = '';
        $clone->encrypted    = false;
        return $clone;
    }

    /**
     * Return a copy that verifies the server certificate against a custom CA
     * bundle instead of the system trust store (e.g. a private CA).
     *
     * Applies to both TLS modes: the ssl context at connect for implicit TLS,
     * and the stream options set before the STARTTLS crypto upgrade. An empty
     * path clears it again. Immutable: yields a fresh, unconnected clone.
     */
    public function withCaFile(string $caFile): self
    {
        $clone = clone $this;
        $clone->caFile       = $caFile === '' ? null : $caFile;
        $clone->socket       = null;
        $clone->lastResponse = '';
        $clone->encrypted    = false;
        return $clone;
    }

    /**
     * Mask secrets so the SMTP password never leaks through var_dump(),
     * print_r(depth), stack traces, or logging that reflects object state.
     */
    public function __debugInfo(): array
    {
        return [
            'host'        => $this->host,
            'port'        => $this->port,
            'username'    => $this->username,
            'password'    => $this->password === '' ? '' : '****',
            'timeout'     => $this->timeout,
            'implicitTls' => $this->implicitTls,
            'requireTls'  => $this->requireTls,
            'heloHost'    => $this->heloHost,
            'caFile'      => $this->caFile,
            'encrypted'   => $this->encrypted,
            'connected'   => $this->socket !== null,
        ];
    }

    /**
     * Send an email with optional cancellation support.
     *
     * Blocking contract: this call is synchronous. It drives the entire SMTP
     * conversation with blocking socket I/O (stream_socket_client/fwrite/fgets)
     * and therefore BLOCKS the ReactPHP event loop for its full duration — up
     * to `$timeout` seconds per network round-trip. Run it off the loop (worker
     * or child process) when loop responsiveness matters.
     *
     * @param Email $email
     * @param CancellationToken|null $token  Optional cancellation token to abort the send
     * @throws \RuntimeException  On cancellation, timeout, or other send failures
     *
     * @note AsyncOps::withTimeout() cannot be used here without a full async rewrite
     *       of the transport layer (replacing the blocking stream_socket_client/fwrite/fgets
     *       above with ReactPHP streams). Cancellation is an ENTRY pre-check only:
     *       once the blocking conversation starts, no callback can fire mid-call,
     *       because this method never returns to the event loop (CALIBER_LEARNINGS:
     *       onCancel() on a synchronous call is a silent no-op).
     */
    public function send(Email $email, ?CancellationToken $token = null): void
    {
        // Fail fast if already cancelled
        if ($token !== null && $token->isCancelled()) {
            throw new \RuntimeException(Lang::t('smtp.send_cancelled'));
        }

        // Envelope shape is checked BEFORE the dial: a transport used directly
        // (not via Mailer) must refuse an empty envelope rather than pay a
        // connection for it or fall back to a fabricated sender.
        if ($email->from === []) {
            throw new \InvalidArgumentException(Lang::t('mailer.no_from'));
        }
        if ($email->allRecipients() === []) {
            throw new \InvalidArgumentException(Lang::t('mailer.no_recipient'));
        }

        try {
            $this->connect();
            $this->startTlsIfNeeded();
            $this->authenticateIfNeeded();
            $this->sendMailFrom($email->from[0]);
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
        $target  = $this->connectTarget();
        $context = \stream_context_create($this->streamContextOptions());
        $socket  = @\stream_socket_client(
            $target,
            $errno,
            $errstr,
            (float) $this->timeout,
            \STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($socket === false) {
            throw new \RuntimeException(Lang::t('smtp.connect_failed', ['addr' => $target, 'errstr' => (string) $errstr, 'errno' => (string) $errno]));
        }

        $this->socket = $socket;
        \stream_set_timeout($this->socket, $this->timeout);

        // On implicit-TLS ports (SMTPS/465) the TLS handshake already completed
        // during stream_socket_client(): the greeting and every byte after it
        // travel over TLS. Mark the channel encrypted so STARTTLS is never sent.
        if ($this->implicitTls) {
            $this->encrypted = true;
        }

        $this->readResponse(220);

        // Identify with EHLO
        $this->sendRaw("EHLO {$this->getHeloHost()}\r\n");
        $this->readResponse(250);
    }

    /**
     * Build the stream_socket_client() target.
     *
     * Implicit-TLS ports use the `tls://` scheme so the socket is encrypted from
     * the first byte; everything else connects plaintext (and may upgrade later
     * via STARTTLS). Pure/side-effect-free so the choice is unit-testable.
     */
    private function connectTarget(): string
    {
        $scheme = $this->implicitTls ? 'tls' : 'tcp';
        return "{$scheme}://{$this->host}:{$this->port}";
    }

    /**
     * Stream-context options for the connect. Peer verification is always on for
     * TLS connects — never blanket-disable verify_peer.
     *
     * @return array<string, array<string, mixed>>
     */
    private function streamContextOptions(): array
    {
        $options = ['socket' => ['connect_timeout' => $this->timeout]];
        if ($this->implicitTls) {
            $options['ssl'] = [
                'verify_peer'      => true,
                'verify_peer_name' => true,
                'peer_name'        => $this->host,
                'SNI_enabled'      => true,
            ];
            if ($this->caFile !== null) {
                $options['ssl']['cafile'] = $this->caFile;
            }
        }
        return $options;
    }

    private function startTlsIfNeeded(): void
    {
        // Already encrypted (implicit TLS / SMTPS) — STARTTLS would be a protocol
        // error against an already-secured channel.
        if ($this->encrypted) {
            return;
        }

        if (!$this->hasExtension('STARTTLS')) {
            // Server did not advertise STARTTLS: we cannot upgrade. Refuse to
            // continue in plaintext when TLS was required (downgrade-strip guard).
            if ($this->requireTls) {
                throw new \RuntimeException(Lang::t('smtp.tls_required', ['host' => $this->host]));
            }
            return;
        }

        $this->sendRaw("STARTTLS\r\n");
        $this->readResponse(220);

        // Set SSL options on the socket BEFORE enabling crypto. Peer verification
        // stays ON — do not blanket-disable verify_peer.
        \stream_context_set_option($this->socket, 'ssl', 'verify_peer', true);
        \stream_context_set_option($this->socket, 'ssl', 'verify_peer_name', true);
        \stream_context_set_option($this->socket, 'ssl', 'peer_name', $this->host);
        if ($this->caFile !== null) {
            \stream_context_set_option($this->socket, 'ssl', 'cafile', $this->caFile);
        }

        $crypto = \stream_socket_enable_crypto($this->socket, true, \STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if ($crypto !== 1) {
            // 1 = handshake completed; 0/false = failure. Treat anything else as
            // a failed upgrade so requireTls semantics stay honest.
            throw new \RuntimeException(Lang::t('smtp.starttls_failed'));
        }
        $this->encrypted = true;

        // Re-EHLO after TLS so the post-upgrade extension list (e.g. AUTH) is fresh.
        $this->sendRaw("EHLO {$this->getHeloHost()}\r\n");
        $this->readResponse(250);
    }

    private function authenticateIfNeeded(): void
    {
        if ($this->username === '' || $this->password === '') {
            return;
        }

        if (!$this->encrypted) {
            // docs-fix-1023 S-1: AUTH LOGIN ships base64 (not encrypted)
            // credentials. A plaintext channel here is either a stripped
            // STARTTLS downgrade or an explicit withRequireTls(false) opt-out
            // — refuse either way rather than hand the network a password.
            throw new \RuntimeException(Lang::t('smtp.auth_insecure'));
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

    private function sendMailFrom(string $address): void
    {
        $this->sendRaw("MAIL FROM:<{$this->bareAddr($address)}>\r\n");
        $this->readResponse(250);
    }

    private function sendRcptTo(string $address): void
    {
        $this->sendRaw("RCPT TO:<{$this->bareAddr($address)}>\r\n");
        $this->readResponse(250);
    }

    private function sendData(Email $email): void
    {
        $this->sendRaw("DATA\r\n");
        $this->readResponse(354);

        $mime = $this->dotStuff($this->buildMimeMessage($email));
        $this->sendRaw($mime . "\r\n.\r\n");
        $this->readResponse(250);
    }

    /**
     * Apply RFC 5321 §4.5.2 dot-stuffing: prefix each line starting with
     * a literal dot with an extra dot so it isn't interpreted as a terminator.
     *
     * Mirrors charmbracelet/pop dot-stuffing.
     */
    private function dotStuff(string $mime): string
    {
        return \preg_replace('/^\./m', '..', $mime);
    }

    // -------------------------------------------------------------------------
    // MIME building
    // -------------------------------------------------------------------------

    /**
     * Render the full RFC 2046 MIME message for an email (headers + body).
     *
     * Public render seam: it performs no I/O, so callers (and the CLI's
     * `--render` dry-run) can inspect exactly what a send would transmit
     * without connecting. The single generated boundary is authoritative —
     * it is declared in the Content-Type header, opens every part, and
     * closes the message (docs-fix-1023 C-1: a second random boundary used
     * to open the body part, making every emitted message structurally
     * invalid for strict parsers).
     */
    public function buildMimeMessage(Email $email): string
    {
        $boundary = \bin2hex(\random_bytes(16));
        $lines = [];

        // Headers
        $lines = \array_merge($lines, $this->buildHeaders($email, $boundary));

        // Body
        $lines = \array_merge($lines, $this->buildBody($email, $boundary));

        // Attachments
        $lines = \array_merge($lines, $this->buildAttachments($email->attachments, $boundary));

        $lines[] = '--' . $boundary . '--';

        return \implode("\r\n", $lines);
    }

    /**
     * Build MIME headers for the email.
     *
     * @return list<string>
     */
    private function buildHeaders(Email $email, string $boundary): array
    {
        $lines = [];
        $lines[] = "From: {$this->addrListHeader($email->from)}";
        $lines[] = "To: {$this->addrListHeader($email->to)}";
        if ($email->cc !== []) {
            $lines[] = "Cc: {$this->addrListHeader($email->cc)}";
        }
        if ($email->subject !== null) {
            $lines[] = "Subject: {$this->encodeHeaderWord($email->subject)}";
        }
        $lines[] = 'Date: ' . \date('r');
        $lines[] = 'Message-ID: <' . \bin2hex(\random_bytes(16)) . '@' . $this->messageIdHost() . '>';
        $lines[] = "MIME-Version: 1.0";
        $lines[] = "Content-Type: multipart/mixed; boundary=\"{$boundary}\"";
        if ($email->replyTo !== null) {
            $lines[] = "Reply-To: {$this->addrListHeader([$email->replyTo])}";
        }
        return $lines;
    }

    /**
     * Build MIME body parts (text and/or HTML).
     *
     * The part opener MUST use the caller's declared multipart/mixed
     * boundary; an inner multipart/alternative declares its own boundary
     * inside its own part header.
     *
     * @return list<string>
     */
    private function buildBody(Email $email, string $boundary): array
    {
        $lines = [];
        $lines[] = '';
        $lines[] = '--' . $boundary;

        $bodyBoundary = \bin2hex(\random_bytes(16));
        if ($email->htmlBody !== null) {
            $lines[] = "Content-Type: multipart/alternative; boundary=\"{$bodyBoundary}\"";
            $lines[] = '';
            $lines[] = '--' . $bodyBoundary;
        }

        if ($email->body !== null) {
            $body = $email->bodyWithSignature() ?? $email->body;
            $normalized = \preg_replace('/\r\n|\r/', "\n", $body);
            $lines[] = 'Content-Type: text/plain; charset="utf-8"';
            $lines[] = 'Content-Transfer-Encoding: ' . $this->cteFor($normalized);
            $lines[] = '';
            $lines = \array_merge($lines, \explode("\n", $normalized));
            $lines[] = '';
        }

        if ($email->htmlBody !== null) {
            $lines[] = '--' . $bodyBoundary;
            $lines[] = 'Content-Type: text/html; charset="utf-8"';
            $normalized = \preg_replace('/\r\n|\r/', "\n", $email->htmlBody);
            $lines[] = 'Content-Transfer-Encoding: ' . $this->cteFor($normalized);
            $lines[] = '';
            $lines = \array_merge($lines, \explode("\n", $normalized));
            $lines[] = '';
            $lines[] = '--' . $bodyBoundary . '--';
            $lines[] = '';
        }

        return $lines;
    }

    /**
     * Build MIME attachment parts.
     *
     * @param list<Attachment> $attachments
     * @return list<string>
     */
    private function buildAttachments(array $attachments, string $boundary): array
    {
        $lines = [];
        foreach ($attachments as $att) {
            $content = $att->content();
            $encoded = \chunk_split(\base64_encode($content), 76, "\n");

            // RFC 2045 quoted-string: CRLF is refused at Attachment construction
            // (docs-fix-1023 C-2); quotes and backslashes are escaped here so a
            // name like a"b.txt cannot corrupt the quoted parameter.
            $name = self::quoteMimeParam($att->filename);

            $headers = [
                "Content-Type: {$att->mimeType}; name=\"{$name}\"",
                "Content-Transfer-Encoding: base64",
                "Content-Disposition: " . ($att->cid !== null
                    ? "inline; filename=\"{$name}\""
                    : "attachment; filename=\"{$name}\""
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
        return $lines;
    }

    // -------------------------------------------------------------------------
    // I/O helpers
    // -------------------------------------------------------------------------

    private function sendRaw(string $data): void
    {
        if ($this->socket === null) {
            throw new \RuntimeException(Lang::t('smtp.not_connected'));
        }
        $written = \fwrite($this->socket, $data);
        if ($written === false) {
            throw new \RuntimeException(Lang::t('smtp.write_error'));
        }
        if ($written < \strlen($data)) {
            throw new \RuntimeException(Lang::t('smtp.incomplete_write'));
        }
    }

    /**
     * Read a (possibly multi-line) SMTP reply and validate its status code.
     *
     * RFC 5321 §4.2.1: continuation lines use "<code>-text", the final line
     * "<code> text". All lines are captured into {@see $lastResponse} (joined by
     * "\n") so {@see hasExtension()} can inspect the full EHLO keyword list —
     * reading only the first line would hide advertised extensions like STARTTLS.
     */
    private function readResponse(int $expectedCode): void
    {
        if ($this->socket === null) {
            throw new \RuntimeException(Lang::t('smtp.not_connected'));
        }

        $lines = [];
        do {
            $line = \fgets($this->socket);
            if ($line === false) {
                throw new \RuntimeException(Lang::t('smtp.no_response'));
            }
            if ($line === '') {
                throw new \RuntimeException(Lang::t('smtp.empty_response'));
            }
            $line     = \rtrim($line, "\r\n");
            $lines[]  = $line;
            // A hyphen in the 4th column marks a continuation; anything else ends it.
            $continue = \strlen($line) >= 4 && $line[3] === '-';
        } while ($continue);

        $this->lastResponse = \implode("\n", $lines);

        $code = (int) \substr($lines[0], 0, 3);
        if ($code !== $expectedCode) {
            throw new \RuntimeException(Lang::t('smtp.unexpected_response', ['response' => $this->lastResponse]));
        }
    }

    /**
     * True when the last (EHLO) reply advertised the named SMTP extension.
     *
     * Each reply line is "<3-digit code><sep><keyword> [params]"; strip the code
     * and separator before matching so "250-STARTTLS" / "250 AUTH LOGIN" resolve
     * to the keywords STARTTLS / AUTH.
     */
    private function hasExtension(string $name): bool
    {
        $name = \strtoupper($name);
        foreach (\explode("\n", $this->lastResponse) as $line) {
            $keyword = \strtoupper(\trim(\substr($line, 4)));
            if ($keyword === $name || \str_starts_with($keyword, $name . ' ')) {
                return true;
            }
        }
        return false;
    }

    private function getHeloHost(): string
    {
        return $this->heloHost !== '' ? $this->heloHost : (\gethostname() ?: 'localhost');
    }

    /**
     * Escape a value for use inside an RFC 2045 quoted-string parameter.
     */
    private static function quoteMimeParam(string $value): string
    {
        return \str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    /**
     * RFC 2822 addr-spec domain for the generated Message-ID: the HELO host,
     * filtered to legal hostname characters so arbitrary heloHost values can
     * never forge header content.
     */
    private function messageIdHost(): string
    {
        $clean = \preg_replace('/[^A-Za-z0-9.\-]/', '', $this->getHeloHost());
        return $clean !== '' ? $clean : 'localhost';
    }

    private function addrListHeader(array $addrs): string
    {
        $formatted = [];
        foreach ($addrs as $addr) {
            $formatted[] = $this->formatAddressForHeader($addr);
        }
        return \implode(', ', $formatted);
    }

    /**
     * Extract the bare address from a "Name <addr@host>" string.
     *
     * Mirrors charmbracelet/pop bare address extraction.
     */
    private function bareAddr(string $addr): string
    {
        // Check for "Name <addr@host>" format
        if (\preg_match('/<([^>]+)>/', $addr, $matches)) {
            return $matches[1];
        }
        return $addr;
    }

    /**
     * Format an address for a header line (From:, To:, Cc:).
     * Display names are RFC 2047 encoded; bare addresses are used as-is.
     */
    private function formatAddressForHeader(string $addr): string
    {
        // Check for "Name <addr@host>" format
        if (\preg_match('/^(.+)\s<([^>]+)>$/', $addr, $matches)) {
            $displayName = \trim($matches[1]);
            $emailAddr = $matches[2];
            // RFC 2047 encode the display name if it contains non-ASCII
            if (\preg_match('/[^\x00-\x7F]/', $displayName)) {
                $displayName = '=?UTF-8?B?' . \base64_encode($displayName) . '?=';
            }
            return "{$displayName} <{$emailAddr}>";
        }
        return $addr;
    }

    /**
     * RFC 2047 encode a header word if it contains non-ASCII bytes.
     *
     * Mirrors charmbracelet/pop header encoding.
     */
    private function encodeHeaderWord(string $word): string
    {
        if (\preg_match('/[^\x00-\x7F]/', $word)) {
            return '=?UTF-8?B?' . \base64_encode($word) . '?=';
        }
        return $word;
    }

    /**
     * Determine Content-Transfer-Encoding for a body.
     * Returns '8bit' if the body contains non-ASCII bytes, else '7bit'.
     */
    private function cteFor(string $body): string
    {
        return \preg_match('/[^\x00-\x7F]/', $body) ? '8bit' : '7bit';
    }
}
