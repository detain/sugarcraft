<?php

declare(strict_types=1);

namespace SugarCraft\Image;

use SugarCraft\Core\I18n\Lang as BaseLang;

/**
 * Per-library translation facade for sugar-image.
 *
 * Wraps the shared {@see \SugarCraft\Core\I18n\T} registry with the
 * `'image'` namespace baked in. Translated strings live in `lang/en.php`
 * beside this file, keyed with the same `image.` prefix the lookups use.
 *
 * @extends BaseLang
 */
final class Lang extends BaseLang
{
    protected const NAMESPACE = 'image';
    protected const DIR = __DIR__ . '/../lang';
}
