<?php

declare(strict_types=1);

namespace App;

use SugarCraft\Core\I18n\Lang as BaseLang;

/**
 * Per-app translation facade for candy-starter.
 *
 * Wraps the shared {@see \SugarCraft\Core\I18n\T} registry with the
 * `'starter'` namespace baked in. Translated strings live in
 * {@see ../lang/en.php}; copy this class when you fork the template and
 * change NAMESPACE to your app's name.
 */
final class Lang extends BaseLang
{
    protected const NAMESPACE = 'starter';
    protected const DIR = __DIR__ . '/../lang';
}
