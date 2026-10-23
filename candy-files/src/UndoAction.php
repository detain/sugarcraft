<?php

declare(strict_types=1);

namespace SugarCraft\Files;

use SugarCraft\Core\Undo\UndoActionType;

/**
 * Represents a reversible operation in the file manager.
 *
 * Each action stores enough information to reverse itself.
 * The undo system maintains stacks of these actions.
 *
 * @see Mirrors yorukot/superfile/undo.Action
 */
final class UndoAction
{
    /**
     * @param list<array{path:string,isDir:bool,trash:string}>|array<string,string> $items Items the action operates on
     *        (delete: the list shape — path, isDir, and the trash location the
     *        bytes were moved to; '' when the cross-device fallback removed
     *        them permanently. move/rename/copy: a map of old/source path →
     *        new/destination path.)
     * @param array<string,string> $renames Map of old path => new path (for rename)
     * @param array<string,string> $moves Map of original path => new path (for move)
     * @param array<string,string> $copies Map of source => destination (for copy)
     */
    private function __construct(
        public readonly UndoActionType $type,
        public readonly string $description,
        public readonly array $items,
    ) {
    }

    /**
     * Create a delete action that can restore deleted items.
     *
     * @param list<array{path:string,isDir:bool,trash:string}> $items
     */
    public static function delete(array $items): self
    {
        return new self(
            UndoActionType::Delete,
            Lang::t('undo.delete', ['count' => count($items)]),
            $items,
        );
    }

    /**
     * Create a rename action that can reverse the rename.
     *
     * @param array<string,string> $renames Map of old path => new path
     */
    public static function rename(array $renames): self
    {
        return new self(
            UndoActionType::Rename,
            Lang::t('undo.rename', ['count' => count($renames)]),
            $renames,
        );
    }

    /**
     * Create a move action that can reverse the move.
     *
     * @param array<string,string> $moves Map of original path => new path
     */
    public static function move(array $moves): self
    {
        return new self(
            UndoActionType::Move,
            Lang::t('undo.move', ['count' => count($moves)]),
            $moves,
        );
    }

    /**
     * Create a copy action (cannot truly be undone, but tracked for history).
     *
     * @param array<string,string> $copies Map of source => destination
     */
    public static function copy(array $copies): self
    {
        return new self(
            UndoActionType::Copy,
            Lang::t('undo.copy', ['count' => count($copies)]),
            $copies,
        );
    }
}
