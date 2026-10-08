<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * The two process-changing syscalls btop's proc menus make — `kill(2)` and
 * `setpriority(2)` — behind a seam, so the signal/renice menus can be
 * exercised without touching real processes and `--fake` mode (whose pids
 * are invented) never signals a live one.
 *
 * Both return 0 on success or the errno (EPERM, ESRCH, EINVAL, ...). They
 * are only ever called from inside a Cmd.
 */
interface ProcessControl
{
    /** btop `kill(pid, signal)` (Menu::signalChoose / signalSend). */
    public function signal(int $pid, int $signal): int;

    /** btop `Proc::set_priority(pid, nice)` (Menu::reniceMenu). */
    public function renice(int $pid, int $nice): int;
}
