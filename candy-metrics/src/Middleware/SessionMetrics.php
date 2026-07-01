<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Middleware;

use SugarCraft\Metrics\Registry;
use SugarCraft\Wish\Context;
use SugarCraft\Wish\Middleware;
use SugarCraft\Wish\Session;

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

    public function handle(Context $ctx, Session $session, callable $next)
    {
        $tags = ['user' => $session->user, 'term' => $session->term];
        if ($this->extraTags !== null) {
            $tags = array_merge($tags, ($this->extraTags)($session));
        }

        try {
            $this->registry->counter('wish.session.connect', 1.0, $tags);
        } catch (\Throwable) {
            // Registry failures must not prevent session handling.
        }

        $stop = null;
        try {
            $stop = $this->registry->time('wish.session.duration', $tags);
        } catch (\Throwable) {
            // Registry failures must not prevent session handling.
        }

        try {
            $next($ctx, $session);
        } catch (\Throwable $e) {
            try {
                $this->registry->counter('wish.session.error', 1.0, $tags + ['exception' => $e::class]);
            } catch (\Throwable) {
                // Registry failures must not suppress the original exception.
            }
            throw $e;
        } finally {
            if ($stop !== null) {
                try {
                    $stop();
                } catch (\Throwable) {
                    // Timer stop failures must not suppress the original exception.
                }
            }
        }
    }
}
