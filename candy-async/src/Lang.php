<?php

declare(strict_types=1);

namespace SugarCraft\Async;

use SugarCraft\Core\I18n\Lang as BaseLang;

/**
 * Per-library translation facade for candy-async.
 *
 * Wraps the shared {@see \SugarCraft\Core\I18n\T} registry with the
 * 'async' namespace baked in. Translated strings live in
 * {@see ../lang/en.php}.
 *
 * @extends BaseLang
 */
final class Lang extends BaseLang
{
    protected const NAMESPACE = 'async';
    protected const DIR = __DIR__ . '/../lang';
}
