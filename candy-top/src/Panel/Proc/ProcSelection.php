<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Proc;

/**
 * The proc list's scroll position and cursor — btop's proc_start /
 * proc_selected / proc_last_selected.
 *
 *  - `start`: rows scrolled off the top (0-based);
 *  - `selected`: 1-based row on screen, 0 = nothing selected (btop shows
 *    no bar until the first up/down);
 *  - `lastSelected`: the row the detailed view was opened from, restored
 *    by the next `down` (or on close when proc_follow_detailed is off).
 *
 * {@see move()} ports Proc::selection key for key; {@see clamp()} is the
 * bounds check Proc::draw runs before every frame, which here is also
 * applied after every move so the stored cursor never runs past the list
 * (btop leaves proc_selected inflated past the end and only clamps the
 * drawn copy — a deviation in that corner only).
 *
 * Mirrors aristocratos/btop Proc::selection / Proc::draw's bounds check
 * (src/btop_draw.cpp).
 */
final class ProcSelection
{
    private function __construct(
        public readonly int $start,
        public readonly int $selected,
        public readonly int $lastSelected,
    ) {
    }

    public static function new(int $start = 0, int $selected = 0, int $lastSelected = 0): self
    {
        return new self(max(0, $start), max(0, $selected), max(0, $lastSelected));
    }

    /**
     * Apply one btop selection key: up, down, page_up, page_down, home,
     * end, mouse_scroll_up, mouse_scroll_down or `mousey<N>` (a scrollbar
     * click N rows below the top arrow).
     */
    public function move(string $key, int $numpids, int $selectMax): self
    {
        $start = $this->start;
        $selected = $this->selected;
        $last = $this->lastSelected;
        $selectMax = max(1, $selectMax);

        if ($key === 'up') {
            if ($selected > 0) {
                if ($start > 0 && $selected === 1) {
                    $start--;
                } else {
                    $selected--;
                }
                $last = 0;
            }
        } elseif ($key === 'mouse_scroll_up') {
            if ($start > 0) {
                $start = max(0, $start - 3);
            }
        } elseif ($key === 'mouse_scroll_down') {
            if ($start < $numpids - $selectMax) {
                $start = min($numpids - $selectMax, $start + 3);
            }
        } elseif ($key === 'down') {
            if ($start < $numpids - $selectMax && $selected === $selectMax) {
                $start++;
            } elseif ($selected === 0 && $last > 0) {
                $selected = $last;
                $last = 0;
            } else {
                $selected++;
            }
        } elseif ($key === 'page_up') {
            if ($selected > 0 && $start === 0) {
                $selected = 0;
            } else {
                $start = max(0, $start - $selectMax);
            }
        } elseif ($key === 'page_down') {
            if ($selected > 0 && $start >= $numpids - $selectMax) {
                $selected = $selectMax;
            } else {
                $start = max(0, min($start + $selectMax, max(0, $numpids - $selectMax)));
            }
        } elseif ($key === 'home') {
            $start = 0;
            if ($selected > 0) {
                $selected = 1;
            }
        } elseif ($key === 'end') {
            $start = max(0, $numpids - $selectMax);
            if ($selected > 0) {
                $selected = $selectMax;
            }
        } elseif (str_starts_with($key, 'mousey')) {
            $start = self::scrollTo((int) substr($key, 6), $numpids, $selectMax);
        }

        return (new self($start, $selected, $last))->clamp($numpids, $selectMax);
    }

    /**
     * btop's proportional scrollbar click (draw §3.5):
     * `start = round(y * (numpids - selectMax - 2) / (selectMax - 2))`,
     * clamped to [0, numpids - selectMax]. `$y` counts rows below the top
     * arrow. A track of two rows or less has no proportion to take; it
     * divides by 1 instead of btop's divide-by-zero.
     */
    public static function scrollTo(int $y, int $numpids, int $selectMax): int
    {
        $start = (int) round($y * ($numpids - $selectMax - 2) / max(1, $selectMax - 2));

        return max(0, min($start, max(0, $numpids - $selectMax)));
    }

    /**
     * btop's scrollbar thumb row below the top arrow:
     * `clamp(round(start * selectMax / (numpids - selectMax)), 0, height - 5)`.
     */
    public static function thumb(int $start, int $numpids, int $selectMax, int $height): int
    {
        if ($numpids <= $selectMax) {
            return 0;
        }

        return max(0, min((int) round($start * $selectMax / ($numpids - $selectMax)), $height - 5));
    }

    /** Proc::draw's bounds check. */
    public function clamp(int $numpids, int $selectMax): self
    {
        $start = $this->start;
        $selected = $this->selected;
        if ($start > 0 && $numpids <= $selectMax) {
            $start = 0;
        }
        if ($start > $numpids - $selectMax) {
            $start = max(0, $numpids - $selectMax);
        }
        $selected = min($selected, max(0, $selectMax), max(0, $numpids));

        return $start === $this->start && $selected === $this->selected
            ? $this
            : new self($start, $selected, $this->lastSelected);
    }

    public function withSelected(int $selected): self
    {
        return new self($this->start, max(0, $selected), $this->lastSelected);
    }

    public function withStart(int $start): self
    {
        return new self(max(0, $start), $this->selected, $this->lastSelected);
    }

    public function withLastSelected(int $last): self
    {
        return new self($this->start, $this->selected, max(0, $last));
    }

    /** 0-based index into the visible rows of the selected row, or null. */
    public function index(): ?int
    {
        return $this->selected > 0 ? $this->start + $this->selected - 1 : null;
    }
}
