<?php

declare(strict_types=1);

/**
 * Render one deterministic candy-top `--fake` frame (the real Panels::standard
 * fake sources, fixed clock, 8-core host) in a given theme and print its SGR.
 *
 *   php prompt_kit/tools/candy-top-theme-preview.php <theme> [cols] [rows] [keys...]
 *
 * <theme> is any color_theme value (Default, pastel, mellow.theme, ...). Keys
 * are fed after the data ticks: `down`/`up`/`left`/`right` (move a
 * selection), `enter`, or a char; a `pre:` prefix feeds the key BEFORE the
 * ticks (`pre:v` opens the VM dashboard so its cards carry history). Each
 * key's own Cmds (a toggled box's first sample) run too.
 * CANDY_TOP_SHOWN_BOXES overrides shown_boxes.
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
if (getenv('CANDY_TOP_SHOWN_BOXES')) {
    $config = $config->with('shown_boxes', (string) getenv('CANDY_TOP_SHOWN_BOXES'));
}
// The fake fleet's host runs VMs: the cpu title offers the `vms` button.
$host = Harness::host()->withVmHost(true);
$panels = Panels::standard($host, $config, true);
$palette = ThemeRegistry::new(null, [])->load($config->colorTheme(), $config->bool('theme_background'), false);
$app = App::start(
    $config,
    $palette,
    $host,
    $panels,
    static fn (): ClockTickMsg => new ClockTickMsg(Harness::TIME, 3600.0),
    getenv('CANDY_TOP_256') === '1' ? ColorProfile::Ansi256 : ColorProfile::TrueColor,
);
[$app] = $app->update(new WindowSizeMsg($cols, $rows));
[$app] = $app->update(new ClockTickMsg(Harness::TIME, 3600.0));
$feed = static function (App $app, string $key): App {
    [$app, $cmd] = $app->update(match ($key) {
        'down' => new KeyMsg(KeyType::Down),
        'up' => new KeyMsg(KeyType::Up),
        'left' => new KeyMsg(KeyType::Left),
        'right' => new KeyMsg(KeyType::Right),
        'enter' => new KeyMsg(KeyType::Enter),
        default => new KeyMsg(KeyType::Char, $key),
    });
    $pending = [$cmd];
    while ($pending !== []) {
        foreach (Cmds::run(array_shift($pending)) as $m) {
            if (!$m instanceof TickRequest) {
                [$app, $next] = $app->update($m);
                $pending[] = $next;
            }
        }
    }

    return $app;
};
foreach ($keys as $key) {
    if (str_starts_with($key, 'pre:')) {
        $app = $feed($app, substr($key, 4));
    }
}
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
    if (!str_starts_with($key, 'pre:')) {
        $app = $feed($app, $key);
    }
}
echo (string) $app->view(), "\n";
