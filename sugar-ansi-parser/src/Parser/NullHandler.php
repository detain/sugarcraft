<?php

declare(strict_types=1);

namespace SugarCraft\AnsiParser\Parser;

/**
 * A {@see Handler} that consumes everything and does nothing — the named
 * destination for streams whose bytes must be parsed but not acted on
 * (finding sugar-ansi-parser-005). Hand-rolled equivalents lurk in at least
 * four consumer trees; shipping one honest copy replaces them.
 *
 * Deliberately does NOT implement {@see BulkPrintHandler}: a null sink is
 * the one consumer that always wants the byte-level per-char truth (a run
 * would have to be un-looped to count characters anyway), and staying
 * classic keeps the counter semantics exact — `printCount()` counts RUNES,
 * one per printChar(), never runs.
 *
 * Interface-growth caveat, stated honestly (this is why no `abstract
 * BaseHandler` arm was taken — the house convention is `final`): when the
 * `Handler` interface gains a method, this class stops compiling like every
 * correct `implements Handler`. That is the feature: the silent stub-drift
 * the finding documents lives in consumers that hand-roll a class and forget
 * to learn the new event, while NullHandler's growth failure is loud and
 * points straight at the missing no-op.
 *
 * Lib-original — charmbracelet ships no null handler; upstream tests pass
 * nil-ish sinks per case.
 */
final class NullHandler implements Handler
{
    private int $print = 0;
    private int $execute = 0;
    private int $csi = 0;
    private int $esc = 0;
    private int $osc = 0;
    private int $dcs = 0;
    private int $sosPmApc = 0;

    public function printChar(string $rune): void
    {
        $this->print++;
    }

    public function execute(int $byte): void
    {
        $this->execute++;
    }

    /**
     * @param list<int> $params
     */
    public function csiDispatch(int $final, array $params, int $prefix, int $intermediate): void
    {
        $this->csi++;
    }

    public function escDispatch(int $final, int $intermediate): void
    {
        $this->esc++;
    }

    public function oscDispatch(string $data): void
    {
        $this->osc++;
    }

    /**
     * @param list<int> $params
     */
    public function dcsDispatch(int $final, array $params, int $prefix, int $intermediate, string $data): void
    {
        $this->dcs++;
    }

    public function sosPmApcDispatch(string $kind, string $data): void
    {
        $this->sosPmApc++;
    }

    public function printCount(): int
    {
        return $this->print;
    }

    public function executeCount(): int
    {
        return $this->execute;
    }

    public function csiCount(): int
    {
        return $this->csi;
    }

    public function escCount(): int
    {
        return $this->esc;
    }

    public function oscCount(): int
    {
        return $this->osc;
    }

    public function dcsCount(): int
    {
        return $this->dcs;
    }

    public function sosPmApcCount(): int
    {
        return $this->sosPmApc;
    }
}
