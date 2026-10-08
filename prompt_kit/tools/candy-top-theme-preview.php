<?php

declare(strict_types=1);

/**
 * Render one deterministic candy-top `--fake` frame (the real Panels::standard
 * fake sources, fixed clock, 8-core host) in a given theme and print its SGR.
 *
 *   php prompt_kit/tools/candy-top-theme-preview.php <theme> [cols] [rows] [keys...]
 *
 * <theme> is any color_theme value (Default, pastel, mellow.theme, ...). Keys
 * are fed after the first sample: `down` (select a row), `enter`, or a char.
 * Render it with prompt_kit/tools/sgr-to-svg.php, then `rsvg-convert` to PNG.
 */

use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Top\App;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Msg\ClockTickMsg;
use SugarCraft\Top\Msg\DataTickMsg;
use SugarCraft\Core\TickRequest;
use SugarCraft\Top\Panel\Panels;
use SugarCraft\Top\Tests\Support\Cmds;
use SugarCraft\Top\Tests\Support\Harness;
use SugarCraft\Top\Theme\ThemeRegistry;

require dirname(__DIR__, 2) . '/candy-top/vendor/autoload.php';

date_default_timezone_set('UTC');
$theme = $argv[1] ?? 'Default';
$cols = (int) ($argv[2] ?? 120);
$rows = (int) ($argv[3] ?? 40);
$keys = array_slice($argv, 4);

// CANDY_TOP_THEME_BG=0 previews theme_background = false (terminal background).
$config = Config::new()->with('color_theme', $theme)
    ->with('theme_background', getenv('CANDY_TOP_THEME_BG') !== '0')
    // CANDY_TOP_256=1 previews truecolor = false (lowcolor, Ansi256 profile).
    ->with('truecolor', getenv('CANDY_TOP_256') !== '1')
    ->with('lowcolor', getenv('CANDY_TOP_256') === '1')
    ->withShownBoxesSettled(2);
$host = Harness::host();
$palette = ThemeRegistry::new(null, [])->load($config->colorTheme(), $config->bool('theme_background'), false);
$app = App::start(
    $config,
    $palette,
    $host,
    Panels::standard($host, $config, true),
    static fn (): ClockTickMsg => new ClockTickMsg(Harness::TIME, 3600.0),
    getenv('CANDY_TOP_256') === '1' ? ColorProfile::Ansi256 : ColorProfile::TrueColor,
);
[$app] = $app->update(new WindowSizeMsg($cols, $rows));
[$app] = $app->update(new ClockTickMsg(Harness::TIME, 3600.0));
// Pump the fake sources for CANDY_TOP_TICKS data ticks (default 40) so the
// graphs carry history; clock ticks are dropped to keep the time fixed.
$queue = Cmds::run($app->init());
$ticks = (int) (getenv('CANDY_TOP_TICKS') ?: 40);
while ($queue !== []) {
    $msg = array_shift($queue);
    if ($msg instanceof TickRequest) {
        $produced = ($msg->produce)();
        if (!$produced instanceof DataTickMsg || --$ticks < 0) {
            continue;
        }
        $msg = $produced;
    }
    [$app, $cmd] = $app->update($msg);
    array_push($queue, ...Cmds::run($cmd));
}
foreach ($keys as $key) {
    [$app] = $app->update(match ($key) {
        'down' => new KeyMsg(KeyType::Down),
        'enter' => new KeyMsg(KeyType::Enter),
        default => new KeyMsg(KeyType::Char, $key),
    });
}
echo (string) $app->view(), "\n";
