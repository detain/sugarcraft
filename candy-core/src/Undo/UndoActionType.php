<?php

declare(strict_types=1);

namespace SugarCraft\Core\Undo;

/**
 * Types of undoable actions.
 */
enum UndoActionType: string
{
    case Delete = 'delete';
    case Move = 'move';
    case Rename = 'rename';
    case Copy = 'copy';
    case Insert = 'insert';
    case Modify = 'modify';
    case Custom = 'custom';
}
