<?php

declare(strict_types=1);

namespace SugarCraft\Top\Overlay;

use SugarCraft\Core\Msg;
use SugarCraft\Top\View\Surface;

/**
 * One btop Menu (src/btop_menu.cpp) on the App-owned overlay stack: the
 * main menu, help, the signal / renice menus and every msgBox (size error,
 * signal confirm, signal failure) — and, from P-F2, the options menu.
 *
 * Lifecycle: something REQUESTS an overlay — a panel through
 * {@see \SugarCraft\Top\Panel\PanelResult::$overlay}, a Cmd through
 * {@see \SugarCraft\Top\Msg\OpenOverlayMsg}, an App global key — and the
 * App pushes it ({@see OverlayStack::push()} applies btop's menu size
 * rule). While it is on top the App paints the whole frame dimmed (btop's
 * `inactive_fg` + uncolor backdrop) and calls {@see paint()} with the full
 * {@see Surface}; when {@see capturesInput()} is true it receives every
 * key and mouse event ahead of every panel (only `ctrl+c` comes first).
 * Every other Msg (samples, ticks, resizes) keeps flowing to the panels.
 * With background_update on (btop's default) the dimmed backdrop stays
 * live; with it off — and always in tty mode — the App freezes the
 * backdrop as it was when the menu opened (btop pause_output) and only
 * re-captures it on a resize.
 *
 * Contract: immutable — {@see update()} returns the next state in an
 * {@see OverlayResult} (a null overlay closes it); no I/O in update() or
 * paint(): a signal or a renice runs inside the returned Cmd. Geometry is
 * recomputed from the {@see OverlayContext} on every call, so a resize
 * while the overlay is open just re-centres it.
 */
interface Overlay
{
    /** True while this overlay owns all keyboard and mouse input. */
    public function capturesInput(): bool;

    /**
     * The terminal size btop's Menu::process requires before showing this
     * menu ([cols, rows]); below it the App shows the size-error box
     * instead (btop resets every pending menu and sets SizeError).
     *
     * @return array{0: int, 1: int}
     */
    public function minSize(): array;

    public function update(Msg $msg, OverlayContext $context): OverlayResult;

    /** Paint over `$surface` (the whole frame, already dimmed). */
    public function paint(Surface $surface, OverlayContext $context): void;
}
