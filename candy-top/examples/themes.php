<?php

declare(strict_types=1);

/**
 * themes — list the themes candy-top can select, one name per line.
 *
 *   php examples/themes.php
 *
 * The output is the built-in Default and TTY themes followed by the 43 bundled
 * .theme files under themes/. The registry is built from the bundled directory
 * alone, so a user's ~/.config/candy-top/themes/ never changes the output. Pass
 * a name to `candy-top --theme <name>` to run with it for one session.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use SugarCraft\Top\Theme\ThemeRegistry;

$names = ThemeRegistry::fromDirs(ThemeRegistry::bundledDir())->names();
foreach ($names as $name) {
    echo $name, "\n";
}
echo count($names), " selectable themes\n";
