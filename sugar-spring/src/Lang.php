<?php

declare(strict_types=1);

namespace SugarCraft\Spring;

use SugarCraft\Core\I18n\Lang as BaseLang;

/**
 * Per-library translation facade for sugar-spring.
 *
 * Wraps the shared {@see \SugarCraft\Core\I18n\T} registry with the
 * `'spring'` namespace baked in. Translated strings live under the
 * sibling `lang/` directory — all sixteen locale files are equally home
 * there (`lang/en.php` is the canonical English source, not a fallback
 * dumping ground: the runtime lookup walks exact locale, then base,
 * then `en`, then the raw key).
 *
 * @extends BaseLang
 */
final class Lang extends BaseLang
{
    protected const NAMESPACE = 'spring';
    protected const DIR = __DIR__ . '/../lang';
}
