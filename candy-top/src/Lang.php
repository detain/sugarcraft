<?php

declare(strict_types=1);

namespace SugarCraft\Top;

use SugarCraft\Core\I18n\Lang as BaseLang;

/**
 * Per-library translation facade for candy-top.
 *
 * Wraps the shared {@see \SugarCraft\Core\I18n\T} registry with the `'top'`
 * namespace baked in. Translated strings live in {@see ../lang/en.php}; the
 * config-file option descriptions are looked up here too, so a localized
 * build writes its `config.conf` comments in the user's language.
 *
 * @extends BaseLang
 */
final class Lang extends BaseLang
{
    protected const NAMESPACE = 'top';
    protected const DIR = __DIR__ . '/../lang';
}
