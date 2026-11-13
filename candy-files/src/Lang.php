<?php

declare(strict_types=1);

namespace SugarCraft\Files;

use SugarCraft\Core\I18n\Lang as BaseLang;

/**
 * Per-library translation facade for candy-files.
 *
 * No upstream — house pattern (finding 051). The per-lib Lang facade is a
 * SugarCraft convention (canonical shape: sugar-pty/src/Lang.php).
 *
 * Wraps the shared {@see \SugarCraft\Core\I18n\T} registry with
 * the `'files'` namespace baked in. Translated strings live in
 * {@see ../lang/en.php}.
 *
 * @extends BaseLang
 */
final class Lang extends BaseLang
{
    protected const NAMESPACE = 'files';
    protected const DIR = __DIR__ . '/../lang';
}
