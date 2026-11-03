<?php

declare(strict_types=1);

namespace SugarCraft\Log;

use SugarCraft\Core\I18n\Lang as BaseLang;

/**
 * Per-library translation facade for sugar-log.
 *
 * Wraps the shared {@see \SugarCraft\Core\I18n\T} registry with the
 * `'log'` namespace baked in. Translated strings live in `lang/en.php`
 * (and its sibling locales) next to this file's package root.
 */
final class Lang extends BaseLang
{
    protected const NAMESPACE = 'log';
    protected const DIR = __DIR__ . '/../lang';
}
