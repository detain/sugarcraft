<?php

declare(strict_types=1);

namespace SugarCraft\Animation;

use SugarCraft\Core\I18n\Lang as BaseLang;

/**
 * Per-library translation facade for sugar-animation.
 *
 * Wraps the shared {@see \SugarCraft\Core\I18n\T} registry with the
 * `'animation'` namespace baked in. Translated strings live in
 * {@see ../lang/en.php}.
 *
 * @extends BaseLang
 */
final class Lang extends BaseLang
{
    protected const NAMESPACE = 'animation';
    protected const DIR = __DIR__ . '/../lang';
}
