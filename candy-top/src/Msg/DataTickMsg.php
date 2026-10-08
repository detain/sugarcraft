<?php

declare(strict_types=1);

namespace SugarCraft\Top\Msg;

use SugarCraft\Core\Msg;

/**
 * The update_ms data tick. `$generation` lets the App ignore a tick armed
 * under a superseded period (after `+`/`-` re-arms) — candy-core ticks are
 * one-shot and cannot be cancelled, only outdated.
 */
final class DataTickMsg implements Msg
{
    public function __construct(
        public readonly int $generation,
    ) {
    }
}
