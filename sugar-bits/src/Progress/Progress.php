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
    public function __construct(
        public readonly float $percent = 0.0,
        public readonly int $width = 40,
        public readonly string $fullChar  = '█',
        public readonly string $emptyChar = '░',
        public readonly bool $showPercent = true,
        public readonly ?Color $fillColor  = null,
        public readonly ?Color $emptyColor = null,
        public readonly ColorProfile $profile = ColorProfile::TrueColor,
    ) {
        if ($width < 0) {
            throw new \InvalidArgumentException('progress width must be >= 0');
        }
    }

    public static function new(): self
    {
        return new self();
    }

    public function withPercent(float $p): self
    {
        $clamped = max(0.0, min(1.0, $p));
        return new self(
            $clamped, $this->width, $this->fullChar, $this->emptyChar,
            $this->showPercent, $this->fillColor, $this->emptyColor, $this->profile,
        );
    }

    public function withWidth(int $w): self
    {
        return new self(
            $this->percent, $w, $this->fullChar, $this->emptyChar,
            $this->showPercent, $this->fillColor, $this->emptyColor, $this->profile,
        );
    }

    public function withRunes(string $full, string $empty): self
    {
        return new self(
            $this->percent, $this->width, $full, $empty,
            $this->showPercent, $this->fillColor, $this->emptyColor, $this->profile,
        );
    }

    public function withShowPercent(bool $show): self
    {
        return new self(
            $this->percent, $this->width, $this->fullChar, $this->emptyChar,
            $show, $this->fillColor, $this->emptyColor, $this->profile,
        );
    }

    public function withFillColor(?Color $c): self
    {
        return new self(
            $this->percent, $this->width, $this->fullChar, $this->emptyChar,
            $this->showPercent, $c, $this->emptyColor, $this->profile,
        );
    }

    public function withEmptyColor(?Color $c): self
    {
        return new self(
            $this->percent, $this->width, $this->fullChar, $this->emptyChar,
            $this->showPercent, $this->fillColor, $c, $this->profile,
        );
    }

    public function withColorProfile(ColorProfile $p): self
    {
        return new self(
            $this->percent, $this->width, $this->fullChar, $this->emptyChar,
            $this->showPercent, $this->fillColor, $this->emptyColor, $p,
        );
    }

    public function view(): string
    {
        $barWidth = $this->width;
        if ($this->showPercent) {
            $barWidth = max(0, $this->width - 5); // " 100%"
        }

        $filledCells = (int) round($this->percent * $barWidth);
        $emptyCells  = $barWidth - $filledCells;

        $full  = str_repeat($this->fullChar,  $filledCells);
        $empty = str_repeat($this->emptyChar, $emptyCells);

        if ($this->fillColor !== null) {
            $full = $this->fillColor->toFg($this->profile) . $full . "\x1b[0m";
        }
        if ($this->emptyColor !== null) {
            $empty = $this->emptyColor->toFg($this->profile) . $empty . "\x1b[0m";
        }

        $bar = $full . $empty;
        if (!$this->showPercent) {
            return $bar;
        }
        $pctText = sprintf('%3d%%', (int) round($this->percent * 100));
        return $bar . ' ' . $pctText;
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
