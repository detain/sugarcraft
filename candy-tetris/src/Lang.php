<?php

declare(strict_types=1);

namespace SugarCraft\Tetris;

use SugarCraft\Core\I18n\Lang as BaseLang;

/**
 * Per-library translation facade for candy-tetris.
 *
 * Wraps the shared {@see \SugarCraft\Core\I18n\T} registry with the
 * `'tetris'` namespace baked in. Translated strings live in
 * {@see ../lang/en.php}; the binary calls `T::setLocale(T::detect())`
 * at startup so `$LANG`/`$LC_ALL` pick the catalogue. With no locale
 * set (or a missing key) every lookup falls back to `en` — byte-identical
 * to the literals these keys replaced, which is what keeps the render
 * goldens stable across the activation.
 *
 * @extends BaseLang
 */
final class Lang extends BaseLang
{
    protected const NAMESPACE = 'tetris';
    protected const DIR = __DIR__ . '/../lang';
}
