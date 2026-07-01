<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Channel;

use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Wish\Channel\Msg\BreakMsg;
use SugarCraft\Wish\Channel\Msg\EnvMsg;
use SugarCraft\Wish\Channel\Msg\ExecMsg;
use SugarCraft\Wish\Channel\Msg\PtyReqMsg;
use SugarCraft\Wish\Channel\Msg\ShellMsg;
use SugarCraft\Wish\Channel\Msg\SignalMsg;
use SugarCraft\Wish\Channel\Msg\WindowChangeMsg;
use SugarCraft\Wish\Session;
use SugarCraft\Wish\Transport\ChildSpawner;

/**
 * Default channel handler wiring the common case: PTY + shell.
 *
 * Tracks per-session channel state (PTY allocation, env vars, dims)
 * and drives a {@see ChildSpawner} when the shell or exec channel
 * request arrives. Signal and window-change messages are forwarded
 * into the active PTY master.
 *
 * Concrete transports inject themselves as a {@see ChildSpawner} at
 * construction time so the handler can call {@see runChild()} when
 * the shell or exec request fires.
 */
final class DefaultChannelHandler implements ChannelHandler
{
    private ?bool $ptyAllocated = null;

    private int $cols = 80;

    private int $rows = 24;

    /** @var array<string,string> */
    private array $envVars = [];

    /** @var list<string> */
    private array $pendingCommand = [];

    private bool $shellRequested = false;

    private bool $execRequested = false;

    /**
     * Known-dangerous env vars that are always excluded, even if
     * a client lists them in `$acceptEnv`.
     *
     * @var list<string>
     */
    private const DANGEROUS_VARS = [
        'LD_PRELOAD',
        'LD_LIBRARY_PATH',
        'BASH_ENV',
        'ENV',
        'IFS',
        'PATH',
        'SHELL',
    ];

    /**
     * @param ChildSpawner|null $spawner   injected so `handleShell` can call `runChild()`
     * @param Session|null      $session   used to seed initial cols/rows when they're non-zero
     * @param list<string>      $acceptEnv allowlist of client env-var names that may be
     *                                     passed to the child; empty (the default) means
     *                                     accept none beyond the floor vars
     */
    public function __construct(
        private readonly ?ChildSpawner $spawner = null,
        ?Session $session = null,
        private readonly array $acceptEnv = [],
    ) {
        if ($session !== null && $session->cols > 0) {
            $this->cols = $session->cols;
        }
        if ($session !== null && $session->rows > 0) {
            $this->rows = $session->rows;
        }
    }

    public function handlePtyReq(PtyReqMsg $msg, Session $session): void
    {
        $this->ptyAllocated = $msg->wantPty;
        if ($msg->wantPty) {
            $this->cols = $msg->cols > 0 ? $msg->cols : ($session->cols > 0 ? $session->cols : 80);
            $this->rows = $msg->rows > 0 ? $msg->rows : ($session->rows > 0 ? $session->rows : 24);
        }
    }

    public function handleWindowChange(WindowChangeMsg $msg, Session $session): void
    {
        $this->cols = $msg->cols > 0 ? $msg->cols : 80;
        $this->rows = $msg->rows > 0 ? $msg->rows : 24;
    }

    public function handleShell(ShellMsg $msg, Session $session): void
    {
        $this->shellRequested = $msg->wantShell;
        if ($msg->wantShell && $this->spawner !== null) {
            $this->spawnShell($session);
        }
    }

    public function handleExec(ExecMsg $msg, Session $session): void
    {
        $this->execRequested = true;
        $this->pendingCommand = self::parseCommandString($msg->command);
        if ($this->spawner !== null) {
            $this->spawnWithExec($session, $this->pendingCommand);
        }
    }

    public function handleSignal(SignalMsg $msg, Session $session): void
    {
        // Map RFC-4254 signal names to PHP SIG* constants.
        $map = [
            'INT'  => \SIGINT  ?? (\defined('SIGINT')  ? \SIGINT  : 2),
            'TERM' => \SIGTERM ?? (\defined('SIGTERM') ? \SIGTERM : 15),
            'HUP'  => \SIGHUP  ?? (\defined('SIGHUP')  ? \SIGHUP  : 1),
            'QUIT' => \SIGQUIT ?? (\defined('SIGQUIT') ? \SIGQUIT : 3),
            'KILL' => \SIGKILL ?? (\defined('SIGKILL') ? \SIGKILL : 9),
            'USR1' => \SIGUSR1 ?? (\defined('SIGUSR1') ? \SIGUSR1 : 10),
            'USR2' => \SIGUSR2 ?? (\defined('SIGUSR2') ? \SIGUSR2 : 12),
            'WINCH'=> \SIGWINCH?? (\defined('SIGWINCH')? \SIGWINCH: 28),
        ];

        $sig = $map[$msg->signalName] ?? null;
        if ($sig !== null) {
            $this->spawner?->signalChild($sig);
        }
    }

    public function handleEnv(EnvMsg $msg, Session $session): void
    {
        $this->envVars[$msg->name] = $msg->value;
    }

    public function handleBreak(BreakMsg $msg, Session $session): void
    {
    }

    public function cols(): int
    {
        return $this->cols;
    }

    public function rows(): int
    {
        return $this->rows;
    }

    public function ptyAllocated(): ?bool
    {
        return $this->ptyAllocated;
    }

    /**
     * @return array<string,string>
     */
    public function envVars(): array
    {
        return $this->envVars;
    }

    public function shellRequested(): bool
    {
        return $this->shellRequested;
    }

    public function execRequested(): bool
    {
        return $this->execRequested;
    }

    /**
     * @return list<string>
     */
    public function pendingCommand(): array
    {
        return $this->pendingCommand;
    }

    private function spawnShell(Session $session): void
    {
        if ($this->spawner === null) {
            return;
        }
        $env = $this->buildEnv($session);
        $cols = $this->cols > 0 ? $this->cols : 80;
        $rows = $this->rows > 0 ? $this->rows : 24;
        $sessColsRows = $session->cols > 0 ? $session->cols : $cols;
        $sessRows = $session->rows > 0 ? $session->rows : $rows;
        $effectiveSession = new Session(
            user: $session->user,
            clientHost: $session->clientHost,
            clientPort: $session->clientPort,
            serverHost: $session->serverHost,
            serverPort: $session->serverPort,
            term: $session->term,
            cols: $sessColsRows,
            rows: $sessRows,
            tty: $session->tty,
            command: $session->command,
            lang: $session->lang,
        );
        $this->spawner->runChild($effectiveSession, ['/bin/bash', '-l'], $env);
    }

    /**
     * @param list<string> $cmd
     */
    private function spawnWithExec(Session $session, array $cmd): void
    {
        if ($this->spawner === null) {
            return;
        }
        $env = $this->buildEnv($session);
        $this->spawner->runChild($session, $cmd, $env);
    }

    /**
     * Build the environment for the child process.
     *
     * Always returns a floor of safe vars (TERM, USER, LANG, PATH, HOME).
     * Client-supplied env vars are merged only if their names appear in
     * the $acceptEnv allowlist, and never includes known-dangerous vars
     * (LD_PRELOAD, LD_LIBRARY_PATH, BASH_ENV, ENV, IFS, PATH, SHELL)
     * even if explicitly allowlisted.
     *
     * @return array<string,string>
     */
    private function buildEnv(Session $session): array
    {
        // Start from the safe floor — never inherit the supervisor's env.
        $env = [
            'TERM'  => $session->term ?: 'xterm-256color',
            'USER'  => $session->user,
            'LANG'  => $session->lang ?: 'C.UTF-8',
            'PATH'  => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
            'HOME'  => '/home/' . $session->user,
        ];

        // Merge only allowlisted client vars; exclude dangerous ones unconditionally.
        foreach ($this->envVars as $name => $value) {
            if (\in_array($name, self::DANGEROUS_VARS, true)) {
                continue;
            }
            if (\in_array($name, $this->acceptEnv, true)) {
                $env[$name] = $value;
            }
        }

        return $env;
    }

    /**
     * Split a command string into argv tokens.
     *
     * Handles basic quoting (single and double) and backslash escapes
     * outside single quotes (consumes the next char literally so
     * `foo\ bar` → one token `foo bar`). Inside single quotes a
     * backslash is literal (POSIX). Empty quoted args (`cmd ''`) are
     * preserved as empty-string tokens.
     *
     * This is a pragmatic tokenizer, NOT full POSIX word-splitting —
     * no `$()`, backticks, variable expansion, or globbing. Tokens go
     * to `runChild()` as argv with no shell, so the divergence is safe
     * but documented.
     *
     * @return list<string>
     */
    private static function parseCommandString(string $command): array
    {
        $tokens = [];
        $current = '';
        $inSingle = false;
        $inDouble = false;
        $quoteOpened = false; // tracks whether current token had a quote open
        $i = 0;
        $len = \strlen($command);

        while ($i < $len) {
            $ch = $command[$i];

            if (!$inDouble && !$inSingle) {
                if ($ch === "'") {
                    $inSingle = true;
                    $quoteOpened = true;
                } elseif ($ch === '"') {
                    $inDouble = true;
                    $quoteOpened = true;
                } elseif ($ch === '\\') {
                    // Backslash escape outside quotes: consume next char literally.
                    $i++;
                    if ($i < $len) {
                        $current .= $command[$i];
                    }
                } elseif ($ch === ' ') {
                    if ($current !== '' || $quoteOpened) {
                        $tokens[] = $current;
                        $current = '';
                        $quoteOpened = false;
                    }
                } else {
                    $current .= $ch;
                }
            } elseif ($inSingle && $ch === "'") {
                // Inside single quotes, backslash is literal — no special handling.
                $inSingle = false;
            } elseif ($inDouble && $ch === '"') {
                // Emit empty-string token for `''` or `""` before clearing.
                if ($quoteOpened && $current === '') {
                    $tokens[] = '';
                }
                $inDouble = false;
                $quoteOpened = false;
            } else {
                $current .= $ch;
            }
            $i++;
        }

        if ($current !== '' || $quoteOpened) {
            $tokens[] = $current;
        }

        return $tokens;
    }
}
