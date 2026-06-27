<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Renderer;

use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Lang;
use SugarCraft\Palette\Color;

/**
 * ASCII / ANSI renderer — maps each pixel to a luminance character, optionally
 * tinted (see {@see AsciiColorMode}).
 *
 * Unlike the half/quarter-block renderers (which pack 2 or 4 pixels into one
 * cell), this maps **one source pixel to one terminal cell**, so the output is
 * one terminal line per pixel row — a true text grid. That makes it cheap, it
 * works in any terminal (Mono needs no colour at all), and crucially it *tiles*:
 * a poster grid can stitch these cards side by side just like the block
 * renderers, which the pixel-graphics protocols (sixel/kitty/iTerm2) cannot.
 *
 * Luminance is BT.601: `Y = (77R + 150G + 29B) >> 8`. Rows are joined with
 * `"\n"` (never `"\r\n"` — a stray carriage return collapses a stitched rail).
 */
final class AsciiRenderer implements Renderer
{
    /** Standard 15-step luminance ramp, darkest → brightest. */
    private const RAMP = ' .,:;i1tfLCG08@';

    public function __construct(
        private readonly AsciiColorMode $color = AsciiColorMode::TrueColor,
    ) {
    }

    public function render(ImageSource $image, int $width, ?int $height = null): string
    {
        if ($width <= 0) {
            throw new \InvalidArgumentException(Lang::t('renderer.invalid_width', ['width' => $width]));
        }
        if ($height !== null && $height <= 0) {
            throw new \InvalidArgumentException(Lang::t('renderer.invalid_height', ['height' => $height]));
        }

        $effectiveHeight = $height ?? (int) round($width / $image->aspectRatio());
        if ($effectiveHeight <= 0) {
            $effectiveHeight = 1;
        }

        $src = imagecreatefromstring($image->bytes);
        if ($src === false) {
            throw new \RuntimeException(Lang::t('renderer.gd_load_failed'));
        }
        if (!imageistruecolor($src)) {
            imagepalettetotruecolor($src);
        }

        // One pixel per cell: resample to exactly width × effectiveHeight.
        $scaled = imagecreatetruecolor($width, $effectiveHeight);
        if ($scaled === false) {
            imagedestroy($src);
            throw new \RuntimeException(Lang::t('renderer.gd_resize_failed'));
        }
        imagecopyresampled(
            $scaled, $src,
            0, 0, 0, 0,
            $width, $effectiveHeight,
            imagesx($src), imagesy($src),
        );
        imagedestroy($src);

        $rampMax = strlen(self::RAMP) - 1;
        $reset = "\x1b[0m";

        try {
            $lines = [];
            for ($y = 0; $y < $effectiveHeight; $y++) {
                $line = '';
                $coloured = false;
                for ($x = 0; $x < $width; $x++) {
                    $idx = imagecolorat($scaled, $x, $y);
                    $rgb = $idx === false ? ['red' => 0, 'green' => 0, 'blue' => 0] : imagecolorsforindex($scaled, $idx);
                    $r = $rgb['red'];
                    $g = $rgb['green'];
                    $b = $rgb['blue'];

                    $luma = (($r * 77) + ($g * 150) + ($b * 29)) >> 8;
                    $ch = self::RAMP[(int) round($luma / 255 * $rampMax)];

                    $line .= match ($this->color) {
                        AsciiColorMode::Mono      => $ch,
                        AsciiColorMode::TrueColor => "\x1b[38;2;{$r};{$g};{$b}m" . $ch,
                        AsciiColorMode::Ansi256   => "\x1b[38;5;" . (new Color($r, $g, $b))->toAnsi256Index() . 'm' . $ch,
                    };
                    if ($this->color !== AsciiColorMode::Mono) {
                        $coloured = true;
                    }
                }
                $lines[] = $coloured ? $line . $reset : $line;
            }
        } finally {
            imagedestroy($scaled);
        }

        return implode("\n", $lines);
    }

    public function name(): string
    {
        return $this->color->value;
    }

    public function supportsAlpha(): bool
    {
        return false;
    }

    /** Plain-text SGR output — no stored image identity to delete. */
    public function delete(string $imageId): string
    {
        return '';
    }
}
