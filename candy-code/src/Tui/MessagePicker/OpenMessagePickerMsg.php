<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\MessagePicker;

use SugarCraft\Code\App\Msg;

/**
 * Open the message picker over the session's conversation checkpoints
 * (plan_comparison C-TUI-3, GAP-UI-15 — Claude Code's Esc Esc picker).
 *
 * The rows are read from the session store by whoever holds it when the
 * message is answered, so the message itself carries no payload: it is the
 * door signal only. Sent by `Chat` over its Cmd channel (the idle Escape
 * two-beat) because the picker is SHELL state: it is held by
 * {@see \SugarCraft\Code\App\App}, which answers this message — the same
 * routing the settings overlay's {@see \SugarCraft\Code\Tui\Settings\OpenSettingsMsg}
 * uses.
 */
final readonly class OpenMessagePickerMsg implements Msg
{
}
