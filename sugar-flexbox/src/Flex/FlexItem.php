<?php

declare(strict_types=1);

namespace SugarCraft\Flexbox\Flex;

use SugarCraft\Core\Concerns\Mutable;
use SugarCraft\Core\Util\Width;
use SugarCraft\Flexbox\Support\Sgr;

/**
 * A single item inside a FlexBox.
 *
 * Holds content + layout constraints (ratio, basis).
 * Uses ratio-based sizing (like CSS flex-grow) for space distribution.
 *
 * The content is fixed at construction (every `with*` answers a fresh
 * instance), so measured geometry is derivation over immutable input:
 * the split lines and both axis measures memoize themselves once and
 * every later frame reads the cache — a reused item inside a hot
 * render loop costs one explode per INSTANCE, not per frame.
 */
final class FlexItem
{
    use Mutable;

    public readonly string $content;

    /** Relative size ratio — items with higher ratios take more space. */
    public readonly int $ratio;

    /**
     * Fixed main-axis reservation in cells (row: width, column: height).
     * 0 means NO fixed reservation — the item then shares free space by
     * ratio; a positive basis excludes the item from the flex pool.
     */
    public readonly int $basis;

    /** Style applied to this item (ANSI string). */
    public readonly string $style;

    /**
     * Memo of the raw explode("\n") split of content — content is fixed at
     * construction so the split is derivable once per instance and reused by
     * every measure and every frame. Not readonly: PHP forbids a readonly
     * property being written lazily, but the value is immutable-by-contract.
     *
     * @var list<string>|null
     */
    private ?array $lineMemo = null;

    /** Memo of measuredWidth() for this instance. */
    private ?int $widthMemo = null;

    /** Memo of measuredHeight() for this instance. */
    private ?int $heightMemo = null;

    /**
     * The lib's copy path (FBX-23): every with* answers a new instance
     * built from the four constructor fields plus the overrides. The
     * measured memos are deliberately NOT carried — a new content,
     * ratio or basis invalidates every memo anyway, and 'fresh by
     * construction' is the law the old hand-repeated tails kept.
     *
     * @param array<string, mixed> $changes
     */
    protected function mutate(array $changes): static
    {
        return new self(...array_merge([
            'content' => $this->content,
            'ratio'   => $this->ratio,
            'basis'   => $this->basis,
            'style'   => $this->style,
        ], $changes));
    }

    private function __construct(
        string $content,
        int $ratio = 1,
        int $basis = 0,
        string $style = '',
    ) {
        $this->content = $content;
        $this->ratio   = $ratio;
        $this->basis   = $basis;
        $this->style   = $style;
    }

    /**
     * The raw content split on newlines, once per instance.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        return $this->lineMemo ??= explode("\n", $this->content);
    }

    /**
     * Natural content width — the widest raw line, measured on the UNSWEPT
     * string (the renderer sanitizes per line later; see the "Trust boundary"
     * disclosure). Cached: content is fixed at construction, so this is pure
     * derivation with a safe memo.
     */
    public function measuredWidth(): int
    {
        if ($this->widthMemo !== null) {
            return $this->widthMemo;
        }
        $widths = array_map(Width::string(...), $this->lines());

        return $this->widthMemo = $widths === [] ? 0 : max($widths);
    }

    /**
     * Natural content height — the raw line count (same raw-measurement ingress
     * as measuredWidth()). Cached.
     */
    public function measuredHeight(): int
    {
        return $this->heightMemo ??= count($this->lines());
    }

    public static function new(string $content = ''): self
    {
        return new self($content);
    }

    public function withContent(string $content): self
    {
        return $this->mutate(['content' => $content]);
    }

    /**
     * @param int $ratio Growth weight, >= 0 (mirrors CSS, where a negative
     *                   flex-grow is invalid). 0 is legal and means "never
     *                   grow": the item then keeps a 1-cell floor even
     *                   beside flex siblings.
     */
    public function withRatio(int $ratio): self
    {
        if ($ratio < 0) {
            throw new \InvalidArgumentException(
                'Ratio must be >= 0; got ' . $ratio . '.',
            );
        }
        return $this->mutate(['ratio' => $ratio]);
    }

    /**
     * @param int $basis Fixed main-axis reservation in cells, >= 0. A
     *                   positive basis excludes the item from the flex pool
     *                   and is clamped to the container size at render
     *                   time, so an oversized value shrinks instead of
     *                   storming memory.
     */
    public function withBasis(int $basis): self
    {
        if ($basis < 0) {
            throw new \InvalidArgumentException(
                'Basis must be >= 0 cells; got ' . $basis . '.',
            );
        }
        return $this->mutate(['basis' => $basis]);
    }

    public function withStyle(string $ansiStyle): self
    {
        $style = Sgr::normalize($ansiStyle, 'FlexItem style');
        return $this->mutate(['style' => $style]);
    }

    /**
     * Alias of withBasis(): the fixed MAIN-AXIS reservation. The name is
     * row-flavoured — in a column box this same value is the item's
     * HEIGHT in cells, not its width — which makes it an orthographic
     * trap (finding-065: direction-flipping names lie in half the modes).
     *
     * @deprecated Use {@see self::withBasis()}, which names the axis by
     *     role, not by direction. REMOVED-IN: 2.0.0.
     */
    public function withWidth(int $width): self { return $this->withBasis($width); }
}
