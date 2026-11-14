<?php

declare(strict_types=1);

namespace SugarCraft\CandyImage;

use SugarCraft\Core\I18n\Lang as BaseLang;

/**
 * Per-package translation facade for candy-image.
 *
 * Wraps the shared {@see \SugarCraft\Core\I18n\T} registry with the
 * `'candy-image'` namespace baked in — unique to this package, because
 * `T::register()` is first-wins and the hard-required sibling sugar-image
 * bakes `'image'` itself (sugar-image/src/Lang.php); sharing a namespace
 * would silently degrade the loser's every string to `image.<key>`
 * literals in any process that resolves both. CLI-facing strings live in
 * {@see ../lang/en.php} (the source locale; further locales follow the
 * repo's add-locale flow).
 *
 * @extends BaseLang
 */
final class Lang extends BaseLang
{
    protected const NAMESPACE = 'candy-image';
    protected const DIR = __DIR__ . '/../lang';
}
