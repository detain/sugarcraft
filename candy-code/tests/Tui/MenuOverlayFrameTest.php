<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Commands\CommandRegistry;
use SugarCraft\Code\Message;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Renderer as LiveRenderer;
use SugarCraft\Code\Tui\Components\MenuBar;
use SugarCraft\Code\Tui\Menu\MenuPanel;
use SugarCraft\Code\Tui\Pane;
use SugarCraft\Code\Tui\Renderer as TuiRenderer;
use SugarCraft\Code\Tui\Settings\OpenSettingsMsg;
use SugarCraft\Code\Tui\Settings\SettingsSources;
use SugarCraft\Code\Tui\VideoPlayer\VideoPlayerScreen;
use SugarCraft\Code\Tests\Support\VideoPlayerScriptedDecoder;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;
use SugarCraft\Video\Decode\RgbFrame;
use SugarCraft\Video\Msg\TickMsg;
use SugarCraft\Video\Player;
use SugarCraft\Video\Render\Mode;

/**
 * The menu overlay seam every shell surface shares (P-MENU.c).
 *
 * Three bugs this replaced, each pinned by a regression leg below:
 *
 * - the Agents dashboard never spliced the dropdown into its frame, yet the
 *   key that opens it (F10) and the click that opens it (the title zone) are
 *   both live on that surface — the menu opened invisibly;
 * - the video-player frame registered dropdown ROW zones through the scan
 *   while painting no panel over the picture, so a click landed on a video
 *   row and ran a command the user could not see;
 * - the splice had no width clamp: with a narrow terminal the menu names give
 *   way (compose() sheds them), yet {@see MenuBar::activeMenuColumn()} kept
 *   counting the unpainted ones, so the box could be anchored past the edge
 *   and the spliced row reached wider than the terminal.
 *
 * The invariant that answers all three: the dropdown is ONE panel kit product
 * ({@see MenuBar::panels()} / {@see MenuBar::panelsMarked()}) and every frame
 * path both paints and marks the same geometry — a registered row zone is
 * exactly a painted row, and no painted row escapes its $cols.
 */
final class MenuOverlayFrameTest extends TestCase
{
    private const VARS = ['CANDY_CODE_DISABLE_MOUSE', 'CANDY_CODE_DISABLE_MOUSE_CLICKS'];
    private const CLIP = '/media/sess/clip.mp4';

    protected function setUp(): void
    {
        parent::setUp();
        $this->chromeReset();
    }

    protected function tearDown(): void
    {
        $this->chromeReset();
        TuiRenderer::setSize(200, 60); // leave the process-global size cache as found (round-61 flake law)
        parent::tearDown();
    }

    /** @return array<string, array{0: string}> */
    public static function framePaths(): array
    {
        return [
            'chat band' => ['chat'],
            'agents dashboard' => ['agents'],
            'settings editor' => ['settings'],
            'media form' => ['media'],
            'video player' => ['video'],
        ];
    }

    /**
     * Every surface paints the open dropdown and marks exactly what it
     * painted: each item row is visible, its zone sits on that row, and no
     * line is wider than the terminal.
     */
    #[DataProvider('framePaths')]
    public function testEveryFramePathPaintsTheDropdownItMarks(string $surface): void
    {
        $app = $this->bandApp($surface);
        MenuBar::openMenu(1);
        $frame = TuiRenderer::renderView($app, 120, 30)->body;

        $plain = Ansi::strip($frame);
        $lines = explode("\n", $plain);
        foreach ($lines as $line) {
            self::assertLessThanOrEqual(120, Width::string($line), $surface . ': no frame line may exceed the terminal');
        }

        $items = MenuBar::menuItems($this->categoryLabel(1));
        self::assertNotEmpty($items, $surface);

        // The seam ran on this surface: the panel's top border always splices
        // into the first frame row under the bar, even where the frame's
        // bottom edge then buries every label row.
        self::assertStringContainsString('┌', $lines[1] ?? '', $surface . ': panel border row was not spliced');

        $zones = TuiRenderer::chromeScanner()->prefixed(MenuBar::MENU_ITEM_ZONE_PREFIX);

        foreach ($items as $row => $label) {
            $paintedRow = null;
            foreach ($lines as $i => $line) {
                if (str_contains($line, '│ ' . $label)) {
                    $paintedRow = $i + 1;
                    break;
                }
            }

            $zone = $zones[MenuBar::MENU_ITEM_ZONE_PREFIX . $row] ?? null;

            // Paint ⇔ mark, row by row: a label visible in the frame must be
            // clickable, and a registered zone must sit on the row the panel
            // actually painted it on. The `…` continuation row and the rows
            // the bottom clip buried answer to no item.
            self::assertSame(
                $paintedRow !== null,
                $zone !== null,
                $surface . ': item "' . $label . '" painted=' . ($paintedRow ?? 'no') . ' zone=' . ($zone !== null ? 'yes' : 'no'),
            );

            if ($zone === null) {
                continue;
            }

            self::assertSame($paintedRow, $zone->startRow, $surface . ': zone row vs painted row for "' . $label . '"');
            self::assertStringContainsString('│ ' . $label, (string) ($lines[$zone->startRow - 1] ?? ''), $surface . ': zone ' . $zone->id . ' must sit on a row the panel actually painted');
        }
    }

    /**
     * The graphics leg of the headline law (P-MENU.c review MINOR, post-weld
     * cure): where {@see VideoPlayerScreen::clipsLines()} is false the video
     * frame path refuses paint AND mark together — box glyphs never splice
     * into kitty/sixel/iterm2 protocol bytes, and no dropdown row registers
     * a click zone over a picture the frame did not paint. The refusals are
     * the two guards in {@see TuiRenderer::renderVideoPlayer()}: the
     * `if ($cellGrid)` around overlayMenu (paint leg) and the `: null` arm
     * of the panelTop ternary (mark leg — scanChrome builds marks from the
     * panel kit, not from the painted frame, so the ternary alone decides
     * them).
     *
     * Three live states of a graphics screen are pinned, because the splice
     * budget makes each guard observable on a different one: the preparing
     * placeholder and the failed card are multi-row text frames where a
     * removed paint guard splices a visible `┌` border; the ready screen is
     * ONE logical protocol write whose two-row frame leaves a removed paint
     * guard only the `│ …` continuation row — still spliced into the blob's
     * row band — to corrupt; and the four-row failed card is the height at
     * which a removed panelTop refusal actually registers a menuitem zone.
     * The shorter frames bury every label row, which is exactly why the
     * shared paint⇔mark provider test could not carry this leg: with all
     * items unpainted it stays green on a deleted ternary.
     *
     * Control: the same shell with a CLIPPING mode splices its border, so
     * the refusals above name the mode, not a dead harness. (The control
     * marks no zones for the same budget reason — the provider test pins
     * paint⇔mark there.)
     */
    public function testTheGraphicsLegRefusesToPaintAndMarkTogether(): void
    {
        $app = $this->bandApp('chat');

        foreach ($this->graphicsScreens() as $label => $screen) {
            MenuBar::closeMenu();
            MenuBar::openMenu(1);
            TuiRenderer::chromeScanner()->clear();

            self::assertFalse($screen->clipsLines(), $label . ': graphics modes are the refusal premise');
            self::assertNotEmpty(
                MenuBar::panels($app->withVideoPlayer($screen), 120, 1),
                $label . ': a panel exists to be refused — the menu is genuinely open',
            );

            $plain = Ansi::strip(TuiRenderer::renderView($app->withVideoPlayer($screen), 120, 30)->body);

            self::assertStringNotContainsString('┌', $plain, $label . ': no border row may splice into protocol bytes');
            self::assertStringNotContainsString('│ …', $plain, $label . ': no overflow row may splice into protocol bytes either');
            self::assertSame(
                [],
                TuiRenderer::chromeScanner()->prefixed(MenuBar::MENU_ITEM_ZONE_PREFIX),
                $label . ': the graphics leg must not mark rows it did not paint',
            );

            $screen->stop();
        }

        MenuBar::closeMenu();
        MenuBar::openMenu(1);
        TuiRenderer::chromeScanner()->clear();
        $control = Ansi::strip(TuiRenderer::renderView(
            $app->withVideoPlayer(VideoPlayerScreen::preparing(self::CLIP, Mode::HalfBlock)),
            120,
            30,
        )->body);
        self::assertStringContainsString('┌', $control, 'control: the text leg splices — the refusal is the mode');
    }

    /**
     * The three states a graphics (non-clipping) video screen takes on the
     * live path: the async-build placeholder, the answered player with a
     * real encoded kitty blob (scripted, child-free decoder — never
     * ffmpeg, per VideoPlayerScreenTest), and the error card.
     *
     * @return array<string, VideoPlayerScreen>
     */
    private function graphicsScreens(): array
    {
        $frames = [];
        for ($i = 0; $i < 4; $i++) {
            $tint = chr(40 + $i * 30);
            $frames[] = new RgbFrame(str_repeat($tint, 80 * 60 * 3), 80, 60, null);
        }

        $player = Player::fromDecoder(new VideoPlayerScriptedDecoder($frames), 8, 3, 10.0, Mode::Kitty, 4, self::CLIP, paused: false);
        [$ready] = VideoPlayerScreen::ready(self::CLIP, $player, Mode::Kitty)->update(new TickMsg());

        return [
            'preparing' => VideoPlayerScreen::preparing(self::CLIP, Mode::Kitty),
            'ready' => $ready,
            'failed' => VideoPlayerScreen::failed(self::CLIP, 'ffprobe is dead', Mode::Kitty),
        ];
    }

    /**
     * The narrow-terminal leg: F10 opens menu one and the arrow keys walk the
     * bar through EVERY menu, painted or shed, so every index must render a
     * frame that still fits 80 columns. Before the seam the box was anchored
     * by counting names compose() had already shed, and the spliced rows ran
     * past the edge.
     */
    public function testNoOpenMenuPushesAnyFrameLinePastEightyColumns(): void
    {
        $app = $this->bandApp('chat');

        for ($index = 1; $index <= $this->menuCount(); ++$index) {
            MenuBar::closeMenu();
            MenuBar::openMenu($index);
            $frame = TuiRenderer::renderView($app, 80, 24)->body;

            foreach (explode("\n", Ansi::strip($frame)) as $line) {
                self::assertLessThanOrEqual(
                    80,
                    Width::string($line),
                    'menu ' . $index . ' open must not widen any frame line past 80 columns',
                );
            }
        }
    }

    /**
     * The panel's own contract, pinned at the seam the pre-kit splice used:
     * the box's left border sits one cell right of the title start
     * (byte-equivalence law), the row is where the caller asked for it, and a
     * far-shed anchor is pulled left instead of leaving the edge.
     */
    public function testThePanelCarriesTheGeometryTheOverlaySplicesAt(): void
    {
        $app = $this->bandApp('chat');
        MenuBar::openMenu(1);

        $panels = MenuBar::panels($app, 120, 1);
        self::assertCount(1, $panels);
        $panel = $panels[0];

        self::assertInstanceOf(MenuPanel::class, $panel);
        self::assertSame(MenuBar::activeMenuColumn() + 1, $panel->col, 'the border lands where the old splice put it');
        self::assertSame(1, $panel->row);
        self::assertSame(MenuBar::renderDropdown($app->theme()), $panel->lines);

        MenuBar::closeMenu();
        self::assertSame([], MenuBar::panels($app, 120, 1), 'a closed bar carries no panel to paint or mark');

        MenuBar::activateMenu($this->menuCount());
        $far = MenuBar::panels($app, 60, 1);
        self::assertCount(1, $far);
        self::assertLessThanOrEqual(
            60,
            $far[0]->col + $far[0]->width() - 1,
            'a shed anchor clamps inside the terminal instead of splicing past the edge',
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function chromeReset(): void
    {
        foreach (self::VARS as $var) {
            putenv($var);
        }

        MenuBar::closeMenu();
        VideoPlayerScreen::stopActive();
        TuiRenderer::resetSizeCache();
        TuiRenderer::chromeScanner()->clear();
        LiveRenderer::scanner()->clear();
        LiveRenderer::setZoneOrigin(0, 0);

        (new \ReflectionProperty(App::class, 'chromeClickTracker'))->setValue(null, null);
        (new \ReflectionProperty(Chat::class, 'clickTracker'))->setValue(null, null);
    }

    /** The surface under test as it sits under the shell chrome. */
    private function bandApp(string $surface): App
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('stub');
        $app = App::new($provider, 'test-model')
            ->withChat((new Chat(history: [Message::user('a line under the menu')]))->withSize(120, 30));

        if ($surface === 'settings') {
            [$app] = $app
                ->withSettingsSources(static fn (): SettingsSources => SettingsSources::fromLaunch(
                    '/nonexistent-root',
                    null,
                    null,
                    null,
                    ['CANDY_CODE_PROVIDER' => 'dev-sglang'],
                ))
                ->update(new OpenSettingsMsg());

            return $app;
        }

        return match ($surface) {
            'chat' => $app,
            'agents' => $app->withPane(Pane::Agents),
            'media' => $app->openMediaForm(),
            'video' => $app->withVideoPlayer(VideoPlayerScreen::preparing('/media/sess/clip.mp4', Mode::HalfBlock)),
            default => $app,
        };
    }

    /** The display name of the $index-th (1-based) derived menu. */
    private function categoryLabel(int $index): string
    {
        $names = [];
        foreach (CommandRegistry::all() as $spec) {
            $names[$spec->category] = true;
        }

        return array_keys($names)[$index - 1];
    }

    private function menuCount(): int
    {
        $names = [];
        foreach (CommandRegistry::all() as $spec) {
            $names[$spec->category] = true;
        }

        return count($names);
    }
}
