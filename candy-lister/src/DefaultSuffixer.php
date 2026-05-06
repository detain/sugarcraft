<?php

declare(strict_types=1);

namespace CandyCore\Lister;

/**
 * Default suffixer — shows a < marker on the first line of the cursor item,
 * pads the rest to maintain horizontal alignment.
 */
final class DefaultSuffixer implements Suffixer
{
    public string $currentMarker = '<';

    private int $itemIndex   = 0;
    private int $cursorIndex = 0;
    private int $markerWidth = 1;
    private int $suffixWidth = 1;

    public function initSuffixer(
        \Stringable $value,
        int $currentIndex,
        int $cursorIndex,
        int $lineOffset,
        int $width,
        int $height,
    ): int {
        $this->itemIndex   = $currentIndex;
        $this->cursorIndex = $cursorIndex;
        $this->markerWidth = DefaultPrefixer::ansiWidth($this->currentMarker);
        $this->suffixWidth = $this->markerWidth;

        return $this->suffixWidth;
    }

    public function suffix(int $currentLine, int $totalLines): string
    {
        if ($this->itemIndex === $this->cursorIndex && $currentLine === 0) {
            return $this->currentMarker;
        }
        // Empty suffix — line will be right-padded to viewport width by the Model
        return '';
    }
}
