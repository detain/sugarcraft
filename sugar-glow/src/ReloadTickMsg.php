<?php

declare(strict_types=1);

namespace SugarCraft\Glow;

use SugarCraft\Core\Msg;

/**
 * Timer tick that drives the pager's file-watch pump.
 *
 * E735 step 10.25: armed by {@see GlowModel::init()} /
 * {@see GlowModel::update()} via `Cmd::tick()` — the same one-shot-re-arm
 * chain sugar-bits' Stopwatch runs (TickMsg + id routing). The message
 * carries no payload; the sweep decision lives in the Model so tests can
 * drive it by dispatching the Msg directly, with no wall-clock in play.
 *
 * Mirrors charmbracelet/glow's pager file-watcher reload event.
 */
final class ReloadTickMsg implements Msg
{
    /** Routing id shared by the arming closure and the Model handler. */
    public const ID = 'glow.reload';

    public function __construct(public readonly string $id = self::ID)
    {
    }
}
