<?php

declare(strict_types=1);

namespace SugarCraft\NavStack;

use SugarCraft\Core\I18n\Lang as BaseLang;

/**
 * Per-library translation facade for sugar-navstack.
 *
 * Wraps the shared {@see \SugarCraft\Core\I18n\T} registry with the
 * `'navstack'` namespace baked in. Translated strings live in
 * {@see ../lang/en.php}.
 *
 * SCOPE: this library currently renders no translatable PROSE — its
 * only user-visible strings are the breadcrumb separator and the
 * truncation ellipsis, which are punctuation glyphs chosen per
 * {@see Breadcrumb::setSeparator()}/{@see setTruncator()}, not text to
 * translate. The facade and lang file are therefore DORMANT on purpose:
 * they exist so a host app can localize those glyphs through the shared
 * registry without touching this library's API.
 *
 * @extends BaseLang
 */
final class Lang extends BaseLang
{
    protected const NAMESPACE = 'navstack';
    protected const DIR = __DIR__ . '/../lang';
}
