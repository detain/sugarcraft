<?php

declare(strict_types=1);

namespace CandyCore\Wish;

/**
 * SSH session entry point.
 *
 * **Architecture.** Unlike upstream `charmbracelet/wish`, which
 * speaks the SSH wire protocol directly via `gliderlabs/ssh`,
 * CandyWish leans on the host's OpenSSH daemon: the operator
 * configures `sshd` to invoke a CandyWish-aware script via
 * `ForceCommand` (in `sshd_config`) or `command="..."` (in an
 * `authorized_keys` line). Each connection spawns a fresh PHP
 * process; this class is what that process instantiates.
 *
 * That trade buys us a battle-tested SSH implementation, key /
 * host management, fail2ban hooks, audit logs, and the existing
 * sshd CI surface — at the cost of being a stdin/stdout adapter
 * rather than a full SSH library. The middleware-stack programming
 * model is unchanged.
 *
 * **Lifecycle.**
 *
 *   1. `Server::new()` constructs an empty stack.
 *   2. `->use($middleware)` appends middleware in registration order.
 *   3. `->serve()` builds a {@see Session} from the SSH env vars,
 *      then walks the stack: each middleware decides whether to
 *      continue to `$next` or stop. The terminal middleware is
 *      typically {@see Middleware\BubbleTea} which mounts a
 *      CandyCore Program and runs until the user disconnects.
 *
 * **Example sshd snippet** (`/etc/ssh/sshd_config.d/wish.conf`):
 *
 * ```
 * Match User wishuser
 *     ForceCommand /usr/bin/php /opt/wish/server.php
 *     AllowTcpForwarding no
 *     PermitTTY yes
 * ```
 *
 * **Example `server.php`** (the per-connection entry):
 *
 * ```php
 * Server::new()
 *     ->use(new Logger())
 *     ->use(new Auth(['ed25519:AAAA...']))
 *     ->use(new BubbleTea(fn() => new MyApp()))
 *     ->serve();
 * ```
 */
final class Server
{
    /** @var list<Middleware> */
    private array $stack = [];

    public static function new(): self
    {
        return new self();
    }

    public function use(Middleware $m): self
    {
        $this->stack[] = $m;
        return $this;
    }

    /**
     * Build the {@see Session} from the current environment and
     * run the middleware chain. Returns when the chain finishes
     * (which, for a terminal {@see Middleware\BubbleTea}, is when
     * the user disconnects).
     */
    public function serve(?Session $session = null): void
    {
        $session ??= Session::fromEnvironment();
        $this->dispatch($session, 0);
    }

    private function dispatch(Session $session, int $idx): void
    {
        if ($idx >= count($this->stack)) {
            return;
        }
        $next = function (Session $s) use ($idx): void {
            $this->dispatch($s, $idx + 1);
        };
        $this->stack[$idx]->handle($session, $next);
    }
}
