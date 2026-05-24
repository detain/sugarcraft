<?php

declare(strict_types=1);

namespace SugarCraft\Serve;

use SugarCraft\Core\I18n\Lang as BaseLang;

/**
 * Per-library translation facade for candy-serve.
 *
 * Wraps the shared {@see \SugarCraft\Core\I18n\T} registry with the
 * `'serve'` namespace baked in. Translated strings live in
 * {@see ../lang/en.php}.
 *
 * @extends BaseLang
 */
final class Lang extends BaseLang
{
    protected const NAMESPACE = 'serve';
    protected const DIR = __DIR__ . '/../lang';
}
