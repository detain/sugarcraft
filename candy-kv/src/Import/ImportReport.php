<?php

declare(strict_types=1);

namespace SugarCraft\Kv\Import;

/**
 * Outcome of one committed import: what landed, what it replaced, what
 * `--if-exists=skip` left alone.
 *
 * Mirrors no upstream shape — candy-kv-051's demand was that an import that
 * quietly replaced live entries must be able to SAY how many; an int count
 * cannot carry that, so the importers answer with this triple. Every number
 * is exact: a row counted as overwritten was provably live the moment before
 * its write, inside the same transaction that committed it.
 */
final class ImportReport
{
    public function __construct(
        public readonly int $imported,
        public readonly int $overwritten,
        public readonly int $skipped,
    ) {
    }
}
