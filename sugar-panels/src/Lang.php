<?php

declare(strict_types=1);

namespace SugarCraft\Panels;

use SugarCraft\Core\I18n\Lang as BaseLang;

/**
 * Per-library translation facade for sugar-panels.
 *
 * Wraps the shared {@see \SugarCraft\Core\I18n\T} registry with the
 * `'panels'` namespace baked in. Translated strings live in
 * {@see ../lang/en.php}.
 *
 * Currently DORMANT: the render path emits no user-facing strings, so there
 * are zero production `Lang::t()` callers. The facade and the `align.*` /
 * `valign.*` keys are pre-wired for a future label surface; lookup follows
 * the house chain exact locale → base locale → `en` → raw key.
 *
 * @extends BaseLang
 */
final class Lang extends BaseLang
{
    protected const NAMESPACE = 'panels';
    protected const DIR = __DIR__ . '/../lang';
}
