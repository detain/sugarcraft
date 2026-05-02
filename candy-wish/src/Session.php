<?php

declare(strict_types=1);

namespace CandyCore\Wish;

/**
 * Per-connection session metadata exposed to middleware.
 *
 * In a CandyWish deployment the SSH protocol is handled by an
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
        public readonly string $user,
        public readonly string $clientHost,
        public readonly int    $clientPort,
        public readonly string $serverHost,
        public readonly int    $serverPort,
        public readonly string $term,
        public readonly int    $cols,
        public readonly int    $rows,
        public readonly ?string $tty,
        public readonly ?string $command,
        public readonly string $lang,
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
        $env = static fn(string $k): ?string =>
            (isset($_SERVER[$k]) && $_SERVER[$k] !== '')
                ? (string) $_SERVER[$k]
                : (getenv($k) === false || getenv($k) === '' ? null : (string) getenv($k));

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
     * @return array<string,string>
     */
    public function toLogContext(): array
    {
        return [
            'user'        => $this->user,
            'client_addr' => $this->clientHost . ':' . $this->clientPort,
            'term'        => $this->term,
            'tty'         => $this->tty ?? '-',
            'pty'         => $this->cols . 'x' . $this->rows,
            'command'     => $this->command ?? '',
        ];
    }
}
