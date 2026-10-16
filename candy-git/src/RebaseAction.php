<?php

declare(strict_types=1);

namespace SugarCraft\Git;

/**
 * Represents a single action in an interactive rebase todo list.
 */
enum RebaseAction: string
{
    case Pick   = 'pick';
    case Reword = 'reword';
    case Edit   = 'edit';
    case Squash = 'squash';
    case Drop   = 'drop';
}
