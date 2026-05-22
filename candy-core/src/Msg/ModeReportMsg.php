<?php

declare(strict_types=1);

namespace SugarCraft\Core\Msg;

use SugarCraft\Core\ModeState;
use SugarCraft\Core\Msg;

/**
 * Reply to a {@see \SugarCraft\Core\Cmd::requestMode()} (DECRQM). The
 * terminal answers `CSI [?] <mode> ; <state> $ y` — the `?` flag is
 * carried in {@see $private} (true for DEC private modes like 1006,
 * 2026, 2027; false for ANSI modes).
 */
final class ModeReportMsg implements Msg
{
    public function __construct(
        public readonly int $mode,
        public readonly bool $private,
        public readonly ModeState $state,
    ) {
    }
}
