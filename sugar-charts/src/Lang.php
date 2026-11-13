<?php

declare(strict_types=1);

namespace SugarCraft\Charts;

use SugarCraft\Core\I18n\Lang as BaseLang;

/**
 * Per-library translation facade for sugar-charts.
 *
 * Wraps the shared {@see \SugarCraft\Core\I18n\T} registry with the
 * `'charts'` namespace baked in.
 *
 * Convention (sugar-charts-061): user-visible domain refusals
 * (InvalidArgumentException/RuntimeException raised for bad input) carry
 * `Lang::t(...)` messages so they translate; pure programming errors
 * (TypeErrors from signature violations, internal invariant belts such as
 * Graph's LogicException path) stay raw — they are never shown to end
 * users and must not gain a locale round-trip. Translated strings live in
 * {@see ../lang/en.php} (and any sibling `<locale>.php` files).
 *
 * @extends BaseLang
 */
final class Lang extends BaseLang
{
    protected const NAMESPACE = 'charts';
    protected const DIR = __DIR__ . '/../lang';
}
