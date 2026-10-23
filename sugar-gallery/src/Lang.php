<?php

declare(strict_types=1);

namespace SugarCraft\Gallery;

use SugarCraft\Core\I18n\Lang as BaseLang;

/**
 * Per-library translation facade for sugar-gallery.
 *
 * Wraps the shared {@see \SugarCraft\Core\I18n\T} registry with the
 * `'gallery'` namespace baked in. Translated strings live in
 * {@see ../lang/en.php} (source of truth) plus the 15 non-English locales;
 * sugar-gallery is an original component, so these entry points are the
 * lib's own — they are not inherited from any Go project (the old
 * "mirrors charmbracelet/gum" line in lang/en.php was false; docs-review-1023
 * M3 retired it).
 *
 * @extends BaseLang
 */
final class Lang extends BaseLang
{
    protected const NAMESPACE = 'gallery';
    protected const DIR = __DIR__ . '/../lang';
}
