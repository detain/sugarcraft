<?php

declare(strict_types=1);

/**
 * Render candy-top's ipmi box ALONE (no App wiring yet) into a cols×rows
 * terminal and print its SGR — the phase-1 visual check for the box.
 *
 *   php prompt_kit/tools/candy-top-ipmi-preview.php <theme> [cols] [rows] [source] [box WxH]
 *
 * source: `demo` (FakeIpmi::demo, the ASUS/AMI capture, varying — default),
 * `skynet2` / `kvm521` (static captures from candy-top/tests/fixtures/ipmi/),
 * `noaccess`, `notool`, `starting`.
 * box: the box's own size inside the terminal (default: the whole terminal).
 * Env: CANDY_TOP_TICKS rounds fed (default 12), CANDY_TOP_IPMI_FAMILY the
 * outline family (default net), CANDY_TOP_256=1, CANDY_TOP_TTY=1.
 *
 *   php prompt_kit/tools/candy-top-ipmi-preview.php pastel 120 40 > f.sgr
 *   php prompt_kit/tools/sgr-to-svg.php f.sgr f.svg && rsvg-convert f.svg -o f.png
 */

use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Top\Collect\Ipmi\IpmiState;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Panel\IpmiPanel;
use SugarCraft\Top\Panel\PanelFrame;
use SugarCraft\Top\Source\Fake\FakeIpmi;
use SugarCraft\Top\Tests\Panel\Ipmi\IpmiPaintKit;
use SugarCraft\Top\Theme\ThemeRegistry;

require dirname(__DIR__, 2) . '/candy-top/vendor/autoload.php';

$theme = $argv[1] ?? 'pastel';
$cols = (int) ($argv[2] ?? 120);
$rows = (int) ($argv[3] ?? 40);
$sourceName = $argv[4] ?? 'demo';
[$bw, $bh] = isset($argv[5]) ? array_map('intval', explode('x', $argv[5])) : [$cols, $rows];

$tty = getenv('CANDY_TOP_TTY') === '1';
$config = Config::new()->with('color_theme', $tty ? 'TTY' : $theme)
    ->with('truecolor', getenv('CANDY_TOP_256') !== '1')
    ->with('lowcolor', getenv('CANDY_TOP_256') === '1')
    ->with('tty_mode', $tty);
$palette = ThemeRegistry::new(null, [])->load($config->colorTheme(), true, $tty);
$profile = $tty ? ColorProfile::Ansi : (getenv('CANDY_TOP_256') === '1' ? ColorProfile::Ansi256 : ColorProfile::TrueColor);

$fixtures = dirname(__DIR__, 2) . '/candy-top/tests/fixtures/ipmi';
$reader = match ($sourceName) {
    'skynet2', 'kvm521' => FakeIpmi::fromCaptures(IpmiPaintKit::captures("$fixtures/$sourceName")),
    'noaccess' => FakeIpmi::failing(IpmiState::NoAccess),
    'notool' => FakeIpmi::failing(IpmiState::NoTool),
    'starting' => null,
    default => FakeIpmi::demo(),
};
$panel = IpmiPanel::new($reader);
$panel = IpmiPaintKit::feed($panel, $config, $bw, $bh, (int) (getenv('CANDY_TOP_TICKS') ?: 12));
$surface = IpmiPaintKit::surface($panel, $config, $cols, $rows, $bw, $bh, $palette, $profile, getenv('CANDY_TOP_IPMI_FAMILY') ?: 'net');
echo implode("\n", $surface->lines()), "\n";
