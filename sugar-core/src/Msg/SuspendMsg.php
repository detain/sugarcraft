<?php

declare(strict_types=1);

namespace SugarCraft\Core\Msg;

use SugarCraft\Core\Msg;

/**
 * Sentinel that asks the runtime to suspend the program (Ctrl-Z /
 * SIGTSTP semantics). The runtime tears down terminal state, re-raises
 * SIGTSTP on the process group with the default handler, and on
 * SIGCONT re-arms its handlers, restores the alt screen / raw mode,
 * and dispatches a {@see ResumeMsg} to the model.
 *
 * Mirrors Bubble Tea's `tea.SuspendMsg`. Models typically don't
 * inspect this directly — they emit `Cmd::suspend()` to request a
 * suspend, and react to `ResumeMsg` to refresh state on return.
 */
final class SuspendMsg implements Msg
{
}
