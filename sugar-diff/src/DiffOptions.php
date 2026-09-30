<?php

declare(strict_types=1);

namespace SugarCraft\Diff;

/**
 * Immutable knob-set for {@see Diff::compute()} and the unified writer.
 *
 * Every `with*()` returns a new instance; the receiver never changes. Defaults
 * reproduce the ported sugar-crush engine byte-for-byte: three context lines,
 * exact comparison, no `\ No newline at end of file` markers, and real path
 * labels (never `/dev/null`) on both header rows.
 *
 * Mirrors the hardcoded behaviour of
 * sugar-crush/src/Tools/Concerns/BuildsUnifiedDiff.php
 * (`CONTEXT_LINES = 3`, `MAX_LCS_CELLS = 250_000`, exact `===` line
 * comparison, marker-free output) widened from constants into options.
 */
final class DiffOptions
{
    /** Lines of unchanged context shown around each hunk, matching `diff -u`'s default. */
    public const DEFAULT_CONTEXT_LINES = 3;

    /**
     * Guard against the O(n*m) LCS table on a huge scattered edit —
     * common-prefix/suffix trimming only shrinks the middle region for a
     * *localized* change — past this many cells the engine falls back to a
     * non-minimal but still-valid delete-all/insert-all block instead of
     * hanging.
     */
    public const DEFAULT_MAX_LCS_CELLS = 250_000;

    private function __construct(
        public readonly int $contextLines,
        public readonly int $maxLcsCells,
        public readonly bool $ignoreWhitespace,
        public readonly bool $ignoreCase,
        public readonly bool $noNewlineMarker,
        public readonly bool $devNullOnEmptySide,
    ) {}

    /** The faithful-port defaults: 3 context lines, exact comparison, plain headers. */
    public static function new(): self
    {
        return new self(
            contextLines: self::DEFAULT_CONTEXT_LINES,
            maxLcsCells: self::DEFAULT_MAX_LCS_CELLS,
            ignoreWhitespace: false,
            ignoreCase: false,
            noNewlineMarker: false,
            devNullOnEmptySide: false,
        );
    }

    /** Unchanged-context window per hunk; negative requests clamp to 0. */
    public function withContextLines(int $lines): self
    {
        return $this->mutate(['contextLines' => max(0, $lines)]);
    }

    /** LCS-table cell budget; negative requests clamp to 0 (always fall back). */
    public function withMaxLcsCells(int $cells): self
    {
        return $this->mutate(['maxLcsCells' => max(0, $cells)]);
    }

    /** Treat lines equal when their whitespace-stripped forms match (emitted text keeps original bytes). */
    public function withIgnoreWhitespace(bool $ignore = true): self
    {
        return $this->mutate(['ignoreWhitespace' => $ignore]);
    }

    /** Compare lines case-insensitively (emitted text keeps the old-side bytes). */
    public function withIgnoreCase(bool $ignore = true): self
    {
        return $this->mutate(['ignoreCase' => $ignore]);
    }

    /** Annotate rows that sit at an unterminated end-of-file with `\ No newline at end of file`. */
    public function withNoNewlineMarker(bool $on = true): self
    {
        return $this->mutate(['noNewlineMarker' => $on]);
    }

    /** Label an empty side's header row `/dev/null` (git's new/deleted-file shape). */
    public function withDevNullOnEmptySide(bool $on = true): self
    {
        return $this->mutate(['devNullOnEmptySide' => $on]);
    }

    /** @param array{contextLines?:int,maxLcsCells?:int,ignoreWhitespace?:bool,ignoreCase?:bool,noNewlineMarker?:bool,devNullOnEmptySide?:bool} $changes */
    private function mutate(array $changes = []): self
    {
        return new self(
            contextLines: $changes['contextLines'] ?? $this->contextLines,
            maxLcsCells: $changes['maxLcsCells'] ?? $this->maxLcsCells,
            ignoreWhitespace: $changes['ignoreWhitespace'] ?? $this->ignoreWhitespace,
            ignoreCase: $changes['ignoreCase'] ?? $this->ignoreCase,
            noNewlineMarker: $changes['noNewlineMarker'] ?? $this->noNewlineMarker,
            devNullOnEmptySide: $changes['devNullOnEmptySide'] ?? $this->devNullOnEmptySide,
        );
    }
}
