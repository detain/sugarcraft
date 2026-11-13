<?php

declare(strict_types=1);

namespace SugarCraft\Mouse;

use SugarCraft\Core\I18n\Lang as BaseLang;

/**
 * Per-library translation facade for sugar-mouse.
 *
 * Adds the 'mouse' namespace to the shared {@see \SugarCraft\Core\I18n\T}
 * registry so callers pass bare keys. Translated strings live in
 * {@see ../lang/en.php}.
 *
 * @extends BaseLang
 */
final class Lang extends BaseLang
{
    protected const NAMESPACE = 'mouse';
    protected const DIR = __DIR__ . '/../lang';
}
