<?php

declare(strict_types=1);

namespace SugarCraft\Readline;

use SugarCraft\Readline\Selection\SelectionEngine;

/**
 * Port-of-spirit (not literal) of erikgeiser/promptkit `selection` in
 * multi-select mode.
 *
 * Multi-choice selection prompt with cursor navigation, filter, pagination,
 * and minimum / maximum selection enforcement.
 *
 * Marks are tracked by the *original* choice index, so they survive filter
 * changes. Selected values are returned in original choice order.
 *
 * The list engine (filter, movement aliases, pagination, width/height
 * bounds) lives in {@see SelectionEngine}, shared with
 * {@see SelectionPrompt}; this class adds the mark layer, the min/max
 * submit veto, and the ◉/○ row rendering.
 */
final class MultiSelectPrompt
{
    use SelectionEngine;

    /** @var array<int,true>  Set of marked indices into {@see $choices}. */
    private array $marked = [];

    private int $minSelect = 0;
    private int $maxSelect = 0;     // 0 = unlimited

    private string $markedGlyph   = '◉ ';
    private string $unmarkedGlyph = '○ ';

    /** @param list<string> $choices */
    public function __construct(private readonly string $label, array $choices)
    {
        $this->initChoices($choices);
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

    /** Chrome rows: label, selected-count hint, filter bar, page footer. */
    protected function chromeRows(): int
    {
        return 4;
    }

    /** Space toggles the mark under the cursor (it never submits here). */
    protected function onSpace(): static
    {
        return $this->toggleCurrent();
    }

    /**
     * Marks are keyed by original choice index; a replaced list makes every
     * old index meaningless, so the mark set resets with the choices.
     */
    protected function onChoicesReplaced(self $state): static
    {
        $state->marked = [];
        return $state;
    }

    // -------------------------------------------------------------------------
    // Input
    // -------------------------------------------------------------------------

    public function submit(): self
    {
        if ($this->submitted || $this->aborted || !$this->canSubmit()) {
            return $this;
        }
        $clone = clone $this;
        $clone->submitted = true;
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

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    public function view(): string
    {
        $bound = $this->displayWidth;
        $lines = [RowFit::row([[Ansi::sanitize($this->label), $this->labelStyle]], $bound)];

        // Whole-sentence hint per LOCALES.md: the min/max arms are separate
        // keys, not fragments concatenated onto a translated sentence
        // (English word order must not leak into every locale).
        $count = count($this->marked);
        if ($this->minSelect > 0 && $this->maxSelect > 0) {
            $hintKey = 'multiselect.hint_min_max';
            $hintParams = ['count' => $count, 'min' => $this->minSelect, 'max' => $this->maxSelect];
        } elseif ($this->minSelect > 0) {
            $hintKey = 'multiselect.hint_min';
            $hintParams = ['count' => $count, 'min' => $this->minSelect];
        } elseif ($this->maxSelect > 0) {
            $hintKey = 'multiselect.hint_max';
            $hintParams = ['count' => $count, 'max' => $this->maxSelect];
        } else {
            $hintKey = 'multiselect.hint';
            $hintParams = ['count' => $count];
        }
        $lines[] = RowFit::row([[Lang::t($hintKey, $hintParams), null]], $bound);

        $lines[] = $this->renderFilterBar();

        if ($this->filtered === []) {
            $lines[] = RowFit::row([[Lang::t('selection.no_matches'), null]], $bound);
            return implode("\n", $lines);
        }

        $offset = $this->page * $this->effectivePageSize();
        $end    = min($offset + $this->effectivePageSize(), count($this->filtered));
        for ($i = $offset; $i < $end; $i++) {
            $choiceIdx = $this->filtered[$i];
            $glyph     = isset($this->marked[$choiceIdx]) ? $this->markedGlyph : $this->unmarkedGlyph;
            $style     = $i === $this->cursor ? $this->cursorStyle : null;
            $lines[]   = RowFit::row([[$glyph . Ansi::sanitize($this->choices[$choiceIdx]), $style]], $bound);
        }

        if ($this->totalPages() > 1) {
            $lines[] = RowFit::row([[Lang::t('selection.page', ['page' => $this->page + 1, 'of' => $this->totalPages()]), null]], $bound);
        }

        return implode("\n", $lines);
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

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
}
