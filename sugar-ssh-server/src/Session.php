<?php

declare(strict_types=1);

namespace SugarCraft\SshServer;

/**
 * Per-connection session metadata exposed to middleware.
 *
 * In a SugarSshServer deployment the SSH protocol is handled by an
 * external `sshd` (OpenSSH) and the PHP entry point runs once per
 * connection via `ForceCommand` (or an `authorized_keys` line). The
 * SSH daemon hands us standard environment variables — username,
 * client IP / port, the requested PTY dimensions — which this
 * Session class normalises so every middleware sees the same
 * shape regardless of how the user authenticated.
 *
 * Construct via {@see fromEnvironment()} (reads `\$_SERVER` /
 * `getenv()`) or directly with explicit values for tests.
 *
 * @phpstan-type Env array{
 *     SSH_CONNECTION?: string,
 *     SSH_CLIENT?: string,
 *     SSH_TTY?: string,
 *     USER?: string,
 *     LOGNAME?: string,
 *     LANG?: string,
 *     TERM?: string,
 *     COLUMNS?: string,
 *     LINES?: string,
 *     SSH_AUTH_SOCK?: string,
 *     SSH_ORIGINAL_COMMAND?: string,
 * }
 */
final class Session
{
    public function __construct(
        public readonly string  $user,
        public readonly string  $clientHost,
        public readonly int     $clientPort,
        public readonly string  $serverHost,
        public readonly int     $serverPort,
        public readonly string  $term,
        public readonly int     $cols,
        public readonly int     $rows,
        public readonly ?string $tty,
        public readonly ?string $command,
        public readonly string  $lang,
        public readonly ?string $sessionId = null,
        public readonly ?string $authMethod = null,
        public readonly ?string $keyFingerprint = null,
        public readonly ?string $clientVersion = null,
        public readonly ?string $serverVersion = null,
    ) {}

    /**
     * Read the current sshd environment and build a Session.
     *
     * Handles the standard OpenSSH env shape:
     *   - `SSH_CONNECTION` = "<client_ip> <client_port> <server_ip> <server_port>"
     *   - `SSH_CLIENT`     = "<client_ip> <client_port> <server_port>" (older)
     *   - `USER` / `LOGNAME` for the authenticated user
     *   - `SSH_TTY`, `SSH_ORIGINAL_COMMAND`, `LANG`, `TERM`, `COLUMNS`, `LINES`
     *
     * Missing values default to safe placeholders rather than
     * raising — the caller can decide whether to refuse a
     * malformed session.
     */
    public static function fromEnvironment(): self
    {
        // Cache getenv result to avoid calling it multiple times per key.
        // getenv() queries the system environment which may involve
        // syscall overhead on each invocation.
        $env = static function(string $k): ?string {
            if (isset($_SERVER[$k]) && $_SERVER[$k] !== '') {
                return (string) $_SERVER[$k];
            }
            $v = getenv($k);
            return ($v === false || $v === '') ? null : (string) $v;
        };

        $conn = $env('SSH_CONNECTION') ?? $env('SSH_CLIENT') ?? '';
        $parts = preg_split('/\s+/', $conn) ?: [];
        $clientHost = $parts[0] ?? '';
        $clientPort = isset($parts[1]) ? (int) $parts[1] : 0;
        $serverHost = $parts[2] ?? '';
        $serverPort = isset($parts[3]) ? (int) $parts[3] : 0;

        return new self(
            user:       $env('USER')   ?? $env('LOGNAME') ?? '',
            clientHost: $clientHost,
            clientPort: $clientPort,
            serverHost: $serverHost,
            serverPort: $serverPort,
            term:       $env('TERM')    ?? 'xterm-256color',
            cols:       (int) ($env('COLUMNS') ?? '80'),
            rows:       (int) ($env('LINES')   ?? '24'),
            tty:        $env('SSH_TTY'),
            command:    $env('SSH_ORIGINAL_COMMAND'),
            lang:       $env('LANG')    ?? 'C.UTF-8',
        );
    }

    public function isInteractive(): bool
    {
        return $this->tty !== null && $this->tty !== '';
    }

    /**
     * Create a new Session with protocol metadata populated.
     *
     * There is no handshake to wait for: this library speaks no SSH, so the
     * transports call this once, on the way into the stack, with values they
     * derived locally (audit MAJOR 3). What each of the five actually means:
     * `$sessionId` is minted per process (`bin2hex(random_bytes(16))`), not
     * issued by a daemon, so it correlates lines within one login and nothing
     * beyond it; `$authMethod` is sniffed from `SSH_USER_AUTH` where a wrapper
     * exported that; `$keyFingerprint` has no producer in this library today,
     * so a stack that wants pairing supplies it through `Auth`'s validator;
     * and the two version fields read environment names stock OpenSSH never
     * exports (audit MAJOR 21), which is why they arrive here null on an
     * ordinary host. Middleware sees the fields either way — an empty column
     * is information about the delivery, not a broken reader.
     */
    public function withProtocolMetadata(
        ?string $sessionId,
        ?string $authMethod,
        ?string $keyFingerprint,
        ?string $clientVersion,
        ?string $serverVersion,
    ): self {
        $copy = new self(
            user:             $this->user,
            clientHost:       $this->clientHost,
            clientPort:       $this->clientPort,
            serverHost:       $this->serverHost,
            serverPort:       $this->serverPort,
            term:             $this->term,
            cols:             $this->cols,
            rows:             $this->rows,
            tty:              $this->tty,
            command:          $this->command,
            lang:             $this->lang,
            sessionId:        $sessionId,
            authMethod:        $authMethod,
            keyFingerprint:   $keyFingerprint,
            clientVersion:    $clientVersion,
            serverVersion:    $serverVersion,
        );
        // A hop that already settled a status keeps it: the number does
        // not travel back to null just because geometry or metadata
        // refreshed on the way down the stack.
        $copy->exitStatus = $this->exitStatus;
        $copy->refused    = $this->refused;
        return $copy;
    }

    /**
     * Create a new Session with a different terminal geometry,
     * carrying every other field — protocol metadata included
     * (parse-once/carry: rebuilds must never silently drop
     * sessionId/authMethod/versions).
     */
    public function withSize(int $cols, int $rows): self
    {
        $copy = new self(
            user:             $this->user,
            clientHost:       $this->clientHost,
            clientPort:       $this->clientPort,
            serverHost:       $this->serverHost,
            serverPort:       $this->serverPort,
            term:             $this->term,
            cols:             $cols,
            rows:             $rows,
            tty:              $this->tty,
            command:          $this->command,
            lang:             $this->lang,
            sessionId:        $this->sessionId,
            authMethod:       $this->authMethod,
            keyFingerprint:   $this->keyFingerprint,
            clientVersion:    $this->clientVersion,
            serverVersion:    $this->serverVersion,
        );
        // A hop that already settled a status keeps it: the number does
        // not travel back to null just because geometry or metadata
        // refreshed on the way down the stack.
        $copy->exitStatus = $this->exitStatus;
        $copy->refused    = $this->refused;
        return $copy;
    }

    /**
     * Status the session accumulated while it was being served.
     *
     * Deliberately NOT one of the fifteen wire fields above, and
     * deliberately mutable: the transports hand ONE of these objects down
     * the middleware stack, and every `with*()` rebuild clones from the
     * wire fields, so a fluent `withExitStatus()` would be discarded by
     * the next hop. Audit MAJOR 9 is exactly that failure - a refused
     * login used to answer `0`, so a denied `ssh host backup.sh`
     * 'succeeded' for the cron job and the CI step that ran it.
     */
    private ?int $exitStatus = null;

    private bool $refused = false;

    /**
     * The child's exit code once a spawner has run one, else null.
     */
    public function exitStatus(): ?int
    {
        return $this->exitStatus;
    }

    /**
     * Called by a transport as its child settles (see
     * {@see \SugarCraft\SshServer\Transport\ChildSpawner}).
     */
    public function recordExitStatus(int $status): void
    {
        $this->exitStatus = $status;
    }

    /**
     * True when a middleware stopped this session rather than serving it.
     *
     * Set by the gates this library ships - Auth, PasswordAuth,
     * KeyboardInteractive, CertificateAuth, RateLimit - and available to
     * your own, so 'the chain ended' and 'the chain refused' stay
     * distinguishable all the way to the exit code.
     */
    public function refused(): bool
    {
        return $this->refused;
    }

    /**
     * Say that this session was refused, so the process can exit nonzero.
     *
     * Asking for the wrong name, presenting a key that pairs with nobody,
     * exhausting a token bucket and typing a bad answer all mean the same
     * thing to a caller reading `$?`: this session did not run. Marking
     * them once at the deny site is the difference between a CI step that
     * notices and one that logs a green check.
     */
    public function markRefused(): void
    {
        $this->refused = true;
    }

    /**
     * The fields a session-start/end record is made of.
     *
     * Before this method returned six keys (audit MAJOR 3): the three
     * protocol fields were dropped, which meant a log line could not be
     * tied back to an authentication decision at all — 'who got in, and
     * how' was unanswerable from the file, and the admin guide's
     * correlation advice pointed at a field that was never written.
     *
     * Every value here is a string, and '-' means 'not carried': the
     * guide's grep-able shapes are the same whether or not sshd delivered
     * the slot. Remember what K6 established about provenance — sessionId
     * is minted per process by the transport, authMethod is sniffed from
     * `SSH_USER_AUTH` where a wrapper exported it, and keyFingerprint has
     * no producer in this library today.
     *
     * @return array<string,string>
     */
    public function toLogContext(): array
    {
        return [
            'user'            => $this->user,
            'client_addr'     => $this->clientHost . ':' . $this->clientPort,
            'term'            => $this->term,
            'tty'             => $this->tty ?? '-',
            'pty'             => $this->cols . 'x' . $this->rows,
            'command'         => $this->command ?? '',
            'session'         => $this->sessionId ?? '-',
            'auth_method'     => $this->authMethod ?? '-',
            'key_fingerprint' => $this->keyFingerprint ?? '-',
        ];
    }
}
