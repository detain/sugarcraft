<?php

declare(strict_types=1);

namespace SugarCraft\Top\View;

/**
 * How much of a gpu box is drawn — btop PR #1881 `Gpu::Detail`. Narrow
 * boxes drop the less important sections instead of overflowing:
 * Compact loses the enc/dec, memory and PCIe sections, Minimal also the
 * power row.
 *
 * Mirrors aristocratos/btop PR #1881 detail_for_width,
 * detail_panel_width, detail_height.
 */
enum GpuDetail: string
{
    case Full = 'full';
    case Compact = 'compact';
    case Minimal = 'minimal';

    /** btop detail_for_width: >= 56 Full, >= 44 Compact, else Minimal. */
    public static function forWidth(int $boxWidth): self
    {
        return match (true) {
            $boxWidth >= 56 => self::Full,
            $boxWidth >= 44 => self::Compact,
            default => self::Minimal,
        };
    }

    /** btop detail_panel_width: the stats sub-box width for a `$boxWidth`-wide box. */
    public function panelWidth(int $boxWidth): int
    {
        return match ($this) {
            self::Full => FrameBuilder::clamp(intdiv($boxWidth, 2), 41, 55),
            self::Compact => FrameBuilder::clamp(intdiv($boxWidth, 2), 26, 40),
            self::Minimal => FrameBuilder::clamp($boxWidth - 10, 24, 32),
        };
    }

    /**
     * btop detail_height: rows a gpu box needs at this level, `$offset`
     * being the device's gpu_b_height_offsets entry (its stat rows).
     */
    public function height(int $offset): int
    {
        return match ($this) {
            self::Full => $offset + 4,
            self::Compact => min($offset, 3) + 4,
            self::Minimal => min($offset, 1) + 4,
        };
    }
}
