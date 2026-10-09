<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Panel\Ipmi;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;
use SugarCraft\Top\Collect\Ipmi\IpmiReader;
use SugarCraft\Top\Collect\Ipmi\IpmiState;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Panel\Ipmi\PowerGauge;
use SugarCraft\Top\Panel\Ipmi\PowerHistory;
use SugarCraft\Top\Panel\IpmiPanel;
use SugarCraft\Top\Source\Fake\FakeIpmi;
use SugarCraft\Top\Tests\Panel\Gfx\PanelPaint;
use SugarCraft\Top\Theme\ThemeConfig;
use SugarCraft\Top\Theme\ThemeRegistry;
use SugarCraft\Top\View\Ink;
use SugarCraft\Top\View\Rect;

/**
 * The ipmi box painted straight into a Rect (box outline + panel), at
 * every detail level, from both real captures, the varying demo and the
 * failure states. Goldens under tests/fixtures/panels/ipmi/; regenerate
 * with `CANDY_TOP_UPDATE_GOLDENS=1 vendor/bin/phpunit tests/Panel/Ipmi/IpmiPaintTest.php`
 * (one golden per test).
 */
final class IpmiPaintTest extends TestCase
{
    private static function fixtures(string $host): string
    {
        return \dirname(__DIR__, 2) . "/fixtures/ipmi/$host";
    }

    private static function reader(string $source): ?IpmiReader
    {
        return match ($source) {
            'skynet2', 'kvm521' => FakeIpmi::fromCaptures(IpmiPaintKit::captures(self::fixtures($source))),
            'noaccess' => FakeIpmi::failing(IpmiState::NoAccess),
            'starting' => null,
            default => FakeIpmi::demo(),
        };
    }

    private static function config(bool $tty = false): Config
    {
        return Config::new()->with('color_theme', $tty ? 'TTY' : 'Default')->with('tty_mode', $tty);
    }

    /** @return array{0: string, 1: string} [plain grid, sgr lines] */
    private static function render(string $source, int $w, int $h, int $rounds = 6, bool $tty = false): array
    {
        $config = self::config($tty);
        $panel = IpmiPaintKit::feed(IpmiPanel::new(self::reader($source)), $config, $w, $h, $rounds);
        $palette = $tty ? ThemeRegistry::new(null, [])->load('TTY', true, true) : ThemeConfig::new();
        $surface = IpmiPaintKit::surface($panel, $config, $w, $h, $w, $h, $palette, $tty ? ColorProfile::Ansi : ColorProfile::TrueColor);

        return [PanelPaint::grid($surface, Rect::new(0, 0, $w, $h)), implode("\n", $surface->lines()) . "\n"];
    }

    public function testAmiCaptureFullAt120x40(): void
    {
        PanelPaint::assertGolden($this, 'ipmi', 'skynet2-120x40.txt', self::render('skynet2', 120, 40)[0]);
    }

    public function testAmiCaptureFullAt120x40Sgr(): void
    {
        PanelPaint::assertGolden($this, 'ipmi', 'skynet2-120x40.sgr', self::render('skynet2', 120, 40)[1]);
    }

    public function testHpCaptureFullAt120x40(): void
    {
        PanelPaint::assertGolden($this, 'ipmi', 'kvm521-120x40.txt', self::render('kvm521', 120, 40)[0]);
    }

    public function testDemoAt200x60(): void
    {
        PanelPaint::assertGolden($this, 'ipmi', 'demo-200x60.txt', self::render('demo', 200, 60, 40)[0]);
    }

    public function testMidSizeDenseLists(): void
    {
        PanelPaint::assertGolden($this, 'ipmi', 'demo-90x20.txt', self::render('demo', 90, 20)[0]);
    }

    public function testWideShortStrip(): void
    {
        PanelPaint::assertGolden($this, 'ipmi', 'demo-120x12.txt', self::render('demo', 120, 12)[0]);
    }

    public function testCompact(): void
    {
        PanelPaint::assertGolden($this, 'ipmi', 'demo-56x13.txt', self::render('demo', 56, 13)[0]);
    }

    public function testTiny(): void
    {
        PanelPaint::assertGolden($this, 'ipmi', 'kvm521-40x7.txt', self::render('kvm521', 40, 7)[0]);
    }

    public function testNoAccessReason(): void
    {
        PanelPaint::assertGolden($this, 'ipmi', 'noaccess-60x12.txt', self::render('noaccess', 60, 12)[0]);
    }

    public function testStarting(): void
    {
        PanelPaint::assertGolden($this, 'ipmi', 'starting-40x8.txt', self::render('starting', 40, 8)[0]);
    }

    public function testTtyModeSgr(): void
    {
        PanelPaint::assertGolden($this, 'ipmi', 'tty-120x40.sgr', self::render('kvm521', 120, 40, 6, true)[1]);
    }

    /**
     * TTY mode keeps btop's console glyph set: no braille (gauge, history
     * and sparklines degrade to bars), no ●/▰, no 24-bit colour.
     */
    public function testTtyModeUsesNoBrailleOrTrueColor(): void
    {
        foreach (['demo', 'kvm521'] as $source) {
            foreach ([[120, 40], [56, 13], [200, 18]] as [$w, $h]) {
                $sgr = self::render($source, $w, $h, 6, true)[1];
                $this->assertSame(0, preg_match('/[\x{2800}-\x{28FF}●▰▱━⚠]/u', $sgr), "$source {$w}x$h");
                $this->assertStringNotContainsString("\x1b[38;2", $sgr);
            }
        }
    }

    public function testAmiCaptureAt256Colours(): void
    {
        $config = Config::new()->with('color_theme', 'Default')->with('truecolor', false)->with('lowcolor', true);
        $panel = IpmiPaintKit::feed(IpmiPanel::new(self::reader('skynet2')), $config, 120, 40, 6);
        $surface = IpmiPaintKit::surface($panel, $config, 120, 40, 120, 40, ThemeConfig::new(), ColorProfile::Ansi256);
        $sgr = implode("\n", $surface->lines()) . "\n";
        $this->assertStringNotContainsString("\x1b[38;2", $sgr, 'no 24-bit colour in a 256-colour session');
        PanelPaint::assertGolden($this, 'ipmi', 'skynet2-120x40-256.sgr', $sgr);
    }

    /**
     * A hostile or broken BMC: escape sequences, 8-bit CSI (lone 0x9b and
     * its UTF-8 form), OSC title sets and invalid UTF-8 in the FRU product,
     * the vendor name, sensor names and the SEL text never reach the
     * screen — the parsers sanitise at the boundary.
     */
    public function testHostileBmcStringsRenderClean(): void
    {
        $captures = IpmiPaintKit::captures(self::fixtures('skynet2'));
        $evil = "\x1b[2J\x1b]0;pwned\x07\x9b31m\xc2\x9b1;1H\xff\xfe";
        $captures['fru'] = str_replace('ESC8000A-E12', 'ESC' . $evil . '8000', $captures['fru']);
        $captures['mc'] = str_replace('ASUSTek Computer Inc.', 'Evil' . $evil . ' Inc.', $captures['mc']);
        $captures['sensors'] = str_replace('Inlet Temp      ', 'Inlet' . $evil . 'Temp', $captures['sensors']);
        $captures['sel_last'] = str_replace('Power off/down', "Power\x1b[31m off\x9b2J", $captures['sel_last']);
        $config = self::config();
        $panel = IpmiPaintKit::feed(IpmiPanel::new(FakeIpmi::fromCaptures($captures)), $config, 120, 40, 2);
        $surface = IpmiPaintKit::surface($panel, $config, 120, 40, 120, 40, ThemeConfig::new());
        $out = $surface->render();
        $plain = implode("\n", $surface->plainLines());

        $this->assertSame(1, preg_match('//u', $out), 'valid UTF-8 throughout');
        $this->assertStringNotContainsString("\xc2\x9b", $out);
        $this->assertStringNotContainsString("\x1b[2J", $out);
        $this->assertStringNotContainsString("\x1b]", $out, 'no OSC');
        $this->assertStringNotContainsString("\x07", $out);
        $this->assertStringNotContainsString("\x1b[31m", $out, 'the BMC never picks a colour');
        // Sanitize's contract: 7-bit and raw 8-bit sequences go whole; the UTF-8
        // spelling of C1 (\xc2\x9b) loses its introducer and leaves `1;1H` as
        // inert text — harmless without the introducer, so not asserted absent.
        foreach (['pwned', '[2J', '31m'] as $residue) {
            $this->assertStringNotContainsString($residue, $plain, "no escape residue: $residue");
        }
        $this->assertStringContainsString('ESC', $plain, 'the product still shows');
        $this->assertStringContainsString('Power off', str_replace("\n", ' ', $plain), 'the SEL entry still shows');
    }

    public function testDuplicateLabelsKeepTheirNumbers(): void
    {
        [$plain] = self::render('kvm521', 120, 40);
        $this->assertStringContainsString('15-VR P1 Mem', $plain);
        $this->assertStringContainsString('16-VR P1 Mem', $plain);
        $this->assertStringContainsString('VR P1 ', $plain, 'a unique name is still stripped');
    }

    /** @return iterable<string, array{0: string}> */
    public static function sources(): iterable
    {
        yield 'demo' => ['demo'];
        yield 'kvm521' => ['kvm521'];
        yield 'noaccess' => ['noaccess'];
        yield 'starting' => ['starting'];
    }

    /** Every size from a 1×1 box up paints without error and stays inside the box. */
    #[DataProvider('sources')]
    public function testEverySizeFitsItsBox(string $source): void
    {
        $sizes = [];
        foreach ([1, 2, 3, 5, 8, 12, 20, 34, 50, 64, 80, 101, 140] as $w) {
            foreach ([1, 2, 3, 5, 7, 9, 13, 16, 24, 40] as $h) {
                $sizes[] = [$w, $h];
            }
        }
        foreach ($sizes as [$w, $h]) {
            [$plain] = self::render($source, $w, $h, 3);
            $lines = explode("\n", rtrim($plain, "\n"));
            $this->assertCount($h, $lines, "{$w}x$h");
            foreach ($lines as $line) {
                $this->assertSame($w, Width::string($line), "{$w}x$h");
            }
        }
    }

    public function testSerialsShowOnlyWhenAsked(): void
    {
        $this->assertStringNotContainsString('SN0000000000', self::render('kvm521', 120, 40)[0]);
    }

    public function testGaugeAndHistoryShapes(): void
    {
        $ink = Ink::new(ThemeConfig::new());
        $rows = PowerGauge::render($ink, 24, 6, 0.5, 0.2, 0.3, 0.6);
        $this->assertCount(6, $rows);
        foreach ($rows as $row) {
            $this->assertSame(24, Width::string((string) preg_replace('/\x1b\[[0-9;]*m/', '', $row)));
        }
        $plain = implode('', array_map(static fn (string $r): string => (string) preg_replace('/\x1b\[[0-9;]*m/', '', $r), $rows));
        $this->assertSame(1, preg_match('/[\x{2801}-\x{28FF}]/u', $plain), 'braille dots drawn');
        $this->assertSame(6, PowerGauge::height(24));
        $this->assertSame(['   '], PowerGauge::render($ink, 3, 1, 0.5, null, null, null), 'too small: blank');

        $empty = PowerGauge::render($ink, 24, 6, 0.0, null, null, null);
        $full = PowerGauge::render($ink, 24, 6, 1.0, null, null, null);
        $this->assertNotSame($empty, $full);

        $hist = PowerHistory::render($ink, 10, 3, [100.0, 200.0, 300.0], 300.0);
        $this->assertCount(3, $hist);
        $this->assertSame([], PowerHistory::render($ink, 0, 3, [], 1.0));
        $spark = (string) preg_replace('/\x1b\[[0-9;]*m/', '', PowerHistory::spark($ink, 4, [1.0, 2.0, 3.0], 0.0, 3.0, 'temp'));
        $this->assertSame(4, mb_strlen($spark));
        $this->assertSame('', PowerHistory::spark($ink, 0, [], 0.0, 1.0, 'temp'));
    }
}
