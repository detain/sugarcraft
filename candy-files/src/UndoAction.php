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
 * CF-40 (audit 064) memory-weight law: the pair maps this class used to
 * hold for move/copy stored BOTH absolute paths per leg. A 170k-file
 * copy pinned a multi-MB map per action and UNDO_LIMIT 50 could keep 50
 * of them — hundreds of MB worst case, forever. Move and copy now store
 * one compact leg record — {srcDir, dstDir, names, dstNames} — because
 * both circuits are homogeneous: operationSources() answers a single
 * source directory and the destination is a single directory (flat legs
 * share the name; the extract circuit carries its staged spelling in
 * $dstNames). The absolute pair map is re-derived on demand by
 * {@see self::absolutePairs()} while a reversal actually runs, so the
 * 2×N-absolute-path shape exists only transiently, never on a stack.
 *
 * Weight is bounded, not just folded: {@see self::enforceLegCap()}
 * keeps the total legs across both stacks under a ceiling by evicting
 * the OLDEST legs inside the OLDEST compressible action (newest first
 * retained). This is the weight cap; the Manager's UNDO_LIMIT is the
 * action-count/trash quota from CF-11's lineage — the two never fight
 * because the leg cap only trims legs the action-count slice would not
 * have touched yet, oldest-first in both. Delete and insert records are
 * never trimmed: a delete record is the ONLY locator of trashed bytes
 * (035's law — silently dropping one strands real content) and a bulk
 * delete cannot be honest as a partial undo. When such records alone
 * exceed the ceiling the excess is accepted: undoability of bytes beats
 * the weight bound. That rebase is disclosed here, in-codec, per the
 * finding's own instruction.
 *
 * No upstream — house pattern. Finding 106 relabel: an earlier line
 * claimed "Mirrors yorukot/superfile/undo.Action"; superfile has no
 * undo.Action symbol — this reversible-operation record is the library's
 * own (its own undo stack lives in {@see Manager}).
 */
final class UndoAction
{
    /**
     * @param list<array{path:string,isDir:bool,trash:string}>|array<string,string>|list<string>|array{srcDir:string,dstDir:string,names:list<string>,dstNames:?list<string>} $items
     *        Items the action operates on.
     *        (delete: the list shape — path, isDir, and the trash location the
     *        bytes were moved to; '' when the cross-device fallback removed
     *        them permanently. move/copy: the compact leg record — see the
     *        class docblock. rename: a map of old path → new path (always
     *        one pair, never compressed). insert: a list of created
     *        directory paths.)
     * @param int $appliedLegs Legs recorded at construction (the honest
     *        "what was applied" count, immutable under {@see self::trimTo()};
     *        equals legCount() until a weight-cap trim hits).
     */
    private function __construct(
        public readonly UndoActionType $type,
        public readonly string $description,
        public readonly array $items,
        public readonly int $appliedLegs = 0,
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
            count($items),
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
            count($renames),
        );
    }

    /**
     * Create a move action from the compact leg record.
     *
     * CF-40: takes the shared source directory, the shared destination
     * directory, and the moved base names (schedule order preserved)
     * instead of an original-path => new-path map; {@see self::absolutePairs()}
     * re-derives the map byte-identically for the reversers.
     *
     * @param list<string> $names Moved entry names, relative to $srcDir
     */
    public static function move(string $srcDir, string $dstDir, array $names): self
    {
        $names = array_values($names);
        return new self(
            UndoActionType::Move,
            Lang::t('undo.move', ['count' => count($names)]),
            ['srcDir' => $srcDir, 'dstDir' => $dstDir, 'names' => $names, 'dstNames' => null],
            count($names),
        );
    }

    /**
     * Create a copy action from the compact leg record.
     *
     * Copy undo is informational only (the original bytes stay put), but
     * the record still bounded nothing before CF-40 — it held the whole
     * ok-map of a completed batch, which for a bulk copy is exactly the
     * weight the finding measured. The leg record keeps the count
     * honest and the provenance cheap.
     *
     * @param list<string> $names Copied leg names, relative to $srcDir
     * @param ?list<string> $dstNames Destination basenames when they differ
     *        from the source spellings (the archive-extract circuit stages
     *        members under their archive-relative path and lands them under
     *        the flat name); null means the legs are flat.
     */
    public static function copy(string $srcDir, string $dstDir, array $names, ?array $dstNames = null): self
    {
        $names = array_values($names);
        return new self(
            UndoActionType::Copy,
            Lang::t('undo.copy', ['count' => count($names)]),
            ['srcDir' => $srcDir, 'dstDir' => $dstDir, 'names' => $names, 'dstNames' => $dstNames === null ? null : array_values($dstNames)],
            count($names),
        );
    }

    /**
     * Create an insert action for directories the user created (CF-10).
     *
     * Undo removes the created paths — but only while they are still empty
     * (reverseInsert), so anything the user put inside is never eaten by an
     * undo keystroke. Redo recreates the (empty) directories.
     *
     * @param list<string> $paths Created directory paths, in creation order
     */
    public static function insert(array $paths): self
    {
        return new self(
            UndoActionType::Insert,
            Lang::t('undo.create', ['count' => count($paths)]),
            $paths,
            count($paths),
        );
    }

    /**
     * Whether this record uses the CF-40 compact leg encoding (and is
     * therefore safe for the weight cap to trim from its oldest end).
     */
    public function isCompressible(): bool
    {
        return $this->type === UndoActionType::Move || $this->type === UndoActionType::Copy;
    }

    /** Number of file legs this record remembers (weight-cap currency). */
    public function legCount(): int
    {
        return $this->isCompressible() ? count($this->items['names']) : $this->appliedLegs;
    }

    /**
     * Re-derive the absolute source => destination map for a compressible
     * record, byte-identical to what the construction loop joined per leg
     * (Pane::join is the same function at both ends, and the names carry
     * the original schedule order).
     *
     * @return array<string,string>
     */
    public function absolutePairs(): array
    {
        if (!$this->isCompressible()) {
            return $this->items;
        }
        $pairs = [];
        $dstNames = $this->items['dstNames'];
        foreach ($this->items['names'] as $i => $name) {
            $dstName = $dstNames === null ? $name : $dstNames[$i];
            $pairs[Pane::join($this->items['srcDir'], $name)] = Pane::join($this->items['dstDir'], $dstName);
        }
        return $pairs;
    }

    /**
     * Return a copy of this record retaining only its NEWEST $keep legs —
     * the weight cap's eviction primitive. The description is rebuilt so
     * the count it names is the count the reversal will actually perform,
     * with an explicit disclosure of what the cap already forgot
     * (067's polarity: no stack may advertise a reversibility it lacks).
     */
    public function trimTo(int $keep): self
    {
        if (!$this->isCompressible() || $keep < 1 || $keep >= $this->legCount()) {
            return $this;
        }
        $names = array_slice($this->items['names'], -$keep);
        $dstNames = $this->items['dstNames'] === null ? null : array_slice($this->items['dstNames'], -$keep);
        $base = $this->type === UndoActionType::Move
            ? Lang::t('undo.move', ['count' => $keep])
            : Lang::t('undo.copy', ['count' => $keep]);
        return new self(
            $this->type,
            $base . Lang::t('undo.capped', ['kept' => $keep, 'applied' => $this->appliedLegs]),
            ['srcDir' => $this->items['srcDir'], 'dstDir' => $this->items['dstDir'], 'names' => $names, 'dstNames' => $dstNames],
            $this->appliedLegs,
        );
    }

    /**
     * Bound the total remembered legs across both undo stacks.
     *
     * Pure function — the Manager's six stack-mutation sites all route
     * through it, and the pinning test calls it directly. Eviction order
     * is oldest action first (undo stack bottom, then redo stack bottom),
     * oldest LEGS first within it, never below one leg per compressible
     * record and never into delete/insert/rename territory (see the class
     * docblock for why the locator records are untouchable).
     *
     * @param list<UndoAction> $undo
     * @param list<UndoAction> $redo
     * @return array{0:list<UndoAction>,1:list<UndoAction>}
     */
    public static function enforceLegCap(array $undo, array $redo, int $limit): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('leg cap must be >= 1, got ' . $limit);
        }
        $legs = static function (array $stack): int {
            $n = 0;
            foreach ($stack as $action) {
                $n += $action->legCount();
            }
            return $n;
        };
        $deficit = $legs($undo) + $legs($redo) - $limit;
        if ($deficit <= 0) {
            return [$undo, $redo];
        }
        $trim = static function (array $stack) use (&$deficit): array {
            foreach ($stack as $i => $action) {
                if ($deficit <= 0) {
                    break;
                }
                if (!$action->isCompressible()) {
                    continue;
                }
                $at = $action->legCount();
                if ($at <= 1) {
                    continue;
                }
                $drop = min($at - 1, $deficit);
                $stack[$i] = $action->trimTo($at - $drop);
                $deficit -= $drop;
            }
            return $stack;
        };
        $undo = $trim($undo);
        $redo = $trim($redo);
        return [$undo, $redo];
    }
}
