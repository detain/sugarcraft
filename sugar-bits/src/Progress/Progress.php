<?php

declare(strict_types=1);

namespace CandyCore\Bits\Progress;

use CandyCore\Core\Util\Color;
use CandyCore\Core\Util\ColorProfile;
use CandyCore\Core\Util\Width;

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
    ) {
        if ($width < 0) {
            throw new \InvalidArgumentException('progress width must be >= 0');
        }
        // Clamp at construction time so direct `new Progress(...)` callers
        // can't smuggle in an out-of-range value that later turns into a
        // negative str_repeat() count and crashes view().
        $this->percent = max(0.0, min(1.0, $percent));
    }

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

    public function view(): string
    {
        // The percent suffix " 100%" needs ~5 cells. Rather than guess
        // the formatted suffix length, render it once and use its
        // measured width.
        $pctText = $this->showPercent
            ? sprintf($this->percentFormat, (int) round($this->percent * 100))
            : '';
        $suffixCells = $this->showPercent ? Width::string($pctText) + 1 : 0;

        $showSuffix = $this->showPercent && $this->width > $suffixCells;
        $barWidth   = $showSuffix ? $this->width - $suffixCells : $this->width;

        $filledCells = (int) round($this->percent * $barWidth);
        $emptyCells  = $barWidth - $filledCells;

        // Gradient takes priority over flat fillColor.
        if ($this->gradientStart !== null && $this->gradientEnd !== null && $filledCells > 0) {
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
        return $bar . ' ' . $pctText;
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
