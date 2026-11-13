<?php

declare(strict_types=1);

namespace SugarCraft\AnsiParser\Support;

use SugarCraft\AnsiParser\Parser\CsiHandler;

/**
 * A recording {@see CsiHandler} test double that ships in `src/` so any
 * consumer can autoload it (finding sugar-ansi-parser-006) — the house
 * published-shape precedent is sugar-testing, which ships `ProgramSimulator`
 * and friends under its own `src/` for exactly this reason.
 *
 * Every public call logs a row in the DebugHandler vocabulary
 * (`['type' => method, 'detail' => payload]`), so a consumer that already
 * speaks {@see \SugarCraft\AnsiParser\Parser\DebugHandler}'s log learns ONE
 * shape, not two. Query methods (`gridRows`/`gridCols`) are recorded too:
 * this is a spy, and "which geometry did the adapter fall back to" is part
 * of the observable truth.
 *
 * Being `final implements CsiHandler` is the acceptance story: interface
 * growth breaks this class AT COMPILE TIME, which is precisely how the
 * hand-rolled anonymous doubles used to drift silently — a consumer double
 * that never learned a new interface method simply returned its default
 * while tests stayed green.
 *
 * The log is bounded when `$maxRows` is given: the OLDEST rows are dropped
 * (the most recent N events are what an overrun diagnostic wants) and every
 * drop increments the public `$dropped` counter — cap drops are signalable,
 * the same law `stringOverflowed()` serves on the parser.
 *
 * Lib-original — charmbracelet ships no recording handler; upstream tests
 * hand-roll per-case function fields.
 */
final class RecordingCsiHandler implements CsiHandler
{
    /** @var list<array{type: string, detail: mixed}> */
    public array $log = [];

    /** Rows discarded by the `$maxRows` cap since the last {@see resetLog()}. */
    public int $dropped = 0;

    public function __construct(
        private readonly int $rows = 25,
        private readonly int $cols = 80,
        private readonly ?int $maxRows = null,
    ) {
    }

    /**
     * Snapshot-style rows: `filter('cuu')` returns the matching subset, the
     * same helper vocabulary as {@see \SugarCraft\AnsiParser\Parser\DebugHandler::filter()}.
     *
     * @return list<array{type: string, detail: mixed}>
     */
    public function filter(string $type): array
    {
        return array_values(array_filter($this->log, static fn ($e) => $e['type'] === $type));
    }

    /** Empties the log and the drop counter (not the grid geometry). */
    public function resetLog(): void
    {
        $this->log = [];
        $this->dropped = 0;
    }

    public function printable(string $rune): void
    {
        $this->record('printable', $rune);
    }

    public function cuu(int $count): void
    {
        $this->record('cuu', $count);
    }

    public function cud(int $count): void
    {
        $this->record('cud', $count);
    }

    public function cuf(int $count): void
    {
        $this->record('cuf', $count);
    }

    public function cub(int $count): void
    {
        $this->record('cub', $count);
    }

    public function cup(int $row, int $col): void
    {
        $this->record('cup', ['row' => $row, 'col' => $col]);
    }

    public function hvp(int $row, int $col): void
    {
        $this->record('hvp', ['row' => $row, 'col' => $col]);
    }

    /**
     * @param list<int> $params
     */
    public function sgr(array $params): void
    {
        $this->record('sgr', $params);
    }

    public function ed(int $mode): void
    {
        $this->record('ed', $mode);
    }

    public function el(int $mode): void
    {
        $this->record('el', $mode);
    }

    public function decset(int $mode, int $prefix): void
    {
        $this->record('decset', ['mode' => $mode, 'prefix' => $prefix]);
    }

    public function decrst(int $mode, int $prefix): void
    {
        $this->record('decrst', ['mode' => $mode, 'prefix' => $prefix]);
    }

    public function decstbm(int $top, int $bottom): void
    {
        $this->record('decstbm', ['top' => $top, 'bottom' => $bottom]);
    }

    public function tbc(int $mode): void
    {
        $this->record('tbc', $mode);
    }

    public function cht(int $count = 1): void
    {
        $this->record('cht', $count);
    }

    public function cbt(int $count = 1): void
    {
        $this->record('cbt', $count);
    }

    public function cr(): void
    {
        $this->record('cr');
    }

    public function lf(): void
    {
        $this->record('lf');
    }

    public function su(int $count = 1): void
    {
        $this->record('su', $count);
    }

    public function sd(int $count = 1): void
    {
        $this->record('sd', $count);
    }

    public function il(int $count = 1): void
    {
        $this->record('il', $count);
    }

    public function dl(int $count = 1): void
    {
        $this->record('dl', $count);
    }

    public function ich(int $count = 1): void
    {
        $this->record('ich', $count);
    }

    public function dch(int $count = 1): void
    {
        $this->record('dch', $count);
    }

    public function rep(int $count = 1): void
    {
        $this->record('rep', $count);
    }

    public function scosc(): void
    {
        $this->record('scosc');
    }

    public function scorc(): void
    {
        $this->record('scorc');
    }

    public function gridRows(): int
    {
        $this->record('gridRows');

        return $this->rows;
    }

    public function gridCols(): int
    {
        $this->record('gridCols');

        return $this->cols;
    }

    private function record(string $type, mixed $detail = null): void
    {
        if ($this->maxRows !== null && count($this->log) >= $this->maxRows) {
            array_shift($this->log);
            $this->dropped++;
        }
        $this->log[] = ['type' => $type, 'detail' => $detail];
    }
}
