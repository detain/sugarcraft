<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui\MediaForm;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Media\MediaRequest;
use SugarCraft\Code\Message;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Renderer as LiveRenderer;
use SugarCraft\Code\Theme;
use SugarCraft\Code\ToolResult;
use SugarCraft\Code\Tui\MediaForm\MediaForm;
use SugarCraft\Code\Tui\Renderer as TuiRenderer;
use SugarCraft\Image\Mosaic;
use SugarCraft\Mouse\Mark;

/**
 * P-IMAGE-MENU: the /generate popup is content-sized and centred, not a
 * fullscreen takeover. Pins for {@see MediaForm::naturalSize()} and the
 * shell's compose-band-behind-a-veiled-box path — the box lands where the
 * zone offsets say it does, the frame never over-runs the terminal, no
 * picture paints through the popup, and the menu bar above it stays live.
 *
 * The exact-size {@see MediaForm::view()} contract itself stays pinned by
 * MediaFormRenderTest's goldens; nothing here re-derives the box's own bytes.
 */
final class MediaFormPopupSizeTest extends TestCase
{
    private const VARS = ['CANDY_CODE_DISABLE_MOUSE', 'CANDY_CODE_DISABLE_MOUSE_CLICKS'];

    /** The form box's own top border, from its corner through the title. */
    private const BOX_CORNER = "\u{256D}\u{2500} \u{1F3A8}";

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::VARS as $var) {
            putenv($var);
        }

        TuiRenderer::resetSizeCache();
        LiveRenderer::scanner()->clear();
        TuiRenderer::chromeScanner()->clear();
        LiveRenderer::setZoneOrigin(0, 0);
    }

    protected function tearDown(): void
    {
        foreach (self::VARS as $var) {
            putenv($var);
        }

        TuiRenderer::resetSizeCache();
        LiveRenderer::scanner()->clear();
        TuiRenderer::chromeScanner()->clear();

        parent::tearDown();
    }

    public function testTheBoxIsContentSizedAndCentredInsideTheBand(): void
    {
        $theme = Theme::default();
        $form = MediaForm::open(null);
        [$boxCols, $boxRows] = $form->naturalSize($theme, 200, 47);

        self::assertGreaterThanOrEqual(60, $boxCols, 'the MIN_COLS floor keeps the strip readable');
        self::assertLessThanOrEqual(104, $boxCols, 'the MAX_COLS cap keeps it a popup, not a screen');
        self::assertLessThan(200, $boxCols, 'content fits inside the cap on a wide terminal');
        self::assertGreaterThanOrEqual(7, $boxRows, 'six chrome rows plus a body of at least one');

        $view = TuiRenderer::renderView($this->popupApp()->openMediaForm(), 200, 50);
        $lines = explode("\n", Ansi::strip($view->body));
        self::assertCount(50, $lines);

        $top = $this->popupRowIndex($lines, self::BOX_CORNER);
        // Chrome without a hosted Chat: the one menu row, then the shell's
        // four bottom rows (input pane block plus the status bar — counted
        // off the probe frame, not assumed).
        $paneRows = 50 - 5;
        self::assertSame(1 + intdiv($paneRows - $boxRows, 2), $top, 'the box sits at the band centre');

        self::assertSame(
            intdiv(200 - $boxCols, 2),
            $this->popupCellOf($lines[$top], self::BOX_CORNER),
            "horizontal centring matches Veil's floor",
        );
    }

    public function testContentWiderThanTheBandTakesTheWholeBandAndTheCapBoundsWideOnes(): void
    {
        $theme = Theme::default();
        $roomy = MediaForm::open(MediaRequest::new()->fromArray(['prompt' => str_repeat('p', 400)]));

        [$narrowCols] = $roomy->naturalSize($theme, 80, 40);
        self::assertSame(80, $narrowCols, 'a demand past the band takes the full band width');

        [$cappedCols] = $roomy->naturalSize($theme, 200, 50);
        self::assertSame(104, $cappedCols, 'past the cap, the cap is the box — the MAX_COLS law');
    }

    public function testAShallowBandBoundsTheBox(): void
    {
        [$cols, $rows] = MediaForm::open(null)->naturalSize(Theme::default(), 200, 10);

        self::assertLessThanOrEqual(104, $cols);
        self::assertSame(10, $rows, 'the band is the ceiling; the box never overflows it');
    }

    public function testNoPaintedRowOverRunsTheTerminal(): void
    {
        $app = $this->popupApp()->openMediaForm();

        foreach ([[200, 50], [120, 30], [70, 24]] as [$cols, $rows]) {
            $lines = explode("\n", Ansi::strip(TuiRenderer::renderView($app, $cols, $rows)->body));
            self::assertCount($rows, $lines, "frame at {$cols}x{$rows}");
            foreach ($lines as $index => $line) {
                self::assertLessThanOrEqual(
                    $cols,
                    Width::string($line),
                    "row {$index} at {$cols}x{$rows} overflowed: [{$line}]",
                );
            }
        }
    }

    public function testFormZonesLandOnPaintedCells(): void
    {
        $theme = Theme::default();
        $app = $this->popupApp()->openMediaForm();
        $form = $app->mediaForm;
        self::assertNotNull($form);
        [$boxCols, $boxRows] = $form->naturalSize($theme, 200, 47);

        $lines = explode("\n", Ansi::strip(TuiRenderer::renderView($app, 200, 50)->body));
        $top = $this->popupRowIndex($lines, self::BOX_CORNER);
        $left = $this->popupCellOf($lines[$top], self::BOX_CORNER);

        $box = explode("\n", Ansi::strip($form->view($theme, $boxCols, $boxRows)));
        self::assertCount($boxRows, $box, 'the view keeps its exact-size contract at box size');

        $inked = 0;
        foreach ($form->zones($theme, $boxCols, $boxRows) as [$row, $from, $to, $id]) {
            $segment = $this->popupCells($box[$row], $from, $to - $from);
            self::assertSame(
                $segment,
                $this->popupCells($lines[$top + $row], $left + $from, $to - $from),
                "zone [{$id}] does not sit on the painted box cells",
            );

            if (trim($segment) !== '') {
                $inked++;
            }
        }

        self::assertGreaterThan(0, $inked, 'tab zones were checked against painted labels, not blanks');
    }

    public function testTheViewCarriesNoImagesWhileThePopupIsUp(): void
    {
        if (!\extension_loaded('gd')) {
            self::markTestSkipped('sugar-image decodes images through ext-gd');
        }

        $chat = new Chat(
            history: [
                Message::user('screenshot'),
                Message::assistant('')->withToolResults([
                    new ToolResult(name: 'shot', result: 'captured', id: 'shot_1', imageBytes: $this->popupRedPng()),
                ]),
            ],
            expanded: ['shot_1' => true],
            mosaic: Mosaic::sixel(),
        );

        $plain = TuiRenderer::renderView($this->popupApp($chat), 120, 40);
        self::assertNotSame([], $plain->images, 'the transcript has a live placement to hand up');

        $popup = TuiRenderer::renderView($this->popupApp($chat)->openMediaForm(), 120, 40);
        self::assertSame([], $popup->images, 'a pixel-graphics layer never paints through the popup');
    }

    public function testTheMenuBarAndTheBoxStayTheOnlyClickTargets(): void
    {
        $theme = Theme::default();
        $app = $this->popupApp()->openMediaForm();
        $view = TuiRenderer::renderView($app, 200, 50);
        $lines = explode("\n", Ansi::strip($view->body));

        // The menu bar's row: its first painted cell resolves to a chrome
        // zone — the bar stays operable while the modal is up.
        $firstInk = 0;
        foreach ($this->popupClusters($lines[0]) as [$cluster, $width]) {
            if (trim($cluster) !== '') {
                break;
            }
            $firstInk += $width;
        }
        self::assertNotNull(TuiRenderer::chromeZoneAt($firstInk + 1, 1), 'the menu bar stays clickable');

        // The box's first zone (a tab): a click at the offset the renderer
        // registered it at resolves to that `media:tab:` id — zone map and
        // painted corner agree.
        $form = $app->mediaForm;
        self::assertNotNull($form);
        [$boxCols, $boxRows] = $form->naturalSize($theme, 200, 47);
        $top = $this->popupRowIndex($lines, self::BOX_CORNER);
        $left = $this->popupCellOf($lines[$top], self::BOX_CORNER);
        [$zoneRow, $from, $to, $zoneId] = $form->zones($theme, $boxCols, $boxRows)[0];
        self::assertStringStartsWith(MediaForm::TAB_ZONE, $zoneId);

        $zone = TuiRenderer::chromeZoneAt($left + $from + 1, $top + $zoneRow + 1);
        self::assertNotNull($zone, 'the painted tab is clickable at its offset');
        self::assertSame($zoneId, $zone->id);
    }

    public function testPadBandSweepsZoneTagsOutOfTheBackdropBeforeTheCellCut(): void
    {
        $cols = 24;
        $rows = 3;
        $band = "header row\n"
            . 'aa ' . Mark::zone('strip:reviewer', 'live') . ' bb'
            . "\nfooter row";

        $padded = (string) (new \ReflectionMethod(TuiRenderer::class, 'padBand'))
            ->invoke(null, $band, $cols, $rows);

        $lines = explode("\n", $padded);
        self::assertCount($rows, $lines);
        foreach ($lines as $line) {
            self::assertSame($cols, Width::string($line), 'every backdrop line is squared to the band width');
            self::assertStringNotContainsString("\u{e000}", $line, 'no sentinel half rides into Veil to be split');
            self::assertStringNotContainsString("\u{e001}", $line, 'no sentinel half rides into Veil to be split');
        }

        self::assertStringNotContainsString('strip:reviewer', $padded, 'no zone id bytes survive in the backdrop');
        self::assertStringContainsString('live', $lines[1], 'the painted content between the sentinels survives');
        self::assertStringContainsString('aa', $lines[1]);
        self::assertStringContainsString('bb', $lines[1]);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function popupApp(?Chat $chat = null): App
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('TestProvider');

        $app = App::new($provider, 'test-model');

        return $chat === null ? $app : $app->withChat($chat);
    }

    /** The row index (0-based) of the first frame line carrying `$needle`. */
    private function popupRowIndex(array $lines, string $needle): int
    {
        foreach ($lines as $index => $line) {
            if (str_contains($line, $needle)) {
                return $index;
            }
        }

        self::fail("frame never painted [{$needle}]");
    }

    /** The display cell where `$needle` starts in a stripped line. */
    private function popupCellOf(string $line, string $needle): int
    {
        $at = mb_strpos($line, $needle);
        self::assertIsInt($at);

        return Width::string((string) mb_substr($line, 0, $at));
    }

    /** `$count` terminal cells of a stripped line starting at cell `$from`. */
    private function popupCells(string $line, int $from, int $count): string
    {
        $out = '';
        $used = 0;
        foreach ($this->popupClusters($line) as [$cluster, $width]) {
            if ($used >= $from + $count) {
                break;
            }
            if ($used >= $from) {
                $out .= $cluster;
            }
            $used += $width;
        }

        return $out;
    }

    /**
     * Terminal-cell walk of a stripped line: [cluster, width] pairs. Box
     * glyphs, the title emoji, and plain letters score their real widths, so
     * zone cells compare on cells rather than bytes.
     *
     * @return iterable<array{0: string, 1: int}>
     */
    private function popupClusters(string $line): iterable
    {
        $offset = 0;
        $length = strlen($line);
        while ($offset < $length) {
            $cluster = preg_match('/./us', $line, $m, 0, $offset) === 1 ? $m[0] : $line[$offset];
            yield [$cluster, Width::string($cluster)];
            $offset += strlen($cluster);
        }
    }

    private function popupRedPng(): string
    {
        $image = imagecreatetruecolor(8, 8);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 30, 30));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
