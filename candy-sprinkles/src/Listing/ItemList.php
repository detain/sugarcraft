<?php

declare(strict_types=1);

namespace CandyCore\Sprinkles\Listing;

/**
 * Renders an enumerated list — bullet, dash, numbered, alphabet, etc.
 *
 * ```php
 * echo ItemList::new()
 *     ->item('Apple')
 *     ->item('Banana')
 *     ->enumerator(Enumerator::arabic())
 *     ->render();
 * ```
 *
 * Output:
 *   1. Apple
 *   2. Banana
 *
 * Markers are right-padded to the widest one so item text always aligns.
 * Multi-line items keep the indentation on continuation lines.
 */
final class ItemList
{
    /** @var list<string> */
    private array $items = [];
    /** @var \Closure(int,int):string */
    private \Closure $enumerator;

    public function __construct()
    {
        $this->enumerator = Enumerator::dash();
    }

    public static function new(): self
    {
        return new self();
    }

    public function item(string $text): self
    {
        $clone = clone $this;
        $clone->items = [...$this->items, $text];
        return $clone;
    }

    /** @param iterable<string> $items */
    public function items(iterable $items): self
    {
        $clone = clone $this;
        foreach ($items as $i) {
            $clone->items[] = $i;
        }
        return $clone;
    }

    /** @param \Closure(int,int):string $fn */
    public function enumerator(\Closure $fn): self
    {
        $clone = clone $this;
        $clone->enumerator = $fn;
        return $clone;
    }

    public function render(): string
    {
        $total = count($this->items);
        if ($total === 0) {
            return '';
        }

        $markers = [];
        $maxMarker = 0;
        for ($i = 0; $i < $total; $i++) {
            $m = ($this->enumerator)($i, $total);
            $markers[] = $m;
            $maxMarker = max($maxMarker, mb_strlen($m, 'UTF-8'));
        }

        $lines = [];
        foreach ($this->items as $i => $text) {
            $marker = $markers[$i];
            $pad    = $maxMarker - mb_strlen($marker, 'UTF-8');
            $prefix = $marker === ''
                ? str_repeat(' ', $maxMarker > 0 ? $maxMarker + 1 : 0)
                : $marker . str_repeat(' ', $pad) . ' ';
            $indent = str_repeat(' ', mb_strlen($prefix, 'UTF-8'));

            $itemLines = explode("\n", $text);
            $first = true;
            foreach ($itemLines as $il) {
                $lines[] = ($first ? $prefix : $indent) . $il;
                $first = false;
            }
        }
        return implode("\n", $lines);
    }

    public function __toString(): string
    {
        return $this->render();
    }
}
