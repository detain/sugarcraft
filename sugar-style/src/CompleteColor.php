<?php

declare(strict_types=1);

namespace SugarCraft\Style;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * Profile-aware colour triple. Lets the designer pick the *exact*
 * colour to use at each capability tier, rather than relying on
 * runtime quantization from a single TrueColor value. Mirrors
 * lipgloss v2's `CompleteColor`.
 *
 * Use with {@see Style::foregroundComplete()} /
 * {@see Style::backgroundComplete()}; the live {@see ColorProfile}
 * (whatever was set on the Style via `colorProfile()`) picks one of
 * the three colours at render time.
 *
 * For terminals reporting `Ascii` / `NoTty`, the ANSI fallback is
 * returned but `Style::buildContentSgr()` will skip emission anyway
 * since SGR isn't supported.
 */
final class CompleteColor
{
    public function __construct(
        public readonly Color $trueColor,
        public readonly Color $ansi256,
        public readonly Color $ansi,
    ) {}

    public function pick(ColorProfile $profile): Color
    {
        return match (true) {
            $profile->supportsTrueColor() => $this->trueColor,
            $profile->supports256()       => $this->ansi256,
            default                       => $this->ansi,
        };
    }
}
