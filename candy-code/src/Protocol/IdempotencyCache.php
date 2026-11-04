<?php

declare(strict_types=1);

namespace SugarCraft\Code\Protocol;

/**
 * The answers to side-effecting requests that carried an `idempotencyKey`
 * (Appendix O §6.3): a retry with the same key, from the same principal, to
 * the same method, gets the ORIGINAL answer instead of doing the thing twice —
 * a prompt resent after a dropped connection is not a second turn.
 *
 * Bounded both ways: an answer is kept {@see TTL_SECONDS}, at most
 * {@see MAX_ENTRIES} per principal, AND at most {@see MAX_BYTES_PER_PRINCIPAL}
 * of answer bytes per principal — a count cap alone let 1,000 fat answers
 * (a paged transcript, a media descriptor list) stand unbounded in size.
 * The oldest answer is forgotten first under either pressure.
 */
final class IdempotencyCache
{
    public const TTL_SECONDS = 300;

    public const MAX_ENTRIES = 1000;

    /**
     * Answer bytes held per principal before the oldest are forgotten. The
     * figures §6.3 takes from OpenClaw bound the count; this bounds the
     * mass — an idempotency map is a convenience, never a memory lease.
     */
    public const MAX_BYTES_PER_PRINCIPAL = 8 * 1024 * 1024;

    public const MAX_KEY_LENGTH = 64;

    /** @var array<string, array<string, array{expires: float, answer: mixed, bytes: int}>> principal => key => entry */
    private array $entries = [];

    /** @var array<string, int> principal => answer bytes held by its live slots */
    private array $bytes = [];

    /** @param \Closure(): float $clock */
    private function __construct(private readonly \Closure $clock)
    {
    }

    /** @param (\Closure(): float)|null $clock */
    public static function new(?\Closure $clock = null): self
    {
        return new self($clock ?? static fn (): float => \microtime(true));
    }

    /**
     * The answer remembered for ($principal, $method, $key), or null.
     *
     * @return array{answer: mixed}|null wrapped, so a remembered null is told apart from none
     */
    public function get(string $principal, string $method, string $key): ?array
    {
        $slot = $method . "\0" . $key;
        $entry = $this->entries[$principal][$slot] ?? null;
        if ($entry === null) {
            return null;
        }
        if ($entry['expires'] <= ($this->clock)()) {
            unset($this->entries[$principal][$slot]);
            $this->bytes[$principal] -= $entry['bytes'];
            if (($this->entries[$principal] ?? []) === []) {
                unset($this->entries[$principal], $this->bytes[$principal]);
            }

            return null;
        }

        return ['answer' => $entry['answer']];
    }

    public function put(string $principal, string $method, string $key, mixed $answer): void
    {
        // Measured first: a value that cannot be retained (a closure) must
        // refuse the cache whole, with no half-applied state behind it.
        $bytes = self::answerBytes($answer);

        $slot = $method . "\0" . $key;
        $previous = $this->entries[$principal][$slot] ?? null;
        if ($previous !== null) {
            // An overwrite replaces its answer's mass; it does not stack.
            $this->bytes[$principal] -= $previous['bytes'];
        }
        unset($this->entries[$principal][$slot]);
        $this->entries[$principal][$slot] = ['expires' => ($this->clock)() + self::TTL_SECONDS, 'answer' => $answer, 'bytes' => $bytes];
        $this->bytes[$principal] = ($this->bytes[$principal] ?? 0) + $bytes;

        while (\count($this->entries[$principal]) > self::MAX_ENTRIES || $this->bytes[$principal] > self::MAX_BYTES_PER_PRINCIPAL) {
            $oldest = \array_shift($this->entries[$principal]);
            $this->bytes[$principal] -= $oldest['bytes'];
        }
        if ($this->entries[$principal] === []) {
            // An answer too big to fit its own budget is forgotten as fast
            // as it arrived; the slot map stays empty rather than lying.
            unset($this->entries[$principal], $this->bytes[$principal]);
        }
    }

    /** Answers held for $principal (expired ones included until next read). */
    public function count(string $principal): int
    {
        return \count($this->entries[$principal] ?? []);
    }

    /** Answer bytes held for $principal (expired ones included until next read). */
    public function bytes(string $principal): int
    {
        return $this->bytes[$principal] ?? 0;
    }

    /**
     * The mass $answer would occupy: the serialized length, the honest
     * in-memory proxy PHP offers without walking arbitrary structures.
     *
     * @throws \RuntimeException when $answer cannot be retained at all
     */
    private static function answerBytes(mixed $answer): int
    {
        try {
            $serialized = \serialize($answer);
        } catch (\Throwable $unretainable) {
            throw new \RuntimeException(
                'idempotency answer cannot be retained: ' . $unretainable->getMessage(),
                0,
                $unretainable,
            );
        }

        return \strlen($serialized);
    }
}
