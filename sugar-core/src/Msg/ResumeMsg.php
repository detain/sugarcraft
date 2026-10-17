<?php

declare(strict_types=1);

namespace SugarCraft\Core\Msg;

use SugarCraft\Core\Msg;

/**
 * Dispatched by the runtime after a {@see SuspendMsg} cycle resumes
 * (the user hit `fg` or the foreground job was restored). Models can
 * use this to re-emit any output that was clobbered by an external
 * editor / pager run mid-program.
 *
 * Mirrors Bubble Tea's `tea.ResumeMsg`.
 */
final class ResumeMsg implements Msg
{
}
