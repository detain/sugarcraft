<?php

declare(strict_types=1);

namespace SugarCraft\Core\Msg;

use SugarCraft\Core\Msg;

/**
 * Sentinel that asks the runtime to quit cleanly with a SIGINT-style
 * exit. Programs can dispatch this to abort with the same teardown
 * path that Ctrl-C triggers, distinguishing "user-requested abort"
 * from a graceful {@see QuitMsg} for downstream tooling.
 *
 * Mirrors Bubble Tea's `tea.InterruptMsg`.
 */
final class InterruptMsg implements Msg
{
}
