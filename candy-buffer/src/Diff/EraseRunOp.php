<?php

declare(strict_types=1);

namespace SugarCraft\Buffer\Diff;

/**
 * Erase N characters starting at the current cursor position
 * using ECH (Erase Character) sequence \x1b[N X.
 *
 * ECH erases characters without moving the cursor, replacing them
 * with the terminal's default background / erase character.
 *
 * @readonly
 */
final class EraseRunOp extends DiffOp
{
    public function __construct(
        public readonly int $count,
    ) {}
}
