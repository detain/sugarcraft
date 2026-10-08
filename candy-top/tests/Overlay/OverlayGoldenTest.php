<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Overlay;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Top\App;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Msg\ClockTickMsg;
use SugarCraft\Top\Msg\SampledMsg;
use SugarCraft\Top\Overlay\Menus;
use SugarCraft\Top\Overlay\Overlay;
use SugarCraft\Top\Overlay\ReniceMenu;
use SugarCraft\Top\Overlay\SignalMenu;
use SugarCraft\Top\Overlay\Signals;
use SugarCraft\Top\Panel\Panels;
use SugarCraft\Top\Source\Fake\FakeProcessControl;
use SugarCraft\Top\Tests\Support\Cmds;
use SugarCraft\Top\Tests\Support\Harness;
use SugarCraft\Top\Theme\TtyTheme;
use SugarCraft\Top\View\Surface;

/**
 * Whole-frame goldens of every P-F1 overlay over the deterministic
 * Harness frame (dimmed backdrop included). Cell grids are
 * tests/fixtures/overlays/*.txt, styled rows *.sgr. Regenerate with
 * `CANDY_TOP_UPDATE_GOLDENS=1 vendor/bin/phpunit tests/Overlay/OverlayGoldenTest.php`
 * and review the diff; a missing golden fails.
 */
final class OverlayGoldenTest extends TestCase
{
    private const DIR = __DIR__ . '/../fixtures/overlays/';

    private string $tz;

    protected function setUp(): void
    {
        T::reset();
        $this->tz = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->tz);
    }

    private static function keyMsg(string $k): KeyMsg
    {
        return match ($k) {
            'down' => new KeyMsg(KeyType::Down),
            'up' => new KeyMsg(KeyType::Up),
            'right' => new KeyMsg(KeyType::Right),
            'left' => new KeyMsg(KeyType::Left),
            'enter' => new KeyMsg(KeyType::Enter),
            default => new KeyMsg(KeyType::Char, $k),
        };
    }

    /**
     * @param list<string> $keys pressed after the overlay opens (or to open it)
     */
    private static function framed(int $cols, int $rows, ?Overlay $overlay, array $keys, ?Config $config = null): Surface
    {
        $app = Harness::running($cols, $rows, $config);
        if ($overlay !== null) {
            $app = $app->withOverlay($overlay);
        }
        foreach ($keys as $k) {
            [$app] = $app->update(self::keyMsg($k));
        }
        $surface = $app->surface();
        self::assertNotNull($surface);

        return $surface;
    }

    /** @return iterable<string, array{string, int, int, ?\Closure, list<string>, array<string, bool|int|string>}> */
    public static function grids(): iterable
    {
        yield 'main menu' => ['main-120x40', 120, 40, null, ['m'], []];
        yield 'main menu, help selected' => ['main-help-80x24', 80, 24, null, ['m', 'down'], []];
        yield 'help' => ['help-120x40', 120, 40, null, ['?'], []];
        yield 'help paged' => ['help-80x24', 80, 24, null, ['h'], []];
        yield 'help page 2' => ['help-80x24-page2', 80, 24, null, ['h', 'down'], []];
        yield 'signal chooser' => ['signals-120x40', 120, 40, static fn (): Overlay => SignalMenu::new(4410, 'node', FakeProcessControl::new()), ['1', '5'], []];
        yield 'renice' => ['renice-120x40', 120, 40, static fn (): Overlay => ReniceMenu::new(4410, 'node', FakeProcessControl::new()), ['up', 'up'], []];
        yield 'signal confirm' => ['confirm-120x40', 120, 40, static fn (): Overlay => Menus::signalSend(FakeProcessControl::new(), 4410, 'node', Signals::SIGTERM), [], []];
        yield 'signal confirm, No' => ['confirm-no-120x40', 120, 40, static fn (): Overlay => Menus::signalSend(FakeProcessControl::new(), 4410, 'node', Signals::SIGKILL), ['right'], []];
        yield 'signal failure' => ['signal-error-120x40', 120, 40, static fn (): Overlay => Menus::signalReturn(Signals::EPERM), [], []];
        yield 'refused toggle' => ['size-error-70x24', 70, 24, null, ['2'], ['shown_boxes' => 'cpu proc']];
        yield 'options' => ['options-120x40', 120, 40, null, ['o'], []];
        yield 'options 80x24 paged' => ['options-80x24', 80, 24, null, ['o'], []];
        yield 'options cpu tab' => ['options-cpu-120x40', 120, 40, null, ['o', '1', 'down'], []];
        yield 'options update_ms' => ['options-update-ms-120x40', 120, 40, null, ['o', ...array_fill(0, 13, 'down'), 'right'], []];
        yield 'options editing' => ['options-edit-120x40', 120, 40, null, ['o', ...array_fill(0, 8, 'down'), 'enter', 'x'], []];
        yield 'options warning' => ['options-warning-120x40', 120, 40, null, ['o', ...array_fill(0, 13, 'down'), 'left'], ['update_ms' => 100]];
    }

    /**
     * @param list<string> $keys
     * @param array<string, bool|int|string> $options
     */
    #[DataProvider('grids')]
    public function testCellGridGolden(string $name, int $cols, int $rows, ?\Closure $overlay, array $keys, array $options): void
    {
        // The Harness palette is Default; name it so the options menu shows it.
        $config = Config::new()->with('color_theme', 'Default');
        foreach ($options as $k => $v) {
            $config = $config->with($k, $v);
        }
        $surface = self::framed($cols, $rows, $overlay === null ? null : $overlay(), $keys, $config);
        $this->assertSame($rows, count($surface->lines()));
        $this->assertGolden(self::DIR . $name . '.txt', implode("\n", $surface->plainLines()) . "\n");
    }

    public function testConfirmBoxSgrGolden(): void
    {
        $surface = self::framed(120, 40, Menus::signalSend(FakeProcessControl::new(), 4410, 'node', Signals::SIGTERM), []);
        // The box: rows 16..24, columns 35..84.
        $this->assertGolden(self::DIR . 'confirm-120x40.sgr', self::cropSgr($surface, 35, 16, 50, 9));
    }

    public function testMainMenuSgrGolden(): void
    {
        $surface = self::framed(120, 40, null, ['m']);
        // Banner from row 9 and the three entries below it.
        $this->assertGolden(self::DIR . 'main-120x40.sgr', self::cropSgr($surface, 24, 9, 72, 16));
    }

    public function testTtyMainMenuSgrGolden(): void
    {
        $host = Harness::host();
        $config = Config::new()->with('tty_mode', true);
        $app = App::start($config, TtyTheme::new(), $host, Panels::placeholders($host, $config, true), static fn (): ClockTickMsg => new ClockTickMsg(Harness::TIME), ColorProfile::Ansi);
        [$app] = $app->update(new WindowSizeMsg(80, 24));
        foreach (Cmds::of(SampledMsg::class, $app->init()) as $msg) {
            [$app] = $app->update($msg);
        }
        [$app] = $app->update(new KeyMsg(KeyType::Char, 'm'));
        $surface = $app->surface();
        $this->assertNotNull($surface);
        $this->assertGolden(self::DIR . 'main-tty-80x24.sgr', self::cropSgr($surface, 0, 1, 80, 16));
    }

    /** Rows of a rectangle: each style change as its canonical SGR, then the glyphs. */
    private static function cropSgr(Surface $s, int $x0, int $y0, int $w, int $h): string
    {
        $out = '';
        for ($y = $y0; $y < $y0 + $h; $y++) {
            $active = null;
            for ($x = $x0; $x < $x0 + $w; $x++) {
                $style = $s->style($x, $y);
                if ($style !== $active) {
                    $out .= "\x1b[0m" . $style;
                    $active = $style;
                }
                $out .= $s->glyph($x, $y);
            }
            $out .= "\x1b[0m\n";
        }

        return $out;
    }

    private function assertGolden(string $path, string $actual): void
    {
        if (getenv('CANDY_TOP_UPDATE_GOLDENS') === '1') {
            file_put_contents($path, $actual);
            $this->markTestIncomplete("Golden {$path} was (re)written; review the diff.");
        }
        $this->assertFileExists($path, 'Missing golden; regenerate with CANDY_TOP_UPDATE_GOLDENS=1 and review it.');
        $this->assertSame((string) file_get_contents($path), $actual);
    }
}
