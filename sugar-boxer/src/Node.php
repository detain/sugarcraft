<?php

declare(strict_types=1);

namespace SugarCraft\Boxer;

use SugarCraft\Sprinkles\Align;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Sprinkles\VAlign;

/**
 * Immutable layout tree node.
 *
 * @property NodeKind              $kind         LEAF | HORIZONTAL | VERTICAL | NOBORDER
 * @property list<Node>            $children     Child nodes (empty for LEAF)
 * @property string                $content      Leaf string content
 * @property int                   $minWidth
 * @property int                   $maxWidth
 * @property int                   $minHeight
 * @property int                   $maxHeight
 * @property int                   $padding      Inner padding (cells)
 * @property bool                  $border       Whether to draw box border
 * @property int                   $spacing      Gap between children (cells)
 * @property Border|null           $borderStyle  Canonical border chars (candy-sprinkles)
 * @property Style|null            $style        Canonical style (candy-sprinkles)
 * @property string                $title        Box title text
 * @property array{int,int,int,int} $margin      Outer spacing (top/right/bottom/left)
 * @property Align|null            $alignH       Horizontal text alignment
 * @property VAlign|null           $alignV       Vertical text alignment
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
    /** Flex/grow weight: 0 = fixed (sized by min*+border), >0 = fills leftover space by weight. */
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
        $this->content     = $content;
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
        $this->title       = $title;
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
     * No-border wrapper — children rendered flat without vertical separators.
     */
    public static function noBorder(Node $child): self
    {
        return new self(self::NOBORDER, '', [$child]);
    }

    // -------------------------------------------------------------------------
    // With* builders
    // -------------------------------------------------------------------------

    public function withMinWidth(int $w): self
    {
        return $this->with(minWidth: $w);
    }

    public function withMaxWidth(int $w): self
    {
        return $this->with(maxWidth: $w);
    }

    public function withMinHeight(int $h): self
    {
        return $this->with(minHeight: $h);
    }

    public function withMaxHeight(int $h): self
    {
        return $this->with(maxHeight: $h);
    }

    /**
     * Mark this child as flexible: in a horizontal/vertical parent it grows to
     * fill the space left after the fixed (non-flex) siblings take their natural
     * size, sharing that leftover with other flex children in proportion to the
     * weight. A weight of 0 means the child is fixed (non-flex).
     */
    public function withFlex(int $weight): self
    {
        return $this->with(flex: \max(0, $weight));
    }

    /** Shorthand for {@see withFlex}(1): this child fills the leftover space. */
    public function withGrow(): self
    {
        return $this->withFlex(1);
    }

    public function withPadding(int $cells): self
    {
        return $this->with(padding: $cells);
    }

    public function withBorder(bool $show): self
    {
        // When enabling the border, use rounded chars as default if no
        // borderStyle is set.  When disabling, leave borderStyle untouched —
        // it can still be composed via withBorderStyle() later.
        $borderStyle = $show
            ? ($this->borderStyle ?? Border::rounded())
            : $this->borderStyle;
        return $this->with(border: $show, borderStyle: $borderStyle);
    }

    public function withSpacing(int $cells): self
    {
        return $this->with(spacing: $cells);
    }

    /**
     * Set the border character set from candy-sprinkles.
     * Passing null clears the border style (border bool still controls visibility).
     */
    public function withBorderStyle(?Border $b): self
    {
        // Pass sentinel to preserve all other properties via with()'s fallback
        return $this->with(borderStyle: $b, style: self::nop(), alignH: self::nop(), alignV: self::nop());
    }

    /**
     * Set the canonical style from candy-sprinkles.
     */
    public function withStyle(?Style $s): self
    {
        return $this->with(style: $s, borderStyle: self::nop(), alignH: self::nop(), alignV: self::nop());
    }

    /**
     * Set the box title text.
     */
    public function withTitle(string $t): self
    {
        return $this->with(title: $t, borderStyle: self::nop(), style: self::nop(), alignH: self::nop(), alignV: self::nop());
    }

    /**
     * Set outer margin (top, right, bottom, left).
     * sugar-boxer-specific: candy-sprinkles Style does not ship margin as a
     * first-class concept.
     *
     * @param int $top
     * @param int $right  Defaults to 0 (uses $top)
     * @param int $bottom Defaults to 0 (uses $top)
     * @param int $left   Defaults to 0 (uses $right)
     */
    public function withMargin(int $top, int $right = 0, int $bottom = 0, int $left = 0): self
    {
        $n = \func_num_args();
        $right  = $n >= 2 ? $right  : $top;
        $bottom = $n >= 3 ? $bottom : $top;
        $left   = $n >= 4 ? $left   : $right;
        return $this->with(margin: [$top, $right, $bottom, $left], borderStyle: self::nop(), style: self::nop(), alignH: self::nop(), alignV: self::nop());
    }

    /**
     * Set horizontal text alignment (sugar-boxer-specific).
     */
    public function withAlignH(Align $a): self
    {
        return $this->with(alignH: $a, borderStyle: self::nop(), style: self::nop(), alignV: self::nop());
    }

    /**
     * Set vertical text alignment (sugar-boxer-specific).
     */
    public function withAlignV(VAlign $v): self
    {
        return $this->with(alignV: $v, borderStyle: self::nop(), style: self::nop(), alignH: self::nop());
    }

    public function withContent(string $content): self
    {
        return $this->with(content: $content);
    }

    // -------------------------------------------------------------------------
    // Dimension queries
    // -------------------------------------------------------------------------

    /** Total width including border and padding. */
    public function totalWidth(): int
    {
        if ($this->kind === self::LEAF) {
            $inner = $this->minWidth;
            if ($this->border) $inner += 2;
            if ($this->padding > 0) $inner += $this->padding * 2;
            return $inner;
        }

        $childWidths = \array_sum(\array_map(fn(Node $c) => $c->totalWidth(), $this->children));
        $gaps = (\count($this->children) - 1) * $this->spacing;

        // For HORIZONTAL, border is shared; for VERTICAL, each child may have border
        if ($this->kind === self::HORIZONTAL) {
            $extra = $this->border ? 2 : 0;
            return $childWidths + $gaps + $extra;
        }

        // VERTICAL: use max child width
        $maxChild = \count($this->children) > 0
            ? \max(...\array_map(fn(Node $c) => $c->totalWidth(), $this->children))
            : 0;
        $extra = $this->border ? 2 : 0;
        return $maxChild + $gaps + $extra;
    }

    /** Total height including border and padding. */
    public function totalHeight(): int
    {
        if ($this->kind === self::LEAF) {
            $inner = $this->minHeight;
            if ($this->border) $inner += 2;
            if ($this->padding > 0) $inner += $this->padding * 2;
            return $inner;
        }

        if ($this->kind === self::VERTICAL) {
            $childHeights = \array_sum(\array_map(fn(Node $c) => $c->totalHeight(), $this->children));
            $gaps = (\count($this->children) - 1) * $this->spacing;
            $extra = $this->border ? 2 : 0;
            return $childHeights + $gaps + $extra;
        }

        // HORIZONTAL: use max child height
        $maxChild = \count($this->children) > 0
            ? \max(...\array_map(fn(Node $c) => $c->totalHeight(), $this->children))
            : 0;
        $extra = $this->border ? 2 : 0;
        return $maxChild + $extra;
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    /**
     * Sentinel for "do not change" vs explicit null.
     * Using a private static method as a sentinel factory to avoid passing
     * an instance into the constructor and to keep the type clean.
     */
    private static function nop(): \stdClass
    {
        static $sentinel;
        return $sentinel ??= new \stdClass();
    }

    private function with(
        ?string $content = null,
        ?int $minWidth = null,
        ?int $maxWidth = null,
        ?int $minHeight = null,
        ?int $maxHeight = null,
        ?int $padding = null,
        ?bool $border = null,
        ?int $spacing = null,
        mixed $borderStyle = null,
        mixed $style = null,
        string $title = '',
        array $margin = [0, 0, 0, 0],
        mixed $alignH = null,
        mixed $alignV = null,
        ?int $flex = null,
    ): self {
        // Preserve existing value when sentinel is passed (no arg).
        // Explicitly pass null to clear.
        $resolvedBorderStyle = $borderStyle === self::nop()
            ? $this->borderStyle
            : ($borderStyle ?? ($this->borderStyle ?? null));

        $resolvedStyle = $style === self::nop()
            ? $this->style
            : ($style ?? $this->style);

        $resolvedAlignH = $alignH === self::nop()
            ? $this->alignH
            : ($alignH ?? $this->alignH);

        $resolvedAlignV = $alignV === self::nop()
            ? $this->alignV
            : ($alignV ?? $this->alignV);

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
            $resolvedBorderStyle,
            $resolvedStyle,
            $title       ?: $this->title,
            $margin      !== [0, 0, 0, 0] ? $margin : $this->margin,
            $resolvedAlignH,
            $resolvedAlignV,
            $flex        ?? $this->flex,
        );
    }
}
