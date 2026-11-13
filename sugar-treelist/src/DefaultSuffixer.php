<?php

declare(strict_types=1);

namespace SugarCraft\TreeList;

use SugarCraft\Core\Util\Width;
use SugarCraft\TreeList\Support\AssertGlyph;

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
        AssertGlyph::field(self::class, 'currentMarker', $this->currentMarker);

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
