<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Modal;

use SugarCraft\Dash\Foundation\Item;

/**
 * A modal dialog for selecting multiple items from a list.
 */
final class MultiselectModal implements Item
{
    /** @var list<string> */
    private array $options;
    /** @var list<int> */
    private array $selectedIndices;

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

    public function render(): string
    {
        $result = '';
        if ($this->title !== null) {
            $result .= $this->title . "\n";
        }
        foreach ($this->options as $i => $option) {
            $checked = in_array($i, $this->selectedIndices, true);
            $prefix = $checked ? '[x] ' : '[ ] ';
            $result .= $prefix . $option . "\n";
        }
        return rtrim($result, "\n");
    }
}
