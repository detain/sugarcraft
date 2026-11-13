<?php

declare(strict_types=1);

namespace SugarCraft\CodeShot\Support;

/**
 * A font file too large to base64-embed into the SVG.
 *
 * The actionable sibling of {@see OutputTooLarge} (candy-codeshot-021): the
 * engine refuses the embed before any memory is spent, and the CLI turns
 * the typed fields into a localized refusal instead of the raw English
 * engine line.
 */
final class FontTooLarge extends \RuntimeException
{
    public readonly string $path;
    public readonly int $size;
    public readonly int $cap;

    public function __construct(string $path, int $size, int $cap)
    {
        $this->path = $path;
        $this->size = $size;
        $this->cap  = $cap;

        parent::__construct(sprintf(
            'Font file too large to embed: %d bytes exceed the %s limit (%s)',
            $this->size, $this->capLabel(), $this->path,
        ));
    }

    /**
     * The ceiling as people read it: a whole number of MiB gets the unit
     * form the message has always shipped ("32 MiB"), anything else falls
     * back to the raw byte count rather than a rounded lie.
     */
    public function capLabel(): string
    {
        return $this->cap % 1_048_576 === 0
            ? intdiv($this->cap, 1_048_576) . ' MiB'
            : (string) $this->cap;
    }
}
