<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Bar;

use SugarCraft\Core\Concerns\Mutable;
use SugarCraft\Core\Util\Width;
use SugarCraft\Sprinkles\Lang;
use SugarCraft\Sprinkles\Style;

/**
 * A themed single-line status bar with left / center / right segment groups.
 *
 * This is the shared owner primitive behind the per-lib status bars in
 * candy-hermit, sugar-dash and sugar-crush: each becomes a thin themed
 * wrapper over this class. It is a superset of all three — it can express
 *
 *  - three positioned groups (left / center / right), each a list of
 *    {@see Segment}s joined by a configurable {@see separator()};
 *  - per-segment {@see Style} theming (crush) *or* one bar-wide base
 *    {@see style()} that colours the fill and any un-styled text (dash);
 *  - a fixed target {@see width()} that both fills (spacer padding to the
 *    width) and caps (truncates overflow), with left/right edge {@see caps()};
 *  - a natural (width-less) mode that simply concatenates the groups.
 *
 * When a fixed width is set, groups are laid out left-anchored / centered /
 * right-anchored with the free space split around the center group. On
 * overflow the bar truncates by priority left > right > center, preserving
 * each surviving segment's SGR (ANSI-aware truncation).
 *
 * Combining a bar-wide background {@see style()} with per-segment styles is
 * supported, but a per-segment style that emits an SGR reset will interrupt
 * the bar background at that point — the two consumer modes (bar-wide colour
 * vs. per-segment colour) are normally used one at a time.
 *
 * Immutable + fluent — every setter returns a new instance via {@see mutate()}.
 */
final class StatusBar
{
    use Mutable;

    /**
     * @param list<Segment> $left
     * @param list<Segment> $center
     * @param list<Segment> $right
     */
    public function __construct(
        private readonly array $left = [],
        private readonly array $center = [],
        private readonly array $right = [],
        private readonly string $separator = ' ',
        private readonly ?int $width = null,
        private readonly ?Style $style = null,
        private readonly string $leftCap = '',
        private readonly string $rightCap = '',
        private readonly string $fillChar = ' ',
        private readonly bool $visible = true,
    ) {}

    /** Empty, visible, auto-width status bar. */
    public static function new(): self
    {
        return new self();
    }

    // ─── Segment groups ─────────────────────────────────────────────────

    /**
     * Replace the left group. Accepts {@see Segment}s and/or plain strings
     * (wrapped into un-styled segments).
     */
    public function left(Segment|string ...$segments): self
    {
        return $this->mutate(['left' => self::normalize($segments)]);
    }

    /** Replace the center group. Same input shape as {@see left()}. */
    public function center(Segment|string ...$segments): self
    {
        return $this->mutate(['center' => self::normalize($segments)]);
    }

    /** Replace the right group. Same input shape as {@see left()}. */
    public function right(Segment|string ...$segments): self
    {
        return $this->mutate(['right' => self::normalize($segments)]);
    }

    /** Append to the left group. */
    public function addLeft(Segment|string ...$segments): self
    {
        return $this->mutate(['left' => [...$this->left, ...self::normalize($segments)]]);
    }

    /** Append to the center group. */
    public function addCenter(Segment|string ...$segments): self
    {
        return $this->mutate(['center' => [...$this->center, ...self::normalize($segments)]]);
    }

    /** Append to the right group. */
    public function addRight(Segment|string ...$segments): self
    {
        return $this->mutate(['right' => [...$this->right, ...self::normalize($segments)]]);
    }

    // ─── Layout knobs ───────────────────────────────────────────────────

    /** Separator inserted between segments within a single group. */
    public function separator(string $separator): self
    {
        return $this->mutate(['separator' => $separator]);
    }

    /**
     * Fixed target width in cells. The bar fills (pads) up to the width and
     * caps (truncates) longer content. Pass null for natural (auto) width.
     */
    public function width(?int $width): self
    {
        if ($width !== null && $width < 0) {
            throw new \InvalidArgumentException(Lang::t('style.width_nonneg'));
        }
        return $this->mutate(['width' => $width]);
    }

    /** Bar-wide base style — colours the fill and any un-styled text. Null clears it. */
    public function style(?Style $style): self
    {
        return $this->mutate(['style' => $style]);
    }

    /** Edge caps (borders) placed outside the segment groups. */
    public function caps(string $left, string $right): self
    {
        return $this->mutate(['leftCap' => $left, 'rightCap' => $right]);
    }

    /** Glyph used to fill the gaps between groups (default `' '`). */
    public function fillChar(string $char): self
    {
        return $this->mutate(['fillChar' => $char === '' ? ' ' : $char]);
    }

    /** Toggle visibility — a hidden bar renders to the empty string. */
    public function visible(bool $on = true): self
    {
        return $this->mutate(['visible' => $on]);
    }

    /** Alias for `visible(false)`. */
    public function hidden(): self
    {
        return $this->visible(false);
    }

    // ─── Accessors ──────────────────────────────────────────────────────

    /** @return list<Segment> */
    public function leftSegments(): array
    {
        return $this->left;
    }

    /** @return list<Segment> */
    public function centerSegments(): array
    {
        return $this->center;
    }

    /** @return list<Segment> */
    public function rightSegments(): array
    {
        return $this->right;
    }

    public function getSeparator(): string
    {
        return $this->separator;
    }

    public function getWidth(): ?int
    {
        return $this->width;
    }

    public function getStyle(): ?Style
    {
        return $this->style;
    }

    /** @return array{0:string,1:string} [leftCap, rightCap] */
    public function getCaps(): array
    {
        return [$this->leftCap, $this->rightCap];
    }

    public function getFillChar(): string
    {
        return $this->fillChar;
    }

    public function isVisible(): bool
    {
        return $this->visible;
    }

    // ─── Render ─────────────────────────────────────────────────────────

    /**
     * Assemble the bar into a single line. Returns the empty string when
     * the bar is hidden.
     */
    public function render(): string
    {
        if (!$this->visible) {
            return '';
        }

        $leftStr   = $this->joinGroup($this->left);
        $centerStr = $this->joinGroup($this->center);
        $rightStr  = $this->joinGroup($this->right);

        if ($this->width === null) {
            // Natural mode: concatenate the groups as-is (no fill / no cap-to-width).
            $bar = $this->leftCap . $leftStr . $centerStr . $rightStr . $this->rightCap;
            return $this->style !== null ? $this->style->render($bar) : $bar;
        }

        $capWidth  = Width::string($this->leftCap) + Width::string($this->rightCap);
        $available = max(0, $this->width - $capWidth);
        $content   = $this->layout($leftStr, $centerStr, $rightStr, $available);

        $bar = $this->leftCap . $content . $this->rightCap;

        // Guard the pathological case where the caps alone exceed the target
        // width: clamp the whole bar back down so it never over-runs.
        if (Width::string($bar) > $this->width) {
            $bar = Width::truncateAnsi($bar, $this->width);
        }

        return $this->style !== null ? $this->style->render($bar) : $bar;
    }

    public function __toString(): string
    {
        return $this->render();
    }

    // ─── Internals ──────────────────────────────────────────────────────

    /**
     * Join a group's segments with the separator, skipping empty runs so
     * separators never double up around a blank segment.
     *
     * @param list<Segment> $segments
     */
    private function joinGroup(array $segments): string
    {
        $parts = [];
        foreach ($segments as $segment) {
            if ($segment->isEmpty()) {
                continue;
            }
            $parts[] = $segment->render();
        }
        return implode($this->separator, $parts);
    }

    /**
     * Position the three groups inside `$available` cells: left-anchored,
     * center centered, right-anchored, gaps filled with {@see fillChar}.
     * On overflow, truncate by priority left > right > center.
     */
    private function layout(string $leftStr, string $centerStr, string $rightStr, int $available): string
    {
        if ($available <= 0) {
            return '';
        }

        $l = Width::string($leftStr);
        $c = Width::string($centerStr);
        $r = Width::string($rightStr);
        $total = $l + $c + $r;

        if ($total <= $available) {
            $free     = $available - $total;
            $leftGap  = intdiv($free, 2);
            $rightGap = $free - $leftGap;
            return $leftStr
                . $this->fill($leftGap)
                . $centerStr
                . $this->fill($rightGap)
                . $rightStr;
        }

        // Overflow: allocate by priority left > right > center, ANSI-aware
        // truncation so surviving segments keep their styling.
        $leftKeep   = min($l, $available);
        $rightKeep  = min($r, $available - $leftKeep);
        $centerKeep = $available - $leftKeep - $rightKeep;

        $lt = Width::truncateAnsi($leftStr, $leftKeep);
        $ct = Width::truncateAnsi($centerStr, $centerKeep);
        $rt = Width::truncateAnsi($rightStr, $rightKeep);

        $out = $lt . $ct . $rt;

        // Truncation may land short of a boundary (e.g. a wide grapheme that
        // would overflow its budget); pad the tail back out to the width.
        $short = $available - Width::string($out);
        if ($short > 0) {
            $out .= $this->fill($short);
        }
        return $out;
    }

    private function fill(int $count): string
    {
        return $count > 0 ? str_repeat($this->fillChar, $count) : '';
    }

    /**
     * Coerce a variadic mix of strings and Segments into a list of Segments.
     *
     * @param  array<int, Segment|string> $segments
     * @return list<Segment>
     */
    private static function normalize(array $segments): array
    {
        $out = [];
        foreach ($segments as $segment) {
            $out[] = $segment instanceof Segment ? $segment : Segment::of($segment);
        }
        return $out;
    }
}
