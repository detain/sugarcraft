<?php

declare(strict_types=1);

namespace SugarCraft\Boxer;

use SugarCraft\Core\Util\Width;
use SugarCraft\Sprinkles\Align;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Sprinkles\VAlign;

/**
 * Box-drawing layout renderer.
 *
 * Builds a tree of {@see Node}s (leaf/horizontal/vertical/noborder) and
 * renders it as ANSI box-drawing characters within a fixed viewport.
 *
 * Port of treilik/bubbleboxer.
 *
 * @see https://github.com/treilik/bubbleboxer
 */
final class SugarBoxer
{
    /**
     * Create a new SugarBoxer instance.
     */
    public static function new(): self
    {
        return new self();
    }

    // -------------------------------------------------------------------------
    // Node factory helpers (delegated to Node statics for ergonomics)
    // -------------------------------------------------------------------------

    public function leaf(string $content = ''): Node
    {
        return Node::leaf($content);
    }

    public function horizontal(Node ...$children): Node
    {
        return Node::horizontal(...$children);
    }

    public function vertical(Node ...$children): Node
    {
        return Node::vertical(...$children);
    }

    public function noBorder(Node $child): Node
    {
        return Node::noBorder($child);
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    /**
     * Render a layout node tree into a string within the given viewport.
     *
     * @param Node $root   Root layout node
     * @param int  $width  Viewport width in cells
     * @param int  $height Viewport height in lines
     * @return string      Rendered layout with box-drawing characters
     */
    public function render(Node $root, int $width, int $height): string
    {
        // 2D cell grid: each cell holds one logical character (any byte length).
        // Storing as char-cells avoids byte/multibyte boundary corruption that
        // happens when slicing strings containing UTF-8 box-drawing glyphs.
        $cells = \array_fill(0, $height, \array_fill(0, $width, ' '));
        $this->renderNode($root, 0, 0, $width, $height, $cells);

        $out = [];
        foreach ($cells as $row) {
            $out[] = \implode('', $row);
        }
        return \implode("\n", $out);
    }

    /**
     * Render a node at the given viewport region.
     *
     * @param Node                            $node  Node to render
     * @param int                             $x     Left offset
     * @param int                             $y     Top offset
     * @param int                             $w     Available width
     * @param int                             $h     Available height
     * @param array<int, array<int, string>>  $cells Mutable cell grid (modified in place)
     */
    private function renderNode(Node $node, int $x, int $y, int $w, int $h, array &$cells): void
    {
        if ($w <= 0 || $h <= 0) return;

        switch ($node->kind) {
            case Node::LEAF:
                $this->renderLeaf($node, $x, $y, $w, $h, $cells);
                break;
            case Node::HORIZONTAL:
                $this->renderHorizontal($node, $x, $y, $w, $h, $cells);
                break;
            case Node::VERTICAL:
                $this->renderVertical($node, $x, $y, $w, $h, $cells);
                break;
            case Node::NOBORDER:
                $this->renderNoBorder($node, $x, $y, $w, $h, $cells);
                break;
        }
    }

    private function renderLeaf(Node $node, int $x, int $y, int $w, int $h, array &$cells): void
    {
        $b = $node->border ? 1 : 0;
        $p = $node->padding;

        $cx = $x + $b;          // content left
        $cy = $y + $b;          // content top
        $cw = $w - $b * 2;      // content width
        $ch = $h - $b * 2;      // content height

        if ($cw <= 0 || $ch <= 0) return;

        // Clamp padding when it would consume the entire content axis. Without
        // this, a tiny viewport with non-zero padding zeroes out content area
        // and the leaf disappears entirely.
        $padH = ($p * 2 >= $cw) ? \max(0, \intdiv($cw - 1, 2)) : $p;
        $padV = ($p * 2 >= $ch) ? \max(0, \intdiv($ch - 1, 2)) : $p;

        $pcx = $cx + $padH;
        $pcy = $cy + $padV;
        $pcw = $cw - $padH * 2;
        $pch = $ch - $padV * 2;

        if ($pcw <= 0 || $pch <= 0) return;

        if ($node->border) {
            $this->drawBorder($node->borderStyle, $x, $y, $w, $h, $cells);
        }

        $this->renderContent($node->content, $pcx, $pcy, $pcw, $pch, $cells);
    }

    private function renderHorizontal(Node $node, int $x, int $y, int $w, int $h, array &$cells): void
    {
        $children = $node->children;
        $n = \count($children);
        if ($n === 0) return;

        $b  = $node->border ? 1 : 0;
        $sp = $node->spacing;

        $availableW = $w - $b * 2;
        $availableH = $h - $b * 2;

        if ($availableW <= 0 || $availableH <= 0) return;

        // Distribute width proportionally by minWidth weights
        $weights = \array_map(fn(Node $c) => $c->minWidth > 0 ? $c->minWidth : 1, $children);
        $totalWeight = \array_sum($weights);

        $offsets = $this->distribute($availableW, $weights, $totalWeight, $sp, $b);

        // Draw outer border first
        if ($node->border) {
            $this->drawBorder($node->borderStyle, $x, $y, $w, $h, $cells);
        }

        // Render each child. distribute() already bakes the border pad into
        // offsets[0], so don't add $b again here or the children get pushed
        // one cell deeper than the available space and the trailing child
        // ends up with zero size.
        for ($i = 0; $i < $n; $i++) {
            $child = $children[$i];
            $ox = $x + $offsets[$i];
            $ow = $i === $n - 1
                ? $w - $b - $offsets[$i]
                : $offsets[$i + 1] - $offsets[$i] - $sp;

            $this->renderNode($child, $ox, $y + $b, $ow, $availableH, $cells);

            // Draw vertical separator between children (inside the outer border)
            if ($i < $n - 1 && $sp === 0) {
                $this->drawVLine($node->borderStyle, $x + $offsets[$i + 1] - 1, $y + $b, $availableH, $cells);
            }
        }
    }

    private function renderVertical(Node $node, int $x, int $y, int $w, int $h, array &$cells): void
    {
        $children = $node->children;
        $n = \count($children);
        if ($n === 0) return;

        $b  = $node->border ? 1 : 0;
        $sp = $node->spacing;

        $availableW = $w - $b * 2;
        $availableH = $h - $b * 2;

        if ($availableW <= 0 || $availableH <= 0) return;

        $weights = \array_map(fn(Node $c) => $c->minHeight > 0 ? $c->minHeight : 1, $children);
        $totalWeight = \array_sum($weights);

        $offsets = $this->distribute($availableH, $weights, $totalWeight, $sp, $b);

        if ($node->border) {
            $this->drawBorder($node->borderStyle, $x, $y, $w, $h, $cells);
        }

        for ($i = 0; $i < $n; $i++) {
            $child = $children[$i];
            $oy = $y + $offsets[$i];
            $oh = $i === $n - 1
                ? $h - $b - $offsets[$i]
                : $offsets[$i + 1] - $offsets[$i] - $sp;

            $this->renderNode($child, $x + $b, $oy, $availableW, $oh, $cells);

            if ($i < $n - 1 && $sp === 0) {
                $this->drawHLine($node->borderStyle, $x + $b, $y + $offsets[$i + 1] - 1, $availableW, $cells);
            }
        }
    }

    private function renderNoBorder(Node $node, int $x, int $y, int $w, int $h, array &$cells): void
    {
        if ($node->children === []) return;
        $this->renderNode($node->children[0], $x, $y, $w, $h, $cells);
    }

    // -------------------------------------------------------------------------
    // Content rendering
    // -------------------------------------------------------------------------

    /**
     * Render text content within a content region with word-wrap.
     */
    private function renderContent(string $text, int $x, int $y, int $w, int $h, array &$cells): void
    {
        if ($w <= 0 || $h <= 0) return;

        $wrapped = $this->wordWrap($text, $w);
        $linesToRender = \array_slice($wrapped, 0, $h);

        foreach ($linesToRender as $lineIdx => $line) {
            $lineY = $y + $lineIdx;
            if ($lineY >= \count($cells)) break;

            $chars = \preg_split('//u', $line, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
            for ($i = 0; $i < $w; $i++) {
                $char = $chars[$i] ?? ' ';
                $this->setChar($x + $i, $lineY, $char, $cells);
            }
        }
    }

    /**
     * Word-wrap text to fit a given column width.
     *
     * @return list<string>
     */
    private function wordWrap(string $text, int $width): array
    {
        if ($width <= 0) return [''];

        $result = [];
        foreach (\explode("\n", $text) as $paragraphLine) {
            $words = \preg_split('/\s+/', $paragraphLine) ?: [];
            $current = '';

            foreach ($words as $word) {
                $test = $current === '' ? $word : $current . ' ' . $word;
                if ($this->strWidth($test) <= $width) {
                    $current = $test;
                } else {
                    if ($current !== '') {
                        $result[] = $current;
                    }
                    if ($this->strWidth($word) > $width) {
                        // Split oversized word
                        $result = \array_merge($result, $this->splitWord($word, $width));
                    } else {
                        $current = $word;
                    }
                }
            }

            if ($current !== '') {
                $result[] = $current;
            }
        }

        return $result ?: [''];
    }

    private function splitWord(string $word, int $width): array
    {
        $chunks = [];
        $len = \mb_strlen($word, 'UTF-8');
        for ($i = 0; $i < $len; $i += $width) {
            $chunks[] = \mb_substr($word, $i, $width, 'UTF-8');
        }
        return $chunks ?: [''];
    }

    // -------------------------------------------------------------------------
    // Border drawing
    // -------------------------------------------------------------------------

    /**
     * Draw a border using the characters from the given Border object.
     * Uses Border::rounded() as default when $border is null.
     */
    private function drawBorder(?Border $border, int $x, int $y, int $w, int $h, array &$cells): void
    {
        if ($w < 2 || $h < 2) return;

        $b = $border ?? Border::rounded();

        // Corners
        $this->setChar($x,           $y,           $b->topLeft,     $cells);
        $this->setChar($x + $w - 1,  $y,           $b->topRight,    $cells);
        $this->setChar($x,           $y + $h - 1,  $b->bottomLeft,  $cells);
        $this->setChar($x + $w - 1,  $y + $h - 1,  $b->bottomRight, $cells);

        // Top/bottom edges
        for ($i = 1; $i < $w - 1; $i++) {
            $this->setChar($x + $i, $y,           $b->top,    $cells);
            $this->setChar($x + $i, $y + $h - 1,  $b->bottom, $cells);
        }

        // Left/right edges
        for ($j = 1; $j < $h - 1; $j++) {
            $this->setChar($x,           $y + $j, $b->left,  $cells);
            $this->setChar($x + $w - 1,  $y + $j, $b->right, $cells);
        }
    }

    private function drawVLine(?Border $border, int $x, int $y, int $h, array &$cells): void
    {
        $b = $border ?? Border::rounded();
        for ($j = 0; $j < $h; $j++) {
            $this->setChar($x, $y + $j, $b->left, $cells);
        }
    }

    private function drawHLine(?Border $border, int $x, int $y, int $w, array &$cells): void
    {
        $b = $border ?? Border::rounded();
        for ($i = 0; $i < $w; $i++) {
            $this->setChar($x + $i, $y, $b->top, $cells);
        }
    }

    // -------------------------------------------------------------------------
    // Cell-level operations
    // -------------------------------------------------------------------------

    /**
     * Set a single character at cell ($x, $y) in the grid. The grid is a 2D
     * array where each slot holds one logical character regardless of UTF-8
     * byte length, so writes are O(1) and never split a multibyte glyph.
     */
    private function setChar(int $x, int $y, string $char, array &$cells): void
    {
        if ($y < 0 || $y >= \count($cells)) return;
        if ($x < 0 || $x >= \count($cells[$y])) return;
        $cells[$y][$x] = $char;
    }

    // -------------------------------------------------------------------------
    // Width distribution
    // -------------------------------------------------------------------------

    /**
     * Distribute available space across children by weight.
     *
     * @param list<int> $weights
     * @return list<int> Starting offsets for each child
     */
    private function distribute(int $available, array $weights, int $totalWeight, int $spacing, int $borderPad): array
    {
        $n = \count($weights);
        $offsets = [0 => $borderPad];

        for ($i = 0; $i < $n - 1; $i++) {
            $share = (int) \round($weights[$i] / $totalWeight * ($available - $spacing * ($n - 1)));
            $share = \max($share, 1);
            $offsets[] = $offsets[$i] + $share + $spacing;
        }

        return $offsets;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Visible width — delegates to candy-core's grapheme/width-aware util. */
    private function strWidth(string $s): int
    {
        return Width::string($s);
    }
}
