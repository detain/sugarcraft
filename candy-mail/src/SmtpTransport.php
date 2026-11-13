<?php

declare(strict_types=1);

namespace SugarCraft\Mail;

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
 * Peer certificates are verified in both modes; the handshake itself carries
 * a TLS 1.2 protocol floor on every encrypted path (implicit connect and
 * STARTTLS upgrade alike) — legacy SSLv3/TLS 1.0/1.1 are never negotiated.
 * {@see withCaFile()} selects a
 * custom CA bundle. Credentials are never sent over an unencrypted channel:
 * AUTH refuses to start on a plaintext connection in every mode, and the
 * advertised SASL mechanisms are honoured — PLAIN is negotiated when offered,
 * LOGIN is the fallback; a list this client cannot speak is a refusal.
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
    /**
     * TEST-ONLY seam (CM-43): null consults extension_loaded('openssl') live;
     * false simulates the extension being absent so the named-refusal arms
     * are pinned without rebuilding PHP. Follows the ResendTransport $apiUrl
     * injection precedent.
     */
    private ?bool $assumeOpenssl = null;

    /** @var resource|null */
    private $socket = null;
    private string $lastResponse = '';
    /** Runtime flag: is the live channel currently encrypted? */
    private bool $encrypted = false;
    /**
     * Latch (CM-46): was the most recent session encrypted? Reset at the
     * start of every connect, raised by implicit TLS or a completed STARTTLS
     * upgrade, and deliberately NOT cleared on disconnect — the success
     * banner calls name() after quit() has closed the socket, and the
     * transport it reports must describe the channel the mail actually
     * travelled, not the current (gone) socket state.
     */
    private bool $sessionEncrypted = false;
    /** EHLO capability snapshot — lastResponse is clobbered by later replies. */
    private bool $serverSupports8Bit = false;
    /**
     * EHLO SIZE (RFC 1870) ceiling snapshot, same lifetime as the 8BITMIME
     * flag: null = not advertised (or SIZE 0 = unlimited). Checked against
     * the composed document BEFORE claiming DATA (CM-49).
     */
    private ?int $serverSizeLimit = null;

    /**
     * Protocol floor for every encrypted channel: TLS 1.2, with 1.3 eligible.
     * SSLv3/TLS 1.0/1.1 are refused by negotiation, never offered — a server
     * that can only speak them fails the handshake loudly (the point of
     * REQUIRE_TLS is that the channel IS encrypted to a modern standard).
     */
    private const TLS_MIN_PROTO = \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
    /** enable_crypto bitmask: TLS 1.2|1.3 only, not the legacy TLS_CLIENT auto set. */
    private const TLS_CRYPTO_METHODS = \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | \STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;

    /**
     * Construction-time input floors — one home for the bounds.
     *
     * The heloHost grammar is the precedent: a value the conversation cannot
     * legally carry is refused at construction, not garbled later by the
     * connect layer. The host grammar accepts RFC 1123 hostnames and IPv4
     * literals alike, each judged by character set; the CLI range arms are
     * thin wrappers over these validators so the bounds cannot drift apart.
     */
    public static function isValidHost(string $host): bool
    {
        return \preg_match('/^[A-Za-z0-9.\-]{1,253}$/', $host) === 1;
    }

    public static function isValidPort(int $port): bool
    {
        return $port >= 1 && $port <= 65535;
    }

    public static function isValidTimeout(int $timeout): bool
    {
        return $timeout >= 1 && $timeout <= 3600;
    }

    public function __construct(
        string $host,
        int $port = 587,
        string $username = '',
        #[\SensitiveParameter]
        string $password = '',
        int $timeout = 30,
        string $heloHost = '',
        ?bool $assumeOpenssl = null,
    ) {
        // heloHost is interpolated verbatim into
        // `EHLO {host}\r\n` — BEFORE STARTTLS and BEFORE AUTH — so a value
        // carrying one CRLF pair forges whole SMTP commands (MAIL FROM /
        // RCPT TO / DATA) on the protocol channel. The Message-ID path has
        // always filtered the same value (messageIdHost()); construction is
        // now the choke too: any heloHost outside the RFC 1123 hostname
        // grammar (letters, digits, '.', '-') that the EHLO argument may
        // legally carry is refused loudly. '' keeps the gethostname() default.
        if ($heloHost !== '' && \preg_match('/^[A-Za-z0-9.\-]{1,253}$/', $heloHost) !== 1) {
            throw new \InvalidArgumentException(Lang::t('smtp.invalid_helo_host', ['helo' => $heloHost]));
        }
        // Construction is the choke for every input the wire grammar
        // cannot recover from: an empty or illegal host used to surface as an
        // opaque stream_socket_client URL error, a zero timeout produced the
        // split infinite-connect/instant-read personality, and an out-of-range
        // port dialled garbage. Refuse all three loudly, named, here.
        if ($host === '' || !self::isValidHost($host)) {
            throw new \InvalidArgumentException(Lang::t('smtp.invalid_host', ['host' => $host]));
        }
        if (!self::isValidPort($port)) {
            throw new \InvalidArgumentException(Lang::t('smtp.invalid_port', ['port' => (string) $port]));
        }
        if (!self::isValidTimeout($timeout)) {
            throw new \InvalidArgumentException(Lang::t('smtp.invalid_timeout', ['timeout' => (string) $timeout]));
        }
        // Exactly one credential half is a configuration accident, not a
        // posture: authenticateIfNeeded() no-ops when EITHER half is empty,
        // so a lone username (or lone password) used to depart unauthenticated
        // while the operator believed AUTH would run. Both empty is the legal
        // anonymous relay posture and stands; a half pair is refused loudly.
        if (($username === '') !== ($password === '')) {
            throw new \InvalidArgumentException(Lang::t('smtp.incomplete_credentials'));
        }
        $this->host        = $host;
        $this->port        = $port;
        $this->username    = $username;
        $this->password    = $password;
        $this->timeout     = $timeout;
        $this->implicitTls = ($port === 465);
        // Secure by default: a send that cannot reach an
        // encrypted channel throws rather than leaking mail/credentials in
        // plaintext. Opt out explicitly with withRequireTls(false).
        $this->requireTls  = true;
        $this->heloHost    = $heloHost;
        $this->assumeOpenssl = $assumeOpenssl;
    }

    /**
     * Control whether the send fails when the channel cannot be encrypted.
     *
     * REQUIRED TLS is the constructor default: with the
     * flag on, a server that does not offer STARTTLS (or a failed upgrade)
     * throws instead of sending credentials/mail in the clear. Pass false to
     * opt back into opportunistic behaviour — trusted networks only.
     * Immutable per repo convention: yields a fresh, unconnected clone.
     *
     * CM-64 exemption from the house Mutable-trait pattern, recorded: the TLS
     * flags are deliberately not constructor parameters (a dialled host must
     * never inherit an implicit-TLS posture from a parsed port by accident),
     * so clone-and-reset is the fitting wither - mutate() would route session
     * state through the ctor's grammar gates it does not belong to.
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
     *
     * CM-64 exemption from the house Mutable-trait pattern, recorded: the TLS
     * flags are deliberately not constructor parameters (a dialled host must
     * never inherit an implicit-TLS posture from a parsed port by accident),
     * so clone-and-reset is the fitting wither - mutate() would route session
     * state through the ctor's grammar gates it does not belong to.
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
     * Mask secrets so the SMTP password never leaks through var_dump() or
     * print_r() reflection of live object state.
     *
     * Truth-flip: the old sentence claimed this alone
     * covered "logging that reflects object state" — false. var_export() and
     * serialize() read real properties, and stack traces read call arguments;
     * those channels are sealed separately, and one honest remainder exists:
     *  - var_dump/print_r            — masked by this method.
     *  - serialize()/unserialize()   — refused by __serialize() below.
     *  - var_export() resurrection   — refused by __set_state() below (the
     *    generated `__set_state` text can never be eval'd back into a live,
     *    credentialed transport).
     *  - stack/trace arguments       — redacted by #[\SensitiveParameter] on
     *    $password, even with zend.exception_ignore_args=0.
     *  - var_export($t, true) TEXT, the (array) cast, and
     *    ReflectionProperty::getValue — NOT sealable while the plaintext
     *    lives in a property: each reads real property bytes and no magic
     *    hook observes them. Treat such a dump like a dump of process
     *    memory; do not log it.
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
     * Refuse serialisation outright: a serialised transport is a
     * plaintext credential on disk or in a cache, and unserialize() of it
     * resurrected a live credentialed sender. Transports are cheap to build
     * where they are used; there is nothing worth persisting in one.
     *
     * @throws \RuntimeException always
     */
    public function __serialize(): array
    {
        throw new \RuntimeException(Lang::t('transport.not_serializable'));
    }

    /**
     * Refuse var_export() resurrection — see the __debugInfo contract
     * above for what this does and does not seal.
     *
     * @throws \RuntimeException always
     */
    public static function __set_state(array $properties): static
    {
        throw new \RuntimeException(Lang::t('transport.not_serializable'));
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

            // 8-bit bodies are only negotiable when
            // the server advertised 8BITMIME (RFC 6152). Without the
            // advertisement the envelope stays plain MAIL FROM and the
            // document is encoded 7-bit safe (quoted-printable text parts).
            $needs8Bit = $this->emailNeeds8Bit($email);
            $use8Bit   = $needs8Bit && $this->serverSupports8Bit;

            $this->sendMailFrom($email->from[0], $use8Bit);
            foreach ($email->allRecipients() as $rcpt) {
                $this->sendRcptTo($rcpt);
            }
            $this->sendData($email, $use8Bit || !$needs8Bit);
            $this->quit();
        } catch (\Throwable $e) {
            $this->disconnect();
            throw new \RuntimeException(Lang::t('smtp.send_failed', ['message' => $e->getMessage()]), 0, $e);
        }
    }

    public function name(): string
    {
        // The success banner says 'Email sent via {transport}' — reporting a
        // bare smtp:// after a send that actually rode TLS under-reports the
        // security of the channel it just certified (CM-46). Implicit mode
        // is known at construction; STARTTLS is only truthful after the
        // upgrade latched.
        if ($this->implicitTls) {
            return "smtps://{$this->host}:{$this->port}";
        }
        if ($this->sessionEncrypted) {
            return "smtp+starttls://{$this->host}:{$this->port}";
        }
        return "smtp://{$this->host}:{$this->port}";
    }

    // -------------------------------------------------------------------------
    // Connection lifecycle
    // -------------------------------------------------------------------------

    private function connect(): void
    {
        // Implicit TLS dials an ssl:// transport that does not exist without
        // ext-openssl: the failure used to surface as an opaque
        // stream_socket_client URL error. Name the remedy before the dial
        // (CM-43, mirroring the STARTTLS arm).
        if ($this->implicitTls && !$this->tlsExtensionAvailable()) {
            throw new \RuntimeException(Lang::t('smtp.openssl_missing'));
        }

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

        // The encrypted flag used to be set once and
        // never cleared — a second send() on the same instance skipped its
        // STARTTLS negotiation because the stale flag claimed the fresh
        // (plaintext) socket was already encrypted. Derive the flag from
        // THIS connection's mode on every connect; on implicit-TLS ports
        // (SMTPS/465) the handshake already completed inside
        // stream_socket_client(), so the channel is encrypted from byte one.
        $this->encrypted = $this->implicitTls;
        // CM-46: the banner latch starts from THIS connection's mode — a
        // second send() cannot inherit the first one's encrypted verdict.
        $this->sessionEncrypted = $this->implicitTls;

        $this->readResponse(220);

        // Identify with EHLO; fall back to HELO only if the server does not
        // know EHLO at all (RFC 5321 §4.1.4 — a legacy server answers HELO
        // with 250 and simply has no extension list). A HELO-only session
        // advertises nothing: STARTTLS and AUTH stay unavailable, so the
        // fail-closed REQUIRE_TLS / AUTH floors still refuse rather than
        // silently downgrade.
        $this->sendRaw("EHLO {$this->getHeloHost()}\r\n");
        try {
            $this->readResponse(250);
        } catch (\RuntimeException $e) {
            if (!$this->lastResponseIsPermanentRejection()) {
                throw $e; // 4yz transient / timeout etc. — report honestly
            }
            $this->sendRaw("HELO {$this->getHeloHost()}\r\n");
            $this->readResponse(250);
        }

        // Snapshot the 8BITMIME advertisement NOW
        // (RFC 6152). lastResponse is overwritten by the AUTH reply and by
        // later responses, so a capability read at MAIL FROM time would
        // inspect the wrong server text. (A HELO session has no extension
        // list; the snapshot just stays false.)
        $this->serverSupports8Bit = $this->hasExtension('8BITMIME');
        $this->serverSizeLimit    = $this->ehloSizeLimit();
    }

    /**
     * True when the last server reply is a 5xx permanent error — the one
     * case in which retrying the greeting as HELO is correct (RFC 5321 §4.1.4).
     */
    private function lastResponseIsPermanentRejection(): bool
    {
        return \preg_match('/^5[0-9][0-9]([ -])/', $this->lastResponse) === 1;
    }

    /**
     * Parse the RFC 1870 SIZE extension from the EHLO reply captured in
     * lastResponse: `250-SIZE 10485760` (the `SIZE=` shape too). Absent, or
     * the unlimited `SIZE 0` answer, yields null. A digit run too wide for
     * int is treated as unlimited, never wrapped.
     */
    private function ehloSizeLimit(): ?int
    {
        if (\preg_match('/^250[- ]SIZE[ =](\d+)/mi', $this->lastResponse, $m) !== 1) {
            return null;
        }
        if (\strlen($m[1]) > 18) {
            return null;
        }
        $limit = (int) $m[1];

        return $limit > 0 ? $limit : null;
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
            ] + self::sslFloorOptions();
            if ($this->caFile !== null) {
                $options['ssl']['cafile'] = $this->caFile;
            }
        }
        return $options;
    }

    /**
     * The ssl-context legs that carry the TLS 1.2 protocol floor. Shared by
     * the implicit-TLS connect context and the pre-STARTTLS option block so
     * both encrypted paths floor identically (assertable through either seam).
     *
     * @return array<string, int>
     */
    private static function sslFloorOptions(): array
    {
        return [
            'min_proto_version' => self::TLS_MIN_PROTO,
            'crypto_method'     => self::TLS_CRYPTO_METHODS,
        ];
    }

    /**
     * Is the TLS extension this transport needs actually present? The
     * CM-43 seam lets tests pin the named-refusal arms with the probe
     * forced false; production consults the live runtime.
     */
    private function tlsExtensionAvailable(): bool
    {
        return $this->assumeOpenssl ?? \extension_loaded('openssl');
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

        // A server that offers STARTTLS against a client whose TLS extension
        // is missing used to die inside enable_crypto with a raw
        // PHP-internal diagnostic. Name the missing extension — the same
        // posture ResendTransport takes for ext-curl (resend.curl_missing) —
        // and refuse BEFORE sending STARTTLS, so the relay never sees a
        // command we cannot honour.
        if (!$this->tlsExtensionAvailable()) {
            throw new \RuntimeException(Lang::t('smtp.openssl_missing'));
        }

        $this->sendRaw("STARTTLS\r\n");
        $this->readResponse(220);

        // Set SSL options on the socket BEFORE enabling crypto. Peer verification
        // stays ON — do not blanket-disable verify_peer.
        \stream_context_set_option($this->socket, 'ssl', 'verify_peer', true);
        \stream_context_set_option($this->socket, 'ssl', 'verify_peer_name', true);
        \stream_context_set_option($this->socket, 'ssl', 'peer_name', $this->host);
        foreach (self::sslFloorOptions() as $key => $value) {
            \stream_context_set_option($this->socket, 'ssl', $key, $value);
        }
        if ($this->caFile !== null) {
            \stream_context_set_option($this->socket, 'ssl', 'cafile', $this->caFile);
        }

        // Explicit 1.2|1.3 bitmask rather than the TLS_CLIENT catch-all: the
        // server must not be able to settle the upgrade on a legacy protocol.
        // The @ is deliberate, not laundering: a failed handshake makes PHP
        // emit the raw OpenSSL diagnostic (certificate-verify text built from
        // the PEER's chain) onto stderr — an untrusted-bytes egress the rest
        // of this transport refuses to have. The failure IS handled: the
        // return value below routes it into the named starttls_failed throw.
        $crypto = @\stream_socket_enable_crypto($this->socket, true, self::TLS_CRYPTO_METHODS);
        if ($crypto !== 1 && $crypto !== true) {
            // Success signal: the docs promise 1, but a blocking-stream
            // completion against an explicit protocol bitmask answers bool
            // true on current PHP/OpenSSL runtimes (pinned live by
            // TlsChannelTest — before that harness existed, requiring a strict
            // === 1 silently failed every real STARTTLS upgrade). 0 = more
            // data, false = failure; anything else fails loud so requireTls
            // semantics stay honest.
            throw new \RuntimeException(Lang::t('smtp.starttls_failed'));
        }
        $this->encrypted = true;
        // The upgrade happened — the banner latch keeps this verdict even
        // after quit()/disconnect clears the live flag (CM-46).
        $this->sessionEncrypted = true;

        // Re-EHLO after TLS so the post-upgrade extension list (e.g. AUTH) is fresh.
        $this->sendRaw("EHLO {$this->getHeloHost()}\r\n");
        $this->readResponse(250);

        // The plaintext EHLO's capability list is void after STARTTLS —
        // re-snapshot from the post-upgrade EHLO (servers may advertise MORE
        // inside TLS, never assume the pre-TLS answer carries over).
        $this->serverSupports8Bit = $this->hasExtension('8BITMIME');
        $this->serverSizeLimit    = $this->ehloSizeLimit();
    }

    private function authenticateIfNeeded(): void
    {
        if ($this->username === '' || $this->password === '') {
            return;
        }

        if (!$this->encrypted) {
            // AUTH LOGIN ships base64 (not encrypted)
            // credentials. A plaintext channel here is either a stripped
            // STARTTLS downgrade or an explicit withRequireTls(false) opt-out
            // — refuse either way rather than hand the network a password.
            throw new \RuntimeException(Lang::t('smtp.auth_insecure'));
        }

        // The old branch returned silently and sent
        // the message ANONYMOUSLY — a server (or MITM) that strips the AUTH
        // advertisement got an unauthenticated relay while the caller
        // believed it had logged in. Fail loud instead; RFC 5321 §2.3.1
        // makes AUTH an optional extension, so its absence is a policy
        // decision the transport must not make on the caller's behalf. The
        // refusal now covers BOTH shapes of that decision: no AUTH line at
        // all, and an advertisement carrying no mechanism this client speaks
        // (a blind-fire at a PLAIN-only or CRAM-MD5-only relay used to walk
        // into a mid-conversation 504 instead).
        $mechanisms = self::authMechanisms($this->lastResponse);
        if ($mechanisms === []) {
            throw new \RuntimeException(Lang::t('smtp.auth_unavailable', ['host' => $this->host]));
        }

        if (\in_array('PLAIN', $mechanisms, true)) {
            // RFC 4616: SASL PLAIN rides ONE command — base64 of
            // \0 authcid \0 password. Preferring it costs the relay a single
            // round instead of the LOGIN three-step, and it is the only
            // mechanism here that a PLAIN-only advertisement actually speaks.
            $token = \base64_encode("\0" . $this->username . "\0" . $this->password);
            $this->sendRaw("AUTH PLAIN {$token}\r\n");
            $this->readResponse(235); // Authentication successful
            return;
        }

        if (!\in_array('LOGIN', $mechanisms, true)) {
            // The advertisement named mechanisms this client does not
            // implement (CRAM-MD5, OAUTHBEARER, …). Refuse by name rather
            // than guess — auth_unavailable is the same loud floor.
            throw new \RuntimeException(Lang::t('smtp.auth_unavailable', ['host' => $this->host]));
        }

        // LOGIN-only advertisement: the legacy three-step stays the fallback.
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
        // Belt-braces: a closed channel is never an encrypted one.
        $this->encrypted = false;
        // Capability state belongs to the socket we just threw away.
        $this->serverSupports8Bit = false;
    }

    private function quit(): void
    {
        if ($this->socket === null) {
            return;
        }

        // By the time send() reaches quit() the message
        // is already queued (DATA answered 250). A protocol blip during the
        // farewell — server closes without 221, write to a half-closed socket —
        // used to propagate and latch a FALSE smtp.send_failed over a
        // successful delivery. QUIT is best-effort teardown; swallow its
        // errors and let the disconnect below be authoritative.
        try {
            $this->sendRaw("QUIT\r\n");
            $this->readResponse(221);
        } catch (\Throwable) {
            // deliberate: the message was accepted; the goodbye is optional
        }

        $this->disconnect();
    }

    // -------------------------------------------------------------------------
    // SMTP commands
    // -------------------------------------------------------------------------

    private function sendMailFrom(string $address, bool $declare8Bit = false): void
    {
        // RFC 6152: BODY=8BITMIME may only be asserted when the server
        // advertised the 8BITMIME extension (the flag is computed from the
        // EHLO snapshot by the caller). Envelopes are 7-bit by RFC 5321
        // §2.3.8 without it.
        $param = $declare8Bit ? ' BODY=8BITMIME' : '';
        $this->sendRaw("MAIL FROM:<{$this->bareAddr($address)}>{$param}\r\n");
        $this->readResponse(250);
    }

    private function sendRcptTo(string $address): void
    {
        $this->sendRaw("RCPT TO:<{$this->bareAddr($address)}>\r\n");
        // 251 (user not local; the relay forwards) is an accepted outcome at
        // this call-site alone; 252 and every other code stay refusals.
        $this->readResponseAny([250, 251]);
    }

    private function sendData(Email $email, bool $allow8Bit = true): void
    {
        // CM-49: compose the document BEFORE claiming DATA. A relay that
        // advertised an RFC 1870 SIZE ceiling used to learn the document
        // overshoots it only after paying the full 354 and streaming every
        // byte — refusal now happens while nothing oversized is on the wire.
        $mime = $this->dotStuff($this->buildMimeMessage($email, $allow8Bit));
        if ($this->serverSizeLimit !== null && \strlen($mime) > $this->serverSizeLimit) {
            throw new \RuntimeException(Lang::t('smtp.size_exceeded', [
                'bytes' => (string) \strlen($mime),
                'limit' => (string) $this->serverSizeLimit,
            ]));
        }

        $this->sendRaw("DATA\r\n");
        $this->readResponse(354);

        // buildMimeMessage() now terminates with the closing CRLF of the last
        // boundary line, so the dot-stuffer output goes straight out followed
        // only by the <CRLF>.<CRLF> terminator (RFC 5321 §4.1.1.4).
        $this->sendRaw($mime . ".\r\n");
        $this->readResponse(250);
    }

    /**
     * Does the message carry any non-ASCII text that would travel as 8-bit?
     * Base64 attachments are 7-bit-safe and never trigger this.
     */
    private function emailNeeds8Bit(Email $email): bool
    {
        $plain = $email->bodyWithSignature() ?? $email->body;
        foreach ([$plain, $email->htmlBody] as $text) {
            if ($text !== null && \preg_match('/[^\x00-\x7F]/', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Apply RFC 5321 §4.5.2 dot-stuffing: prefix each line starting with
     * a literal dot with an extra dot so it isn't interpreted as a terminator.
     *
     * No upstream surface to mirror: charmbracelet/pop delegates message
     * framing to go-mail, whose SMTP client stuffs internally - this port
     * owns the step, so the citation is the RFC.
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
     * closes the message: a second random boundary used to open the body
     * part, making every emitted message structurally invalid for strict
     * parsers.
     */
    public function buildMimeMessage(Email $email, bool $allow8Bit = true): string
    {
        $boundary = \bin2hex(\random_bytes(16));
        $lines = [];

        // Headers
        $lines = \array_merge($lines, $this->buildHeaders($email, $boundary));

        // Body
        $lines = \array_merge($lines, $this->buildBody($email, $boundary, $allow8Bit));

        // Attachments
        $lines = \array_merge($lines, $this->buildAttachments($email->attachments, $boundary));

        $lines[] = '--' . $boundary . '--';

        // RFC 2046 §5.1: the close-delimiter terminates a line — the document
        // ends with CRLF after the final "--", so consumers never append onto
        // the boundary token itself. (MIME edge tests used to pin the bare
        // "--boundary--" tail; the wire is identical because sendData() used
        // to bolt on the missing CRLF before the SMTP dot.)
        return \implode("\r\n", $lines) . "\r\n";
    }

    /**
     * Fold a header value so no physical line exceeds the RFC 5322 §2.1.1
     * limit of 998 content octets, breaking at existing spaces and prefixing
     * each continuation with one WSP (obs-fold, §2.2.3).
     */
    private static function foldHeader(string $field, string $value): string
    {
        if (\strlen($field) + 2 + \strlen($value) <= 998) {
            return "{$field}: {$value}";
        }

        // CM-69: folding happens at spaces only, so a whitespace-free token
        // longer than one line's budget cannot be represented — the old bytes
        // passed it through as a knowingly over-long line (obs-fold cannot
        // resplit it either). Refuse at build time, naming the field, before
        // anything reaches the wire. Splittable values keep the documented
        // soft-wrap behaviour. Budget 997 = 998 minus the continuation WSP.
        foreach (\explode(' ', $value) as $token) {
            if (\strlen($token) > 997) {
                throw new \InvalidArgumentException(Lang::t('email.header_too_long', ['field' => $field]));
            }
        }

        $lines   = [];
        $current = $field . ':';
        foreach (\explode(' ', $value) as $word) {
            // +1 for the joining space; never emit a fold before the first
            // word (an unsplittable single token stays long — folding it
            // mid-word would corrupt encoded-words).
            if (\strlen($current) + 1 + \strlen($word) > 998 && $current !== $field . ':') {
                $lines[]  = $current;
                $current  = ' ' . $word;
                continue;
            }
            $current .= ' ' . $word;
        }
        $lines[] = $current;

        return \implode("\r\n", $lines);
    }

    /**
     * Build MIME headers for the email.
     *
     * @return list<string>
     */
    private function buildHeaders(Email $email, string $boundary): array
    {
        $lines   = [];
        $lines[] = self::foldHeader('From', $this->addrListHeader($email->from));
        $lines[] = self::foldHeader('To', $this->addrListHeader($email->to));
        if ($email->cc !== []) {
            $lines[] = self::foldHeader('Cc', $this->addrListHeader($email->cc));
        }
        if ($email->subject !== null) {
            $lines[] = self::foldHeader('Subject', $this->encodeHeaderWord($email->subject));
        }
        $lines[] = 'Date: ' . \date('r');
        $lines[] = 'Message-ID: <' . \bin2hex(\random_bytes(16)) . '@' . $this->messageIdHost() . '>';
        $lines[] = "MIME-Version: 1.0";
        $lines[] = "Content-Type: multipart/mixed; boundary=\"{$boundary}\"";
        if ($email->replyTo !== null) {
            $lines[] = self::foldHeader('Reply-To', $this->addrListHeader([$email->replyTo]));
        }
        // Caller-supplied headers ride after the fixed set in insertion
        // order; foldHeader applies the same 998-octet discipline to them.
        foreach ($email->headers as $name => $value) {
            $lines[] = self::foldHeader($name, $value);
        }
        return $lines;
    }

    /**
     * Build MIME body parts (text and/or HTML).
     *
     * A lone HTML body used to be wrapped in an empty
     * multipart/alternative whose first sub-part carried no headers at all —
     * malformed under strict parsers. Single representations now emit as one
     * part; only the genuine text+HTML pair gets the alternative wrapper,
     * which declares its own boundary inside its own part header.
     *
     * $allow8Bit=false (server did not advertise 8BITMIME, RFC 6152) forces
     * 8-bit text onto quoted-printable so the whole document stays 7-bit safe.
     *
     * @return list<string>
     */
    private function buildBody(Email $email, string $boundary, bool $allow8Bit = true): array
    {
        $plain = $email->body !== null ? ($email->bodyWithSignature() ?? $email->body) : null;
        $html  = $email->htmlBody;

        if ($plain === null && $html === null) {
            return [];
        }

        if ($plain !== null && $html !== null) {
            $bodyBoundary = \bin2hex(\random_bytes(16));
            $lines        = ['', '--' . $boundary];
            $lines[]      = "Content-Type: multipart/alternative; boundary=\"{$bodyBoundary}\"";
            $lines[]      = '';
            $lines        = \array_merge($lines, $this->textPart('text/plain', $plain, $allow8Bit, $bodyBoundary));
            $lines        = \array_merge($lines, $this->textPart('text/html', $html, $allow8Bit, $bodyBoundary));
            $lines[]      = '--' . $bodyBoundary . '--';
            $lines[]      = '';
            return $lines;
        }

        $lines = ['', '--' . $boundary];

        return \array_merge(
            $lines,
            $this->textPart($plain !== null ? 'text/plain' : 'text/html', $plain ?? $html, $allow8Bit),
            [''],
        );
    }

    /**
     * One text/* part: optional boundary opener line, headers, then the body.
     *
     * @return list<string>
     */
    private function textPart(string $type, string $content, bool $allow8Bit, ?string $openerBoundary = null): array
    {
        $normalized = \preg_replace('/\r\n|\r/', "\n", $content);

        $lines = $openerBoundary !== null ? ['--' . $openerBoundary] : [];

        if (!$allow8Bit && \preg_match('/[^\x00-\x7F]/', $normalized) === 1) {
            // RFC 2045 §6.7: quoted-printable is the canonical 7-bit folding
            // for mostly-ASCII text; quoted_printable_encode() (bundled since
            // PHP 5.3) wraps at 76 octets with soft line breaks.
            $qp = \str_replace("\r\n", "\n", \quoted_printable_encode($normalized));

            return \array_merge(
                $lines,
                ["Content-Type: {$type}; charset=\"utf-8\"", 'Content-Transfer-Encoding: quoted-printable', ''],
                \explode("\n", \rtrim($qp, "\n")),
                [''],
            );
        }

        $lines[] = "Content-Type: {$type}; charset=\"utf-8\"";
        $lines[] = 'Content-Transfer-Encoding: ' . $this->cteFor($normalized);
        $lines[] = '';
        $lines   = \array_merge($lines, \explode("\n", $normalized));
        $lines[] = '';

        return $lines;
    }

    /**
     * RFC 2231 §4/§7 parameter form: ASCII values stay a plain quoted-string;
     * anything else rides `name*=charset''percent-encoded` next to an ASCII
     * compatibility copy (filenames are sanitized against CRLF at Attachment
     * construction, so only the charset case reaches the extended form).
     *
     * @return array{plain:string,extended:?string}
     */
    private static function mimeNameParams(string $attr, string $filename): array
    {
        if (\preg_match('/^[\x20-\x7E]*$/', $filename) === 1 && !\str_contains($filename, '"')) {
            // Plain quoted-string — but STILL through the escaper: a
            // backslash is legal printable ASCII and an unescaped one
            // swallows the closing quote (`report\` used to emit
            // filename="report\", letting the remainder of the parameter
            // list hide behind what is now an open quote, RFC 2045 §3).
            return ['plain' => "{$attr}=\"" . self::quoteMimeParam($filename) . '"', 'extended' => null];
        }

        $ascii   = \preg_replace('/[^\x21-\x7E]/u', '_', $filename)
                ?? \preg_replace('/[^\x21-\x7E]/', '_', $filename) ?? '';
        $escaped = self::quoteMimeParam($ascii);

        // rawurlencode keeps unreserved bytes and the RFC 3986 sub-delims
        // "!" "*" "(" ")" "'"; RFC 2231 attr-char excludes all of them, so
        // force them through percent-encoding too.
        $ext = \rawurlencode($filename);
        $ext = \str_replace(['!', '*', '(', ')', "'"], ['%21', '%2A', '%28', '%29', '%27'], $ext);

        return [
            'plain'    => "{$attr}=\"{$escaped}\"",
            'extended' => "{$attr}*=UTF-8''{$ext}",
        ];
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
            // The document is CRLF-delimited; bare LFs inside the base64 payload
            // (the old chunk_split glue) desynchronized strict line readers.
            $encoded = \chunk_split(\base64_encode($content), 76, "\r\n");

            // RFC 2045 quoted-string: CRLF is refused at Attachment construction
            // already; quotes and backslashes are escaped here so a
            // name like a"b.txt cannot corrupt the quoted parameter. Non-ASCII
            // names additionally ride the RFC 2231 extended form.
            $name = self::mimeNameParams('filename', $att->filename);
            $type = self::mimeNameParams('name', $att->filename);

            $typeParams = $type['extended'] !== null ? $type['plain'] . '; ' . $type['extended'] : $type['plain'];

            $headers = [
                "Content-Type: {$att->mimeType}; {$typeParams}",
                "Content-Transfer-Encoding: base64",
            ];

            $disposition = $att->cid !== null
                ? 'inline; ' . $name['plain']
                : 'attachment; ' . $name['plain'];
            if ($name['extended'] !== null) {
                $disposition .= ';' . "\r\n " . $name['extended'];
            }
            $headers[] = 'Content-Disposition: ' . $disposition;

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

    /** Reply-size caps enforced by the read loop below (CM-67: constants get their own line). */
    private const RESPONSE_LINE_MAX = 4096;
    private const RESPONSE_MAX_LINES = 100;

    private function readResponse(int $expectedCode): void
    {
        // Strict single-code wrapper over the accepted-list core.
        $this->readResponseAny([$expectedCode]);
    }

    /**
     * Loop core: read a (possibly multi-line) reply and demand one of the
     * accepted status codes.
     *
     * RFC 5321 §4.2.1: continuation lines use "<code>-text", the final line
     * "<code> text". All lines are captured into {@see $lastResponse} (joined
     * by "\n") so {@see hasExtension()} can inspect the full EHLO keyword
     * list — reading only the first line would hide advertised extensions
     * like STARTTLS. Every reply is bounded by the RESPONSE_* constants: no
     * line over RESPONSE_LINE_MAX bytes, no reply over RESPONSE_MAX_LINES
     * continuation lines, and the exchange must finish inside the configured
     * timeout. The bare fgets() this replaces grew an arbitrarily long
     * "line" in memory, and a slow 250- drip never tripped the per-read
     * stream timeout, so a hostile server could hold a send() open forever.
     * Each breach fails loud.
     *
     * RFC 5321 §4.1.2.5 makes RCPT the one command in this conversation
     * where a second success code is legal - 251 (user not local; the relay
     * will forward) confirms the recipient as loudly as 250 does. Every other
     * call-site (greeting, EHLO/HELO, STARTTLS, AUTH, MAIL FROM, DATA, QUIT)
     * stays strict single-code; only the RCPT caller widens the list.
     *
     * @param list<int> $accepted
     */
    private function readResponseAny(array $accepted): void
    {
        if ($this->socket === null) {
            throw new \RuntimeException(Lang::t('smtp.not_connected'));
        }

        $deadline = \microtime(true) + (float) $this->timeout;
        $lines    = [];
        do {
            // >= so a zero-length budget trips on the first iteration even when
            // the two clock reads round to the same float (~0.2 µs granularity).
            if (\microtime(true) >= $deadline) {
                throw new \RuntimeException(Lang::t('smtp.response_timeout'));
            }
            // Every read gets the REMAINING budget re-armed, not the full
            // timeout armed once at connect(): a server that dribbles one
            // line just under each stale deadline could hold a reply open
            // for RESPONSE_MAX_LINES x timeout — far past the budget the
            // operator named. Second granularity (stream_set_timeout takes
            // whole seconds); the loop-top deadline check stays the hard
            // bound, so a re-armed read never waits past deadline + 1s.
            \stream_set_timeout($this->socket, max(1, (int) \ceil($deadline - \microtime(true))));
            if (\count($lines) >= self::RESPONSE_MAX_LINES) {
                throw new \RuntimeException(Lang::t('smtp.response_too_long'));
            }
            // Ask for one byte over the cap: a full window without a newline
            // proves the line is longer than RESPONSE_LINE_MAX.
            $line = \stream_get_line($this->socket, self::RESPONSE_LINE_MAX + 1, "\n");
            if ($line === false) {
                // Two personalities wear the false mask: a real EOF (peer
                // closed without answering) and a timeout that delivered not
                // even one byte — on this platform the stall reads back as
                // false with timed_out set. The socket's confession tells
                // them apart; the old code called both 'no response'.
                $meta = @\stream_get_meta_data($this->socket);
                if (\is_array($meta) && ($meta['timed_out'] ?? false) === true) {
                    throw new \RuntimeException(Lang::t('smtp.response_timeout'));
                }
                throw new \RuntimeException(Lang::t('smtp.no_response'));
            }
            if ($line === '') {
                // Same confession on the empty-string arm (some PHP builds
                // report a stall as '' rather than false). A bare newline
                // that actually arrived (no timed_out) is protocol
                // noise and still classifies as empty_response.
                $meta = @\stream_get_meta_data($this->socket);
                if (\is_array($meta) && ($meta['timed_out'] ?? false) === true) {
                    throw new \RuntimeException(Lang::t('smtp.response_timeout'));
                }
                throw new \RuntimeException(Lang::t('smtp.empty_response'));
            }
            if (\strlen($line) > self::RESPONSE_LINE_MAX) {
                throw new \RuntimeException(Lang::t('smtp.response_too_long'));
            }
            $line     = \rtrim($line, "\r\n");
            $lines[]  = $line;
            // A hyphen in the 4th column marks a continuation; anything else ends it.
            $continue = \strlen($line) >= 4 && $line[3] === '-';
        } while ($continue);

        $this->lastResponse = \implode("\n", $lines);

        $firstCode = \substr($lines[0], 0, 3);
        foreach ($lines as $replyLine) {
            // RFC 5321 4.2: every line of a multi-line reply repeats the same
            // code. Recorded choice (CM-51): agreement is required with the
            // FIRST line's code — the accepted-set check below judges that
            // one. A continuation line wearing a different code ("250-..."
            // followed by "554 bye") is a forged conversation dressed as a
            // reply, so it refuses as unexpected_response rather than being
            // swallowed into lastResponse text.
            if (\substr($replyLine, 0, 3) !== $firstCode) {
                throw new \RuntimeException(Lang::t('smtp.unexpected_response', ['response' => $this->lastResponse]));
            }
        }

        $code = (int) \substr($lines[0], 0, 3);
        if (!\in_array($code, $accepted, true)) {
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

    /**
     * The EHLO AUTH advertisement parsed into its mechanism list.
     *
     * RFC 4954 §5.1 permits both capability shapes — `AUTH=mech mech` and
     * `AUTH mech mech` — on any continuation or final line. Walk the reply
     * with hasExtension()'s code+separator grammar (strip the 4-byte reply
     * prefix) and split the mechanism list on whitespace. A bare `AUTH`
     * keyword with no list, and the absence of AUTH entirely, both answer
     * []: there is nothing to negotiate, which is a refusal, not a guess.
     *
     * @return list<string>
     */
    private static function authMechanisms(string $reply): array
    {
        foreach (\explode("\n", $reply) as $line) {
            $keyword = \strtoupper(\trim(\substr($line, 4)));
            if (!\str_starts_with($keyword, 'AUTH')) {
                continue;
            }
            $rest = \substr($keyword, 4);
            if ($rest !== '' && $rest[0] !== '=' && $rest[0] !== ' ') {
                continue; // AUTHX-style word is not the AUTH keyword
            }
            $list = \trim(\ltrim($rest, "= \t"));
            if ($list === '') {
                continue; // bare AUTH with no mechanism list is nothing to negotiate
            }
            return \preg_split('/[ \t]+/', $list, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        }
        return [];
    }

    private function getHeloHost(): string
    {
        return $this->heloHost !== '' ? $this->heloHost : self::heloHostOrLocalhost((string) (\gethostname() ?: ''));
    }

    /**
     * Sanitise a machine-derived identity for the EHLO argument.
     *
     * The constructor rejects an operator-supplied heloHost outside the RFC
     * 1123 hostname grammar outright (fail loud at the boundary); a value
     * sourced from gethostname() is machine-controlled, so a weird hostname
     * must not brick every send — it falls back to the legal generic
     * 'localhost' instead. Either way the bytes reaching the wire after
     * "EHLO " match `^[A-Za-z0-9.\-]{1,253}$`: no CRLF smuggles a second
     * command into the greeting.
     */
    private static function heloHostOrLocalhost(string $candidate): string
    {
        return \preg_match('/^[A-Za-z0-9.\-]{1,253}$/', $candidate) === 1 ? $candidate : 'localhost';
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
     * The angle-addr is the TRAILING route (RFC 5322 §3.4). Anchoring the
     * match at the end keeps the envelope identical to what
     * formatAddressForHeader() displays and to what Email::sanitizeAddr()
     * already validated — the old FIRST-match scan could deliver "x@first"
     * for a value whose header line ended in "<y@second>".
     */
    private function bareAddr(string $addr): string
    {
        if (\preg_match('/<([^>]+)>\s*$/', $addr, $matches) === 1) {
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
            $displayName = $this->encodeHeaderWord(\trim($matches[1]));
            $emailAddr = $matches[2];
            return "{$displayName} <{$emailAddr}>";
        }
        return $addr;
    }

    /**
     * RFC 2047 encode a header value if it contains non-ASCII bytes.
     *
     * Tokens split on spaces; any token whose encoded-word would exceed the
     * RFC 2047 §2 75-character limit is emitted as several encoded-words
     * joined by a space (each carries <= 45 raw bytes, because the
     * "=?UTF-8?B??=" wrapper costs 12 chars and their base64 costs 60:
     * 12 + 60 = 72 <= 75). Long CJK subjects used to
     * ride as one 400-character word that RFC-compliant receivers reject.
     * ASCII values pass through untouched; folding is foldHeader()'s job.
     */
    private function encodeHeaderWord(string $word): string
    {
        if (\preg_match('/[^\x00-\x7F]/', $word) !== 1) {
            return $word;
        }

        $words = [];
        foreach (\explode(' ', $word) as $token) {
            if ($token === '' || \preg_match('/[^\x00-\x7F]/', $token) !== 1) {
                $words[] = $token;
                continue;
            }

            $chars = \preg_split('//u', $token, -1, \PREG_SPLIT_NO_EMPTY);
            if ($chars === false) {
                // Invalid UTF-8: no safe character walk exists — emit one
                // oversized word exactly as the old code did (best effort).
                $words[] = '=?UTF-8?B?' . \base64_encode($token) . '?=';
                continue;
            }

            $buffer = '';
            foreach ($chars as $char) {
                if (\strlen($buffer) + \strlen($char) > 45 && $buffer !== '') {
                    $words[] = '=?UTF-8?B?' . \base64_encode($buffer) . '?=';
                    $buffer  = '';
                }
                $buffer .= $char;
            }
            if ($buffer !== '') {
                $words[] = '=?UTF-8?B?' . \base64_encode($buffer) . '?=';
            }
        }

        return \implode(' ', $words);
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
