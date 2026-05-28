<?php

declare(strict_types=1);

namespace SugarCraft\Mouse;

use SugarCraft\Core\I18n\Lang as BaseLang;

/**
 * Per-library translation facade for candy-mouse.
 *
 * Wraps the shared {@see \SugarCraft\Core\I18n\T} registry with the
 * 'mouse' namespace baked in. Translated strings live in
 * {@see ../lang/en.php}.
 *
 * @extends BaseLang
 */
final class Lang extends BaseLang
{
    private const NAMESPACE = 'mouse';
    private const DIR = __DIR__ . '/../lang';
}
