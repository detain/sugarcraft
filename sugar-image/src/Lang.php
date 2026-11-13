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
 *
 * Mirrors no upstream — SugarCraft house pattern. The per-library
 * translation facade follows the fleet's i18n convention (Lang::t lookup),
 * not any ported class.
 */
final class Lang extends BaseLang
{
    protected const NAMESPACE = 'image';
    protected const DIR = __DIR__ . '/../lang';
}
