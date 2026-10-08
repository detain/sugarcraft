<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel;

/**
 * A panel that also receives ANOTHER box's samples (generic App hook).
 *
 * btop PR #1873 collects the containers in the same pass as the
 * processes (`Ctr::collect(current_procs)` inside Proc::collect). Each
 * candy-top panel samples its own source, so instead of scanning /proc a
 * second time the ctr panel taps the proc panel's {@see
 * \SugarCraft\Top\Msg\SampledMsg}: after the owner has taken it, the App
 * hands the same message to every VISIBLE panel whose {@see taps()} names
 * that box (a hidden tap costs nothing, #1858).
 */
interface SampleTap
{
    /** The box whose samples this panel also wants (`proc`). */
    public function taps(): string;

    /**
     * This panel's state as its box is (re)shown — called by the App
     * before the opening collect(). The ctr box drops its cpu-window
     * baseline here, so a quick hide-and-reopen still counts its first
     * sample instead of comparing against the sample taken before it hid.
     */
    public function opened(): Panel;
}
