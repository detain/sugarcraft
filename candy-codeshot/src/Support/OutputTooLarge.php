<?php

declare(strict_types=1);

namespace SugarCraft\CodeShot\Support;

/**
 * A render whose canvas would cross the advertised pixel ceiling.
 *
 * Both engines refuse oversized output with this type (candy-codeshot-021):
 * the message stays a human-readable English diagnostic for library
 * consumers, while the CLI lane reads the typed fields to answer in the
 * user's own language (the bin's generic render-failure catch interpolates
 * only a raw English reason otherwise).
 */
final class OutputTooLarge extends \RuntimeException
{
    public readonly string $format;
    public readonly int $width;
    public readonly int $height;
    public readonly int $pixels;
    public readonly int $cap;

    public function __construct(string $format, int $width, int $height, int $cap)
    {
        $this->format  = $format;
        $this->width   = $width;
        $this->height  = $height;
        $this->pixels  = $width * $height;
        $this->cap     = $cap;

        parent::__construct(sprintf(
            '%s output too large (%d x %d = %d pixels; cap %d). Reduce content, padding or line count.',
            $this->format, $this->width, $this->height, $this->pixels, $this->cap,
        ));
    }
}
