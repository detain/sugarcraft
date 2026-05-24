<?php

declare(strict_types=1);

namespace SugarCraft\Bounce;

use SugarCraft\Core\I18n\Lang as BaseLang;

/**
 * Per-library translation facade for honey-bounce.
 *
 * Wraps the shared {@see \SugarCraft\Core\I18n\T} registry with the
 * `'bounce'` namespace baked in. Translated strings live in
 * {@see ../lang/en.php}.
 *
 * @extends BaseLang
 */
final class Lang extends BaseLang
{
    protected const NAMESPACE = 'bounce';
    protected const DIR = __DIR__ . '/../lang';
}
