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
 * translate, plus the `aria-label` on {@see NavStack::viewHtml()}'s
 * `<nav>` element: the ONLY string assistive technology ever utters
 * from this package. Its default (`Breadcrumb`) is a plain API constant
 * in the $label parameter — localized by the host passing its own
 * string, never consulted from this registry. The facade and lang file
 * are therefore DORMANT on purpose:
 * the library's own render paths NEVER consult the registry — Breadcrumb
 * starts from its hardcoded defaults. A host that wants localized glyphs
 * reads them itself (`Lang::t('separator')`) and feeds the result to the
 * setters. That is the entire localization story today; nothing else in
 * this package is translatable.
 *
 * @extends BaseLang
 */
final class Lang extends BaseLang
{
    protected const NAMESPACE = 'navstack';
    protected const DIR = __DIR__ . '/../lang';
}
