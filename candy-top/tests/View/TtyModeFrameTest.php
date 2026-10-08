<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\View;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\TickRequest;
use SugarCraft\Top\App;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Lang;
use SugarCraft\Top\Msg\ClockTickMsg;
use SugarCraft\Top\Overlay\HelpMenu;
use SugarCraft\Top\Overlay\MainMenu;
use SugarCraft\Top\Overlay\MsgBox;
use SugarCraft\Top\Overlay\OptionsMenu;
use SugarCraft\Top\Overlay\ReniceMenu;
use SugarCraft\Top\Overlay\SignalMenu;
use SugarCraft\Top\Panel\Panels;
use SugarCraft\Top\Tests\Support\Cmds;
use SugarCraft\Top\Tests\Support\Harness;
use SugarCraft\Top\Theme\ThemeRegistry;

/**
 * Whole-frame TTY mode (btop `tty_mode` / `force_tty` / a /dev/tty* console)
 * with the real `--fake` panels, as bin/candy-top builds them.
 *
 * btop's tty_mode switches exactly these: square corners regardless of
 * rounded_corners (btop_draw.cpp:291-294, btop_menu.cpp:914-917), digit box
 * numbers instead of superscripts (btop_draw.cpp:290, :1835), the "tty"
 * graph symbol for every graph regardless of graph_symbol_* (btop_draw.cpp:498,
 * :600, :1248, :1506, :1714), the 16-colour TTY theme (btop_theme.cpp:462),
 * the uncoloured main-menu items + 16-colour banner (btop_menu.cpp:1232-1289,
 * btop_draw.cpp:148), and the options menu's "E" for the enter symbol
 * (btop_menu.cpp:1699). Everything else — meters (■), tree glyphs, scroll
 * arrows, ↵, battery status symbols — stays the same in btop, so it stays
 * here too.
 *
 * Goldens: tests/fixtures/tty/*; regenerate with
 * `CANDY_TOP_UPDATE_GOLDENS=1 vendor/bin/phpunit tests/View/TtyModeFrameTest.php`.
 */
final class TtyModeFrameTest extends TestCase
{
    /** 24-bit and 256-colour SGR parameters; TTY mode emits only the 16-colour set. */
    private const WIDE_SGR = '/\e\[[0-9;]*?(?<![0-9])(?:38|48);[25](?:;|m)/';

    /**
     * Glyphs btop never draws in tty mode: braille (graph_symbol braille),
     * rounded corners, block2/sextant legacy-computing cells, the block
     * graph's partial blocks, powerline.
     */
    private const NON_TTY_GLYPHS = '/[\x{2800}-\x{28FF}\x{256D}-\x{2570}\x{1FB00}-\x{1FBFF}\x{E0A0}-\x{E0D7}\x{2580}-\x{2587}\x{2589}-\x{258F}\x{2590}\x{2596}-\x{259F}\x{00B9}\x{00B2}\x{00B3}\x{2070}-\x{2079}]/u';

    private string $tz;

    protected function setUp(): void
    {
        $this->tz = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->tz);
    }

    public function testPlainGridGolden80x24(): void
    {
        $grid = implode("\n", self::running(80, 24)->surface()?->plainLines() ?? []) . "\n";
        $this->assertGolden(__DIR__ . '/../fixtures/tty/frame-80x24.txt', $grid);
    }

    public function testSgrGolden80x24(): void
    {
        $this->assertGolden(__DIR__ . '/../fixtures/tty/frame-80x24.sgr', (string) self::running(80, 24)->view() . "\n");
    }

    /** @return iterable<string, array{int, int, list<string>, ?class-string}> */
    public static function states(): iterable
    {
        $page2 = array_merge(['o'], array_fill(0, 12, 'down'));
        foreach ([[80, 24], [120, 40]] as [$w, $h]) {
            yield "{$w}x{$h} frame" => [$w, $h, [], null];
            yield "{$w}x{$h} main menu" => [$w, $h, ['m'], MainMenu::class];
            yield "{$w}x{$h} options" => [$w, $h, ['o'], OptionsMenu::class];
            yield "{$w}x{$h} options page 2" => [$w, $h, $page2, OptionsMenu::class];
            yield "{$w}x{$h} options editing" => [$w, $h, [...$page2, 'enter'], OptionsMenu::class];
            yield "{$w}x{$h} help" => [$w, $h, ['h'], HelpMenu::class];
            yield "{$w}x{$h} signals" => [$w, $h, ['down', 's'], SignalMenu::class];
            yield "{$w}x{$h} renice" => [$w, $h, ['down', 'N'], ReniceMenu::class];
            yield "{$w}x{$h} kill confirm" => [$w, $h, ['down', 'k'], MsgBox::class];
            yield "{$w}x{$h} detail" => [$w, $h, ['down', 'enter'], null];
            yield "{$w}x{$h} tree" => [$w, $h, ['e'], null];
        }
        // btop.cpp:158-170 term_resize notice — painted instead of the boxes, not an overlay.
        yield '50x12 size notice' => [50, 12, [], null];
    }

    /**
     * rounded_corners and every graph_symbol_* are set to their non-tty
     * extremes: tty_mode must override all of them, as in btop.
     *
     * @param list<string> $keys
     * @param ?class-string $overlay what the keys must have opened (else the scan proves nothing)
     */
    #[DataProvider('states')]
    public function testNoWideColourOrNonTtyGlyphs(int $cols, int $rows, array $keys, ?string $overlay): void
    {
        $config = self::config()
            ->with('rounded_corners', true)
            ->with('graph_symbol', 'braille');
        foreach (['cpu', 'mem', 'net', 'proc'] as $box) {
            $config = $config->with('graph_symbol_' . $box, 'braille');
        }
        $app = self::running($cols, $rows, $config);
        foreach ($keys as $key) {
            [$app] = $app->update(self::keyMsg($key));
        }
        if ($overlay === null) {
            $this->assertNull($app->overlay());
        } else {
            $this->assertInstanceOf($overlay, $app->overlay());
        }
        $view = (string) $app->view();
        if ($cols < 80) {
            $this->assertStringContainsString(Lang::t('size.too_small'), $view);
        }

        $this->assertDoesNotMatchRegularExpression(self::WIDE_SGR, $view, 'tty mode leaked a 256/24-bit colour');
        $this->assertSame(0, preg_match_all(self::NON_TTY_GLYPHS, $view, $m), 'non-tty glyphs: ' . implode('', array_unique($m[0])));
        foreach ($app->surface()?->plainLines() ?? [] as $i => $line) {
            $this->assertLessThanOrEqual($cols, mb_strwidth($line), "row {$i} is wider than the terminal");
        }
    }

    public function testTheFrameUsesDigitBoxNumbersAndSquareCorners(): void
    {
        $lines = self::running(80, 24, self::config()->with('rounded_corners', true))->surface()?->plainLines() ?? [];
        $this->assertStringStartsWith('┌─┐1cpu┌', $lines[0]);
        $this->assertStringEndsWith('┐', $lines[0]);
        // The fake host has two GPUs: btop's gpus_extra_height grows the cpu box by two rows.
        $this->assertStringStartsWith('└', $lines[9]);
        $this->assertStringContainsString('┐2mem┌', $lines[10]);
        $this->assertStringContainsString('┐3net┌', $lines[17]);
        $this->assertStringContainsString('┐4proc┌', $lines[10]);
    }

    private static function config(): Config
    {
        return Config::new()->withTtyModeResolved(true, false)->withShownBoxesSettled(0);
    }

    private static function running(int $cols, int $rows, ?Config $config = null): App
    {
        $config ??= self::config();
        $host = Harness::host();
        $palette = ThemeRegistry::new(null, [])->load($config->colorTheme(), $config->bool('theme_background'), $config->ttyMode());
        $app = App::start(
            $config,
            $palette,
            $host,
            Panels::standard($host, $config, true),
            static fn (): ClockTickMsg => new ClockTickMsg(Harness::TIME, 3600.0),
        );
        [$app] = $app->update(new WindowSizeMsg($cols, $rows));
        [$app] = $app->update(new ClockTickMsg(Harness::TIME, 3600.0));
        foreach (Cmds::run($app->init()) as $msg) {
            if (!$msg instanceof TickRequest) {
                [$app] = $app->update($msg);
            }
        }

        return $app;
    }

    private static function keyMsg(string $name): KeyMsg
    {
        return match ($name) {
            'down' => new KeyMsg(KeyType::Down),
            'enter' => new KeyMsg(KeyType::Enter),
            default => new KeyMsg(KeyType::Char, $name),
        };
    }

    private function assertGolden(string $path, string $actual): void
    {
        if (getenv('CANDY_TOP_UPDATE_GOLDENS') === '1') {
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0o777, true);
            }
            file_put_contents($path, $actual);
            $this->markTestIncomplete("Golden {$path} was (re)written; review the diff.");
        }
        // A missing golden is a failure, never a silent first-run pass.
        $this->assertFileExists($path, 'Missing golden; regenerate with CANDY_TOP_UPDATE_GOLDENS=1 and review it.');
        $this->assertSame((string) file_get_contents($path), $actual);
    }
}
