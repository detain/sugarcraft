<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Scrollbar;

use SugarCraft\Bits\Lang;

/**
 * Immutable scrollbar state carrying total content lines, the current
 * vertical offset, and the number of visible lines in the viewport.
 *
 * Mirrors ratatui/ScrollbarState.
 */
final readonly class ScrollbarState
{
    /** Total number of content lines. */
    public int $total;

    /** Current vertical offset (yOffset / position). */
    public int $position;

    /** Number of visible lines in the viewport. */
    public int $viewport;

    /**
     * @param int $total     Total content lines (must be >= 0)
     * @param int $position  Current yOffset (must be in [0, max(0, total - viewport)])
     * @param int $viewport  Visible lines (must be >= 0)
     */
    public function __construct(int $total, int $position, int $viewport)
    {
        if ($total < 0) {
            throw new \InvalidArgumentException(Lang::t('scrollbar.total_nonneg'));
        }
        if ($viewport < 0) {
            throw new \InvalidArgumentException(Lang::t('scrollbar.viewport_nonneg'));
        }

        $maxPosition = max(0, $total - $viewport);
        if ($position < 0 || $position > $maxPosition) {
            throw new \InvalidArgumentException(Lang::t('scrollbar.position_range'));
        }

        $this->total = $total;
        $this->position = $position;
        $this->viewport = $viewport;
    }

    /**
     * Factory constructor with defaults: total=0, position=0, viewport=0.
     */
    public static function new(int $total = 0, int $position = 0, int $viewport = 0): self
    {
        return new self($total, $position, $viewport);
    }
}
