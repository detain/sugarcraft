<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Middleware;

use SugarCraft\Wish\Context;
use SugarCraft\Wish\Lang;
use SugarCraft\Wish\Middleware;
use SugarCraft\Wish\Session;
use SugarCraft\Wish\Transport\ChildSpawner;

/**
 * Terminal middleware that mounts a SugarCraft Program for the
 * connected user — the legacy HostSshd-style entry point.
 *
 * Pass a factory callable that returns the Program implementation
 * (the user's TUI). The factory receives the {@see Session} so it
 * can stamp connection metadata into the model. The Program's
 * `run()` method is invoked attached to the supervisor's STDIN/
 * STDOUT (the slave side of sshd's PTY); control returns when the
 * user disconnects.
 *
 * The middleware does NOT call `\$next` — by design, this is the
 * end of the chain.
 *
 * **Transport compatibility.** `BubbleTea` only works under
 * {@see \SugarCraft\Wish\Transport\HostSshdTransport}. The new
 * default {@see \SugarCraft\Wish\Transport\InProcessTransport}
 * pumps bytes between supervisor stdio and a candy-pty master, so
 * mounting a Program inline in the supervisor process would
 * collide with the pump. Migration paths from in-process mode:
 *
 *   1. **Switch the Server back to HostSshd**:
 *      `Server::new()->withTransport(new HostSshdTransport())->use(new BubbleTea(...))`.
 *      Keeps the pre-PTY-upgrade behaviour, no subprocess overhead.
 *
 *   2. **Use Spawn middleware** with a tiny wrapper script that
 *      runs the Program: `Server::new()->use(new Spawn(fn () => ['cmd' => ['php', 'run-program.php']]))`.
 *      Lets you keep the in-process default while still mounting
 *      a SugarCraft Program — at the cost of subprocess startup.
 *
 * If `BubbleTea::handle()` runs under InProcessTransport (detected
 * via the duck-typed `setTransport` injection that InProcess
 * performs at stack-walk time), it throws a clear error pointing
 * at both migration paths.
 *
 * `$factory` is typed as `callable` rather than tying us to a
 * specific Program subclass so the same middleware works with any
 * stand-in (mock, alternative model, etc.) — useful for tests
 * that don't want to drag in a full bubble-tea cycle.
 */
final class BubbleTea implements Middleware
{
    /** @var callable(Session): object */
    private $factory;

    /**
     * Set true when the active transport is InProcessTransport
     * (which injects itself via `setTransport` at stack-walk time).
     * Triggers the migration-error path in `handle()`.
     */
    private bool $inProcessMode = false;

    /**
     * @param callable(Session): object $factory Returns an object with a `run()` method
     */
    public function __construct(callable $factory)
    {
        $this->factory = $factory;
    }

    /**
     * Duck-typed seam invoked by InProcessTransport at stack-walk
     * time. The mere presence of an injected ChildSpawner means
     * we're under in-process mode and inline Program execution
     * would conflict with the bytes-pump loop — so flag it for
     * `handle()` to refuse.
     */
    public function setTransport(ChildSpawner $transport): void
    {
        $this->inProcessMode = true;
    }

    public function handle(Context $ctx, Session $session, callable $next): void
    {
        if ($this->inProcessMode) {
            throw new \RuntimeException(Lang::t('bubbletea.requires_host_sshd'));
        }

        $program = ($this->factory)($session);
        if (!\is_object($program) || !\method_exists($program, 'run')) {
            throw new \RuntimeException(Lang::t('bubbletea.bad_factory', [
                'got' => \is_object($program) ? $program::class : \gettype($program),
            ]));
        }
        $program->run();
    }
}
