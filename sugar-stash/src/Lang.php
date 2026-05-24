<?php

declare(strict_types=1);

namespace SugarCraft\Stash;

use SugarCraft\Core\I18n\Lang as BaseLang;

/**
 * Per-library translation facade for sugar-stash.
 *
 * Wraps the shared {@see \SugarCraft\Core\I18n\T} registry with the
 * `'stash'` namespace baked in. Translated strings live in
 * {@see ../lang/en.php}.
 *
 * @extends BaseLang
 */
final class Lang extends BaseLang
{
    protected const NAMESPACE = 'stash';
    protected const DIR = __DIR__ . '/../lang';
}
