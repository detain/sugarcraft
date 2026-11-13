<?php

declare(strict_types=1);

namespace SugarCraft\Panels;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;
use SugarCraft\Style\Align;
use SugarCraft\Style\Border;
use SugarCraft\Style\Style;
use SugarCraft\Style\VAlign;

/**
 * Immutable layout tree node.
 *
 * Design antecedent: the kind vocabulary (leaf / horizontal / vertical /
 * noBorder) is modelled on the Node shape in treilik/bubbleboxer's boxer.go —
 * a port of that vocabulary, not of its code. {@see SugarPanels} carries the
 * same antecedent cite, and README *Border Characters* records where the port
 * deliberately diverges (per-node frames vs upstream shared separators).
 *
 * Every public dimension/style facet is a declared `readonly` property below;
 * the `with*()` builders return new instances. (An earlier @property mirror
 * block drifted and cited a phantom `NodeKind` type — the declared properties
 * are the single source of truth.)
 */
final class Node
{
    public const LEAF       = 'leaf';
    public const HORIZONTAL = 'horizontal';
    public const VERTICAL   = 'vertical';
    public const NOBORDER   = 'noborder';

    public readonly string $kind;
    public readonly array $children;
    public readonly string $content;
    public readonly int $minWidth;
    public readonly int $maxWidth;
    public readonly int $minHeight;
    public readonly int $maxHeight;
    /** Flex/grow weight: 0 = fixed (sized by its natural content, floored by min*, plus padding+border), >0 = fills leftover space by weight. */
    public readonly int $flex;
    public readonly int $padding;
    public readonly bool $border;
    public readonly int $spacing;
    public readonly ?Border $borderStyle;
    public readonly ?Style $style;
    public readonly string $title;
    /** @var array{int,int,int,int} */
    public readonly array $margin;
    public readonly ?Align $alignH;
    public readonly ?VAlign $alignV;

    /**
     * @param array{int,int,int,int} $margin top, right, bottom, left
     */
    private function __construct(
        string $kind,
        string $content = '',
        array $children = [],
        int $minWidth = 0,
        int $maxWidth = 0,
        int $minHeight = 0,
        int $maxHeight = 0,
        int $padding = 0,
        bool $border = true,
        int $spacing = 0,
        ?Border $borderStyle = null,
        ?Style $style = null,
        string $title = '',
        array $margin = [0, 0, 0, 0],
        ?Align $alignH = null,
        ?VAlign $alignV = null,
        int $flex = 0,
    ) {
        $this->kind        = $kind;
        // Every string that can reach the grid enters through this choke point
        // (leaf(), withContent() and mutate() all land here), so the paint
        // boundary is applied once, here, and cannot be routed around.
        $this->content     = Sanitize::painted($content);
        $this->children    = $children;
        $this->minWidth    = $minWidth;
        $this->maxWidth    = $maxWidth;
        $this->minHeight   = $minHeight;
        $this->maxHeight   = $maxHeight;
        $this->flex        = $flex;
        $this->padding     = $padding;
        $this->border      = $border;
        $this->spacing     = $spacing;
        $this->borderStyle = $borderStyle;
        $this->style       = $style;
        $this->title       = Sanitize::paintedTitle($title);
        $this->margin      = $margin;
        $this->alignH       = $alignH;
        $this->alignV       = $alignV;
    }

    // -------------------------------------------------------------------------
    // Factory constructors
    // -------------------------------------------------------------------------

    public static function leaf(string $content = ''): self
    {
        return new self(self::LEAF, $content);
    }

    public static function horizontal(Node ...$children): self
    {
        return new self(self::HORIZONTAL, '', $children);
    }

    public static function vertical(Node ...$children): self
    {
        return new self(self::VERTICAL, '', $children);
    }

    /**
     * No-border wrapper — suppresses every frame and every divider through
     * the whole subtree, so adjacent panels render touching. Contrast with
     * {@see self::withBorder}(false), which hides one node's own frame and
     * leaves the rest of the layout's rules in place. The wrapper
     * carries border:false and threads $noFrames on render (see
     * SugarPanels::renderNoBorder; pinned by tests/FramingAndSeparatorsTest.php).
     */
    public static function noBorder(Node $child): self
    {
        // Built with the frame flag off on purpose: the wrapper never paints a
        // border itself, and leaving it at the true default made the measures
        // charge two columns for a frame that cannot exist.
        return new self(self::NOBORDER, '', [$child], border: false);
    }

    // -------------------------------------------------------------------------
    // Shape queries
    // -------------------------------------------------------------------------

    /**
     * Shape predicates. The `kind` string constants remain the wire form, but
     * comparisons are spelled through these so intent reads and the constants
     * cannot be hand-typo'd at call sites.
     */
    public function isLeaf(): bool
    {
        return $this->kind === self::LEAF;
    }

    public function isHorizontal(): bool
    {
        return $this->kind === self::HORIZONTAL;
    }

    public function isVertical(): bool
    {
        return $this->kind === self::VERTICAL;
    }

    // -------------------------------------------------------------------------
    // With* builders
    // -------------------------------------------------------------------------

    public function withMinWidth(int $w): self
    {
        // Clamp negatives to 0 — a dimension can never be < 0. Mirrors withFlex().
        return $this->mutate(minWidth: \max(0, $w));
    }

    public function withMaxWidth(int $w): self
    {
        return $this->mutate(maxWidth: \max(0, $w));
    }

    public function withMinHeight(int $h): self
    {
        return $this->mutate(minHeight: \max(0, $h));
    }

    public function withMaxHeight(int $h): self
    {
        return $this->mutate(maxHeight: \max(0, $h));
    }

    /**
     * Mark this child as flexible: in a horizontal/vertical parent it grows to
     * fill the space left after the fixed (non-flex) siblings take their natural
     * size, sharing that leftover with other flex children in proportion to the
     * weight. A weight of 0 means the child is fixed (non-flex).
     */
    public function withFlex(int $weight): self
    {
        return $this->mutate(flex: \max(0, $weight));
    }

    /** Shorthand for {@see withFlex}(1): this child fills the leftover space. */
    public function withGrow(): self
    {
        return $this->withFlex(1);
    }

    /**
     * Inner inset around painted content. Applies to leaf nodes only; a
     * container ignores it — its children fill the box.
     */
    public function withPadding(int $cells): self
    {
        // Clamp negatives to 0 — padding can never be < 0. Mirrors withFlex().
        return $this->mutate(padding: \max(0, $cells));
    }

    /**
     * Toggle this node's OWN frame. Contrast with {@see self::noBorder()},
     * which flattens a whole subtree's frames and dividers, while this hides
     * only the one box and leaves sibling frames and rules alone. The chosen
     * border style survives a `true → false → true` round-trip: disabling
     * hides the frame, enabling re-shows the style that was last set.
     */
    public function withBorder(bool $show): self
    {
        // When enabling the border, use rounded chars as default if no
        // borderStyle is set.  When disabling, leave borderStyle untouched —
        // it can still be composed via withBorderStyle() later.
        $borderStyle = $show === true
            ? ($this->borderStyle ?? Border::rounded())
            : $this->borderStyle;
        return $this->mutate(border: $show, borderStyle: $borderStyle, borderStyleSet: true);
    }

    public function withSpacing(int $cells): self
    {
        // Clamp negatives to 0 — spacing can never be < 0. Mirrors withFlex().
        return $this->mutate(spacing: \max(0, $cells));
    }

    /**
     * Set the border character set from sugar-style.
     * Passing null CLEARS the style: the frame then falls back to
     * {@see Border::rounded()} at draw time. The border bool still controls
     * visibility — clearing the style does not hide the border.
     */
    public function withBorderStyle(?Border $b): self
    {
        return $this->mutate(borderStyle: $b, borderStyleSet: true);
    }

    /**
     * Set the canonical style from sugar-style.
     * Passing null CLEARS the style — content renders unstyled.
     */
    public function withStyle(?Style $s): self
    {
        return $this->mutate(style: $s, styleSet: true);
    }

    /**
     * Set the box title text.
     * Passing '' CLEARS the title — the top edge renders as an unbroken run.
     */
    public function withTitle(string $t): self
    {
        return $this->mutate(title: $t, titleSet: true);
    }

    /**
     * Set outer margin (top, right, bottom, left).
     * sugar-panels-specific: sugar-style Style does not ship margin as a
     * first-class concept.
     *
     * @param int      $top
     * @param int|null $right  Defaults to $top when null
     * @param int|null $bottom Defaults to $top when null
     * @param int|null $left   Defaults to $right when null
     */
    public function withMargin(int $top, ?int $right = null, ?int $bottom = null, ?int $left = null): self
    {
        $right  ??= $top;
        $bottom ??= $top;
        $left   ??= $right;
        // Clamp negatives to 0 — a margin can never be < 0. Mirrors withFlex().
        $margin = [\max(0, $top), \max(0, $right), \max(0, $bottom), \max(0, $left)];
        return $this->mutate(margin: $margin, marginSet: true);
    }

    /**
     * Set horizontal text alignment (sugar-panels-specific).
     * Passing null CLEARS the alignment — content renders left-aligned.
     */
    public function withAlignH(?Align $a): self
    {
        return $this->mutate(alignH: $a, alignHSet: true);
    }

    /**
     * Set vertical text alignment (sugar-panels-specific).
     * Passing null CLEARS the alignment — content renders top-aligned.
     */
    public function withAlignV(?VAlign $v): self
    {
        return $this->mutate(alignV: $v, alignVSet: true);
    }

    /**
     * Painted string for a leaf. Containers paint their children, never a
     * content string, so content set on a container is stored but inert.
     */
    public function withContent(string $content): self
    {
        return $this->mutate(content: $content);
    }

    // -------------------------------------------------------------------------
    // Dimension queries
    // -------------------------------------------------------------------------

    /**
     * Total width including border, padding, the node's own left+right margin,
     * and — for HORIZONTAL layouts only — the inter-child spacing. Vertical
     * spacing consumes rows, never columns, so it is excluded from the width
     * footprint (mirroring totalHeight()'s treatment of horizontal spacing).
     */
    public function totalWidth(): int
    {
        return $this->measureWidth(false);
    }

    public function totalHeight(): int
    {
        return $this->measureHeight(false);
    }

    /**
     * The node's own horizontal margin (left + right).
     *
     * A max* cap covers the painted box only — frame, padding and content —
     * while margin rides OUTSIDE the cap, so totalWidth() reaches
     * maxWidth + marginWidth(). Callers capping a child's region must allow
     * for it: charging the margin twice clips the child below what it claims.
     */
    public function marginWidth(): int
    {
        return $this->margin[3] + $this->margin[1]; // left + right
    }

    /** Vertical counterpart of marginWidth(): top + bottom. */
    public function marginHeight(): int
    {
        return $this->margin[0] + $this->margin[2]; // top + bottom
    }

    /**
     * Horizontal footprint, computed the way the renderer will spend it.
     *
     * $noFrames reproduces the noBorder() wrapper's suppression: inside a
     * frameless subtree no node paints a frame or a divider, so none may charge
     * for one. The wrapper's own flag is already off (see noBorder()), but its
     * CHILDREN still carry theirs, and a subtree that asks for two columns it
     * will never paint starves its horizontal siblings just as badly as the
     * opposite error.
     *
     * @see totalWidth()
     */
    private function measureWidth(bool $noFrames): int
    {
        // The renderer insets each node's region by its own margin before laying
        // it out (SugarPanels::renderNode), so a node's real horizontal footprint —
        // the space a fixed sibling must reserve in flex layout — is its content
        // width PLUS its left+right margin. Omitting it makes margined children
        // under-claim, so the flex solver overlaps or clips them.
        $marginW = $this->marginWidth();
        $frame   = $this->border === true && $noFrames === false;

        if ($this->isLeaf()) {
            // A leaf's natural size IS its content: the widest visible line, with
            // the SGR runs that ride the text stripped first because they cost no
            // columns. min* is a FLOOR on that measure, never a substitute for it
            // — a fixed ('0' flex) sibling sized only by min* claimed one column
            // and starved its own text, which rendered the README's own dashboard
            // pattern blank.
            $inner = \max($this->minWidth, $this->naturalContentWidth());
            if ($this->padding > 0) $inner += $this->padding * 2;
            if ($frame) $inner += 2;

            return self::capped($inner, $this->maxWidth) + $marginW;
        }

        $childNoFrames = $noFrames || $this->kind === self::NOBORDER;

        // For HORIZONTAL, children sit side-by-side so width is their SUM plus
        // one spacing gap between neighbours; the border is shared once. The
        // child sum is computed only on this branch — VERTICAL never uses it,
        // so summing there was a wasted full subtree traversal (it already
        // re-walks the children for the max).
        if ($this->isHorizontal()) {
            $childWidths = \array_sum(\array_map(fn(Node $c) => $c->measureWidth($childNoFrames), $this->children));
            $gaps = \max(0, \count($this->children) - 1) * $this->spacing;

            return self::capped($childWidths + $gaps + ($frame ? 2 : 0), $this->maxWidth) + $marginW;
        }

        // VERTICAL (and the single-child NOBORDER wrapper): children stack, so
        // the width is the widest child — inter-child spacing does not inflate
        // it. Adding (n-1)*spacing here reserved phantom columns for vertical
        // gaps and let an inflated flex basis starve horizontal siblings.
        //
        // The measures are collected into an array and max()'d as ONE argument:
        // `max(...$oneChild)` would pass a bare int, and PHP only accepts a
        // single value when it is an array — so a one-child stack (which is what
        // noBorder() builds) crashed the basis instead of claiming its width.
        $childWidths = \array_map(fn(Node $c) => $c->measureWidth($childNoFrames), $this->children);
        $maxChild = $childWidths === [] ? 0 : \max($childWidths);

        return self::capped($maxChild + ($frame ? 2 : 0), $this->maxWidth) + $marginW;
    }

    /**
     * Vertical footprint. @see measureWidth() for the shared law.
     */
    private function measureHeight(bool $noFrames): int
    {
        // See totalWidth(): the renderer insets by margin, so a node's real
        // vertical footprint includes its own top+bottom margin.
        $marginH = $this->marginHeight();
        $frame   = $this->border === true && $noFrames === false;

        if ($this->isLeaf()) {
            // See totalWidth(): a leaf's natural height is the number of lines its
            // content carries (unwrapped — the renderer word-wraps only what the
            // region it was given cannot hold), with min* as a floor.
            $inner = \max($this->minHeight, $this->naturalContentLines());
            if ($this->padding > 0) $inner += $this->padding * 2;
            if ($frame) $inner += 2;

            return self::capped($inner, $this->maxHeight) + $marginH;
        }

        $childNoFrames = $noFrames || $this->kind === self::NOBORDER;

        if ($this->isVertical()) {
            $childHeights = \array_sum(\array_map(fn(Node $c) => $c->measureHeight($childNoFrames), $this->children));
            $gaps = \max(0, \count($this->children) - 1) * $this->spacing;

            return self::capped($childHeights + $gaps + ($frame ? 2 : 0), $this->maxHeight) + $marginH;
        }

        // HORIZONTAL: use max child height (collected as an array for the same
        // one-child reason as totalWidth()).
        $childHeights = \array_map(fn(Node $c) => $c->measureHeight($childNoFrames), $this->children);
        $maxChild = $childHeights === [] ? 0 : \max($childHeights);

        return self::capped($maxChild + ($frame ? 2 : 0), $this->maxHeight) + $marginH;
    }

    /**
     * Apply a max* cap to a footprint. A cap of 0 means 'no maximum'.
     */
    private static function capped(int $footprint, int $cap): int
    {
        return ($cap > 0 && $cap < $footprint) ? $cap : $footprint;
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    /** Widest visible line of a leaf's content; 0 for an empty leaf. */
    private function naturalContentWidth(): int
    {
        if ($this->content === '') return 0;

        $widest = 0;
        foreach (\explode("\n", $this->content) as $line) {
            // Ansi::strip removes every escape sequence, so the styling that rides
            // a glyph costs no columns; Width::string then counts grapheme cells
            // (an East Asian glyph charges 2, exactly as the painter lays it).
            $width = Width::string(Ansi::strip($line));
            if ($width > $widest) $widest = $width;
        }

        return $widest;
    }

    /** Line count of a leaf's content, before any renderer word-wrap; 0 when empty. */
    private function naturalContentLines(): int
    {
        return $this->content === '' ? 0 : \count(\explode("\n", $this->content));
    }

    /**
     * Rebuild with the given overrides.
     *
     * House XSet law (AGENTS "nullable fields use a paired bool $XSet
     * sentinel", canonical sugar-style Style / sugar-core Mutable): each
     * clearable nullable field has a paired `...Set` flag. Omitting both keeps
     * the current value; passing `field: $v, fieldSet: true` WRITES $v even
     * when $v is null/''/[0,0,0,0] — so an explicit clear is observable.
     * (An earlier Preserve-sentinel scheme let explicit nulls silently no-op,
     * contradicting the docblocks and README; the flag pair removes that
     * divergence by construction.)
     *
     * @param array{int,int,int,int}|null $margin
     */
    private function mutate(
        ?string $content = null,
        ?int $minWidth = null,
        ?int $maxWidth = null,
        ?int $minHeight = null,
        ?int $maxHeight = null,
        ?int $padding = null,
        ?bool $border = null,
        ?int $spacing = null,
        ?Border $borderStyle = null,
        bool $borderStyleSet = false,
        ?Style $style = null,
        bool $styleSet = false,
        ?string $title = null,
        bool $titleSet = false,
        ?array $margin = null,
        bool $marginSet = false,
        ?Align $alignH = null,
        bool $alignHSet = false,
        ?VAlign $alignV = null,
        bool $alignVSet = false,
        ?int $flex = null,
    ): self {
        return new self(
            $this->kind,
            $content ?? $this->content,
            $this->children,
            $minWidth    ?? $this->minWidth,
            $maxWidth    ?? $this->maxWidth,
            $minHeight   ?? $this->minHeight,
            $maxHeight   ?? $this->maxHeight,
            $padding     ?? $this->padding,
            // Preserve the existing border unless a with*() call set it explicitly
            // — otherwise any later builder (withMinHeight, withGrow, …) would
            // silently re-enable a border that withBorder(false) turned off.
            $border ?? $this->border,
            $spacing     ?? $this->spacing,
            $borderStyleSet ? $borderStyle : $this->borderStyle,
            $styleSet      ? $style        : $this->style,
            $titleSet      ? (string) $title : $this->title,
            $marginSet     ? $margin : $this->margin,
            $alignHSet      ? $alignH     : $this->alignH,
            $alignVSet      ? $alignV     : $this->alignV,
            $flex           ?? $this->flex,
        );
    }
}
