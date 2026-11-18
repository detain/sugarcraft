<?php

declare(strict_types=1);

namespace SugarCraft\Plugins;

use SugarCraft\Core\I18n\Lang as BaseLang;

/**
 * Translation base for a plugin's own catalog.
 *
 * A plugin owns its strings (`<plugin>/lang/`), so the host's catalog never
 * has to carry them. Each plugin subclasses this and sets two constants:
 * NAMESPACE, the first segment of its keys, and DIR, its `lang/` directory.
 * Lookup order is inherited from {@see \SugarCraft\Core\I18n\T}: exact locale,
 * then base language, then `en`, then the raw key.
 *
 * Mirrors the sugar-pty `Lang` facade. The plugin catalog is approved under
 * O4 (plan_plugin.md section 4.11).
 *
 * @extends BaseLang
 */
abstract class Lang extends BaseLang
{
}
