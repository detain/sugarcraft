<?php

declare(strict_types=1);

namespace SugarCraft\Flappy;

use SugarCraft\Core\I18n\Lang as BaseLang;

/**
 * Per-library translation facade for candy-flappy.
 *
 * Wraps the shared {@see \SugarCraft\Core\I18n\T} registry with the
 * `'flappy'` namespace baked in. Translated strings live in this
 * package's `lang/` directory, with `lang/en.php` the source of truth.
 *
 * @extends BaseLang
 */
final class Lang extends BaseLang
{
    protected const NAMESPACE = 'flappy';
    protected const DIR = __DIR__ . '/../lang';
}
