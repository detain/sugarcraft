<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Spinner;

use SugarCraft\Core\Msg;

/**
 * Animation tick for a specific spinner instance, identified by {@see $id}.
 * Each Spinner ignores ticks for other ids so multiple spinners can share
 * an event loop without cross-talk.
 *
 * Mirrors charmbracelet/bubbles spinner.TickMsg.
 */
final class TickMsg implements Msg
{
    public function __construct(public readonly int $id) {}
}
