<?php

declare(strict_types=1);

namespace SugarCraft\Panels;

use SugarCraft\CellBuffer\Buffer;
use SugarCraft\CellBuffer\Cell;
use SugarCraft\CellBuffer\Diff\DiffEncoder;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;
use SugarCraft\Constraints\Constraint\Constraint;
use SugarCraft\Constraints\Direction;
use SugarCraft\Constraints\GreedySolver;
use SugarCraft\Constraints\Region as ConstraintsRegion;
use SugarCraft\Style\Align;
use SugarCraft\Style\Border;
use SugarCraft\Style\Style;
use SugarCraft\Style\VAlign;

/**
 * Box-drawing layout renderer.
 *
 * Builds a tree of {@see Node}s (leaf/horizontal/vertical/noborder) and
 * renders it as ANSI box-drawing characters within a fixed viewport. Borders
 * and styles ride in as sugar-style primitives; content is placed by visible
 * columns (escapes zero-width, wide graphemes two).
 *
 * Design antecedent: treilik/bubbleboxer — SugarPanels is a native PHP
 * project that grew its own flex/solver/diff machinery beyond the original.
 *
 * @see https://github.com/treilik/bubbleboxer
 */
final class SugarPanels
{
    /**
     * Per-cell zero-width budget, in bytes: combining-mark accumulation on an
     * already-placed base cell, and the pre-base carry buffer, are each clipped
     * to this size (fail-soft — marks past the budget are dropped). Legitimate
     * grapheme clusters sit far below it; the cap exists so hostile content
     * cannot grow a single grid cell (and therefore the rendered frame) without
     * bound.
     */
    private const MAX_CARRY = 100;

    /**
     * Byte budget for escape sequences awaiting their grapheme inside
     * {@see visualCells()} (and the line's trailing escape run). Whole
     * sequences past the budget are dropped (fail-soft) so an escape flood
     * cannot grow one cell's payload without bound; sized to keep legitimate
     * OSC-8 hyperlink sequences intact.
     */
    private const MAX_PENDING_ESCAPES = 8192;

    /**
     * Absolute cursor home (CUP to row 1, column 1) prefixed to every frame with
     * at least one column. A zero-width render is the degenerate case: its rows
     * are empty strings joined by bare newlines, with no protocol at all (pinned
     * in EdgeCaseTest and FrameProtocolTest).
     *
     * A frame is only replayable if the terminal knows where it starts. Diff
     * ops address cells absolutely but carry no positioning before their first
     * change — a delta then painted wherever the previous frame happened to
     * leave the cursor, which is how one 'A' plus one 'B' came to read 'AB'.
     * Homing each frame makes full repaints and deltas land on the same origin
     * regardless of cursor history.
     */
    private const HOME = "\x1b[H";

    /**
     * Fixed home anchor {@see DiffEncoder::encode()} opens every encoded stream
     * with (sugar-cellbuffer guarantees it as the documented prologue). A
     * panels delta homes once with {@see self::HOME}; the encoder prologue is a
     * second anchor over the same origin, so the diff path peels it (see
     * {@see render()}) and the wire carries one home token per frame.
     */
    private const ENCODER_HOME = "\x1b[1;1H";

    /**
     * Erase-in-display, prefixed to a full frame only when the viewport
     * RESIZED. Cells outside the new viewport are never visited by the new
     * frame, so without an erase the old frame's glyphs sit on screen as
     * ghosts (a shrink leaves stale columns and rows behind).
     */
    private const ERASE = "\x1b[2J";

    /**
     * Largest viewport side this renderer will paint, in cells.
     *
     * A viewport size is HOST input — a winsize ioctl, a tmux resize, a value
     * parsed off a terminal report — not the host's intent. Every cell is a
     * PHP array slot retained for the whole render, so an unbounded size meant
     * one bogus event could allocate more memory than the host had. Real
     * terminals are nowhere near 4096 cells wide, so the ceiling never bites
     * legitimately; it exists to make the failure loud instead of fatal.
     *
     * Documented in README.md ("Input ranges"); pinned against the prose in
     * ReadmeSnippetsTest so the two cannot drift apart.
     */
    public const MAX_VIEWPORT_DIMENSION = 4096;

    /**
     * Largest total area this renderer will paint, in cells.
     *
     * The per-side ceiling alone is not enough: 4096x4096 is 16.7 million
     * cells. The area budget is what actually bounds the allocation.
     */
    public const MAX_VIEWPORT_CELLS = 1_000_000;

    /**
     * Per-Style SGR-prefix cache, keyed by the Style OBJECT via a WeakMap.
     *
     * A WeakMap ties each entry's lifetime to its Style: when a style is GC'd,
     * its entry is evicted automatically. This replaces the previous
     * spl_object_id keying, which had a latent correctness bug — PHP reuses an
     * object id immediately after the object dies, so a later, DIFFERENT style
     * allocated into that same id slot would alias the dead style's stale prefix
     * and render with the wrong colour/attributes. The old int-keyed array also
     * never evicted, growing unbounded on a long-lived instance. Keying by the
     * object identity fixes both without changing a single rendered byte.
     *
     * @var \WeakMap<Style, string>
     */
    private \WeakMap $sgrPrefixCache;

    /** @var Buffer|null Lazily-built previous frame buffer for diff-based emission */
    private ?Buffer $previousFrame = null;

    /** @var list<list<string>>|null Previous frame's cell grid, kept so the diff buffer can be built lazily on frame 2 */
    private ?array $previousCells = null;

    /** @var int|null Previous render width for resize detection */
    private ?int $prevWidth = null;

    /** @var int|null Previous render height for resize detection */
    private ?int $prevHeight = null;

    /**
     * sugar-constraints's constraint solver in sugar-panels parity mode. The flex
     * distribution (fixed siblings take their natural size, flex children share
     * the leftover by weight) is exactly what sugar-constraints's GreedySolver models;
     * {@see GreedySolver::compat()} flips its three divergent axes (round-split,
     * remainder-to-last, non-truncating overflow) so it reproduces
     * {@see distributeFlex()} byte-for-byte. Immutable + stateless → shared.
     */
    private readonly GreedySolver $flexSolver;

    /**
     * Create a new SugarPanels instance — the canonical door (mirrors the house
     * `::new()` factory convention Node follows). It is sugar over the identical
     * public constructor below; both are documented at {@see self::__construct()}.
     */
    public static function new(): self
    {
        return new self();
    }

    /**
     * Create a new SugarPanels instance.
     *
     * The canonical door is {@see self::new()}; this public constructor is the
     * SAME object reached directly. Both stay open on purpose: a SugarPanels is
     * a STATEFUL per-session renderer (it remembers the previous frame so the
     * next can diff against it), so a host that treats "one render session" as a
     * unit constructs exactly one — and `new` is the honest spelling of that,
     * not a footgun. `::new()` mirrors the factory house convention (Node and the
     * rest of the fleet); it is sugar over the identical call, not a second
     * lifecycle. No hidden state escapes either door: they share one constructor
     * body.
     */
    public function __construct()
    {
        $this->sgrPrefixCache = new \WeakMap();
        $this->flexSolver = GreedySolver::compat();
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
     * FRAME PROTOCOL — every frame with at least one column is self-positioning:
     * it opens with
     * an absolute cursor home (ESC [ H), so a full frame and a delta both paint
     * from row 1 column 1 of the viewport no matter where the previous frame
     * left the cursor. Diff ops address cells absolutely, and the encoder's own
     * home prologue is peeled on the diff path (see {@see self::ENCODER_HOME})
     * so a frame carries exactly one home token. Without the home a delta would
     * land wherever the last frame happened to stop. A full frame that follows a
     * RESIZE additionally opens with erase-in-display (ESC [ 2J): cells that the
     * new viewport cannot reach are never visited by the new frame, and would
     * otherwise keep the old frame's glyphs on screen. The erase is emitted only
     * on a resize — never for a first frame, a zero-area frame or a delta — so a
     * host is never surprised by a wipe it did not cause.
     *
     * Because a delta only carries changed cells, the sequence is opaque to text
     * extraction: capture a full frame (first render, after {@see
     * resetPreviousFrame()}, or after any resize) when a screen reader, clipboard
     * or log needs readable text rather than a cell patch.
     *
     * @param Node $root   Layout node tree
     * @param int  $width  Viewport width in cells (0 allowed → empty rows; negative rejected)
     * @param int  $height Viewport height in lines (0 allowed → ''; negative rejected)
     * @return string      Rendered layout with box-drawing characters
     *
     *  A zero-area frame (either dimension 0) always paints in full and is never
     *  retained as diff state: it has no cell grid a buffer could represent, so
     *  recording it would make the next same-size render crash. A host that
     *  collapses a pane to zero lines and back therefore gets a full repaint,
     *  which is exactly what the terminal needs after being off-screen.
     *
     * @throws \InvalidArgumentException If a viewport dimension is negative,
     *                                  or exceeds MAX_VIEWPORT_DIMENSION per
     *                                  side / MAX_VIEWPORT_CELLS in total.
     */
    public function render(Node $root, int $width, int $height): string
    {
        // Fail loud at the API boundary: without this, a negative
        // dimension leaks array_fill()'s raw ValueError from library internals.
        if ($width < 0 || $height < 0) {
            throw new \InvalidArgumentException(
                "Viewport dimensions must not be negative, got {$width}x{$height}"
            );
        }

        // The upper bound matters for the same reason. A viewport is host
        // input — a winsize read, a tmux report, a resize event — and the
        // whole grid is allocated from it: an unbounded size let one bogus
        // number ask for more memory than the host had (4000x3000 was ~225
        // MiB of cells before a single glyph was painted), and PHP_INT_MAX
        // dimensions used to leak array_fill()'s ValueError instead.
        if ($width > self::MAX_VIEWPORT_DIMENSION
            || $height > self::MAX_VIEWPORT_DIMENSION
            || $width * $height > self::MAX_VIEWPORT_CELLS
        ) {
            throw new \InvalidArgumentException(
                "Viewport {$width}x{$height} exceeds the supported maximum ("
                . self::MAX_VIEWPORT_DIMENSION . " per side, "
                . self::MAX_VIEWPORT_CELLS . ' cells in total)'
            );
        }

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

        // A zero-area frame has no cell grid to diff against: sugar-cellbuffer
        // refuses a zero-dimension buffer, so storing this shape as retained
        // state made the NEXT same-size render crash inside Buffer::fromGrid().
        // Zero area therefore never enters the diff state — it paints empty and
        // leaves the renderer as if it had not been called.
        if ($width === 0 || $height === 0) {
            $this->previousCells = null;
            $this->previousFrame = null;

            // Nothing is painted, so nothing needs positioning: a zero-area
            // frame costs no protocol bytes either.
            return $fullOutput;
        }

        // 'Had state' and 'the state does not fit this viewport' are different
        // events: only a RESIZE needs the erase. A renderer that was merely
        // reset (or that just painted a zero-area frame) has nothing on screen
        // to clear, and the host's own startup paint must not wipe the terminal.
        $hadState = $this->previousCells !== null;
        $resized = $hadState && ($this->prevWidth !== $width || $this->prevHeight !== $height);

        // First frame (or after a resize): emit the full output and remember the
        // cell GRID. Building the diff buffer here is pure waste for callers that
        // use a fresh SugarPanels per render and never reach the diff path, so it
        // is deferred to the first subsequent same-dimension render below. The
        // grid — not the flattened string — is the retained state: re-splitting
        // the flat string cannot recover cell boundaries once multi-byte glyphs
        // and escape-prefixed cells have been concatenated.
        if (!$hadState || $resized) {
            $this->previousCells = $cells;
            $this->prevWidth = $width;
            $this->prevHeight = $height;
            $this->previousFrame = null;

            // A resize retires cells that the new frame cannot reach: their old
            // glyphs would otherwise sit on screen permanently.
            return ($resized ? self::ERASE : '') . self::HOME . $fullOutput;
        }

        // Subsequent frames with same dimensions: materialise the previous buffer lazily
        // (exactly ONCE — cached in $previousFrame), build the current buffer, diff and
        // emit the delta. Reused callers thus build exactly one buffer per frame, same as
        // before; fresh-instance callers build zero.
        $prev = $this->previousFrame ??= $this->bufferFromGrid($this->previousCells, $width, $height);
        $current = $this->bufferFromGrid($cells, $width, $height);
        $ops = $current->diff($prev);
        $this->previousFrame = $current;
        $this->previousCells = $cells;

        $encoder = new DiffEncoder();
        $delta = $encoder->encode($ops);

        // The encoder opens every stream with its own home anchor; this frame
        // already homes with HOME (above). Peel the redundant prologue so a
        // delta carries exactly one positioning token — the diff path exists to
        // save bytes, and double-homing taxes every frame.
        if (\str_starts_with($delta, self::ENCODER_HOME)) {
            $delta = \substr($delta, \strlen(self::ENCODER_HOME));
        }

        return self::HOME . $delta;
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
    private function renderNode(Node $node, int $x, int $y, int $w, int $h, array &$cells, bool $noFrames = false): void
    {
        if ($w <= 0 || $h <= 0) return;

        // Apply margin as outer inset (shifts the region inward)
        [$mt, $mr, $mb, $ml] = $node->margin;
        $x += $ml;
        $y += $mt;
        $w -= $ml + $mr;
        $h -= $mt + $mb;
        if ($w <= 0 || $h <= 0) return;

        switch ($node->kind) {
            case Node::LEAF:
                $this->renderLeaf($node, $x, $y, $w, $h, $cells, $noFrames);
                break;
            case Node::HORIZONTAL:
                $this->renderHorizontal($node, $x, $y, $w, $h, $cells, $noFrames);
                break;
            case Node::VERTICAL:
                $this->renderVertical($node, $x, $y, $w, $h, $cells, $noFrames);
                break;
            case Node::NOBORDER:
                $this->renderNoBorder($node, $x, $y, $w, $h, $cells);
                break;
        }
    }

    /**
     * Apply a node's own max* to the region it was handed.
     *
     * maxWidth/maxHeight cap the node's TOTAL footprint — frame and padding
     * included — which is the same frame of reference the parent layouts use when
     * they reserve space for a child. The leaf used to clamp its CONTENT box
     * instead, so one setter meant two things: a capped leaf still painted a
     * full-size frame around narrower text, and a vertical stack ignored the
     * width cap entirely.
     *
     * @return array{0: int, 1: int} The capped [width, height].
     */
    private function capRegion(Node $node, int $w, int $h): array
    {
        if ($node->maxWidth > 0 && $node->maxWidth < $w) {
            $w = $node->maxWidth;
        }
        if ($node->maxHeight > 0 && $node->maxHeight < $h) {
            $h = $node->maxHeight;
        }

        return [$w, $h];
    }

    private function renderLeaf(Node $node, int $x, int $y, int $w, int $h, array &$cells, bool $noFrames = false): void
    {
        // max* caps the node's WHOLE footprint — frame and padding included.
        // That is the same frame of reference the parents use (they hand a leaf
        // a region already capped by max*), and it replaces the older content-box
        // clamp below, which let a capped leaf still paint a full-size frame
        // around narrower text: one setter, two meanings.
        [$w, $h] = $this->capRegion($node, $w, $h);

        $b = ($node->border === true && $noFrames === false) ? 1 : 0;
        $p = $node->padding;

        $cx = $x + $b;          // content left
        $cy = $y + $b;          // content top
        $cw = $w - $b * 2;      // content width
        $ch = $h - $b * 2;      // content height

        // Draw the frame BEFORE the content-region guards: a bordered
        // box whose region is exactly its border (h=2 or w=2) still has a valid
        // frame to show — returning early here used to blank the whole box.
        // drawBorder itself no-ops below 2x2, so this stays safe at 1-wide/tall.
        // A frameless subtree (inside a noBorder() wrapper) draws none at all.
        if ($b === 1) {
            $this->drawBorder($node, $x, $y, $w, $h, $cells);
        }

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

        $this->renderContent($node->content, $pcx, $pcy, $pcw, $pch, $cells, $node->style, $node->alignH, $node->alignV);
    }

    /**
     * Render a horizontal (left-to-right) panel layout.
     *
     * Separators between children are drawn whenever spacing === 0 — with or
     * without flex children. In a flex layout the separator lands on the
     * dynamically resolved child boundary, where it overwrites the LAST COLUMN
     * of the previous child: a borderless child loses its final cell there, and
     * a bordered one is skipped entirely because its own right edge already is
     * the divider (painting over it used to shave the corner glyphs off the
     * neighbouring frame). Use spacing >= 1 to reserve a visual gap.
     *
     * @param Node                           $node  Node to render
     * @param int                            $x     Left offset
     * @param int                            $y     Top offset
     * @param int                            $w     Available width
     * @param int                            $h     Available height
     * @param array<int, array<int, string>> $cells Mutable cell grid (modified in place)
     */
    private function renderHorizontal(Node $node, int $x, int $y, int $w, int $h, array &$cells, bool $noFrames = false): void
    {
        $children = $node->children;
        $n = \count($children);

        [$w, $h] = $this->capRegion($node, $w, $h);

        $b  = ($node->border === true && $noFrames === false) ? 1 : 0;
        $sp = $node->spacing;

        $availableW = $w - $b * 2;
        $availableH = $h - $b * 2;

        // Draw outer border first: before the collapse guards, so a panel whose
        // children area shrinks to zero — and a panel with NO children — still
        // shows its frame. An empty bordered container used to return before the
        // border was reached, so the documented 'still shows its frame' promise
        // only held for children that collapse, never for an empty list.
        if ($b === 1) {
            $this->drawBorder($node, $x, $y, $w, $h, $cells);
        }

        if ($n === 0) return;

        if ($availableW <= 0 || $availableH <= 0) return;

        // Flex children grow to fill the leftover after fixed siblings; without
        // any flex child, fall back to distributing width by minWidth weights.
        if ($this->hasFlex($children) === true) {
            // Single-pass: build bases and flexes arrays together
            $bases = [];
            $flexes = [];
            foreach ($children as $c) {
                $bases[] = $c->totalWidth();
                $flexes[] = $c->flex;
            }
            $offsets = $this->distributeFlex($availableW, $bases, $flexes, $sp, $b);
        } else {
            $weights = \array_map(fn(Node $c) => $c->minWidth > 0 ? $c->minWidth : 1, $children);
            $totalWeight = \array_sum($weights);
            $offsets = $this->distribute($availableW, $weights, $totalWeight, $sp, $b);
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
            if ($child->maxWidth > 0) {
                // The cap rides the child's TOTAL claim (maxWidth + margin):
                // renderNode() insets the child's margin from this region
                // before the child's own cap applies, so capping at bare
                // maxWidth charged the margin twice and squeezed margined
                // children below what totalWidth() measures (PB-PANELS-34).
                $ow = \min($ow, $child->maxWidth + $child->marginWidth());
            }

            $this->renderNode($child, $ox, $y + $b, $ow, $availableH, $cells, $noFrames);

            // Vertical separator between children, in the previous child's last
            // column when there is no gap to put it in. Skipped for a frameless
            // subtree; inside a painted column the divider itself declines to
            // overwrite whatever is already on screen — see drawVLine().
            if ($i < $n - 1 && $sp === 0 && $noFrames === false) {
                $this->drawVLine($node->borderStyle, $x + $offsets[$i + 1] - 1, $y + $b, $availableH, $cells);
            }
        }
    }

    /**
     * Render a vertical (top-to-bottom) panel layout.
     *
     * @see renderHorizontal() for the separator law: at spacing 0 the divider
     * overwrites the previous child's LAST ROW, and a neighbour that paints its
     * own bottom edge there is left alone so its frame survives intact.
     *
     * @param Node                           $node  Node to render
     * @param int                            $x     Left offset
     * @param int                            $y     Top offset
     * @param int                            $w     Available width
     * @param int                            $h     Available height
     * @param array<int, array<int, string>> $cells Mutable cell grid (modified in place)
     */
    private function renderVertical(Node $node, int $x, int $y, int $w, int $h, array &$cells, bool $noFrames = false): void
    {
        $children = $node->children;
        $n = \count($children);

        [$w, $h] = $this->capRegion($node, $w, $h);

        $b  = ($node->border === true && $noFrames === false) ? 1 : 0;
        $sp = $node->spacing;

        $availableW = $w - $b * 2;
        $availableH = $h - $b * 2;

        // Draw outer border first: before the collapse guards, so a panel whose
        // children area shrinks to zero — and a panel with NO children — still
        // shows its frame (see renderHorizontal()).
        if ($b === 1) {
            $this->drawBorder($node, $x, $y, $w, $h, $cells);
        }

        if ($n === 0) return;

        if ($availableW <= 0 || $availableH <= 0) return;

        // Flex children grow to fill the leftover after fixed siblings; without
        // any flex child, fall back to distributing height by minHeight weights
        // (the historical 1/3-style split).
        if ($this->hasFlex($children) === true) {
            // Single-pass: build bases and flexes arrays together
            $bases = [];
            $flexes = [];
            foreach ($children as $c) {
                $bases[] = $c->totalHeight();
                $flexes[] = $c->flex;
            }
            $offsets = $this->distributeFlex($availableH, $bases, $flexes, $sp, $b);
        } else {
            $weights = \array_map(fn(Node $c) => $c->minHeight > 0 ? $c->minHeight : 1, $children);
            $totalWeight = \array_sum($weights);
            $offsets = $this->distribute($availableH, $weights, $totalWeight, $sp, $b);
        }

        for ($i = 0; $i < $n; $i++) {
            $child = $children[$i];
            $oy = $y + $offsets[$i];
            $oh = $i === $n - 1
                ? $h - $b - $offsets[$i]
                : $offsets[$i + 1] - $offsets[$i] - $sp;
            if ($child->maxHeight > 0) {
                // Margin allowance per PB-PANELS-34; see the horizontal site
                // for why the cap rides the child's TOTAL claim.
                $oh = \min($oh, $child->maxHeight + $child->marginHeight());
            }
            // A stacked child is capped on its COLUMNS too. Only the horizontal
            // layout used to honour maxWidth, so the same setter silently meant
            // 'row height' in a vertical stack and 'box width' anywhere else.
            $ow = $availableW;
            if ($child->maxWidth > 0) {
                $ow = \min($ow, $child->maxWidth + $child->marginWidth());
            }

            $this->renderNode($child, $x + $b, $oy, $ow, $oh, $cells, $noFrames);

            // Horizontal separator between stacked children, in the previous
            // child's last row — see drawVLine() for why the divider declines to
            // paint over cells that already carry a glyph.
            if ($i < $n - 1 && $sp === 0 && $noFrames === false) {
                $this->drawHLine($node->borderStyle, $x + $b, $y + $offsets[$i + 1] - 1, $ow, $cells);
            }
        }
    }

    /**
     * Render the noBorder() wrapper: its subtree is laid out flat.
     *
     * The wrapper used to hand the region straight to its child unchanged, so
     * wrapping a bordered panel in noBorder() produced a byte-identical frame —
     * the documented "children rendered flat without vertical separators"
     * promise did nothing. The child is now rendered with $noFrames, which
     * suppresses every frame and every spacing-0 divider in the subtree.
     */
    private function renderNoBorder(Node $node, int $x, int $y, int $w, int $h, array &$cells): void
    {
        if ($node->children === []) return;
        $this->renderNode($node->children[0], $x, $y, $w, $h, $cells, true);
    }

    // -------------------------------------------------------------------------
    // Content rendering
    // -------------------------------------------------------------------------

    /**
     * Render text content within a content region with word-wrap.
     *
     * @param Style|null  $style   Optional SGR style prefix (fg/bg/attrs from sugar-style)
     * @param Align|null $alignH  Horizontal text alignment
     * @param VAlign|null $alignV Vertical text alignment
     */
    private function renderContent(string $text, int $x, int $y, int $w, int $h, array &$cells, ?Style $style = null, ?Align $alignH = null, ?VAlign $alignV = null): void
    {
        if ($w <= 0 || $h <= 0) return;

        $wrapped = $this->wordWrap($text, $w);
        $linesToRender = \array_slice($wrapped, 0, $h);
        $numLines = \count($linesToRender);

        // Compute vertical alignment top pad
        $topPad = 0;
        if ($alignV === VAlign::Middle) {
            $topPad = \intdiv($h - $numLines, 2);
        } elseif ($alignV === VAlign::Bottom) {
            $topPad = $h - $numLines;
        }
        $topPad = \max(0, $topPad);

        // Pre-compute the SGR prefix from the style: render a single-space probe and
        // keep ONLY its leading escape runs (the opening SGR), dropping the probed
        // space and the closing reset the probe exists to carry.
        // Cache per Style OBJECT via the WeakMap (see $sgrPrefixCache) so repeated leaves and
        // frames that reuse the same immutable Style skip the probe render, while a freed
        // style's entry is evicted automatically and never aliases a later style.
        $sgrPrefix = '';
        if ($style !== null) {
            $cached = $this->sgrPrefixCache[$style] ?? null;
            if ($cached !== null) {
                $sgrPrefix = $cached;
            } else {
                $sgrPrefix = $this->openingSgr($style->render(' '));
                $this->sgrPrefixCache[$style] = $sgrPrefix;
            }
        }

        foreach ($linesToRender as $lineIdx => $line) {
            $lineY = $y + $topPad + $lineIdx;
            if ($lineY >= \count($cells)) break;

            // Apply horizontal alignment: compute left pad and reduce effective width
            $leftPad = 0;
            $lw = $this->strWidth($line);
            if ($alignH === Align::Center) {
                $leftPad = \intdiv($w - $lw, 2);
            } elseif ($alignH === Align::Right) {
                $leftPad = $w - $lw;
            }
            $leftPad = \max(0, $leftPad);

            // Prepend SGR prefix to styled lines.
            // The pad bound passed to placeLine is the REMAINING width after the
            // alignment shift: padding to the full $w from the shifted origin
            // would write leftPad cells past the region's right edge and erase
            // the already-drawn border.
            $lineToPlace = $sgrPrefix !== '' ? $sgrPrefix . $line : $line;
            $this->placeLine($lineToPlace, $x + $leftPad, $lineY, $w - $leftPad, $cells);
        }
    }

    /**
     * The leading escape runs of a styled render — that is, the opening SGR a
     * style emits before its first visible byte.
     *
     * Used to apply a leaf's Style without paying for the probe character the
     * style was rendered with: Style::render() emits `SGR-open + body + reset`,
     * and only the opening run may be prepended to a content line. Consuming
     * escapes from offset 0 with the same scanner the renderer uses for content
     * (see {@see escapeAt}) keeps the derivation in step with the escape
     * vocabulary of the paint path; the first non-escape byte ends the run.
     */
    private function openingSgr(string $styled): string
    {
        $prefix = '';
        $offset = 0;
        $length = \strlen($styled);

        while ($offset < $length && $styled[$offset] === "\x1b") {
            $escape = $this->escapeAt($styled, $offset);
            if ($escape === null) {
                break;
            }
            $prefix .= $escape;
            $offset += \strlen($escape);
        }

        return $prefix;
    }

    /**
     * Place one (already-wrapped) line into the cell grid, ANSI-aware.
     *
     * The line is split into visible cells — each carrying its leading escape
     * sequences with the visible grapheme they style (escapes are zero-width, so
     * they ride in the same grid cell and never consume a column). Placement and
     * truncation are measured by VISIBLE columns (sugar-core {@see Width}), so a
     * styled span keeps its true width and its escapes survive in order; a wide
     * (CJK/emoji) grapheme occupies its two columns with a blanked continuation
     * cell.
     *
     * Reset safety: a colour/attribute left open — whether the source span was
     * unbalanced or a reverse-video span got clipped by the width — is terminated
     * with an SGR reset on the last placed cell, so style never bleeds past the
     * content region into the border or the next row.
     *
     * Hostile input: zero-width payload per base cell, the pre-base carry, and
     * the base cell's own payload are byte-capped (see MAX_CARRY); sequences
     * past the budget are dropped fail-soft. Content is otherwise treated as
     * TRUSTED — see the README trust boundary.
     */
    private function placeLine(string $line, int $x, int $y, int $w, array &$cells): void
    {
        ['cells' => $segments, 'trailing' => $trailing] = $this->visualCells($line);

        $col       = 0;
        $lastCol   = -1;
        $carry     = '';      // zero-width graphemes awaiting a base cell
        $marks     = 0;       // zero-width bytes attached to the current base cell
        $styleOpen = false;
        $truncated = false;

        foreach ($segments as $seg) {
            $gw = $this->strWidth($seg);

            // Zero-width grapheme (combining mark / lone joiner): keep it attached
            // to the previous cell, else carry it onto the next visible one. Both
            // paths fail SOFT at the per-cell byte budget: marks past MAX_CARRY are
            // dropped so hostile content can never grow one grid cell without
            // bound. (UAX#29 fuses a mark run into its base's cluster, so the
            // attack arrives as one oversized zero-width segment — hence the
            // segment itself is codepoint-clipped, not merely the counter.)
            if ($gw <= 0) {
                if ($lastCol >= 0) {
                    if ($marks < self::MAX_CARRY) {
                        $keep = $this->clipCodepoints($seg, self::MAX_CARRY - $marks);
                        $cells[$y][$x + $lastCol] .= $keep;
                        $marks += \strlen($keep);
                    }
                } else {
                    if (\strlen($carry) < self::MAX_CARRY) {
                        $carry .= $this->clipCodepoints($seg, self::MAX_CARRY - \strlen($carry));
                    }
                }
                $styleOpen = $this->sgrLeavesStyleOpen($seg, $styleOpen);
                continue;
            }

            if ($col + $gw > $w) {
                $truncated = true;
                break;
            }

            // Base-cell payload is also bounded: carry (≤ MAX_CARRY) + a real
            // grapheme cluster (legitimate clusters sit far below the slack).
            $this->setChar($x + $col, $y, $this->clipCodepoints($carry . $seg, self::MAX_CARRY + 64), $cells);
            $carry     = '';
            $marks     = 0;
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
        if ($truncated === false && $trailing !== '' && $lastCol >= 0) {
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
                // Cap pending-escape accumulation (fail-soft): whole sequences
                // past the budget are dropped until the next grapheme lands,
                // so an escape flood — or one absurdly long sequence — cannot
                // grow a cell's payload without bound. The budget is sized so
                // legitimate OSC-8 hyperlinks pass untouched. Dropped sequences
                // only ever lose styling: width is never affected, and a lost
                // reset leaves the tracker over-open (redundant trailing reset,
                // harmless) rather than under-open (bleed).
                if (\strlen($seq) <= self::MAX_PENDING_ESCAPES
                    && \strlen($pending) + \strlen($seq) <= self::MAX_PENDING_ESCAPES
                ) {
                    $pending .= $seq;
                }
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
     *
     * The grammar is a lib-local port of sugar-core's Ansi::strip trio
     * (stripEscape / stripCsi / stripString) so the painter charges exactly the
     * bytes the width oracle removes. Five string-sequence introducers are
     * recognised: OSC (ESC ]) — closed by BEL or ST — plus the DCS (ESC P),
     * SOS (ESC X), PM (ESC ^) and APC (ESC _) family, which run to ST only.
     * A CSI (ESC [ …) closes on a final byte 0x40-0x7e; an ESC mid-run cancels
     * and rescans as a fresh introducer, and CAN/SUB (0x18/0x1a) cancel
     * inclusive (ECMA-48 §5.4). For any other ESC the two-byte form is consumed
     * ONLY when the follower is an ECMA-48 Fe final (0x40-0x5f) — the band the
     * strip oracle implements; any other follower (including Fs finals such as
     * ESC c) leaves a lone ESC so its bytes are read on their own merits and a
     * multi-byte grapheme is never split.
     *
     * A string run that reaches end-of-input without BEL/ST swallows its
     * remainder — the same discard the strip oracle and a real terminal perform,
     * keeping grid, oracle and screen in agreement (pinned, see below).
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
                if ($c === 0x1b) {
                    break; // ESC cancels; $j sits ON it so the run excludes it and it rescans fresh
                }
                $j++;
                if ($c >= 0x40 && $c <= 0x7e) break; // final byte closes the CSI
                if ($c === 0x18 || $c === 0x1a) break; // CAN/SUB cancel inclusive (ECMA-48 §5.4)
            }
            return \substr($s, $i, $j - $i);
        }
        // String oscillates: OSC (ESC ]) plus the DCS / SOS / PM / APC family,
        // which run to a String Terminator rather than a final byte. Treating
        // them as two-byte escapes painted the payload as text and then ate the
        // real content behind it, so the whole string is consumed as one run.
        if ($next === ']' || $next === 'P' || $next === 'X' || $next === '^' || $next === '_') {
            $j = $i + 2;
            while ($j < $len) {
                $c = \ord($s[$j]);
                if ($c === 0x07 && $next === ']') {
                    return \substr($s, $i, $j + 1 - $i); // BEL closes an OSC (xterm)
                }
                if ($c === 0x1b) {
                    if (($s[$j + 1] ?? '') === '\\') {
                        return \substr($s, $i, $j + 2 - $i); // ST closes the run
                    }
                    return \substr($s, $i, $j - $i); // stray ESC cancels; the ESC rescans fresh
                }
                $j++;
            }
            // Unterminated: the run is swallowed to end of input — the polarity
            // the strip oracle AND a live terminal both implement. The "paint
            // the remainder as text" alternative is broken by construction: the
            // ESC introducer rides in cell 0, so Ansi::strip/Width::string read
            // the whole frame as one open string (0 columns) while the grid
            // claims a cell per payload byte — the exact grid-vs-oracle desync
            // this function exists to avoid. Pinned in AnsiPlacementTest.
            return \substr($s, $i, $j - $i);
        }

        return ($next !== '' && \ord($next) >= 0x40 && \ord($next) <= 0x5f)
            ? \substr($s, $i, 2) // Fe final: a real two-byte escape
            : "\x1b";             // anything else: a lone ESC
    }

    /** Read one extended grapheme cluster starting at byte $i (intl, with a UTF-8 fallback). */
    private function nextGrapheme(string $s, int $i): string
    {
        static $hasGrapheme = null;
        if ($hasGrapheme === null) {
            $hasGrapheme = \function_exists('grapheme_extract');
        }
        if ($hasGrapheme === true) {
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
     * (38/48 ; 5 ; n and 38/48 ; 2 ; r ; g ; b, plus their colon sub-parameter
     * forms 38:5:n / 38:2:cs:r:g:b) are skipped so a colour index of
     * 0 isn't mistaken for a reset.
     */
    private function sgrLeavesStyleOpen(string $s, bool $open): bool
    {
        if (\strpos($s, "\x1b[") === false) {
            return $open;
        }
        if (\preg_match_all('/\x1b\[([0-9;:]*)m/', $s, $matches) === 0) {
            return $open;
        }
        foreach ($matches[1] as $params) {
            if ($params === '') {
                $open = false; // ESC[m == reset
                continue;
            }
            // Colon sub-parameter form (ITU T.416 / kitty-style: 38:2:cs:r:g:b)
            // carries one MORE parameter than the semicolon form: the colour
            // space sits between the type and the components. Normalise the
            // separators, then skip 5 (truecolour: cs r g b + the extra) or 3
            // (indexed: cs n + the extra) so the walk lands past the colour
            // instead of reading a component (or the CS) as a plain SGR code —
            // which is how 38:2:1:255:0:0m used to score as a reset.
            $codes = \explode(';', \str_replace(':', ';', $params));
            for ($k = 0, $n = \count($codes); $k < $n; $k++) {
                $code = (int) $codes[$k];
                if (($code === 38 || $code === 48) && isset($codes[$k + 1])) {
                    $semicolonForm = \strpos($params, ':') === false;
                    $k += $semicolonForm
                        ? (((int) $codes[$k + 1]) === 2 ? 4 : 2)
                        : (((int) $codes[$k + 1]) === 2 ? 5 : 3);
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
            // One preg_split call for the split (an earlier form ran it twice).
            $split = \preg_split('/\s+/', $paragraphLine);
            $words = $split !== false ? $split : [];
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

        return \count($result) > 0 ? $result : [''];
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

        return \count($chunks) > 0 ? $chunks : [''];
    }

    // -------------------------------------------------------------------------
    // Border drawing
    // -------------------------------------------------------------------------

    /**
     * Draw a border using the characters from the given Border object.
     * Uses Border::rounded() as default when $border is null.
     */
    private function drawBorder(Node $node, int $x, int $y, int $w, int $h, array &$cells): void
    {
        if ($w < 2 || $h < 2) return;

        $border = $node->borderStyle ?? Border::rounded();
        $title  = $node->title;

        // Corners
        $this->setChar($x,           $y,           $border->topLeft,     $cells);
        $this->setChar($x + $w - 1,  $y,           $border->topRight,    $cells);
        $this->setChar($x,           $y + $h - 1,  $border->bottomLeft,  $cells);
        $this->setChar($x + $w - 1,  $y + $h - 1,  $border->bottomRight, $cells);

        // Top/bottom edges
        for ($i = 1; $i < $w - 1; $i++) {
            $this->setChar($x + $i, $y,           $border->top,    $cells);
            $this->setChar($x + $i, $y + $h - 1,  $border->bottom, $cells);
        }

        // Left/right edges
        for ($j = 1; $j < $h - 1; $j++) {
            $this->setChar($x,           $y + $j, $border->left,  $cells);
            $this->setChar($x + $w - 1,  $y + $j, $border->right, $cells);
        }

        // Title: overlay on top border row, clipped to available interior width.
        // Only render when there is room for corners + at least 1 title cell ($w >= 4).
        // Uses setChar per grapheme (visualCells/strWidth) to handle wide glyphs.
        if ($title !== '' && $w >= 4) {
            $maxTitleLen = $w - 2; // interior width (cols between corners)
            ['cells' => $segments, 'trailing' => $trailing] = $this->visualCells($title);
            $col       = 0;
            $lastCell  = -1;
            $styleOpen = false;
            foreach ($segments as $seg) {
                $gw = $this->strWidth($seg);
                if ($col + $gw > $maxTitleLen) break;
                // A title cell is a payload like any other cell: a single grapheme
                // cluster can carry thousands of combining marks, and the border
                // row is not exempt from the per-cell byte budget the content path
                // applies. Clipping keeps one hostile title from inflating the row.
                $this->setChar($x + 1 + $col, $y, $this->clipCodepoints($seg, self::MAX_CARRY + 64), $cells);
                $styleOpen = $this->sgrLeavesStyleOpen($seg, $styleOpen);
                $lastCell  = $x + 1 + $col;
                // Blank continuation cells for wide graphemes
                for ($k = 1; $k < $gw; $k++) {
                    $this->setChar($x + 1 + $col + $k, $y, '', $cells);
                }
                $col += $gw;
            }
            // A truncated title loses its own closing run (it sits in $trailing,
            // past the break), and a title that never closes its SGR would
            // recolour the rest of the border row and every cell after it. The
            // content path closes its spans the same way.
            if ($lastCell >= 0) {
                $openAfterTrailing = $trailing !== '' ? $this->sgrLeavesStyleOpen($trailing, $styleOpen) : $styleOpen;
                if ($openAfterTrailing === true) {
                    $cells[$y][$lastCell] .= "\x1b[0m";
                }
            }
        }
    }

    /**
     * Paint the vertical divider a spacing-0 horizontal layout puts between
     * two children.
     *
     * The divider is drawn INTO the last column of the previous child, which is
     * the cell the renderer used to park an auto-reset when that child's line
     * left a style open. Overwriting it silently un-does the safety net, so the
     * divider itself carries the closing sequence: whatever the neighbour was
     * doing, the boundary ends the span.
     *
     * A span with any visible glyph in it stands down as a whole. The earlier
     * rule asked the neighbouring NODE whether it was bordered, which only
     * covered the direct child: in a borderless container of bordered panels the
     * divider still ran over the grandchildren's ╮/╯ corners, and a stacked
     * layout's ╰───╯ became a bare dash run. Asking the grid instead of the tree
     * is both cheaper and correct at every depth.
     */
    private function drawVLine(?Border $border, int $x, int $y, int $h, array &$cells): void
    {
        if ($this->dividerSpanIsFree($cells, $x, $y, 1, $h) === false) {
            return;
        }
        $b = $border ?? Border::rounded();
        for ($j = 0; $j < $h; $j++) {
            $this->setChar($x, $y + $j, "\x1b[0m" . $b->left, $cells);
        }
    }

    /** @see drawVLine() — the horizontal divider closes the neighbour's span too. */
    private function drawHLine(?Border $border, int $x, int $y, int $w, array &$cells): void
    {
        if ($this->dividerSpanIsFree($cells, $x, $y, $w, 1) === false) {
            return;
        }
        $b = $border ?? Border::rounded();
        for ($i = 0; $i < $w; $i++) {
            $this->setChar($x + $i, $y, "\x1b[0m" . $b->top, $cells);
        }
    }

    /**
     * Is an entire divider span still free?
     *
     * "Free" means no visible glyph: a cell holding only escape bytes (a styling
     * run parked on a space) is empty as far as the screen is concerned, so the
     * divider takes it and brings its reset along.
     *
     * The verdict is all-or-nothing because the divider always lands on the
     * previous child's last row or column. Cell-by-cell it cut a rule straight
     * through the padded tail of a neighbour's text line — half a line of ─
     * after the word 'DASHBOARD' - which reads as a rendering defect, not as a
     * separator. A span with anything visible in it stands down as a whole.
     */
    private function dividerSpanIsFree(array $cells, int $x, int $y, int $w, int $h): bool
    {
        for ($j = 0; $j < $h; $j++) {
            for ($i = 0; $i < $w; $i++) {
                $cell = $cells[$y + $j][$x + $i] ?? null;
                if ($cell !== null && \trim(Ansi::strip($cell)) !== '') {
                    return false;
                }
            }
        }

        return true;
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

    /**
     * Truncate a string to at most $maxBytes at CODEPOINT boundaries.
     *
     * Used by the hostile-input caps: codepoint-level truncation can orphan a
     * combining mark when an adversarial payload is cut mid-cluster — legitimate
     * grapheme clusters sit far below the caps and never reach the cut. Always
     * yields valid UTF-8, so no broken byte ever reaches the terminal.
     */
    private function clipCodepoints(string $s, int $maxBytes): string
    {
        if ($maxBytes <= 0) {
            return '';
        }
        if (\strlen($s) <= $maxBytes) {
            return $s;
        }
        $out = '';
        foreach (\mb_str_split($s, 1, 'UTF-8') as $cp) {
            if (\strlen($out) + \strlen($cp) > $maxBytes) {
                break;
            }
            $out .= $cp;
        }

        return $out;
    }

    // -------------------------------------------------------------------------
    // Width distribution
    // -------------------------------------------------------------------------

    /**
     * Distribute available space across children by weight (the non-flex
     * fallback: children weighted by minWidth/minHeight, defaulting to 1).
     *
     * Delegates the size computation to sugar-constraints's
     * {@see GreedySolver::withMinShare()}, completing the solver deduplication
     * begun in {@see distributeFlex()}.
     * Each child maps to a {@see Constraint::fill()} weighted by its
     * minWidth/minHeight; the min-share floor reproduces distribute()'s bespoke
     * "reserve at least 1 column per not-yet-placed child" sequential clamp that
     * keeps the trailing child from collapsing to 0 in a tight viewport — the
     * fourth quirk the three compat() flags cannot express. The floor's
     * reservation frame is seeded with `$borderPad` ({@see GreedySolver}'s
     * `reserveLead`) and bumped by one `$spacing` per placed child (`reserveGap`),
     * re-creating the old `$used` counter exactly. The equivalence is swept over a
     * grid of viewport sizes and child counts, byte-for-byte, in the suite.
     *
     * @param list<int> $weights
     * @return list<int> Starting offsets for each child
     */
    private function distribute(int $available, array $weights, int $totalWeight, int $spacing, int $borderPad): array
    {
        $n = \count($weights);
        $contentSpan = $available - $spacing * \max(0, $n - 1);

        // All-zero weights → equal split (the old division-by-zero guard). The
        // min-share floor applies the identical guard internally, but doing it
        // here keeps the mapped Fill weights faithful to the pre-migration
        // arithmetic; in practice weights are minWidth/minHeight-derived (>= 1).
        if ($totalWeight === 0) {
            $weights = \array_fill(0, $n, 1);
        }

        // minWidth/minHeight weight → Fill(weight); the floor's round(weight/total
        // * span) proportional split matches distribute()'s per-child share.
        $constraints = [];
        foreach ($weights as $weight) {
            $constraints[] = Constraint::fill($weight);
        }

        // Clamp the solved span to >= 0: when spacing/border exceed the available
        // size the raw content span is negative, and Region rejects a negative
        // width. In that regime the old distribute() clamped every non-last share
        // to 0 (round(weight/total * negativeSpan) <= 0), yielding offsets of
        // borderPad + i*spacing — a span of 0 reproduces exactly those all-zero
        // shares, so the clamp is byte-identical (matches distributeFlex()).
        $regions = GreedySolver::new()
            ->withMinShare(1, $spacing, $borderPad)
            ->solve(
                new ConstraintsRegion(0, 0, \max(0, $contentSpan), 1),
                Direction::Horizontal,
                $constraints,
            );

        // Rebuild the starting-offset array the caller expects: offsets[0] is the
        // border pad; each subsequent offset adds the previous child's solved
        // width plus one inter-child gap. regions[i]->width for i < n-1 equals the
        // old distribute()'s share_i; the final child's size is derived by the
        // caller as the remainder, so its solved width is unused.
        $offsets = [$borderPad];
        for ($i = 0; $i < $n - 1; $i++) {
            $offsets[] = $offsets[$i] + $regions[$i]->width + $spacing;
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
     * Delegates the actual size computation to sugar-constraints's
     * {@see GreedySolver::compat()}: a
     * fixed child maps to a Length reserving its natural size, a flex child to a
     * Fill weighted by its flex weight. compat() mode makes sugar-constraints's greedy
     * solver reproduce this hand-rolled distribution byte-for-byte — the three
     * axes it flips (round-split, remainder-to-the-last Fill, and NON-truncating
     * overflow so fixed children keep their full base and the layout runs off-grid
     * rather than being shrunk) are precisely sugar-panels's flex semantics. A
     * swept layout grid confirms 0 offset divergences.
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

        // Fixed → Length(base) (clamped ≥ 0: Length rejects negatives, matching the
        // old max(0, $bases[$i])); flex → Fill(weight). The gaps are excluded from
        // the solved span and re-inserted below, exactly as the old arithmetic did.
        $constraints = [];
        foreach ($flexes as $i => $f) {
            $constraints[] = $f > 0
                ? Constraint::fill($f)
                : Constraint::length(\max(0, $bases[$i]));
        }

        $regions = $this->flexSolver->solve(
            new ConstraintsRegion(0, 0, $content, 1),
            Direction::Horizontal,
            $constraints,
        );

        // Rebuild the starting-offset array the caller expects: offsets[0] is the
        // border pad; each subsequent offset adds the previous child's solved width
        // plus one inter-child gap. The final child's size is derived by the caller
        // as the remainder (w - b - offsets[last]), so its solved width is unused.
        $offsets = [$borderPad];
        for ($i = 0; $i < $n - 1; $i++) {
            $offsets[] = $offsets[$i] + $regions[$i]->width + $spacing;
        }

        return $offsets;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Visible width — delegates to sugar-core's grapheme/width-aware util. */
    private function strWidth(string $s): int
    {
        return Width::string($s);
    }

    /**
     * Build a Buffer from a rendered cell grid.
     *
     * Each grid slot holds exactly one logical cell: the visible grapheme plus
     * any escape sequences / zero-width carry riding on it (placeLine's write
     * unit). The slot's display width is therefore its VISIBLE width — 2 for a
     * wide glyph (whose continuation slot is the empty string → width 0, the
     * pairing Buffer::diff() requires), 1 otherwise. All cells carry a null
     * style: style changes still diff correctly because the raw escape bytes
     * ride inside the rune and break cell equality.
     *
     * Re-splitting the flattened output string instead (the previous approach)
     * mis-mapped every wide glyph and every escape-prefixed cell to width 1 at
     * shifted columns, so deltas misplaced or silently dropped styled/wide
     * content.
     *
     * @param list<list<string>> $cells  Grid from render(): one string per cell
     * @param int                $width  Buffer width in cells
     * @param int                $height Buffer height in rows
     */
    private function bufferFromGrid(array $cells, int $width, int $height): Buffer
    {
        /** @var list<Cell> $grid */
        $grid = [];
        for ($row = 0; $row < $height; $row++) {
            $line = $cells[$row] ?? [];
            for ($col = 0; $col < $width; $col++) {
                $char = $line[$col] ?? ' ';
                // '' is a wide-glyph continuation (zero visible columns); every
                // other slot shows at least one column.
                $cellWidth = $char === '' ? 0 : \max(1, $this->strWidth($char));
                $grid[$row * $width + $col] = Cell::new($char, null, null, $cellWidth);
            }
        }

        return Buffer::fromGrid($width, $height, $grid);
    }

    /**
     * Reset the previous-frame buffer, forcing the next render to emit
     * a full frame (used on window resize or cursor-position-lost events).
     *
     * The next frame carries the cursor home but NO erase: this renderer may own
     * a region rather than the whole screen, and wiping on a manual reset is a
     * surprise the caller did not ask for. Call it when the terminal state is
     * suspect, or to capture a full frame for text extraction (a delta only
     * carries changed cells and is unreadable as text).
     *
     * The per-Style SGR-prefix cache is deliberately NOT dropped: a cached
     * opening run belongs to a Style object, not to a frame, so it stays valid
     * across a reset — rebuilding it only bought a re-derivation pass.
     */
    public function resetPreviousFrame(): void
    {
        $this->previousFrame = null;
        $this->previousCells = null;
        $this->prevWidth = null;
        $this->prevHeight = null;
    }
}
