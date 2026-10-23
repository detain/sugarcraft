<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile;

use SugarCraft\Core\I18n\Lang as BaseLang;

/**
 * Per-library translation facade for sugar-colorprofile.
 *
 * Wraps the shared {@see \SugarCraft\Core\I18n\T} registry with the
 * `'colorprofile'` namespace baked in. Translated strings live in
 * {@see ../lang/en.php}.
 */
final class Lang extends BaseLang
{
    protected const NAMESPACE = 'colorprofile';
    protected const DIR = __DIR__ . '/../lang';
}
