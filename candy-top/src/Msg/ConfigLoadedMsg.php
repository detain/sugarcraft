<?php

declare(strict_types=1);

namespace SugarCraft\Top\Msg;

use SugarCraft\Core\Msg;
use SugarCraft\Top\Config\LoadResult;
use SugarCraft\Top\Theme\ThemeRegistry;

/**
 * The answer of the `ctrl+r` reload Cmd — btop's SIGUSR2 hot reload
 * (btop.cpp `reload_conf`: init_config + Theme::updateThemes +
 * Theme::setTheme + a full resize redraw). `$result` is null when the App
 * has no config file to read (tests, an unresolvable home); the theme is
 * still reloaded. `$catalog` is the rescanned theme list (null = keep the
 * one the App has).
 */
final class ConfigLoadedMsg implements Msg
{
    public function __construct(
        public readonly ?LoadResult $result,
        public readonly ?ThemeRegistry $catalog = null,
    ) {
    }
}
