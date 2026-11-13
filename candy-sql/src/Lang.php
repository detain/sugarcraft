<?php

declare(strict_types=1);

namespace SugarCraft\Sql;

use SugarCraft\Core\I18n\Lang as BaseLang;

/**
 * Per-library translation facade for candy-sql.
 *
 * Wraps the shared {@see \SugarCraft\Core\I18n\T} registry with the
 * `'sql'` namespace baked in (matching NAMESPACE below — the old docblock
 * named a namespace this class never registered). Translated strings live in
 * {@see ../lang/en.php}.
 *
 */
final class Lang extends BaseLang
{
    protected const NAMESPACE = 'sql';
    protected const DIR = __DIR__ . '/../lang';
}
