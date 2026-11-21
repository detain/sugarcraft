<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord;

use SugarCraft\Plugins\Lang as PluginLang;

/**
 * Translation facade for the Discord bundle. The bundle owns its catalog
 * (`lang/`, plugin-owned under O4, plan_plugin.md 4.11), so the host's
 * `candy-code/lang` no longer carries these strings.
 */
final class Lang extends PluginLang
{
    protected const NAMESPACE = 'discord';
    protected const DIR = __DIR__ . '/../lang';
}
