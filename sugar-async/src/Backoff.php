<?php

declare(strict_types=1);

namespace SugarCraft\Async;

/**
 * One pure backoff law: exponential ceiling, optional full jitter, and a
 * server-supplied hint that outranks both.
 *
 * The schedule is a CEILING — `min(capMs, baseMs * factor^(n-1))` — and
 * {@see withJitter()} turns what a caller actually waits into a uniform draw
 * over `[0, ceiling]` ("full jitter": the draw, not a +/- band, is what
 * decorrelates a fleet retrying in lockstep). A {@see serverHint()} (a
 * `Retry-After`) wins outright and is NEVER jittered: the server named the
 * instant it wants us back, and jittering below it would be a rude retry while
 * jittering above it would be a pointless one.
 *
 * Attempts are 1-based: `forAttempt(1)` is the wait after the FIRST failure.
 * Callers that count from zero pass `$n + 1`.
 *
 * Pure means no clock, no sleep, no loop: the draw source is an injectable
 * `callable(int $max): int`, so a test pins the edges without touching a seed.
 * {@see AsyncOps::retry()} keeps its own seconds/fraction jitter for
 * compatibility; this is the law new seats (provider retry, redial, budget
 * polls) share.
 */
final class Backoff
{
    /** @var (callable(int): int)|null */
    private $rng;

    /**
     * @param (callable(int): int)|null $rng
     */
    private function __construct(
        public readonly float $baseMs,
        public readonly float $factor,
        public readonly ?int $capMs,
        public readonly bool $jitter,
        public readonly ?float $hintSeconds,
        public readonly ?int $attempts,
        ?callable $rng,
    ) {
        $this->rng = $rng;
    }

    /**
     * @param float $baseMs  Ceiling of the wait after the first failure (ms, > 0)
     * @param float $factor  Growth per further failure (>= 1; 1.0 is a fixed tick)
     * @param int|null $capMs  Ceiling of the ceiling; null is uncapped
     */
    public static function new(float $baseMs, float $factor = 2.0, ?int $capMs = null): self
    {
        if ($baseMs <= 0 || !is_finite($baseMs)) {
            throw new \InvalidArgumentException('Base must be a positive finite number of milliseconds');
        }
        if ($factor < 1.0 || !is_finite($factor)) {
            throw new \InvalidArgumentException('Factor must be a finite number >= 1');
        }
        if ($capMs !== null && $capMs < 0) {
            throw new \InvalidArgumentException('Cap must be >= 0 when set');
        }

        return new self($baseMs, $factor, $capMs, false, null, null, null);
    }

    /**
     * Wait a uniform draw over `[0, ceiling]` instead of the ceiling itself.
     *
     * @param (callable(int): int)|null $rng  Draw source: given the ceiling in
     *        ms, returns an int. Clamped into `[0, ceiling]` whatever it
     *        returns. Null draws via random_int() (CSPRNG — does not touch
     *        mt_srand() state).
     */
    public function withJitter(?callable $rng = null): self
    {
        return new self($this->baseMs, $this->factor, $this->capMs, true, $this->hintSeconds, $this->attempts, $rng);
    }

    /** Back to the deterministic ceiling. */
    public function withoutJitter(): self
    {
        return new self($this->baseMs, $this->factor, $this->capMs, false, $this->hintSeconds, $this->attempts, null);
    }

    /**
     * The server's own instruction (seconds, e.g. a parsed Retry-After). While
     * set, {@see forAttempt()} returns it un-jittered and un-capped by
     * $capMs — clamping a server's instruction is the caller's policy, applied
     * before it gets here. Null clears it.
     */
    public function serverHint(?float $seconds): self
    {
        if ($seconds !== null && ($seconds < 0 || !is_finite($seconds))) {
            throw new \InvalidArgumentException('Server hint must be a finite number of seconds >= 0');
        }

        return new self($this->baseMs, $this->factor, $this->capMs, $this->jitter, $seconds, $this->attempts, $this->rng);
    }

    /**
     * Total tries allowed; null (the default) retries forever. See
     * {@see allows()}.
     */
    public function withAttempts(?int $attempts): self
    {
        if ($attempts !== null && $attempts < 1) {
            throw new \InvalidArgumentException('Attempts must be >= 1 when set');
        }

        return new self($this->baseMs, $this->factor, $this->capMs, $this->jitter, $this->hintSeconds, $attempts, $this->rng);
    }

    /**
     * Whether a retry may follow the $n-th failure: with N total attempts the
     * N-th failure is final. Always true for the null (forever) budget.
     */
    public function allows(int $n): bool
    {
        return $this->attempts === null || $n < $this->attempts;
    }

    /** The deterministic ceiling after the $n-th failure, ms. 0 for $n < 1. */
    public function ceiling(int $n): int
    {
        if ($n < 1) {
            return 0;
        }
        $raw = $this->baseMs * ($this->factor ** ($n - 1));
        if ($this->capMs !== null) {
            $raw = min($raw, (float) $this->capMs);
        }

        // Past PHP_INT_MAX the float cast is undefined; an uncapped schedule
        // that large is already "never".
        return $raw >= PHP_INT_MAX ? PHP_INT_MAX : (int) round($raw);
    }

    /**
     * How long to wait after the $n-th failure, in whole milliseconds: the
     * server hint if one is set, else the ceiling — drawn down by full jitter
     * when enabled.
     */
    public function forAttempt(int $n): int
    {
        if ($this->hintSeconds !== null) {
            return (int) round($this->hintSeconds * 1000);
        }

        $ceiling = $this->ceiling($n);
        if (!$this->jitter || $ceiling <= 0) {
            return $ceiling;
        }
        if ($this->rng !== null) {
            return max(0, min($ceiling, (int) ($this->rng)($ceiling)));
        }

        return random_int(0, $ceiling);
    }

    /** {@see forAttempt()} in seconds. */
    public function secondsForAttempt(int $n): float
    {
        return $this->forAttempt($n) / 1000;
    }
}
