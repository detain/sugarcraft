<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\Settings;

use SugarCraft\Crush\App\Msg;

/**
 * The settings-save toast's time is up (roadmap N-P3).
 *
 * Delivered by the tick {@see \SugarCraft\Crush\Chat::applySettings()} returns
 * with the toast, and answered by {@see \SugarCraft\Crush\Chat::update()}: the
 * toast goes only when `$generation` is still the one on screen, so the tick of
 * an earlier save can never cut a later save's toast short.
 */
final readonly class SettingsToastExpiredMsg implements Msg
{
    public function __construct(public int $generation)
    {
    }
}
