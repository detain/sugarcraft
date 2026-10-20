<?php

declare(strict_types=1);

namespace SugarCraft\CandyImage;

use SugarCraft\Core\I18n\Lang as BaseLang;

/**
 * Per-package translation facade for candy-image.
 *
 * Wraps the shared {@see \SugarCraft\Core\I18n\T} registry with the
 * `'image'` namespace baked in. CLI-facing strings live in
 * {@see ../lang/en.php} (the source locale; further locales follow the
 * repo's add-locale flow).
 *
 * @extends BaseLang
 */
final class Lang extends BaseLang
{
    protected const NAMESPACE = 'image';
    protected const DIR = __DIR__ . '/../lang';
}
