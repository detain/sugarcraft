<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Db;

/**
 * Runs one {@see DmlStatement} with transaction semantics (SQL-O03).
 *
 * BEGIN → prepared execute → verify the touched-row count → COMMIT, with
 * ROLLBACK on every other exit. The count check is the last line of defence
 * behind the primary-key addressing: a statement that touched a different
 * number of rows than it promised never commits. Works the same on SQLite,
 * MySQL and PostgreSQL (BEGIN/COMMIT/ROLLBACK are common spellings); on a
 * read-only connection or a read-only database the server's refusal surfaces
 * as the thrown exception, after a rollback that leaves the data untouched.
 */
final class DmlRunner
{
    /**
     * @return int rows the statement touched (committed)
     *
     * @throws \RangeException   the count differed from the promise; nothing was written
     * @throws \RuntimeException the connection cannot prepare, or the server refused the write
     */
    public static function run(DatabaseInterface $db, DmlStatement $s): int
    {
        $stmt = $db->prepare($s->sql);
        if ($stmt === null) {
            throw new \RuntimeException('this connection cannot execute prepared statements');
        }

        $db->exec('BEGIN');
        try {
            if (!$stmt->execute($s->params)) {
                throw new \RuntimeException('the server refused the statement');
            }
            $touched = $stmt->rowCount();
            $benign = $touched === $s->expect || ($s->allowZero && $touched === 0);
            if (!$benign) {
                throw new \RangeException((string) $touched);
            }
            $db->exec('COMMIT');

            return $touched;
        } catch (\Throwable $e) {
            try {
                $db->exec('ROLLBACK');
            } catch (\Throwable) {
                // The original failure is the useful one; a dead connection
                // has already discarded the open transaction.
            }
            throw $e;
        }
    }
}
