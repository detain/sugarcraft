<?php

declare(strict_types=1);

namespace SugarCraft\Vterm\Parser;

use SugarCraft\AnsiParser\Parser\Handler;

/**
 * Records every action dispatched by the parser as a flat log of
 * `['type' => ..., 'detail' => ...]` rows. Used in tests to assert the
 * exact sequence of actions produced by feeding a byte stream.
 *
 * The log is BOUNDED: past {@see self::MAX_LOG_ROWS} entries the oldest
 * rows are dropped. A DebugHandler attached to a live feed of hostile
 * input could otherwise grow its accumulator without limit — the same
 * residency class of defect the emulator's event rings guard against.
 * Trimming drops whole rows oldest-first; recent history stays intact.
 *
 * Implements sugar-ansi-parser's {@see \SugarCraft\AnsiParser\Parser\Handler}.
 */
final class DebugHandler implements Handler
{
    /** Ceiling on retained log rows (drop-oldest past this many). */
    public const MAX_LOG_ROWS = 100_000;

    /**
     * Headroom evicted in one splice. Trimming exactly one row per append
     * would reindex the whole list each time — quadratic under a hostile
     * feed — so the ring runs up to MAX_LOG_ROWS + this grace and then
     * drops the surplus in a single splice.
     */
    public const EVICT_GRACE_ROWS = 4096;

    /** @var list<array{type: string, detail: mixed}> */
    public array $log = [];

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
     * Append one row, evicting the oldest when the ring is full.
     *
     * @param mixed $detail
     */
    private function push(string $type, mixed $detail): void
    {
        $this->log[] = ['type' => $type, 'detail' => $detail];
        if (\count($this->log) > self::MAX_LOG_ROWS + self::EVICT_GRACE_ROWS) {
            array_splice($this->log, 0, \count($this->log) - self::MAX_LOG_ROWS);
        }
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
