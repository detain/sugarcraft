<?php

declare(strict_types=1);

namespace SugarCraft\Core\Msg;

/**
 * Auto-repeat event — emitted while a key is held under the Kitty
 * progressive-keyboard protocol's `REPORT_EVENT_TYPES` flag (Kitty
 * event type 2). Mirrors charmbracelet/bubbletea.Key.IsRepeat.
 */
final class KeyRepeatMsg extends KeyMsg
{
}
