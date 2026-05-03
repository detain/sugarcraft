<?php

declare(strict_types=1);

namespace CandyCore\Stash;

/**
 * Which pane has focus. The Tab key cycles forward through them; the
 * status pane is the only one that takes line-level interaction
 * (stage / unstage), while branches and log are read-only view panes
 * at v1.
 */
enum Pane: string
{
    case Status   = 'status';
    case Branches = 'branches';
    case Log      = 'log';

    public function next(): self
    {
        return match ($this) {
            self::Status   => self::Branches,
            self::Branches => self::Log,
            self::Log      => self::Status,
        };
    }
}
