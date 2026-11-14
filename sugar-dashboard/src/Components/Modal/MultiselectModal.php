<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Components\Modal;

use SugarCraft\Core\Util\Width;
use SugarCraft\Dashboard\Foundation\Item;
use SugarCraft\Dashboard\Foundation\SizedItem;

/**
 * A modal dialog for selecting multiple items from a list.
 */
final class MultiselectModal implements Item, SizedItem
{
    /** @var list<string> */
    private array $options;
    /** @var list<int> */
    private array $selectedIndices;
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param list<string> $options
     * @param list<int> $selectedIndices
     */
    public function __construct(
        array $options = [],
        array $selectedIndices = [],
        private readonly ?string $title = null,
    ) {
        $this->options = $options;
        $this->selectedIndices = $selectedIndices;
    }

    /**
     * @param list<string> $options
     */
    public static function new(array $options, ?string $title = null): self
    {
        return new self(options: $options, title: $title);
    }

    public function withSelectedIndices(array $indices): self
    {
        $clone = clone $this;
        $clone->selectedIndices = $indices;
        return $clone;
    }

    public function toggle(int $index): self
    {
        $clone = clone $this;
        if (in_array($index, $clone->selectedIndices, true)) {
            $clone->selectedIndices = array_values(array_filter($clone->selectedIndices, fn($i) => $i !== $index));
        } else {
            $clone->selectedIndices[] = $index;
        }
        return $clone;
    }

    public function setSize(int $width, int $height): static
    {
        $clone = clone $this;
        if ($width > 0) {
            $clone->width = $width;
        }
        if ($height > 0) {
            $clone->height = $height;
        }
        return $clone;
    }

    /**
     * @return array{0:int,1:int} the box this dialog paints (finding
     *     sugar-dashboard-069: the SizedItem channel layout hosts measure).
     */
    public function innerSize(): array
    {
        $rows = $this->naturalRows();
        return [
            $this->width ?? max(array_map(static fn(string $r): int => Width::string($r), $rows)),
            $this->height ?? count($rows),
        ];
    }

    /**
     * The cursor decorates the FIRST selected row (finding 173): a navigable
     * cursor arrives with the framed composition design, disclosed deferral.
     */
    private function cursorRow(): int
    {
        return $this->selectedIndices === [] ? -1 : min($this->selectedIndices);
    }

    /**
     * @return list<string>
     */
    private function naturalRows(): array
    {
        $rows = [];
        if ($this->title !== null) {
            $rows[] = $this->title;
        }
        $cursor = $this->cursorRow();
        foreach ($this->options as $i => $option) {
            $checked = in_array($i, $this->selectedIndices, true);
            $rows[] = ($i === $cursor ? '> ' : '  ') . ($checked ? '[x] ' : '[ ] ') . $option;
        }
        return $rows;
    }

    public function render(): string
    {
        $rows = $this->naturalRows();
        if ($this->height !== null && $this->height > 0) {
            $headerCount = $this->title !== null ? 1 : 0;
            $budget = max(1, $this->height - $headerCount);
            $count = count($this->options);
            $visible = min($budget, $count);
            $cursor = $this->cursorRow();
            $top = $cursor >= 0
                ? max(0, min($cursor - intdiv(max(0, $visible - 1), 2), $count - $visible))
                : 0;
            $kept = array_slice($rows, 0, $headerCount);
            foreach (range($top, $top + $visible - 1) as $i) {
                if ($i < $count) {
                    $kept[] = $rows[$headerCount + $i];
                }
            }
            $rows = $kept;
        }
        if ($this->width !== null && $this->width > 0) {
            $maxW = $this->width;
            $rows = array_map(
                static fn(string $r): string => Width::string($r) > $maxW ? Width::truncateAnsi($r, $maxW) : $r,
                $rows,
            );
        }
        return implode("\n", $rows);
    }
}
