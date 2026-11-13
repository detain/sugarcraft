<?php

declare(strict_types=1);

namespace SugarCraft\Gallery;

/**
 * One size vocabulary for a poster card: width, poster rows and the two
 * gutters, clamped ONCE at ingress with the same floors the loose-int seams
 * always applied (width ≥ 4, height ≥ 1, spacings ≥ 0). Accepted additively
 * wherever the library took those ints — {@see PosterGrid::new()},
 * {@see PosterGrid::withCardSize()}, {@see Rail::render()}, {@see Rail::perRow()},
 * {@see Rail::frameHeight()}, {@see PosterCard::render()} — and the int
 * signatures stay byte-identical.
 */
final readonly class CardSize
{
    private function __construct(
        private int $width,
        private int $height,
        private int $hSpacing,
        private int $vSpacing,
    ) {
    }

    public static function new(int $width, int $height, int $hSpacing = 2, int $vSpacing = 1): self
    {
        return new self(max(4, $width), max(1, $height), max(0, $hSpacing), max(0, $vSpacing));
    }

    /**
     * Normalise an `int|CardSize` seam: a CardSize passes through untouched
     * (its own gutters win), an int width needs its poster height.
     *
     * @throws \ArgumentCountError when an int width arrives without a height
     */
    public static function from(int|self $width, ?int $height = null, ?int $hSpacing = null, ?int $vSpacing = null): self
    {
        if ($width instanceof self) {
            return $width;
        }
        if ($height === null) {
            throw new \ArgumentCountError('CardSize: an int card width needs a poster height.');
        }

        return self::new($width, $height, $hSpacing ?? 2, $vSpacing ?? 1);
    }

    /** Card width in cells (clamped ≥ 4). */
    public function width(): int
    {
        return $this->width;
    }

    /** Poster rows per card (clamped ≥ 1). */
    public function height(): int
    {
        return $this->height;
    }

    /** Horizontal gutter (clamped ≥ 0). */
    public function hSpacing(): int
    {
        return $this->hSpacing;
    }

    /** Vertical gutter (clamped ≥ 0). */
    public function vSpacing(): int
    {
        return $this->vSpacing;
    }

    public function withWidth(int $width): self
    {
        return self::new($width, $this->height, $this->hSpacing, $this->vSpacing);
    }

    public function withHeight(int $height): self
    {
        return self::new($this->width, $height, $this->hSpacing, $this->vSpacing);
    }

    public function withHSpacing(int $hSpacing): self
    {
        return self::new($this->width, $this->height, $hSpacing, $this->vSpacing);
    }

    public function withVSpacing(int $vSpacing): self
    {
        return self::new($this->width, $this->height, $this->hSpacing, $vSpacing);
    }
}
