<?php

declare(strict_types=1);

namespace SugarCraft\Charts;

use SugarCraft\Core\I18n\Lang as BaseLang;

/**
 * Per-library translation facade for sugar-charts.
 *
 * Wraps the shared {@see \SugarCraft\Core\I18n\T} registry with the
 * `'charts'` namespace baked in. Translated strings live in
 * {@see ../lang/en.php} (and any sibling `<locale>.php` files).
 *
 * @extends BaseLang
 */
final class Lang extends BaseLang
{
    protected const NAMESPACE = 'charts';
    protected const DIR = __DIR__ . '/../lang';
}
