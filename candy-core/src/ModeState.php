<?php

declare(strict_types=1);

namespace CandyCore\Core;

/**
 * Reply states from a DECRPM (Mode Report) reply, returned in
 * {@see \CandyCore\Core\Msg\ModeReportMsg::$state}. Mirrors the values
 * defined in xterm's ctlseqs reference and ECMA-48.
 */
enum ModeState: int
{
    case NotRecognized    = 0;
    case Set              = 1;
    case Reset            = 2;
    case PermanentlySet   = 3;
    case PermanentlyReset = 4;

    /** True when the mode is currently active (set or permanently set). */
    public function isActive(): bool
    {
        return $this === self::Set || $this === self::PermanentlySet;
    }
}
