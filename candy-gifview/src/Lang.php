<?php

declare(strict_types=1);

namespace SugarCraft\GifView;

use SugarCraft\Core\I18n\Lang as BaseLang;

/**
 * Per-library translation facade for candy-gifview.
 *
 * Wraps the shared {@see \SugarCraft\Core\I18n\T} registry with the
 * `'gifview'` namespace baked in. Translated strings live in
 * {@see ../lang/en.php}; the CLI-facing `cli.*` keys moved here from
 * sugar-animation when the viewer split out (rename campaign step 1.3).
 *
 * @extends BaseLang
 */
final class Lang extends BaseLang
{
    protected const NAMESPACE = 'gifview';
    protected const DIR = __DIR__ . '/../lang';
}
