<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Session;

/**
 * Who set a session's name — the `title_source` column.
 *
 * `User` is a `/rename` (or any hand-typed title) and is never overwritten by
 * the auto-titler; `Auto` is the titler's own write
 * ({@see SessionStore::renameSessionIfUnnamed()}). NULL in the column means
 * the row has never been named, or was named before the column existed.
 * This is the storage half of the audit B2 fix: the auto-titler's UPDATE
 * refuses any row a user has named.
 */
enum TitleSource: string
{
    case User = 'user';
    case Auto = 'auto';

    /** Lenient read of the stored column: null for NULL or an unknown value. */
    public static function fromStored(mixed $value): ?self
    {
        return \is_string($value) ? self::tryFrom($value) : null;
    }
}
