<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Msg;

use SugarCraft\Core\Msg;

/**
 * Deferred apply of a pending terminal resize (finding #52).
 *
 * A `WindowSizeMsg` (SIGWINCH) is not one event but a burst: dragging a terminal
 * mouse-resize reflows per character cell, so a single gesture delivers dozens of
 * sizes in a few hundred milliseconds. Rebuilding the decoder where each one lands
 * means dozens of `ffmpeg` spawn/seek/kill cycles — the most expensive thing the
 * player does — to draw a frame the user never sees, and every one of those
 * spawns happens synchronously inside `update()`, stalling the whole pipeline.
 *
 * So `update()` records the geometry and returns a short one-shot timer instead of
 * spawning anything; when the timer fires it dispatches THIS message, and the model
 * applies whatever size is pending at that moment — once per burst, at the final
 * geometry. Carries no payload deliberately: the pending geometry lives in the
 * model, which is what makes a superseded timer a harmless no-op (a resize that
 * already landed leaves nothing pending, and applying nothing is idempotent).
 *
 * Stateless, so a single shared instance is dispatched on every timer.
 *
 * Mirrors charmbracelet/bubbletea's `tea.WindowSizeMsg` handling in lipgloss-based
 * programs, which debounce re-layout rather than re-layout per signal.
 */
final class ResizeRebuildMsg implements Msg
{
    /** Singleton instance to avoid allocation per debounce window. */
    private static ?ResizeRebuildMsg $instance = null;

    /**
     * Return the singleton ResizeRebuildMsg instance.
     *
     * The message is a pure nudge — the model owns the pending geometry — so all
     * debounced resizes can share one immutable instance.
     */
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }
}
