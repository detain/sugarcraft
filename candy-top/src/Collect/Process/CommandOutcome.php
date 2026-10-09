<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\Process;

/**
 * How one bounded child ended ({@see AsyncCommand}):
 *  - Ok: exited 0;
 *  - Failed: exited with another code (or was killed by a signal it did
 *    not get from us) — the program ran and said no;
 *  - Absent: nothing could be spawned (no such executable, proc_open
 *    disabled);
 *  - Timeout: still running at the deadline, SIGKILLed;
 *  - TooLarge: wrote more than its output cap, SIGKILLed (a runaway
 *    child must not grow candy-top's memory without bound).
 */
enum CommandOutcome
{
    case Ok;
    case Failed;
    case Absent;
    case Timeout;
    case TooLarge;
}
