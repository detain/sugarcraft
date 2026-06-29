<?php

declare(strict_types=1);

namespace SugarCraft\Readline;

/**
 * Multi-choice selection prompt with cursor navigation, filter, pagination,
 * and minimum / maximum selection enforcement.
 *
 * Marks are tracked by the *original* choice index, so they survive filter
 * changes. Selected values are returned in original choice order.
 */
final class MultiSelectPrompt
{
    /** @var list<string> */
    private array $choices;

    /** @var list<int>  Indices of {@see $choices} that survive {@see $filter}. */
    private array $filtered;

    /** @var array<int,true>  Set of marked indices into {@see $choices}. */
    private array $marked = [];

    private string $filter = '';
    private int $cursor    = 0;
    private int $page      = 0;
    private int $pageSize  = 10;
    private int $minSelect = 0;
    private int $maxSelect = 0;     // 0 = unlimited
    private bool $submitted = false;
    private bool $aborted   = false;

    private string $cursorStyle = '7';
    private string $labelStyle  = '1;36';
    private string $markedGlyph   = '◉ ';
    private string $unmarkedGlyph = '○ ';

    /** @param list<string> $choices */
    public function __construct(private readonly string $label, array $choices)
    {
        $this->choices  = array_values($choices);
        $this->filtered = array_keys($this->choices);
    }

    /** @param list<string> $choices */
    public static function new(string $label, array $choices): self
    {
        return new self($label, $choices);
    }

    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

    public function withMinSelections(int $min): self
    {
        $clone = clone $this;
        $clone->minSelect = max(0, $min);
        return $clone;
    }

    public function withMaxSelections(int $max): self
    {
        $clone = clone $this;
        $clone->maxSelect = max(0, $max);
        return $clone;
    }

    public function withPageSize(int $size): self
    {
        $clone = clone $this;
        $clone->pageSize = max(1, $size);
        $clone->reclampPage();
        return $clone;
    }

    public function withFilter(string $needle): self
    {
        $clone = clone $this;
        $clone->filter   = $needle;
        $clone->filtered = $needle === ''
            ? array_keys($clone->choices)
            : array_values(array_filter(
                array_keys($clone->choices),
                fn(int $i): bool => stripos($clone->choices[$i], $needle) !== false,
            ));
        $clone->cursor = 0;
        $clone->page   = 0;
        return $clone;
    }

    // -------------------------------------------------------------------------
    // Input
    // -------------------------------------------------------------------------

    public function handleKey(string $key): self
    {
        if ($this->submitted || $this->aborted) {
            return $this;
        }

        return match ($key) {
            Key::Up       => $this->moveCursor(-1),
            Key::Down     => $this->moveCursor(1),
            Key::PageUp   => $this->changePage(-1),
            Key::PageDown => $this->changePage(1),
            Key::Home     => $this->moveCursorTo(0),
            Key::End      => $this->moveCursorTo(count($this->filtered) - 1),
            Key::Space    => $this->toggleCurrent(),
            Key::Enter    => $this->submit(),
            Key::Escape, Key::CtrlC => $this->abort(),
            default       => $this,
        };
    }

    public function submit(): self
    {
        if ($this->submitted || $this->aborted || !$this->canSubmit()) {
            return $this;
        }
        $clone = clone $this;
        $clone->submitted = true;
        return $clone;
    }

    public function abort(): self
    {
        if ($this->submitted || $this->aborted) {
            return $this;
        }
        $clone = clone $this;
        $clone->aborted = true;
        return $clone;
    }

    // -------------------------------------------------------------------------
    // Queries
    // -------------------------------------------------------------------------

    /** @return list<string> Marked values in original choice order. */
    public function selectedValues(): array
    {
        if ($this->aborted) {
            return [];
        }
        $out = [];
        foreach ($this->choices as $i => $value) {
            if (isset($this->marked[$i])) {
                $out[] = $value;
            }
        }
        return $out;
    }

    public function selectionCount(): int { return count($this->marked); }

    /** @return array<int, true> Map of marked choice indices to true. */
    public function marked(): array { return $this->marked; }

    /** True when the current marked set satisfies min / max constraints. */
    public function canSubmit(): bool
    {
        $n = count($this->marked);
        if ($n < $this->minSelect) {
            return false;
        }
        if ($this->maxSelect > 0 && $n > $this->maxSelect) {
            return false;
        }
        return true;
    }

    public function isSubmitted(): bool { return $this->submitted; }
    public function isAborted(): bool   { return $this->aborted; }

    public function cursor(): int        { return $this->cursor; }
    public function filteredCount(): int { return count($this->filtered); }
    public function totalChoices(): int  { return count($this->choices); }

    public function currentPage(): int { return $this->page; }

    public function totalPages(): int
    {
        return max(1, (int) ceil(count($this->filtered) / $this->pageSize));
    }

    /** @return list<string> Slice of filtered choices on the current page. */
    public function currentPageItems(): array
    {
        $offset = $this->page * $this->pageSize;
        $slice  = array_slice($this->filtered, $offset, $this->pageSize);
        return array_map(fn(int $i): string => $this->choices[$i], $slice);
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    public function view(): string
    {
        $lines = [Ansi::wrap(Ansi::sanitize($this->label), $this->labelStyle)];

        $hint = sprintf('[multi-select] selected: %d', count($this->marked));
        if ($this->minSelect > 0) {
            $hint .= sprintf(' (min %d)', $this->minSelect);
        }
        if ($this->maxSelect > 0) {
            $hint .= sprintf(' (max %d)', $this->maxSelect);
        }
        $lines[] = $hint;

        $bar = '[filter] ' . Ansi::sanitize($this->filter);
        if ($this->filter !== '') {
            $bar .= sprintf(' (%d match%s)', count($this->filtered), count($this->filtered) === 1 ? '' : 'es');
        }
        $lines[] = $bar;

        if ($this->filtered === []) {
            $lines[] = '(no matches)';
            return implode("\n", $lines);
        }

        $offset = $this->page * $this->pageSize;
        $end    = min($offset + $this->pageSize, count($this->filtered));
        for ($i = $offset; $i < $end; $i++) {
            $choiceIdx = $this->filtered[$i];
            $glyph     = isset($this->marked[$choiceIdx]) ? $this->markedGlyph : $this->unmarkedGlyph;
            $line      = $glyph . Ansi::sanitize($this->choices[$choiceIdx]);
            if ($i === $this->cursor) {
                $line = Ansi::wrap($line, $this->cursorStyle);
            }
            $lines[] = $line;
        }

        if ($this->totalPages() > 1) {
            $lines[] = sprintf('Page %d/%d', $this->page + 1, $this->totalPages());
        }

        return implode("\n", $lines);
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    private function moveCursor(int $delta): self
    {
        return $this->moveCursorTo($this->cursor + $delta);
    }

    private function moveCursorTo(int $position): self
    {
        $count = count($this->filtered);
        if ($count === 0) {
            return $this;
        }
        $clamped = max(0, min($count - 1, $position));
        if ($clamped === $this->cursor) {
            return $this;
        }
        $clone = clone $this;
        $clone->cursor = $clamped;
        $clone->page   = intdiv($clamped, $clone->pageSize);
        return $clone;
    }

    private function changePage(int $delta): self
    {
        $target  = $this->page + $delta;
        $max     = $this->totalPages() - 1;
        $clamped = max(0, min($max, $target));
        if ($clamped === $this->page) {
            return $this;
        }
        $clone = clone $this;
        $clone->page   = $clamped;
        $clone->cursor = max(
            $clamped * $clone->pageSize,
            min(($clamped + 1) * $clone->pageSize - 1, count($clone->filtered) - 1),
        );
        return $clone;
    }

    private function toggleCurrent(): self
    {
        if ($this->filtered === []) {
            return $this;
        }
        $choiceIdx = $this->filtered[$this->cursor];

        $clone = clone $this;
        if (isset($clone->marked[$choiceIdx])) {
            unset($clone->marked[$choiceIdx]);
            return $clone;
        }
        if ($clone->maxSelect > 0 && count($clone->marked) >= $clone->maxSelect) {
            // At cap: drop the oldest mark to make room (FIFO replacement).
            $oldest = array_key_first($clone->marked);
            if ($oldest !== null) {
                unset($clone->marked[$oldest]);
            }
        }
        $clone->marked[$choiceIdx] = true;
        return $clone;
    }

    private function reclampPage(): void
    {
        // Clamp cursor into valid range first
        $count = count($this->filtered);
        if ($count === 0) {
            $this->cursor = 0;
            $this->page = 0;
            return;
        }
        $this->cursor = max(0, min($count - 1, $this->cursor));
        // Re-derive page from cursor (consistent with moveCursorTo)
        $this->page = intdiv($this->cursor, $this->pageSize);
        // Finally clamp page to valid range
        $this->page = min($this->page, max(0, $this->totalPages() - 1));
    }
}
