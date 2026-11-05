<?php

declare(strict_types=1);

namespace SugarCraft\Git;

/**
 * Which pane has focus. The Tab key cycles forward through them. Every
 * pane carries the row cursor and viewport movement; the status pane is
 * the one that takes file-line actions (stage/unstage/discard/diff) and
 * the branches pane the one that takes branch actions (Space checkout,
 * D delete, w worktrees overlay), while the log pane is navigation only.
 * Repo-wide verbs — commit, amend, new branch, merge, cherry-pick,
 * rebase, undo/redo — dispatch regardless of which pane holds focus.
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
