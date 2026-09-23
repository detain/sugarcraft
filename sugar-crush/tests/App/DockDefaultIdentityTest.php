<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\App;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Tui\Pane;
use SugarCraft\Crush\Tui\Renderer as TuiRenderer;

/**
 * The untouched default frame keeps the LEGACY sidebar measure, to the cell.
 *
 * The docking feature must not move a single pixel of a frame nobody has
 * docked into — that is the goldens constraint the pre-feature baseline was
 * captured under — so while {@see App::dock()} is byte-for-byte
 * {@see App::defaultDock()} the side is measured exactly as the pre-docking
 * renderer measured it: `max(20, floor(bandCols / 4))`. This file pins that
 * rule twice: at the renderer's own measure (reflection on
 * {@see TuiRenderer}-internal `sideWidth()`), and at the painted frame, where
 * the box's top-right corner sits four columns past it — the pane widgets'
 * own box chrome, the same +4 the shipped frame has always carried. A future
 * widening of the "untouched" test — or a seed that fires too early — reds
 * here with a number instead of surfacing as a golden diff.
 *
 * The complementary half (a mutated dock takes its width from
 * {@see \SugarCraft\Layout\Dock\DockLayout::resolve()}) is pinned in
 * {@see DockSeedTest}.
 */
final class DockDefaultIdentityTest extends TestCase
{
    /** Columns of box chrome the pane widgets paint on top of the requested width. */
    private const PAINTED_BOX_CHROME_COLS = 4;

    private ProviderInterface $provider;

    protected function setUp(): void
    {
        parent::setUp();
        TuiRenderer::setSize(200, 60); // deterministic default (round-61 flake; see tests/bootstrap.php)
        $this->provider = $this->createMock(ProviderInterface::class);
        $this->provider->method('name')->willReturn('TestProvider');
    }

    protected function tearDown(): void
    {
        TuiRenderer::setSize(200, 60);
        parent::tearDown();
    }

    /**
     * The rule at its source: with the default dock untouched, sideWidth()
     * answers the legacy quarter at every probe width, single pane or not.
     */
    #[DataProvider('legacyWidths')]
    public function testTheUntouchedDefaultMeasuresTheLegacyQuarter(int $cols, int $legacy): void
    {
        $app = App::new($this->provider, 'test-model')->withChat(new Chat())->withPane(Pane::Files);

        self::assertSame($legacy, self::sideWidth($app, Pane::Files, $cols));
    }

    /**
     * The rule at the pixels (single docked pane — the Files frame every
     * launch opens with): the box corner lands at legacy + own chrome.
     */
    #[DataProvider('legacyWidths')]
    public function testTheDefaultSinglePaneFramePaintsTheLegacyQuarter(int $cols, int $legacy): void
    {
        $frame = $this->frame(Pane::Files, $cols);

        self::assertSameCorner($legacy, self::headerRow($frame, '╭ files '));
    }

    /**
     * The DEFAULT frame can also stack — focusing Tools paints Files docked
     * plus Tools transient — and that stacked default must hold the same
     * legacy measure, because the shipped goldens contain it too.
     */
    #[DataProvider('legacyWidths')]
    public function testTheDefaultStackedFrameAlsoPaintsTheLegacyQuarter(int $cols, int $legacy): void
    {
        $frame = $this->frame(Pane::Tools, $cols);

        self::assertSameCorner($legacy, self::headerRow($frame, '╭ files '));
        self::assertSameCorner($legacy, self::headerRow($frame, '╭ tools '));
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function legacyWidths(): array
    {
        return [
            'narrow floor' => [80, 20],   // floor = 20, max() wins at the clamp edge
            'quarter exact' => [100, 25], // 25
            'odd width' => [127, 31],     // floor(31.75) = 31
            'wide' => [200, 50],          // 50
        ];
    }

    private function frame(Pane $pane, int $cols): string
    {
        $app = App::new($this->provider, 'test-model')
            ->withChat(new Chat())
            ->withPane($pane);

        return Ansi::strip(TuiRenderer::renderView($app, $cols, 40)->body);
    }

    private static function sideWidth(App $app, Pane $pane, int $cols): int
    {
        return (new ReflectionMethod(TuiRenderer::class, 'sideWidth'))->invoke(null, $app, $pane, $cols, 40);
    }

    /**
     * Painted box width = corner column + 1; the widgets inflate the requested
     * measure by their own border chrome, constant across every width probed.
     */
    private static function assertSameCorner(int $legacy, string $headerLine): void
    {
        $corner = mb_strpos($headerLine, '╮');
        self::assertNotFalse($corner, 'the box header must close with ╮');
        self::assertSame(
            $legacy + self::PAINTED_BOX_CHROME_COLS,
            $corner + 1,
            'the default side must paint exactly the legacy quarter plus the widgets\' constant box chrome',
        );
    }

    private static function headerRow(string $frame, string $needle): string
    {
        foreach (explode("\n", $frame) as $line) {
            if (str_starts_with($line, $needle)) {
                return $line;
            }
        }

        self::fail("frame carries no {$needle} header row");
    }
}
