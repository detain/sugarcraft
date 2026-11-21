<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams;

use SugarCraft\Plugins\Lang as PluginLang;

/**
 * Translation facade for the MsTeams bundle. The bundle owns its catalog
 * (`lang/`, plugin-owned under O4, plan_plugin.md 4.11), so the host's
 * `candy-code/lang` no longer carries these strings.
 */
final class Lang extends PluginLang
{
    protected const NAMESPACE = 'msteams';
    protected const DIR = __DIR__ . '/../lang';
}
