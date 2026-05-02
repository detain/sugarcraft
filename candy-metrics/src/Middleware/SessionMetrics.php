<?php

declare(strict_types=1);

namespace CandyCore\Metrics\Middleware;

use CandyCore\Metrics\Registry;
use CandyCore\Wish\Middleware;
use CandyCore\Wish\Session;

/**
 * CandyWish middleware that emits session telemetry.
 *
 * Per session:
 *
 *   - `wish.session.connect`    counter — total connect attempts
 *   - `wish.session.duration`   histogram — seconds the session was open
 *   - `wish.session.error`      counter — incremented if `\$next` threw
 *
 * Tags on every emit: `user`, `term`. Add more by passing
 * `extraTags` as a callable that receives the {@see Session} —
 * useful for stamping client subnet, geo, build version, etc.
 *
 * @phpstan-type ExtraTagsFn callable(Session): array<string,string>
 */
final class SessionMetrics implements Middleware
{
    /** @var (callable(Session): array<string,string>)|null */
    private $extraTags;

    /**
     * @param (callable(Session): array<string,string>)|null $extraTags
     */
    public function __construct(
        private readonly Registry $registry,
        ?callable $extraTags = null,
    ) {
        $this->extraTags = $extraTags;
    }

    public function handle(Session $session, callable $next): void
    {
        $tags = ['user' => $session->user, 'term' => $session->term];
        if ($this->extraTags !== null) {
            $tags = array_merge($tags, ($this->extraTags)($session));
        }
        $this->registry->counter('wish.session.connect', 1.0, $tags);
        $stop = $this->registry->time('wish.session.duration', $tags);

        try {
            $next($session);
        } catch (\Throwable $e) {
            $this->registry->counter('wish.session.error', 1.0, $tags + ['exception' => $e::class]);
            throw $e;
        } finally {
            $stop();
        }
    }
}
