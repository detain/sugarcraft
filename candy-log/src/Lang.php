<?php

declare(strict_types=1);

namespace SugarCraft\Log;

use SugarCraft\Core\I18n\Lang as BaseLang;

/**
 * Per-library translation facade for candy-log.
 *
 * Wraps the shared {@see \SugarCraft\Core\I18n\T} registry with the
 * `'log'` namespace baked in. Translated strings live in
 * {@see ../lang/en.php}.
 *
 * @extends BaseLang
 */
final class Lang extends BaseLang
{
    protected const NAMESPACE = 'log';
    protected const DIR = __DIR__ . '/../lang';
}
