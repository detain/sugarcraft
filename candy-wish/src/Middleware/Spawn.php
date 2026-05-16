<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Middleware;

use SugarCraft\Wish\Lang;
use SugarCraft\Wish\Middleware;
use SugarCraft\Wish\Session;
use SugarCraft\Wish\Transport\ChildSpawner;

/**
 * Terminal middleware that spawns a child process inside the
 * active transport's PTY supervisor.
 *
 * Pass a factory callable that returns the spawn config from a
 * {@see Session}: `['cmd' => list<string>, 'env' => array<string,string>]`.
 * The factory runs at session-time so the cmd / env can be tailored
 * per user (`HOME`/`USER` / login shell selection / env scrubbing).
 *
 * Only works under {@see \SugarCraft\Wish\Transport\InProcessTransport}.
 * The transport injects itself via duck-typed `setTransport` at
 * stack-walk time. If the active transport has no PTY supervisor
 * (HostSshd legacy mode), `handle()` throws at session-time —
 * migrate to {@see BubbleTea} (which works under HostSshd) or
 * switch the Server to `withTransport(new InProcessTransport())`.
 *
 * The PTY backend itself is injectable on the transport (see plan
 * P4.2): `new InProcessTransport($stubPtySystem)` swaps out the
 * libc surface for tests without any change to this middleware.
 *
 * Like {@see BubbleTea}, this middleware does NOT call `$next` —
 * it's the end of the chain by design.
 *
 * Example:
 * ```php
 * Server::new()
 *     ->use(new Logger())
 *     ->use(new Auth(['alice', 'bob']))
 *     ->use(new Spawn(fn (Session $s) => [
 *         'cmd' => ['/bin/bash', '-l'],
 *         'env' => [
 *             'TERM' => $s->term,
 *             'USER' => $s->user,
 *             'HOME' => "/home/{$s->user}",
 *             'PATH' => '/usr/local/bin:/usr/bin:/bin',
 *         ],
 *     ]))
 *     ->serve();
 * ```
 */
final class Spawn implements Middleware
{
    /** @var callable(Session): array */
    private $factory;

    private ?ChildSpawner $transport = null;

    /**
     * @param callable(Session): array{cmd: list<string>, env?: array<string,string>} $factory
     */
    public function __construct(callable $factory)
    {
        $this->factory = $factory;
    }

    /**
     * Duck-typed seam invoked by InProcessTransport at stack-walk
     * time so this middleware doesn't have to be aware of which
     * transport drives the Server. Production code never calls
     * this directly.
     */
    public function setTransport(ChildSpawner $transport): void
    {
        $this->transport = $transport;
    }

    public function handle(Session $session, callable $next): void
    {
        if ($this->transport === null) {
            throw new \RuntimeException(Lang::t('spawn.no_transport'));
        }

        $cfg = ($this->factory)($session);
        if (!\is_array($cfg)) {
            throw new \InvalidArgumentException(Lang::t('spawn.bad_factory_return', [
                'got' => \gettype($cfg),
            ]));
        }
        if (!isset($cfg['cmd']) || !\is_array($cfg['cmd']) || $cfg['cmd'] === []) {
            throw new \InvalidArgumentException(Lang::t('spawn.bad_cmd'));
        }

        /** @var list<string> $cmd */
        $cmd = $cfg['cmd'];
        $env = isset($cfg['env']) && \is_array($cfg['env']) ? $cfg['env'] : null;

        $this->transport->runChild($session, $cmd, $env);

        // Spawn is terminal — do not invoke $next.
    }
}
