<?php

declare(strict_types=1);

namespace SugarCraft\TreeList;

use SugarCraft\Core\Util\Width;

/**
 * Default suffixer — shows a < marker on the first line of the cursor item.
 *
 * The Model right-pads the content cell ONLY for lines where suffix()
 * returns a non-empty marker; every other line is emitted at its natural
 * width (there is no whole-viewport right-padding contract). The marker
 * glyph is validated at init: control bytes are refused, not smuggled.
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
        if (\preg_match('/[\x00-\x1F\x7F]/', $this->currentMarker) === 1
            || !\mb_check_encoding($this->currentMarker, 'UTF-8')
            || \preg_match('/[\x{80}-\x{9F}]/u', $this->currentMarker) === 1
        ) {
            throw new \InvalidArgumentException(
                'DefaultSuffixer:$currentMarker must be a printable glyph string without control characters (C0/DEL/C1).'
            );
        }

        $this->itemIndex   = $currentIndex;
        $this->cursorIndex = $cursorIndex;
        $this->markerWidth = Width::string($this->currentMarker);
        $this->suffixWidth = $this->markerWidth;

        return $this->suffixWidth;
    }

    public function suffix(int $currentLine, int $totalLines): string
    {
        if ($this->itemIndex === $this->cursorIndex && $currentLine === 0) {
            return $this->currentMarker;
        }
        // No suffix for this line — and the Model therefore does NOT right-pad
        // it. Rows without a non-empty suffix end at their content width.
        return '';
    }
}
