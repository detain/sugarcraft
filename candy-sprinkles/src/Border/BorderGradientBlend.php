<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Border;

use SugarCraft\Core\Util\Color;

/**
 * Interpolates between N colors (1-5) to produce 4 border-side colors
 * for a smooth gradient around the border perimeter (top → right →
 * bottom → left clockwise). Mirrors lipgloss v2's BorderGradientBlend.
 */
final class BorderGradientBlend
{
    /**
     * @param non-empty-list<Color> $colors 1-5 colors
     * @param list<Color>          $sides  4 interpolated colors [top, right, bottom, left]
     */
    public function __construct(
        private readonly array $colors,
        private readonly array $sides,
    ) {}

    /**
     * Build a new blend from 2-5 colors. Colors are distributed
     * proportionally around the border perimeter and interpolated
     * to exactly 4 side colors.
     *
     * @param non-empty-list<Color> $colors 1 to 5 colors
     * @throws \InvalidArgumentException When fewer than 1 or more than 5 colors
     */
    public static function fromColors(Color ...$colors): self
    {
        if (count($colors) < 1 || count($colors) > 5) {
            throw new \InvalidArgumentException(
                'BorderGradientBlend requires 1 to 5 colors, got ' . count($colors)
            );
        }

        $n = count($colors);
        $sides = [];

        for ($i = 0; $i < 4; $i++) {
            // Normalised position along the perimeter (0 = start, 1 = full loop)
            $t = $i / 3.0;

            // Which color pair to interpolate between at this stop
            $colorIndex = $t * ($n - 1);
            $lower = (int) \floor($colorIndex);
            $upper = min($lower + 1, $n - 1);
            $localT = $colorIndex - $lower;

            $sides[] = $colors[$lower]->blend($colors[$upper], $localT);
        }

        return new self($colors, $sides);
    }

    /** The N source colors passed to the constructor. */
    public function colors(): array
    {
        return $this->colors;
    }

    /**
     * The 4 interpolated colors — one per border side in clockwise order:
     * [top, right, bottom, left].
     *
     * @return list<Color>
     */
    public function sides(): array
    {
        return $this->sides;
    }
}
