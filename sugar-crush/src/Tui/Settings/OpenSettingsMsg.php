<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\Settings;

use SugarCraft\Crush\App\Msg;

/**
 * Open the full-band settings view (N-P1), optionally with its search box
 * pre-filled — `/settings compaction` arrives as `query: 'compaction'`.
 *
 * Sent by `Chat` over its Cmd channel (the `/settings` and `/config` handler,
 * the palette's "View settings" row) because the editor is SHELL state: it is
 * held by {@see \SugarCraft\Crush\App\App}, which answers this message.
 */
final readonly class OpenSettingsMsg implements Msg
{
    public function __construct(public string $query = '')
    {
    }
}
