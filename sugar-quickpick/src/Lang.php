<?php

declare(strict_types=1);

namespace SugarCraft\QuickPick;

use SugarCraft\Core\I18n\Lang as BaseLang;

/**
 * Per-library translation facade for sugar-quickpick.
 *
 * Wraps the shared {@see \SugarCraft\Core\I18n\T} registry with the
 * `'quickpick'` namespace baked in. Translated strings live in
 * {@see ../lang/en.php}. Covers the rendered chrome of the picker
 * (S-15/-013): the overflow marker, the two empty states, the anchor
 * hint, and the filter caret/overflow indicator. Leading two-space
 * indents are part of the catalogue — chrome rows pad from column 0.
 *
 * @extends BaseLang
 */
final class Lang extends BaseLang
{
    protected const NAMESPACE = 'quickpick';
    protected const DIR = __DIR__ . '/../lang';
}
