<?php

declare(strict_types=1);

namespace SugarCraft\Readline;

use SugarCraft\Readline\Selection\SelectionEngine;

/**
 * Single-choice selection prompt with case-insensitive substring filtering,
 * cursor navigation, and pagination.
 *
 * Filter is applied as a stable view over the original choice list; cursor and
 * pagination are tracked in the *filtered* index space.
 *
 * Each input handler returns a new immutable instance.
 *
 * The list engine (filter, movement aliases, pagination, width/height
 * bounds) lives in {@see SelectionEngine}, shared with
 * {@see MultiSelectPrompt}; this class adds only the submit veto and the
 * single-mark rendering.
 */
final class SelectionPrompt
{
    use SelectionEngine;

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

    /** Chrome rows: label, filter bar, page footer. */
    protected function chromeRows(): int
    {
        return 3;
    }

    /** Space mirrors Enter on this surface: commit the highlighted choice. */
    protected function onSpace(): static
    {
        return $this->submit();
    }

    public function submit(): self
    {
        if ($this->submitted || $this->aborted || $this->filtered === []) {
            return $this;
        }
        $clone = clone $this;
        $clone->submitted = true;
        return $clone;
    }

    // -------------------------------------------------------------------------
    // Queries
    // -------------------------------------------------------------------------

    /** Current choice under the cursor, or null when nothing matches the filter. */
    public function selectedValue(): ?string
    {
        if ($this->aborted) {
            return null;
        }
        $idx = $this->currentChoiceIndex();
        return $idx === null ? null : $this->choices[$idx];
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    public function view(): string
    {
        $bound = $this->displayWidth;
        $lines = [RowFit::row([[Ansi::sanitize($this->label), $this->labelStyle]], $bound)];

        $lines[] = $this->renderFilterBar();

        if ($this->filtered === []) {
            $lines[] = RowFit::row([['(no matches)', null]], $bound);
            return implode("\n", $lines);
        }

        $offset = $this->page * $this->effectivePageSize();
        $end    = min($offset + $this->effectivePageSize(), count($this->filtered));
        for ($i = $offset; $i < $end; $i++) {
            $value  = Ansi::sanitize($this->choices[$this->filtered[$i]]);
            $marker = $i === $this->cursor ? '❯ ' : '  ';
            $style  = $i === $this->cursor ? $this->cursorStyle : null;
            $lines[] = RowFit::row([[$marker . $value, $style]], $bound);
        }

        if ($this->totalPages() > 1) {
            $lines[] = RowFit::row([[sprintf('Page %d/%d', $this->page + 1, $this->totalPages()), null]], $bound);
        }

        return implode("\n", $lines);
    }
}
