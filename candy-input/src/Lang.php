<?php

declare(strict_types=1);

namespace SugarCraft\Input;

use SugarCraft\Core\I18n\Lang as BaseLang;

/**
 * Per-library translation facade for candy-input.
 *
 * Wraps the shared {@see \SugarCraft\Core\I18n\T} registry with the
 * 'input' namespace baked in. Translated strings live in
 * {@see ../lang/en.php}.
 *
 * @extends BaseLang
 */
final class Lang extends BaseLang
{
    private const NAMESPACE = 'input';
    private const DIR = __DIR__ . '/../lang';
}
