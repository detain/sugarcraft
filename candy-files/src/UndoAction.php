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
 * @see Mirrors charmbracelet/superfile/undo.Action
 */
final class UndoAction
{
    /**
     * @param list<array{path:string,isDir:bool,content:?string,stat:array}> $items Items that were deleted (for delete/mkdir)
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
     * @param list<array{path:string,isDir:bool,content:?string,stat:array}> $items
     */
    public static function delete(array $items): self
    {
        return new self(
            UndoActionType::Delete,
            sprintf('delete %d item(s)', count($items)),
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
            sprintf('rename %d item(s)', count($renames)),
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
            sprintf('move %d item(s)', count($moves)),
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
            sprintf('copy %d item(s)', count($copies)),
            $copies,
        );
    }

    /**
     * Create a mkdir action that can remove the created directory.
     *
     * @param list<string> $paths Directories that were created
     */
    public static function mkdir(array $paths): self
    {
        return new self(
            UndoActionType::Insert,
            sprintf('mkdir %d item(s)', count($paths)),
            array_map(fn(string $p) => ['path' => $p, 'isDir' => true], $paths),
        );
    }
}
