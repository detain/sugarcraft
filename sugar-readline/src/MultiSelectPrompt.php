<?php

declare(strict_types=1);

namespace CandyCore\Readline;

/**
 * Multi-item selection prompt with cursor navigation, filtering, pagination,
 * and minimum/maximum selection enforcement.
 *
 * Port of erikgeiser/promptkit.selection.MultiSelection.
 *
 * @see https://github.com/erikgeiser/promptkit
 */
final class MultiSelectPrompt
{
    private string $label;
    private array $allItems = [];

    /** @var list<string> */
    private array $filteredItems = [];

    private string $filterText = '';
    private int $cursor = 0;
    private int $page    = 0;
    private int $perPage = 10;

    /** @var array<int, true> Indices into allItems that are selected */
    private array $selected = [];

    private int $minSelections = 0;
    private int $maxSelections = 0;  // 0 = unlimited

    private bool $confirmed  = false;
    private bool $cancelled  = false;

    private string $cursorStyle   = '7';    // reverse
    private string $selectedStyle = '32';   // green
    private string $labelStyle    = '1;36'; // bold cyan
    private string $matchedStyle  = '33';   // yellow for filter match highlight

    // -------------------------------------------------------------------------
    // Factory
    // -------------------------------------------------------------------------

    public function __construct(string $label, array $items)
    {
        $this->label          = $label;
        $this->allItems       = $items;
        $this->filteredItems  = $items;
    }

    public static function new(string $label, array $items): self
    {
        return new self($label, $items);
    }

    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

    /**
     * Minimum number of selections required before confirmation is allowed.
     */
    public function WithMinSelections(int $min): self
    {
        $clone = clone $this;
        $clone->minSelections = $min;
        return $clone;
    }

    /**
     * Maximum number of selections allowed (0 = unlimited).
     */
    public function WithMaxSelections(int $max): self
    {
        $clone = clone $this;
        $clone->maxSelections = $max;
        return $clone;
    }

    public function WithPerPage(int $n): self
    {
        $clone = clone $this;
        $clone->perPage = $n;
        return $clone;
    }

    /**
     * Style for selected check marker.
     */
    public function WithSelectedStyle(string $ansiCodes): self
    {
        $clone = clone $this;
        $clone->selectedStyle = $ansiCodes;
        return $clone;
    }

    // -------------------------------------------------------------------------
    // Input handling
    // -------------------------------------------------------------------------

    public function HandleKey(string $key): self
    {
        if ($this->confirmed || $this->cancelled) return $this;

        return match ($key) {
            'up', 'k'       => $this->moveCursor(-1),
            'down', 'j'     => $this->moveCursor(1),
            'pageup'        => $this->prevPage(),
            'pagedown'      => $this->nextPage(),
            'home'          => $this->moveCursorToStart(),
            'end'           => $this->moveCursorToEnd(),
            'space'         => $this->toggleSelect(),
            'enter'         => $this->confirm(),
            'esc', 'ctrl_c' => $this->cancel(),
            default         => $this,
        };
    }

    public function Confirm(): self
    {
        if ($this->confirmed || $this->cancelled) return $this;
        $clone = clone $this;
        $clone->confirmed = true;
        return $clone;
    }

    public function Cancel(): self
    {
        $clone = clone $this;
        $clone->cancelled = true;
        return $clone;
    }

    // -------------------------------------------------------------------------
    // Queries
    // -------------------------------------------------------------------------

    /**
     * @return list<string>
     */
    public function SelectedValues(): array
    {
        if ($this->cancelled) return [];
        return \array_values(
            \array_filter($this->allItems, fn($_, $i): bool =>
                isset($this->selected[$i]), ARRAY_FILTER_USE_BOTH
            )
        );
    }

    public function IsConfirmed(): bool { return $this->confirmed; }
    public function IsCancelled(): bool { return $this->cancelled; }

    public function SelectionCount(): int
    {
        return \count($this->selected);
    }

    public function CanConfirm(): bool
    {
        return $this->selectionCount() >= $this->minSelections;
    }

    /**
     * @return list<string>
     */
    public function CurrentPageItems(): array
    {
        $offset = $this->page * $this->perPage;
        return \array_slice($this->filteredItems, $offset, $this->perPage);
    }

    public function TotalPages(): int
    {
        return (int) \ceil(\count($this->filteredItems) / $this->perPage);
    }

    public function CurrentPage(): int { return $this->page; }

    public function FilterMatchCount(): int
    {
        return \count($this->filteredItems);
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    public function View(): string
    {
        $lines = [];

        // Label
        $lines[] = $this->ansi($this->label, $this->labelStyle);

        // Constraint hint
        $minTxt = $this->minSelections > 0 ? " (min {$this->minSelections})" : '';
        $maxTxt = $this->maxSelections > 0 ? " (max {$this->maxSelections})" : '';
        $lines[] = "[multi-select{$minTxt}{$maxTxt}] selected: " . $this->selectionCount();

        // Filter bar
        $filterBar = '[filter] ' . $this->filterText . ' ';
        if ($this->filterText !== '') {
            $filterBar .= \sprintf(' (%d matches)', \count($this->filteredItems));
        }
        $lines[] = $filterBar;

        if ($this->filteredItems === []) {
            $lines[] = '(no matches)';
            return \implode("\n", $lines);
        }

        // Items
        $offset = $this->page * $this->perPage;
        for ($i = 0; $i < $this->perPage; $i++) {
            $idx = $offset + $i;
            if (!isset($this->filteredItems[$idx])) break;

            $item        = $this->filteredItems[$idx];
            // Map filtered index back to allItems index
            $allIdx      = $this->filteredToAllIndex($idx);
            $isSelected  = isset($this->selected[$allIdx]);
            $marker      = $isSelected ? '◉' : '○';
            $prefix      = $marker . ' ';

            $isCursor = ($idx === $this->cursor);

            $itemStr = $prefix . $item;

            if ($isCursor) {
                $itemStr = $this->ansi($itemStr, $this->cursorStyle);
            } elseif ($isSelected) {
                $itemStr = $this->ansi($itemStr, $this->selectedStyle);
            }

            $lines[] = $itemStr;
        }

        // Pagination
        $total = $this->TotalPages();
        if ($total > 1) {
            $lines[] = \sprintf('Page %d/%d', $this->page + 1, $total);
        }

        // Confirm hint
        if ($this->CanConfirm()) {
            $lines[] = $this->ansi('Press Enter to confirm', '90');
        } else {
            $lines[] = $this->ansi(
                "Select at least {$this->minSelections} item(s)",
                '90'
            );
        }

        return \implode("\n", $lines);
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    private function moveCursor(int $delta): self
    {
        $count = \count($this->filteredItems);
        if ($count === 0) return $this;

        $clone = clone $this;
        $cursor = $clone->cursor + $delta;

        if ($cursor < 0) $cursor = 0;
        if ($cursor >= $count) $cursor = $count - 1;

        $clone->cursor = $cursor;
        $clone->page   = \min($clone->TotalPages() - 1, (int) \floor($cursor / $clone->perPage));

        return $clone;
    }

    private function moveCursorToStart(): self
    {
        $clone = clone $this;
        $clone->cursor = 0;
        $clone->page   = 0;
        return $clone;
    }

    private function moveCursorToEnd(): self
    {
        $clone = clone $this;
        $clone->cursor = \max(0, \count($clone->filteredItems) - 1);
        $clone->page   = $clone->TotalPages() - 1;
        return $clone;
    }

    private function prevPage(): self
    {
        $clone = clone $this;
        $clone->page = \max(0, $clone->page - 1);
        return $clone;
    }

    private function nextPage(): self
    {
        $clone = clone $this;
        $clone->page = \min($clone->TotalPages() - 1, $clone->page + 1);
        return $clone;
    }

    private function toggleSelect(): self
    {
        $clone = clone $this;
        $allIdx = $clone->filteredToAllIndex($clone->cursor);

        if (isset($clone->selected[$allIdx])) {
            unset($clone->selected[$allIdx]);
        } else {
            // Check max
            if ($clone->maxSelections > 0 && \count($clone->selected) >= $clone->maxSelections) {
                // At max, deselect the first selected instead
                $first = \array_key_first($clone->selected);
                if ($first !== null) {
                    unset($clone->selected[$first]);
                    $clone->selected[$allIdx] = true;
                }
            } else {
                $clone->selected[$allIdx] = true;
            }
        }

        return $clone;
    }

    private function confirm(): self
    {
        if (!$this->CanConfirm()) return $this;
        return $this->Confirm();
    }

    private function cancel(): self
    {
        return $this->Cancel();
    }

    private function selectionCount(): int
    {
        return \count($this->selected);
    }

    /**
     * Map a cursor position in the filtered list to the corresponding index in allItems.
     * We store by allItems indices; filtering may reorder, so we track via the actual item.
     */
    private function filteredToAllIndex(int $filteredIdx): int
    {
        $item = $this->filteredItems[$filteredIdx] ?? null;
        if ($item === null) return $filteredIdx;

        // Linear search (items list is small)
        foreach ($this->allItems as $i => $v) {
            if ($v === $item) return $i;
        }
        return $filteredIdx;
    }

    private function ansi(string $text, string $codes): string
    {
        if ($codes === '') return $text;
        return "\x1b[{$codes}m{$text}\x1b[0m";
    }
}
