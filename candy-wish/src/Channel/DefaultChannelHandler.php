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
     * @param ChildSpawner|null $spawner  injected so `handleShell` can call `runChild()`
     * @param Session|null      $session  used to seed initial cols/rows when they're non-zero
     */
    public function __construct(
        private readonly ?ChildSpawner $spawner = null,
        ?Session $session = null,
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
     * @return array<string,string>|null
     */
    private function buildEnv(Session $session): ?array
    {
        if ($this->envVars === []) {
            return null;
        }
        $env = $this->envVars;
        $env['TERM'] = $session->term;
        $env['USER'] = $session->user;
        $env['LANG'] = $session->lang;
        return $env;
    }

    /**
     * Split a command string into argv tokens.
     *
     * Handles basic quoting (single and double) but does not perform
     * full shell parsing. Intended for parsing SSH exec command strings.
     *
     * @return list<string>
     */
    private static function parseCommandString(string $command): array
    {
        $tokens = [];
        $current = '';
        $inSingle = false;
        $inDouble = false;
        $i = 0;
        $len = \strlen($command);

        while ($i < $len) {
            $ch = $command[$i];

            if (!$inDouble && !$inSingle) {
                if ($ch === "'") {
                    $inSingle = true;
                } elseif ($ch === '"') {
                    $inDouble = true;
                } elseif ($ch === ' ') {
                    if ($current !== '') {
                        $tokens[] = $current;
                        $current = '';
                    }
                } else {
                    $current .= $ch;
                }
            } elseif ($inSingle && $ch === "'") {
                $inSingle = false;
            } elseif ($inDouble && $ch === '"') {
                $inDouble = false;
            } else {
                $current .= $ch;
            }
            $i++;
        }

        if ($current !== '') {
            $tokens[] = $current;
        }

        return $tokens;
    }
}
