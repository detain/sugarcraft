<?php

declare(strict_types=1);

namespace SugarCraft\Files\Undo;

use SugarCraft\Core\Undo\UndoActionType;
use SugarCraft\Files\UndoAction;

/**
 * Factory for creating UndoAction instances with proper UndoActionType.
 *
 * @see Mirrors charmbracelet/superfile/undo.ActionFactory
 */
final class UndoActionFactory
{
    /**
     * Create a delete action that can restore deleted items.
     *
     * @param list<array{path:string,isDir:bool,content:?string,stat:array}> $items
     */
    public static function delete(array $items): UndoAction
    {
        return UndoAction::delete($items);
    }

    /**
     * Create a rename action that can reverse the rename.
     *
     * @param array<string,string> $renames Map of old path => new path
     */
    public static function rename(array $renames): UndoAction
    {
        return UndoAction::rename($renames);
    }

    /**
     * Create a move action that can reverse the move.
     *
     * @param array<string,string> $moves Map of original path => new path
     */
    public static function move(array $moves): UndoAction
    {
        return UndoAction::move($moves);
    }

    /**
     * Create a copy action (cannot truly be undone, but tracked for history).
     *
     * @param array<string,string> $copies Map of source => destination
     */
    public static function copy(array $copies): UndoAction
    {
        return UndoAction::copy($copies);
    }

    /**
     * Create a mkdir action that can remove the created directory.
     *
     * @param list<string> $paths Directories that were created
     */
    public static function mkdir(array $paths): UndoAction
    {
        return UndoAction::mkdir($paths);
    }
}
