<?php

declare(strict_types=1);

namespace SugarCraft\AnsiParser\Parser;

/**
 * Records every action dispatched by the parser as a flat log of
 * `['type' => ..., 'detail' => ...]` rows. Used in tests to assert the
 * exact sequence of actions produced by feeding a byte stream.
 *
 * Unbounded by default: one row per dispatched action, forever — the right
 * ergonomics for a test fixture on a finite input. For a long-lived mirror
 * fed by an untrusted stream, pass `maxLogRows:`: the log then keeps the
 * MOST RECENT rows as a ring, dropping the oldest in batches and counting
 * every evicted row in the public {@see self::$dropped} (cap drops are
 * signalable, same law as {@see \SugarCraft\AnsiParser\Parser\Parser::stringOverflowed()}).
 * Eviction is batched on purpose: between drops the log may transiently
 * hold up to `maxLogRows + min(EVICT_GRACE_ROWS, maxLogRows)` rows, because
 * trimming exactly one row per append would reindex the whole list on every
 * append — quadratic under a hostile feed. The residency ceiling is that
 * sum, not `maxLogRows` alone. {@see self::resetLog()} is the
 * snapshot-and-clear verb.
 *
 * The 100_000/4_096 numbers are the sugar-vterm ring's shape (finding 030,
 * gate G5 — this class is the fold target); sugar-pty's MAX_EVENTS=1024 is
 * the house floor for smaller mirrors, e.g. `new DebugHandler(maxLogRows: 1024)`.
 *
 * Lib-original (finding 004) — charmbracelet ships no DebugHandler; this is
 * the full-shape showcase for the 7-method {@see Handler} interface.
 */
final class DebugHandler implements Handler
{
    /** Retention ceiling vterm's ring docs; a recommended `maxLogRows:` value. */
    public const MAX_LOG_ROWS = 100_000;

    /**
     * Headroom evicted in one splice (vterm's batch size). A configured
     * smaller cap scales the grace down to the cap itself, so the ring
     * transient never exceeds 2x its retention target.
     */
    public const EVICT_GRACE_ROWS = 4096;

    /** @var list<array{type: string, detail: mixed}> */
    public array $log = [];

    /** Rows evicted by the `maxLogRows` ring since the last {@see self::resetLog()}; 0 while unbounded. */
    public int $dropped = 0;

    /** @param int|null $maxLogRows null (default) keeps today's unbounded shape; a positive value installs the ring */
    public function __construct(private readonly ?int $maxLogRows = null)
    {
    }

    public function printChar(string $rune): void
    {
        $this->push('print', $rune);
    }

    public function execute(int $byte): void
    {
        $this->push('execute', $byte);
    }

    public function csiDispatch(int $final, array $params, int $prefix, int $intermediate): void
    {
        $this->push('csi', [
            'final' => $final,
            'params' => $params,
            'prefix' => $prefix,
            'intermediate' => $intermediate,
        ]);
    }

    public function escDispatch(int $final, int $intermediate): void
    {
        $this->push('esc', [
            'final' => $final,
            'intermediate' => $intermediate,
        ]);
    }

    public function oscDispatch(string $data): void
    {
        $this->push('osc', $data);
    }

    public function dcsDispatch(int $final, array $params, int $prefix, int $intermediate, string $data): void
    {
        $this->push('dcs', [
            'final' => $final,
            'params' => $params,
            'prefix' => $prefix,
            'intermediate' => $intermediate,
            'data' => $data,
        ]);
    }

    public function sosPmApcDispatch(string $kind, string $data): void
    {
        $this->push($kind, $data);
    }

    /**
     * Append one row, evicting the oldest batch when the ring runs past its
     * transient ceiling.
     *
     * @param mixed $detail
     */
    private function push(string $type, mixed $detail): void
    {
        $this->log[] = ['type' => $type, 'detail' => $detail];
        $cap = $this->maxLogRows;
        if ($cap === null) {
            return;
        }
        $ceiling = $cap + min(self::EVICT_GRACE_ROWS, $cap);
        if (\count($this->log) > $ceiling) {
            $surplus = \count($this->log) - $cap;
            array_splice($this->log, 0, $surplus);
            $this->dropped += $surplus;
        }
    }

    /**
     * Forget everything: empty the log and zero the drop counter (the
     * snapshot-and-clear the unbounded warning used to demand by hand).
     */
    public function resetLog(): void
    {
        $this->log = [];
        $this->dropped = 0;
    }

    /**
     * Return only the entries of a given type.
     *
     * @return list<array{type: string, detail: mixed}>
     */
    public function filter(string $type): array
    {
        return array_values(array_filter($this->log, static fn ($e) => $e['type'] === $type));
    }
}
