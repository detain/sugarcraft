<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Progress;

use SugarCraft\Bits\Lang;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * Static progress bar.
 *
 * ```php
 * echo Progress::new()->withWidth(40)->withPercent(0.42)->view();
 * // ████████████████░░░░░░░░░░░░░░░░░░░░░░  42%
 * ```
 *
 * Each setter returns a new instance; the {@see view()} output adapts to
 * the configured width and runes. A solid {@see $fillColor} renders both
 * the filled and empty cells with the same foreground SGR (downsampled
 * via the supplied {@see ColorProfile}); set it to `null` for plain text.
 *
 * Spring-physics interpolation (via HoneyBounce) lands in a follow-up.
 */
final class Progress
{
    public readonly float $percent;

    /** @var list<Color> */
    public readonly array $gradientStops;

    /** @var ?\Closure(int, int, float): Color */
    private readonly ?\Closure $colorFunc;

    public function __construct(
        float $percent = 0.0,
        public readonly int $width = 40,
        public readonly string $fullChar  = '█',
        public readonly string $emptyChar = '░',
        public readonly bool $showPercent = true,
        public readonly ?Color $fillColor  = null,
        public readonly ?Color $emptyColor = null,
        public readonly ColorProfile $profile = ColorProfile::TrueColor,
        public readonly ?Color $gradientStart = null,
        public readonly ?Color $gradientEnd   = null,
        public readonly string $percentFormat = '%3d%%',
        public readonly bool $showValue = false,
        public readonly string $showValueFormat = '%d/%d',
        array $gradientStops = [],
        ?\Closure $colorFunc = null,
    ) {
        if ($width < 0) {
            throw new \InvalidArgumentException(Lang::t('progress.width_nonneg'));
        }
        $this->percent = max(0.0, min(1.0, $percent));
        $this->gradientStops = array_values($gradientStops);
        $this->colorFunc = $colorFunc;
    }

    /** Construct a fresh instance with default state. */
    public static function new(): self
    {
        return new self();
    }

    public function withPercent(float $p): self
    {
        return $this->mutate(percent: max(0.0, min(1.0, $p)));
    }

    /** Increase percent by delta. Mirrors Bubbles' `IncrPercent`. */
    public function incrPercent(float $delta): self
    {
        return $this->withPercent($this->percent + $delta);
    }

    /** Decrease percent by delta. Mirrors Bubbles' `DecrPercent`. */
    public function decrPercent(float $delta): self
    {
        return $this->withPercent($this->percent - $delta);
    }

    public function withWidth(int $w): self        { return $this->mutate(width: $w); }
    public function withRunes(string $full, string $empty): self
    {
        return $this->mutate(fullChar: $full, emptyChar: $empty);
    }
    public function withShowPercent(bool $show): self { return $this->mutate(showPercent: $show); }
    public function withShowValue(bool $show, ?string $format = null): self
    {
        return $this->mutate(showValue: $show, showValueFormat: $format ?? $this->showValueFormat);
    }
    public function withFillColor(?Color $c): self    { return $this->mutate(fillColor: $c, fillColorSet: true); }
    public function withEmptyColor(?Color $c): self   { return $this->mutate(emptyColor: $c, emptyColorSet: true); }
    public function withColorProfile(ColorProfile $p): self { return $this->mutate(profile: $p); }

    /**
     * Render a smooth gradient across the filled cells from `$start`
     * to `$end`. Mirrors Bubbles' `WithGradient`. Overrides any flat
     * `fillColor` set previously. Pass either side as null to clear
     * the gradient and revert to the flat fill colour.
     */
    public function withGradient(?Color $start, ?Color $end): self
    {
        return $this->mutate(
            gradientStart: $start, gradientStartSet: true,
            gradientEnd: $end, gradientEndSet: true,
        );
    }

    /**
     * Disable gradient rendering and fall back to a flat `$color`.
     * Mirrors Bubbles' `WithSolidFill`.
     */
    public function withSolidFill(Color $color): self
    {
        return $this->mutate(
            fillColor: $color, fillColorSet: true,
            gradientStart: null, gradientStartSet: true,
            gradientEnd: null, gradientEndSet: true,
        );
    }

    /**
     * Default rainbow gradient (cyan → magenta) — quick way to get a
     * playful look without picking colours by hand.
     */
    public function withDefaultGradient(): self
    {
        return $this->withGradient(Color::hex('#5fafff'), Color::hex('#ff5fd2'));
    }

    /**
     * Multi-stop colour gradient. Two or more {@see Color}s are evenly
     * distributed across the bar's filled cells; pairs of adjacent
     * stops drive linear blends. Mirrors upstream Bubbles'
     * `WithColors(...colors)`.
     *
     * Passing a single colour acts like {@see withSolidFill()};
     * passing zero clears every gradient setting.
     */
    public function withColors(Color ...$colors): self
    {
        if ($colors === []) {
            return $this->mutate(
                gradientStops: [], gradientStopsSet: true,
                gradientStart: null, gradientStartSet: true,
                gradientEnd: null, gradientEndSet: true,
            );
        }
        if (count($colors) === 1) {
            return $this->withSolidFill($colors[0]);
        }
        return $this->mutate(
            gradientStops: array_values($colors), gradientStopsSet: true,
            // Mirror the first/last stops onto the legacy fields for
            // back-compat — callers that read $gradientStart/End get
            // sensible values.
            gradientStart: $colors[0],                 gradientStartSet: true,
            gradientEnd:   $colors[count($colors) - 1], gradientEndSet: true,
        );
    }

    /**
     * Closure invoked per filled cell to produce a {@see Color}:
     * `fn(int $cell, int $totalCells, float $percent): Color`.
     * Wins over every other colour setting when set. Mirrors
     * upstream `WithColorFunc`.
     *
     * Pass `null` to clear.
     */
    public function withColorFunc(?\Closure $fn): self
    {
        return $this->mutate(colorFunc: $fn, colorFuncSet: true);
    }

    /**
     * Custom format string for the percent suffix. Receives the
     * 0-100 integer via printf, e.g. `'%3d%%'` (default), `'%5.1f%%'`,
     * or `'(%d%%)'`. Mirrors Bubbles' `PercentFormat`.
     */
    public function withPercentFormat(string $fmt): self
    {
        return $this->mutate(percentFormat: $fmt);
    }

    /**
     * Render the bar at an explicit percent without mutating state.
     * Mirrors Bubbles' `ViewAs`.
     */
    public function viewAs(float $percent): string
    {
        return $this->withPercent($percent)->view();
    }

    /** Render the component as a multi-line ANSI string. */
    public function view(): string
    {
        // The percent suffix " 100%" needs ~5 cells. Rather than guess
        // the formatted suffix length, render it once and use its
        // measured width.
        $pctText = $this->showPercent
            ? sprintf($this->percentFormat, (int) round($this->percent * 100))
            : '';

        // Calculate value text (current/total) when showValue is enabled.
        $valueText = '';
        if ($this->showValue) {
            $current = (int) round($this->percent * $this->width);
            $valueText = sprintf($this->showValueFormat, $current, $this->width);
        }

        // Bar width is reduced only when showing percent without value.
        // When showValue is true, the bar stays at full width and the
        // value suffix (with optional percent in parentheses) appends after.
        $suffixCells = $this->showPercent ? Width::string($pctText) + 1 : 0;
        $showSuffix = $this->showPercent && $this->width > $suffixCells;
        $barWidth = $this->width;

        // Build the full suffix text to display.
        $fullSuffixText = '';
        if ($this->showPercent && $this->showValue) {
            // Both shown: percent first, value in parentheses after.
            $fullSuffixText = $pctText . ' (' . $valueText . ')';
            // Don't reduce bar width when value is shown
        } elseif ($this->showPercent) {
            $fullSuffixText = $pctText;
            $barWidth = $showSuffix ? $this->width - $suffixCells : $this->width;
        } elseif ($this->showValue) {
            // Value only: bar stays at full width, suffix appends after.
            $fullSuffixText = $valueText;
            $showSuffix = true;
        }

        $filledCells = (int) round($this->percent * $barWidth);
        $emptyCells  = $barWidth - $filledCells;

        // Precedence (highest first): colorFunc > multi-stop gradient
        // > 2-stop gradient > flat fillColor > no colour.
        if ($this->colorFunc !== null && $filledCells > 0) {
            $full = $this->renderColorFunc($filledCells);
        } elseif (count($this->gradientStops) >= 2 && $filledCells > 0) {
            $full = $this->renderMultiStopGradient($filledCells);
        } elseif ($this->gradientStart !== null && $this->gradientEnd !== null && $filledCells > 0) {
            $full = $this->renderGradient($filledCells);
        } else {
            $full = str_repeat($this->fullChar, $filledCells);
            if ($this->fillColor !== null) {
                $full = $this->fillColor->toFg($this->profile) . $full . "\x1b[0m";
            }
        }

        $empty = str_repeat($this->emptyChar, $emptyCells);
        if ($this->emptyColor !== null) {
            $empty = $this->emptyColor->toFg($this->profile) . $empty . "\x1b[0m";
        }

        $bar = $full . $empty;
        if (!$showSuffix) {
            return $bar;
        }
        return $bar . ' ' . $fullSuffixText;
    }

    /**
     * Paint `$cells` filled glyphs with per-cell colour blended from
     * `$gradientStart` (cell 0) to `$gradientEnd` (cell N-1).
     */
    private function renderGradient(int $cells): string
    {
        if ($cells <= 0 || $this->gradientStart === null || $this->gradientEnd === null) {
            return '';
        }
        $out = '';
        for ($i = 0; $i < $cells; $i++) {
            $t = $cells === 1 ? 0.0 : $i / ($cells - 1);
            $c = $this->gradientStart->blend($this->gradientEnd, $t);
            $out .= $c->toFg($this->profile) . $this->fullChar . "\x1b[0m";
        }
        return $out;
    }

    /**
     * Render `$cells` filled glyphs across N evenly-spaced colour
     * stops. Each cell's colour is the linear blend of its bracketing
     * pair of stops.
     */
    private function renderMultiStopGradient(int $cells): string
    {
        $stops = $this->gradientStops;
        $n = count($stops);
        if ($cells <= 0 || $n < 2) {
            return '';
        }
        $out = '';
        $segments = $n - 1;
        for ($i = 0; $i < $cells; $i++) {
            $t = $cells === 1 ? 0.0 : $i / ($cells - 1);
            $segPos = $t * $segments;
            $segIdx = (int) floor($segPos);
            if ($segIdx >= $segments) {
                $segIdx = $segments - 1;
            }
            $localT = $segPos - $segIdx;
            $c = $stops[$segIdx]->blend($stops[$segIdx + 1], $localT);
            $out .= $c->toFg($this->profile) . $this->fullChar . "\x1b[0m";
        }
        return $out;
    }

    /**
     * Render `$cells` filled glyphs by invoking the user's colour
     * closure for each. Closure shape:
     * `fn(int $cell, int $totalCells, float $percent): Color`.
     */
    private function renderColorFunc(int $cells): string
    {
        if ($cells <= 0 || $this->colorFunc === null) {
            return '';
        }
        $out = '';
        for ($i = 0; $i < $cells; $i++) {
            /** @var Color $c */
            $c = ($this->colorFunc)($i, $cells, $this->percent);
            $out .= $c->toFg($this->profile) . $this->fullChar . "\x1b[0m";
        }
        return $out;
    }

    private function mutate(
        ?float $percent = null,
        ?int $width = null,
        ?string $fullChar = null,
        ?string $emptyChar = null,
        ?bool $showPercent = null,
        ?Color $fillColor = null, bool $fillColorSet = false,
        ?Color $emptyColor = null, bool $emptyColorSet = false,
        ?ColorProfile $profile = null,
        ?Color $gradientStart = null, bool $gradientStartSet = false,
        ?Color $gradientEnd = null, bool $gradientEndSet = false,
        ?string $percentFormat = null,
        ?bool $showValue = null,
        ?string $showValueFormat = null,
        ?array $gradientStops = null, bool $gradientStopsSet = false,
        ?\Closure $colorFunc = null, bool $colorFuncSet = false,
    ): self {
        return new self(
            percent:        $percent       ?? $this->percent,
            width:          $width         ?? $this->width,
            fullChar:       $fullChar      ?? $this->fullChar,
            emptyChar:      $emptyChar     ?? $this->emptyChar,
            showPercent:    $showPercent   ?? $this->showPercent,
            fillColor:      $fillColorSet  ? $fillColor      : $this->fillColor,
            emptyColor:     $emptyColorSet ? $emptyColor     : $this->emptyColor,
            profile:        $profile       ?? $this->profile,
            gradientStart:  $gradientStartSet ? $gradientStart : $this->gradientStart,
            gradientEnd:    $gradientEndSet   ? $gradientEnd   : $this->gradientEnd,
            percentFormat:  $percentFormat ?? $this->percentFormat,
            showValue:      $showValue     ?? $this->showValue,
            showValueFormat: $showValueFormat ?? $this->showValueFormat,
            gradientStops:  $gradientStopsSet ? ($gradientStops ?? []) : $this->gradientStops,
            colorFunc:      $colorFuncSet  ? $colorFunc       : $this->colorFunc,
        );
    }

    public function __toString(): string
    {
        return $this->view();
    }

    /** Reported visible cell width of the rendered view (handy for layout). */
    public function viewWidth(): int
    {
        return Width::string($this->view());
    }
}
