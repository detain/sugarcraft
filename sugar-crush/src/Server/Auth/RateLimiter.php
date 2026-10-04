<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Server\Auth;

/**
 * Per-address throttling of failed authentication (Appendix O §8.2): after
 * {@see MAX_FAILURES} failures inside {@see WINDOW_SECONDS}, the address is
 * locked out for {@see LOCKOUT_SECONDS}. A 64-hex token is not guessable at
 * any rate; what this bounds is a login-code guesser and the log noise of a
 * misconfigured client hammering the port.
 */
final class RateLimiter
{
    public const MAX_FAILURES = 10;

    public const WINDOW_SECONDS = 60;

    public const LOCKOUT_SECONDS = 300;

    /** Addresses tracked at once; the stalest are dropped beyond this. */
    private const CAPACITY = 4096;

    /** @var array<string, list<float>> */
    private array $failures = [];

    /** @var array<string, float> */
    private array $lockedUntil = [];

    /** @param \Closure(): float $clock */
    private function __construct(private readonly \Closure $clock)
    {
    }

    /** @param (\Closure(): float)|null $clock */
    public static function new(?\Closure $clock = null): self
    {
        return new self($clock ?? static fn (): float => \microtime(true));
    }

    /** Seconds until $address may try again; 0 when it is not locked out. */
    public function retryAfter(string $address): int
    {
        $until = $this->lockedUntil[$address] ?? 0.0;
        $left = $until - ($this->clock)();
        if ($left <= 0) {
            unset($this->lockedUntil[$address]);

            return 0;
        }

        return (int) \ceil($left);
    }

    public function recordFailure(string $address): void
    {
        $now = ($this->clock)();
        $recent = \array_values(\array_filter(
            $this->failures[$address] ?? [],
            static fn (float $at): bool => $at > $now - self::WINDOW_SECONDS,
        ));
        $recent[] = $now;

        if (\count($recent) >= self::MAX_FAILURES) {
            $this->lockedUntil[$address] = $now + self::LOCKOUT_SECONDS;
            unset($this->failures[$address]);

            return;
        }

        unset($this->failures[$address]);
        $this->failures[$address] = $recent;
        if (\count($this->failures) > self::CAPACITY) {
            \array_shift($this->failures);
        }
    }
}
