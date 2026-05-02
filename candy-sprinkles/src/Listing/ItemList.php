<?php

declare(strict_types=1);

namespace CandyCore\Sprinkles\Listing;

use CandyCore\Sprinkles\Style;

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
 * Multi-line items keep the indentation on continuation lines. Items
 * may themselves be `ItemList` instances for nested rendering.
 */
final class ItemList
{
    /** @var list<string|self> */
    private array $items = [];
    /** @var \Closure(int,int):string */
    private \Closure $enumerator;
    private ?Style $itemStyle = null;
    private ?Style $enumeratorStyle = null;
    /** @var ?\Closure(int $index, string $text): Style */
    private ?\Closure $itemStyleFunc = null;
    private string $indent = '  ';

    public function __construct()
    {
        $this->enumerator = Enumerator::dash();
    }

    public static function new(): self
    {
        return new self();
    }

    /** @param string|self $text  pass a nested ItemList for sublists */
    public function item(string|self $text): self
    {
        $clone = clone $this;
        $clone->items = [...$this->items, $text];
        return $clone;
    }

    /** @param iterable<string|self> $items */
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

    /**
     * Style applied to every rendered item's text. Combine with
     * {@see itemStyleFunc()} for per-item overrides — the func wins.
     */
    public function itemStyle(?Style $s): self
    {
        $c = clone $this;
        $c->itemStyle = $s;
        return $c;
    }

    /**
     * Per-item style callback: `$fn($index, $itemText)`. Applied after
     * the static {@see itemStyle()} so a func can override per row.
     *
     * @param ?\Closure(int $index, string $text): Style $fn
     */
    public function itemStyleFunc(?\Closure $fn): self
    {
        $c = clone $this;
        $c->itemStyleFunc = $fn;
        return $c;
    }

    /** Style applied to enumerator markers. */
    public function enumeratorStyle(?Style $s): self
    {
        $c = clone $this;
        $c->enumeratorStyle = $s;
        return $c;
    }

    /**
     * Indent string prepended to every line of a nested sublist.
     * Defaults to two spaces; set to a styled border rune for tree-like
     * indentation.
     */
    public function indent(string $s): self
    {
        $c = clone $this;
        $c->indent = $s;
        return $c;
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
            $marker    = $markers[$i];
            $pad       = $maxMarker - mb_strlen($marker, 'UTF-8');
            $rawPrefix = $marker === ''
                ? str_repeat(' ', $maxMarker > 0 ? $maxMarker + 1 : 0)
                : $marker . str_repeat(' ', $pad) . ' ';
            $indent = str_repeat(' ', mb_strlen($rawPrefix, 'UTF-8'));

            $styledMarker = $this->enumeratorStyle !== null && $marker !== ''
                ? $this->enumeratorStyle->render($marker) . str_repeat(' ', $pad) . ' '
                : $rawPrefix;

            // Sublist: render recursively then indent its body.
            if ($text instanceof self) {
                $sub = $text->render();
                if ($sub !== '') {
                    foreach (explode("\n", $sub) as $sl) {
                        $lines[] = $indent . $this->indent . $sl;
                    }
                }
                continue;
            }

            $rendered = $text;
            if ($this->itemStyle !== null) {
                $rendered = $this->itemStyle->render($rendered);
            }
            if ($this->itemStyleFunc !== null) {
                $style = ($this->itemStyleFunc)($i, $text);
                $rendered = $style->render($rendered);
            }

            $itemLines = explode("\n", $rendered);
            $first = true;
            foreach ($itemLines as $il) {
                $lines[] = ($first ? $styledMarker : $indent) . $il;
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
