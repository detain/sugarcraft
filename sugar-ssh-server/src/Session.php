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
    /** Geometry defaults matching the {@see fromEnvironment()} placeholders. */
    public const DEFAULT_COLS = 80;
    public const DEFAULT_ROWS = 24;

    /**
     * Upper bound on peer-declared geometry. AcceptEnv can carry any digit
     * string, so the spawn-time winsize is capped — see declaredSize().
     */
    public const MAX_DECLARED_COLS = 4096;
    public const MAX_DECLARED_ROWS = 4096;

    public function __construct(
        public readonly string  $user = '',
        public readonly string  $clientHost = '',
        public readonly int     $clientPort = 0,
        public readonly string  $serverHost = '',
        public readonly int     $serverPort = 0,
        public readonly string  $term = 'xterm-256color',
        public readonly int     $cols = self::DEFAULT_COLS,
        public readonly int     $rows = self::DEFAULT_ROWS,
        public readonly ?string $tty = null,
        public readonly ?string $command = null,
        public readonly string  $lang = 'C.UTF-8',
        public readonly ?string $sessionId = null,
        public readonly ?string $authMethod = null,
        public readonly ?string $keyFingerprint = null,
        public readonly ?string $clientVersion = null,
        public readonly ?string $serverVersion = null,
    ) {}

    /**
     * Clone-with-changes: the single rebuild site for this value object.
     *
     * Every `with*()` routes through here so the accumulated state
     * (`exitStatus`/`refused`) is carried STRUCTURALLY — the hand-carried
     * fifteen-arg rebuilds this class used to hold could silently drop any
     * field added later, which is the hazard the house `mutate()` rule
     * exists to close (see `SugarCraft\Core\Concerns\Mutable`; the trait
     * itself is not usable verbatim because `get_object_vars()` here would
     * leak the two private accumulators into the constructor spread).
     *
     * @param array<string,mixed> $overrides Constructor-argument overrides.
     */
    private function mutate(array $overrides = []): self
    {
        $copy = new self(...array_merge([
            'user'           => $this->user,
            'clientHost'     => $this->clientHost,
            'clientPort'     => $this->clientPort,
            'serverHost'     => $this->serverHost,
            'serverPort'     => $this->serverPort,
            'term'           => $this->term,
            'cols'           => $this->cols,
            'rows'           => $this->rows,
            'tty'            => $this->tty,
            'command'        => $this->command,
            'lang'           => $this->lang,
            'sessionId'      => $this->sessionId,
            'authMethod'     => $this->authMethod,
            'keyFingerprint' => $this->keyFingerprint,
            'clientVersion'  => $this->clientVersion,
            'serverVersion'  => $this->serverVersion,
        ], $overrides));
        // A hop that already settled a status keeps it: the number does
        // not travel back to null just because geometry or metadata
        // refreshed on the way down the stack.
        $copy->exitStatus = $this->exitStatus;
        $copy->refused    = $this->refused;
        return $copy;
    }

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
        // These reads are NOT cached. The comment that used to sit here
        // claimed a memo per key that this closure never had - and a snapshot
        // would be wrong anyway, because a gate later in the walk scrubs
        // credentials out of the same environment. (2026-11 audit)
        $env = static function(string $k): ?string {
            if (isset($_SERVER[$k]) && $_SERVER[$k] !== '') {
                return (string) $_SERVER[$k];
            }
            $v = getenv($k);
            return ($v === false || $v === '') ? null : (string) $v;
        };

        $conn = $env('SSH_CONNECTION') ?? $env('SSH_CLIENT') ?? '';
        $parts = $conn === '' ? [] : (preg_split('/\s+/', $conn) ?: []);
        $clientHost = $parts[0] ?? '';
        $clientPort = isset($parts[1]) ? (int) $parts[1] : 0;

        // The two shapes this library accepts are different lengths:
        // SSH_CONNECTION is `client-ip client-port server-ip server-port`,
        // SSH_CLIENT is `client-ip client-port server-port` - it has no
        // server-address field at all. Taking index 2 as a host unconditionally
        // stored the server's PORT in `serverHost` on every SSH_CLIENT-only
        // deployment and left `serverPort` at zero, so the audit fields were
        // wrong in exactly the two cases where they were populated at all.
        // (2026-11 audit)
        $serverHost = '';
        $serverPort = 0;
        if (count($parts) >= 4) {
            $serverHost = $parts[2];
            $serverPort = (int) $parts[3];
        } elseif (count($parts) === 3) {
            $serverPort = (int) $parts[2];
        }

        return new self(
            user:       $env('USER')   ?? $env('LOGNAME') ?? '',
            clientHost: $clientHost,
            clientPort: $clientPort,
            serverHost: $serverHost,
            serverPort: $serverPort,
            // A PTY needs a TERM, and nothing guarantees the peer sent one:
            // TERM reaches a ForceCommand child only through sshd's AcceptEnv,
            // which makes it asserted rather than observed. This value is
            // therefore an assumption about the client, not a fact about it.
            term:       $env('TERM')    ?? 'xterm-256color',
            cols:       self::declaredSize($env('COLUMNS'), self::DEFAULT_COLS, self::MAX_DECLARED_COLS),
            rows:       self::declaredSize($env('LINES'),   self::DEFAULT_ROWS, self::MAX_DECLARED_ROWS),
            tty:        $env('SSH_TTY'),
            command:    $env('SSH_ORIGINAL_COMMAND'),
            lang:       $env('LANG')    ?? 'C.UTF-8',
        );
    }

    /**
     * Geometry as the peer declared it, or the default - never zero.
     *
     * `COLUMNS=0`, and `COLUMNS=eighty` (which casts to 0), used to be handed
     * straight through to `Pty::spawn()`, where a zero-width PTY confuses
     * every client that asks its own terminal how big it is. A value that is
     * not a positive integer is treated as undeclared.
     *
     * The ceiling is the other half of the same hazard: `COLUMNS`/`LINES`
     * reach us through sshd `AcceptEnv`, so a peer (or a hostile wrapper)
     * can smuggle `COLUMNS=100000`, and row-buffer-hungry child TUIs then
     * allocate against a geometry nobody typed. Values above the maximum are
     * clamped — the channel stays disclosed in the README env table, the
     * amplification vector is capped here. (2026-11 audit, two findings)
     */
    private static function declaredSize(?string $value, int $default, int $max): int
    {
        if ($value === null || ! ctype_digit($value)) {
            return $default;
        }

        $size = (int) $value;

        return $size >= 1 ? \min($size, $max) : $default;
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
     * derived locally (2026-11 audit). What each of the five actually means:
     * `$sessionId` is minted per process (`bin2hex(random_bytes(16))`), not
     * issued by a daemon, so it correlates lines within one login and nothing
     * beyond it; `$authMethod` is sniffed from `SSH_USER_AUTH` where a wrapper
     * exported that; `$keyFingerprint` has no producer in this library today,
     * so a stack that wants pairing supplies it through `Auth`'s validator;
     * and the two version fields read environment names stock OpenSSH never
     * exports (2026-11 audit), which is why they arrive here null on an
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
        return $this->mutate([
            'sessionId'      => $sessionId,
            'authMethod'     => $authMethod,
            'keyFingerprint' => $keyFingerprint,
            'clientVersion'  => $clientVersion,
            'serverVersion'  => $serverVersion,
        ]);
    }

    /**
     * Create a new Session with a different terminal geometry,
     * carrying every other field — protocol metadata included
     * (parse-once/carry: rebuilds must never silently drop
     * sessionId/authMethod/versions).
     */
    public function withSize(int $cols, int $rows): self
    {
        return $this->mutate(['cols' => $cols, 'rows' => $rows]);
    }

    /**
     * Status the session accumulated while it was being served.
     *
     * Deliberately NOT one of the fifteen wire fields above, and
     * deliberately mutable: the transports hand ONE of these objects down
     * the middleware stack, and every `with*()` rebuild clones from the
     * wire fields, so a fluent `withExitStatus()` would be discarded by
     * the next hop. The 2026-11 refusal finding is exactly that failure - a refused
     * login used to answer `0`, so a denied `ssh host backup.sh`
     * 'succeeded' for the cron job and the CI step that ran it.
     */
    private ?int $exitStatus = null;

    private bool $refused = false;

    /**
     * The child's exit code once a spawner has run one, else null.
     *
     * Read side of the transport-only contract below: the value is written
     * by the transport loop as its child settles, never by request-path
     * decisions.
     */
    public function exitStatus(): ?int
    {
        return $this->exitStatus;
    }

    /**
     * Transport-only: called by the transport loop as its child settles
     * (see {@see \SugarCraft\SshServer\Transport\ChildSpawner}), which is
     * the only place a child's exit code is known.
     *
     * Kept public — the transports live in a sibling namespace and a
     * fluent setter would be discarded by the next stack hop — but that
     * is a forgiveness, not a permission: any middleware can set these
     * (forgery surface; see Security Considerations).
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
     *
     * Transport/deny-site API only — the legitimate writers are this
     * library's gates (Auth, PasswordAuth, KeyboardInteractive,
     * CertificateAuth, RateLimit, the channel handler's refusals) and your
     * own refusal points. Like recordExitStatus() above, it is public by
     * necessity, not invitation: a middleware that marks a session it did
     * not refuse forges the exit contract.
     */
    public function markRefused(): void
    {
        $this->refused = true;
    }

    /**
     * The fields a session-start/end record is made of.
     *
     * Before this method returned six keys (2026-11 audit): the three
     * protocol fields were dropped, which meant a log line could not be
     * tied back to an authentication decision at all — 'who got in, and
     * how' was unanswerable from the file, and the admin guide's
     * correlation advice pointed at a field that was never written.
     *
     * Every value here is a string, and '-' means 'not carried': the
     * guide's grep-able shapes are the same whether or not sshd delivered
     * the slot. Remember what the audit established about provenance — sessionId
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
            'command'         => $this->command ?? '-',
            'session'         => $this->sessionId ?? '-',
            'auth_method'     => $this->authMethod ?? '-',
            'key_fingerprint' => $this->keyFingerprint ?? '-',
        ];
    }
}
