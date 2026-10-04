<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Protocol;

/**
 * The answers to side-effecting requests that carried an `idempotencyKey`
 * (Appendix O §6.3): a retry with the same key, from the same principal, to
 * the same method, gets the ORIGINAL answer instead of doing the thing twice —
 * a prompt resent after a dropped connection is not a second turn.
 *
 * Bounded both ways, with the figures §6.3 takes from OpenClaw: an answer is
 * kept {@see TTL_SECONDS}, and at most {@see MAX_ENTRIES} per principal, the
 * oldest forgotten first.
 */
final class IdempotencyCache
{
    public const TTL_SECONDS = 300;

    public const MAX_ENTRIES = 1000;

    public const MAX_KEY_LENGTH = 64;

    /** @var array<string, array<string, array{expires: float, answer: mixed}>> principal => key => entry */
    private array $entries = [];

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

            return null;
        }

        return ['answer' => $entry['answer']];
    }

    public function put(string $principal, string $method, string $key, mixed $answer): void
    {
        $slot = $method . "\0" . $key;
        unset($this->entries[$principal][$slot]);
        $this->entries[$principal][$slot] = ['expires' => ($this->clock)() + self::TTL_SECONDS, 'answer' => $answer];
        while (\count($this->entries[$principal]) > self::MAX_ENTRIES) {
            \array_shift($this->entries[$principal]);
        }
    }

    /** Answers held for $principal (expired ones included until next read). */
    public function count(string $principal): int
    {
        return \count($this->entries[$principal] ?? []);
    }
}
