<?php

declare(strict_types=1);

namespace SugarCraft\Boxer;

use SugarCraft\Buffer\Buffer;
use SugarCraft\Buffer\Cell;
use SugarCraft\Buffer\Diff\DiffEncoder;
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
    /** @var Buffer|null Previous rendered frame for diff-based emission */
    private ?Buffer $previousFrame = null;

    /** @var int|null Previous render width for resize detection */
    private ?int $prevWidth = null;

    /** @var int|null Previous render height for resize detection */
    private ?int $prevHeight = null;
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
     * On the first render (or after a resize), emits the full buffer.
     * On subsequent renders with the same dimensions, emits only the
     * delta via Buffer::diff() + DiffEncoder for reduced SSH bandwidth.
     *
     * @param Node $root   Root layout node
     * @param int  $width  Viewport width in cells
     * @param int  $height Viewport height in lines
     * @return string      Rendered layout with box-drawing characters
     */
    public function render(Node $root, int $width, int $height): string
    {
        // Detect window resize — reset diff state so we emit a full frame.
        if ($this->prevWidth !== null && ($this->prevWidth !== $width || $this->prevHeight !== $height)) {
            $this->previousFrame = null;
        }
        $this->prevWidth = $width;
        $this->prevHeight = $height;

        // 2D cell grid: each cell holds one logical character (any byte length).
        // Storing as char-cells avoids byte/multibyte boundary corruption that
        // happens when slicing strings containing UTF-8 box-drawing glyphs.
        $cells = \array_fill(0, $height, \array_fill(0, $width, ' '));
        $this->renderNode($root, 0, 0, $width, $height, $cells);

        $out = [];
        foreach ($cells as $row) {
            $out[] = \implode('', $row);
        }
        $fullOutput = \implode("\n", $out);

        // First frame or resize: emit full output and store as previousFrame.
        if ($this->previousFrame === null) {
            $this->previousFrame = $this->bufferFromOutput($fullOutput, $width, $height);
            return $fullOutput;
        }

        // Subsequent frames with same dimensions: compute diff and emit delta.
        $currentFrame = $this->bufferFromOutput($fullOutput, $width, $height);
        $ops = $currentFrame->diff($this->previousFrame);
        $this->previousFrame = $currentFrame;

        $encoder = new DiffEncoder();
        return $encoder->encode($ops);
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

        // Flex children grow to fill the leftover after fixed siblings; without
        // any flex child, fall back to distributing width by minWidth weights.
        if ($this->hasFlex($children)) {
            $offsets = $this->distributeFlex(
                $availableW,
                \array_map(fn(Node $c) => $c->totalWidth(), $children),
                \array_map(fn(Node $c) => $c->flex, $children),
                $sp,
                $b,
            );
        } else {
            $weights = \array_map(fn(Node $c) => $c->minWidth > 0 ? $c->minWidth : 1, $children);
            $totalWeight = \array_sum($weights);
            $offsets = $this->distribute($availableW, $weights, $totalWeight, $sp, $b);
        }

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

        // Flex children grow to fill the leftover after fixed siblings; without
        // any flex child, fall back to distributing height by minHeight weights
        // (the historical 1/3-style split).
        if ($this->hasFlex($children)) {
            $offsets = $this->distributeFlex(
                $availableH,
                \array_map(fn(Node $c) => $c->totalHeight(), $children),
                \array_map(fn(Node $c) => $c->flex, $children),
                $sp,
                $b,
            );
        } else {
            $weights = \array_map(fn(Node $c) => $c->minHeight > 0 ? $c->minHeight : 1, $children);
            $totalWeight = \array_sum($weights);
            $offsets = $this->distribute($availableH, $weights, $totalWeight, $sp, $b);
        }

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
            $this->placeLine($line, $x, $lineY, $w, $cells);
        }
    }

    /**
     * Place one (already-wrapped) line into the cell grid, ANSI-aware.
     *
     * The line is split into visible cells — each carrying its leading escape
     * sequences with the visible grapheme they style (escapes are zero-width, so
     * they ride in the same grid cell and never consume a column). Placement and
     * truncation are measured by VISIBLE columns (candy-core {@see Width}), so a
     * styled span keeps its true width and its escapes survive in order; a wide
     * (CJK/emoji) grapheme occupies its two columns with a blanked continuation
     * cell.
     *
     * Reset safety: a colour/attribute left open — whether the source span was
     * unbalanced or a reverse-video span got clipped by the width — is terminated
     * with an SGR reset on the last placed cell, so style never bleeds past the
     * content region into the border or the next row.
     */
    private function placeLine(string $line, int $x, int $y, int $w, array &$cells): void
    {
        ['cells' => $segments, 'trailing' => $trailing] = $this->visualCells($line);

        $col       = 0;
        $lastCol   = -1;
        $carry     = '';      // zero-width graphemes awaiting a base cell
        $styleOpen = false;
        $truncated = false;

        foreach ($segments as $seg) {
            $gw = $this->strWidth($seg);

            // Zero-width grapheme (combining mark / lone joiner): keep it attached
            // to the previous cell, else carry it onto the next visible one.
            if ($gw <= 0) {
                if ($lastCol >= 0) {
                    $cells[$y][$x + $lastCol] .= $seg;
                } else {
                    $carry .= $seg;
                }
                $styleOpen = $this->sgrLeavesStyleOpen($seg, $styleOpen);
                continue;
            }

            if ($col + $gw > $w) {
                $truncated = true;
                break;
            }

            $this->setChar($x + $col, $y, $carry . $seg, $cells);
            $carry     = '';
            $styleOpen = $this->sgrLeavesStyleOpen($seg, $styleOpen);
            $lastCol   = $col;

            // A wide grapheme spans a continuation cell — blank it so the row
            // keeps exactly its visible width and no stale glyph shows through.
            for ($k = 1; $k < $gw; $k++) {
                $this->setChar($x + $col + $k, $y, '', $cells);
            }
            $col += $gw;
        }

        // The line's own trailing escapes (e.g. an SGR reset) ride on the last
        // cell when the whole line fit; if we truncated mid-line they belonged to
        // clipped cells and are dropped (reset safety below still closes the span).
        if (!$truncated && $trailing !== '' && $lastCol >= 0) {
            $cells[$y][$x + $lastCol] .= $trailing;
            $styleOpen = $this->sgrLeavesStyleOpen($trailing, $styleOpen);
        }

        if ($styleOpen && $lastCol >= 0) {
            $cells[$y][$x + $lastCol] .= "\x1b[0m";
        }

        // Pad the remainder of the region with spaces (overwriting prior content).
        for (; $col < $w; $col++) {
            $this->setChar($x + $col, $y, ' ', $cells);
        }
    }

    /**
     * Split a line into per-cell segments for ANSI-aware placement.
     *
     * Each returned cell is "(zero or more leading escape sequences) + one
     * visible grapheme". Escape sequences trailing the final grapheme (an SGR
     * reset, say) are returned separately so the caller can re-attach them and
     * avoid colour bleed.
     *
     * @return array{cells: list<string>, trailing: string}
     */
    private function visualCells(string $line): array
    {
        $cells   = [];
        $pending = '';
        $len     = \strlen($line);
        $i       = 0;

        while ($i < $len) {
            $seq = $this->escapeAt($line, $i);
            if ($seq !== null) {
                $pending .= $seq;
                $i += \strlen($seq);
                continue;
            }
            $g = $this->nextGrapheme($line, $i);
            if ($g === '') {
                $i++; // defensive: never spin on a zero-length read
                continue;
            }
            $cells[] = $pending . $g;
            $pending = '';
            $i += \strlen($g);
        }

        return ['cells' => $cells, 'trailing' => $pending];
    }

    /**
     * If an ANSI escape sequence begins at byte $i, return it; else null.
     * Recognises CSI (ESC [ … final 0x40-0x7e) and OSC (ESC ] … BEL/ST)
     * sequences. For any other ESC, consumes the two-byte form ONLY when the
     * second byte is ASCII (an ECMA-48 nF/Fe/Fs escape's final byte is always
     * 0x20-0x7e) — so a stray ESC sitting before a multi-byte grapheme returns
     * just the ESC and never splits that grapheme.
     */
    private function escapeAt(string $s, int $i): ?string
    {
        if (($s[$i] ?? '') !== "\x1b") {
            return null;
        }
        $next = $s[$i + 1] ?? '';
        $len  = \strlen($s);

        if ($next === '[') {
            $j = $i + 2;
            while ($j < $len) {
                $c = \ord($s[$j]);
                $j++;
                if ($c >= 0x40 && $c <= 0x7e) break;
            }
            return \substr($s, $i, $j - $i);
        }
        if ($next === ']') {
            $j = $i + 2;
            while ($j < $len) {
                if ($s[$j] === "\x07") { $j++; break; }
                if ($s[$j] === "\x1b" && ($s[$j + 1] ?? '') === '\\') { $j += 2; break; }
                $j++;
            }
            return \substr($s, $i, $j - $i);
        }

        return ($next !== '' && \ord($next) < 0x80) ? \substr($s, $i, 2) : "\x1b";
    }

    /** Read one extended grapheme cluster starting at byte $i (intl, with a UTF-8 fallback). */
    private function nextGrapheme(string $s, int $i): string
    {
        if (\function_exists('grapheme_extract')) {
            $next    = 0;
            $cluster = \grapheme_extract($s, 1, \GRAPHEME_EXTR_COUNT, $i, $next);
            if (\is_string($cluster) && $cluster !== '') {
                return $cluster;
            }
        }
        $b     = \ord($s[$i]);
        $bytes = match (true) {
            ($b & 0x80) === 0    => 1,
            ($b & 0xe0) === 0xc0 => 2,
            ($b & 0xf0) === 0xe0 => 3,
            ($b & 0xf8) === 0xf0 => 4,
            default              => 1,
        };
        return \substr($s, $i, $bytes);
    }

    /**
     * Track SGR open/closed state across the escape sequences in $s, returning
     * whether a style is left open afterwards. ESC[0m / ESC[m close; any other
     * attribute opens. The parameters of extended-colour selectors
     * (38/48 ; 5 ; n and 38/48 ; 2 ; r ; g ; b) are skipped so a colour index of
     * 0 isn't mistaken for a reset.
     */
    private function sgrLeavesStyleOpen(string $s, bool $open): bool
    {
        if (\strpos($s, "\x1b[") === false) {
            return $open;
        }
        if (\preg_match_all('/\x1b\[([0-9;]*)m/', $s, $matches) === 0) {
            return $open;
        }
        foreach ($matches[1] as $params) {
            if ($params === '') {
                $open = false; // ESC[m == reset
                continue;
            }
            $codes = \explode(';', $params);
            for ($k = 0, $n = \count($codes); $k < $n; $k++) {
                $code = (int) $codes[$k];
                if (($code === 38 || $code === 48) && isset($codes[$k + 1])) {
                    $k += ((int) $codes[$k + 1]) === 2 ? 4 : 2;
                    $open = true; // a colour is being set
                    continue;
                }
                $open = $code !== 0;
            }
        }

        return $open;
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
            // A non-empty line that already fits needs no wrapping — preserve it
            // verbatim. Word-wrapping re-joins on single spaces, which collapses
            // intentional runs of whitespace (column alignment in a table, padded
            // key bindings in a hint), so only ever rewrap lines that overflow.
            if ($paragraphLine !== '' && $this->strWidth($paragraphLine) <= $width) {
                $result[] = $paragraphLine;
                continue;
            }
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

    /**
     * Break an oversized word into width-sized chunks, ANSI-aware: escape
     * sequences ride with their grapheme and never count toward the width, and
     * each chunk is measured by visible columns (so wide graphemes and styled
     * runs split on real cell boundaries rather than byte offsets).
     *
     * A single grapheme wider than $width (e.g. a 2-column CJK char in a
     * 1-column region) is emitted as its own oversized chunk — a grapheme is
     * atomic and cannot be split — and {@see placeLine} then clips it to the
     * region. {@see wordWrap} is the sole caller and only reaches here with
     * $width >= 1 and a word whose visible width exceeds $width.
     *
     * @return list<string>
     */
    private function splitWord(string $word, int $width): array
    {
        // Sole caller wordWrap() only reaches here with $width >= 1 and a word of
        // visible width > $width, so the tokenization always yields ≥1 segment.
        ['cells' => $segments, 'trailing' => $trailing] = $this->visualCells($word);

        $chunks = [];
        $buf    = '';
        $col    = 0;
        foreach ($segments as $seg) {
            $gw = $this->strWidth($seg);
            if ($buf !== '' && $gw > 0 && $col + $gw > $width) {
                $chunks[] = $buf;
                $buf      = '';
                $col      = 0;
            }
            $buf .= $seg;
            $col += $gw;
        }
        if ($trailing !== '') {
            $buf .= $trailing;
        }
        if ($buf !== '') {
            $chunks[] = $buf;
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

    /** @param list<Node> $children */
    private function hasFlex(array $children): bool
    {
        foreach ($children as $c) {
            if ($c->flex > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Distribute space when at least one child is flexible. Fixed (non-flex)
     * children take their natural size ($bases, i.e. totalWidth/totalHeight);
     * the leftover is shared among flex children in proportion to their weight,
     * with the last flex child absorbing the rounding remainder so the children
     * sum to exactly the available content span. Returns the same starting-offset
     * shape as {@see distribute()} (the caller derives the final child's size as
     * the remainder, which equals its computed size because the sizes sum exact).
     *
     * @param list<int> $bases   natural size per child (ignored for flex children)
     * @param list<int> $flexes  flex weight per child (0 = fixed)
     * @return list<int>
     */
    private function distributeFlex(int $available, array $bases, array $flexes, int $spacing, int $borderPad): array
    {
        $n       = \count($flexes);
        $gaps    = $spacing * \max(0, $n - 1);
        $content = \max(0, $available - $gaps);

        $totalFlex = \array_sum($flexes);
        $fixedSum  = 0;
        $lastFlex  = -1;
        foreach ($flexes as $i => $f) {
            if ($f > 0) {
                $lastFlex = $i;
            } else {
                $fixedSum += \max(0, $bases[$i]);
            }
        }
        $remaining = \max(0, $content - $fixedSum);

        $sizes     = [];
        $allocated = 0;
        foreach ($flexes as $i => $f) {
            if ($f <= 0) {
                $sizes[$i] = \max(0, $bases[$i]);
            } elseif ($i === $lastFlex) {
                $sizes[$i] = $remaining - $allocated; // absorb the rounding remainder
            } else {
                // $totalFlex >= 1 here: distributeFlex only runs when a flex child exists.
                $share      = (int) \floor($f / $totalFlex * $remaining);
                $sizes[$i]  = $share;
                $allocated += $share;
            }
        }

        $offsets = [$borderPad];
        for ($i = 0; $i < $n - 1; $i++) {
            $offsets[] = $offsets[$i] + $sizes[$i] + $spacing;
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

    /**
     * Build a Buffer from a multi-line string output.
     *
     * All cells are created with null style — the diff algorithm will
     * still work correctly for detecting changed character positions.
     *
     * @param string $output Multi-line string from render()
     * @param int    $width  Buffer width in cells
     * @param int    $height Buffer height in rows
     */
    private function bufferFromOutput(string $output, int $width, int $height): Buffer
    {
        $buffer = Buffer::new($width, $height);
        $lines = \explode("\n", $output);

        for ($row = 0; $row < $height; $row++) {
            $line = $lines[$row] ?? '';
            for ($col = 0; $col < $width; $col++) {
                $char = isset($line[$col]) ? \mb_substr($line, $col, 1) : ' ';
                $cell = Cell::new($char, null, null, 1);
                $buffer = $buffer->withCellAt($col, $row, $cell);
            }
        }

        return $buffer;
    }

    /**
     * Reset the previous-frame buffer, forcing the next render to emit
     * a full frame (used on window resize or cursor-position-lost events).
     */
    public function resetPreviousFrame(): void
    {
        $this->previousFrame = null;
    }
}
