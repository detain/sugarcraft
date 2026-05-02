<?php

declare(strict_types=1);

namespace CandyCore\Wish\Middleware;

use CandyCore\Wish\Middleware;
use CandyCore\Wish\Session;

/**
 * Terminal middleware that mounts a CandyCore Program for the
 * connected user.
 *
 * Pass a factory callable that returns the Program implementation
 * (the user's TUI). The factory receives the {@see Session} so it
 * can stamp connection metadata into the model. The Program's
 * `run()` method is invoked; control returns when the user
 * disconnects.
 *
 * The middleware does NOT call `\$next` — by design, this is the
 * end of the chain.
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
     * @param callable(Session): object $factory Returns an object with a `run()` method
     */
    public function __construct(callable $factory)
    {
        $this->factory = $factory;
    }

    public function handle(Session $session, callable $next): void
    {
        $program = ($this->factory)($session);
        if (!is_object($program) || !method_exists($program, 'run')) {
            throw new \RuntimeException(
                'BubbleTea factory must return an object with a run() method; got '
                . (is_object($program) ? $program::class : gettype($program))
            );
        }
        $program->run();
    }
}
