<?php

declare(strict_types=1);

namespace SugarCraft\SshLauncher;

use SugarCraft\Core\I18n\Lang as BaseLang;

/**
 * Per-library translation facade for candy-ssh-launcher.
 *
 * Wraps the shared {@see \SugarCraft\Core\I18n\T} registry with the
 * `'sshlauncher'` namespace baked in. Translated strings live in
 * {@see ../lang/en.php}. The literal word `wishlist` inside several
 * messages is deliberate: it names the ON-DISK CONFIG FORMAT (the
 * wishlist.{yml,yaml,json} filename heritage predates the rename), not
 * the product.
 *
 * @extends BaseLang
 */
final class Lang extends BaseLang
{
    protected const NAMESPACE = 'sshlauncher';
    protected const DIR = __DIR__ . '/../lang';
}
