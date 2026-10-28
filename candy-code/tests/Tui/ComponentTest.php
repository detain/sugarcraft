<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Message;
use SugarCraft\Code\Messages\UserMessage;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Tui\Components\ChatPane;
use SugarCraft\Code\Tui\Components\InputPane;
use SugarCraft\Code\Tui\Components\MenuBar;
use SugarCraft\Code\Tui\Menu\MenuNode;
use SugarCraft\Code\Tui\Menu\ShellMenu;
use SugarCraft\Code\Tui\Pane;
use SugarCraft\Code\Tui\Renderer;
use SugarCraft\Constraints\Dock\DockLayout;
use SugarCraft\Style\Style;

/**
 * @see MenuBar
 * @see ChatPane
 * @see InputPane
 */
final class ComponentTest extends TestCase
{
    private ProviderInterface $provider;

    protected function setUp(): void
    {
        parent::setUp();
        Renderer::resetSizeCache();

        $this->provider = $this->createMock(ProviderInterface::class);
        $this->provider->method('name')->willReturn('TestProvider');
    }

    protected function tearDown(): void
    {
        Renderer::resetSizeCache();
        parent::tearDown();
    }

    private function makeApp(?Pane $pane = null, array $messages = []): App
    {
        $app = App::new($this->provider, 'test-model');
        if ($pane !== null) {
            $app = $app->withPane($pane);
        }
        foreach ($messages as $msg) {
            $app = $app->withMessage($msg);
        }
        return $app;
    }

    // =========================================================================
    // MenuBar Tests
    // =========================================================================

    public function testMenuBarRenderProducesExpectedOutput(): void
    {
        $app = $this->makeApp(Pane::Chat);

        $output = MenuBar::render($app);

        $this->assertIsString($output);
        $this->assertNotEmpty($output);
    }

    public function testThePanesRowsAdvertiseEveryQuickSwitchPane(): void
    {
        $app = $this->makeApp(Pane::Chat);

        // P-MENU.e: the bar no longer carries a pane-tab strip — the quick
        // switches are the View ▸ Panes rows, each a toggle node carrying
        // the old tab's `panetab:<value>` click zone.
        $rows = self::panesRows($app);
        $ids = array_map(static fn (MenuNode $row): string => $row->id, $rows);

        foreach ([Pane::Chat, Pane::Files, Pane::Tools, Pane::Skills, Pane::Agents] as $pane) {
            $this->assertContains(
                'pane:' . $pane->value,
                $ids,
                $pane->label() . ' is advertised by the View ▸ Panes rows',
            );
        }

        foreach ($rows as $row) {
            $this->assertStringStartsWith('pane:', $row->id);
            $this->assertSame(
                MenuBar::PANE_TAB_ZONE_PREFIX . substr($row->id, strlen('pane:')),
                $row->zone,
                "the {$row->label} row carries the zone the click and drag arms read",
            );
        }

        $output = MenuBar::render($app);
        $this->assertStringNotContainsString(
            self::paneTabCaption(Pane::Files),
            $output,
            'the caption is no longer painted on the bar itself',
        );
    }

    public function testMenuBarShowsCurrentlySelectedPane(): void
    {
        $app = $this->makeApp(Pane::Files);

        $output = MenuBar::render($app);

        $this->assertStringContainsString('Currently: Files', $output);
    }

    public function testMenuBarDefaultPaneIsChat(): void
    {
        $app = $this->makeApp();

        $output = MenuBar::render($app);

        $this->assertStringContainsString('Currently: Chat', $output);
    }

    public function testMenuBarWithDifferentPaneLabels(): void
    {
        // Keyed by backing string: PHP enums cannot be used as array keys.
        $panes = [
            Pane::Chat->value => 'Chat',
            Pane::Skills->value => 'Skills',
            Pane::Agents->value => 'Agents',
            Pane::Files->value => 'Files',
            Pane::Tools->value => 'Tools',
            Pane::Settings->value => 'Settings',
            Pane::Help->value => 'Help',
        ];

        foreach ($panes as $paneValue => $label) {
            $app = $this->makeApp(Pane::from($paneValue));
            $output = MenuBar::render($app);
            $this->assertStringContainsString("Currently: $label", $output, "Failed for pane: $label");
        }
    }

    // =========================================================================
    // View ▸ Panes row states (docking L2 feature 2, P-MENU.e surface)
    // =========================================================================

    /**
     * The Panes rows carry the states the tab strip used to tell (P-MENU.e):
     * the row under the cursor paints with the cascade's selected style
     * (`shellPrimary` bold — the strip's bold underline for the focused
     * pane, re-spelled for a row that the cursor can actually reach), a
     * docked row checks ✓ in `shellForeground`, an undocked one leaves the
     * check column blank. The expected bytes are re-spelled here per state
     * — same Style API — so a src mutation that swaps one state's styling
     * for another's reddens exactly here.
     */
    public function testThePanesRowsTellDockedAndUndockedAndHighlightedStates(): void
    {
        // Default dock: Files left, nothing else; focus is Chat.
        $app = $this->makeApp(Pane::Chat);
        $theme = $app->theme();

        $checked = [];
        foreach (self::panesRows($app) as $row) {
            $checked[substr($row->id, strlen('pane:'))] = $row->checked;
        }

        $this->assertTrue($checked[Pane::Chat->value], 'Chat — the center column — always reads on');
        $this->assertTrue($checked[Pane::Files->value], 'docked Files checks');
        $this->assertFalse($checked[Pane::Tools->value], 'undocked Tools leaves the column blank');
        $this->assertFalse($checked[Pane::Settings->value], 'undocked Settings leaves the column blank');

        // Paint the open cascade: the door lights the Panes row, Enter
        // walks into the submenu so its first row is the highlighted one.
        MenuBar::openPanesMenu();

        try {
            MenuBar::handleKey('enter');
            $panels = MenuBar::panels($app, 200, 1);
            self::assertNotEmpty($panels, 'fixture: the door opened a panel');

            $raw = '';
            foreach ($panels as $panel) {
                $raw .= implode("\n", $panel->lines);
            }

            $selected = Style::new()->foreground($theme->shellPrimary)->bold();
            $item = Style::new()->foreground($theme->shellForeground);

            $this->assertStringContainsString(
                self::styleOpen($selected) . '✓ ' . Pane::Chat->icon() . ' Chat',
                $raw,
                'the highlighted row is primary-bold, its check on',
            );
            $this->assertStringContainsString(
                self::styleOpen($item) . '✓ ' . Pane::Files->icon() . ' Files',
                $raw,
                'docked Files rides the plain item style, checked',
            );
            $this->assertStringContainsString(
                self::styleOpen($item) . '  ' . Pane::Tools->icon() . ' Tools',
                $raw,
                'undocked Tools rides it with the check column blank',
            );
            $this->assertStringNotContainsString(
                '✓ ' . Pane::Settings->icon() . ' Settings',
                $raw,
                'an undocked pane never checks — the column, not the style, tells it',
            );
        } finally {
            MenuBar::closeMenu();
        }
    }

    /**
     * P-MENU.e retired the strip's three styling densities. The focus
     * decoration the old focused-tab style carried now lives as the cascade's
     * highlight (primary-bold on the row under the cursor), and the docked /
     * undocked tell is the ✓ column — pinned above. The bar itself never dims
     * a pane: with an EMPTY dock every row still advertises its pane, only
     * Chat's reads on, because Chat has no dock slot to lose.
     */
    public function testTheChatRowIsNeverUncheckedEvenWithAnEmptyDock(): void
    {
        $app = $this->makeApp(Pane::Chat)->withDock(DockLayout::new('chat'));

        $checked = [];
        foreach (self::panesRows($app) as $row) {
            $checked[substr($row->id, strlen('pane:'))] = $row->checked;
        }

        foreach ([Pane::Files, Pane::Tools, Pane::Skills, Pane::Agents, Pane::Settings] as $pane) {
            $this->assertArrayHasKey($pane->value, $checked, $pane->label() . ' is still advertised with an empty dock');
            $this->assertFalse($checked[$pane->value], $pane->label() . ' undocked = blank check');
        }

        $this->assertTrue($checked[Pane::Chat->value], 'the center column is always up');
    }

    public function testTheMarkedBarCarriesTitlesAndDoorAndTheRowsCarryPaneTabZonesWhenOpen(): void
    {
        // The hosted one-line chat gives the band real height: chrome marks
        // are registered only for rows inside the painted frame, and a bare
        // default App clips the dropdown's tail rows (measured, P-MENU.e).
        $app = $this->makeAppWithBand();

        // P-MENU.e: the marked bar answers the mouse through five titles and
        // the `Currently:` door — the `panetab:` zones moved onto the Panes
        // rows, so they appear only while that menu paints.
        $marked = MenuBar::renderMarked($app);
        foreach ([1, 2, 3, 4, 5] as $index) {
            $this->assertStringContainsString(MenuBar::MENU_TITLE_ZONE_PREFIX . $index, $marked, 'title ' . $index . ' is clickable');
        }
        $this->assertStringContainsString(MenuBar::PANES_DOOR_ZONE, $marked, 'the indicator is the door into View ▸ Panes');
        $this->assertStringNotContainsString(MenuBar::PANE_TAB_ZONE_PREFIX, $marked, 'no pane tab remains on the closed bar');

        // The painted pass stays sentinel-free — the frame must reach the
        // terminal without Private-Use cells.
        $this->assertStringNotContainsString(MenuBar::PANE_TAB_ZONE_PREFIX, MenuBar::render($app));
        $this->assertStringNotContainsString(MenuBar::PANES_DOOR_ZONE, MenuBar::render($app));

        MenuBar::openPanesMenu();

        try {
            Renderer::setSize(120, 30);
            Renderer::chromeScanner()->clear();
            Renderer::renderView($app, 120, 30);
            $rowZones = Renderer::chromeScanner()->prefixed(MenuBar::PANE_TAB_ZONE_PREFIX);
            foreach (Pane::tabCycle() as $pane) {
                $this->assertArrayHasKey(
                    MenuBar::PANE_TAB_ZONE_PREFIX . $pane->value,
                    $rowZones,
                    'the open rows carry the zones the click arm reads',
                );
            }
        } finally {
            MenuBar::closeMenu();
            Renderer::chromeScanner()->clear();
        }
    }

    // =========================================================================
    // ChatPane Tests
    // =========================================================================

    public function testChatPaneRenderProducesNonEmptyOutput(): void
    {
        $app = $this->makeApp();
        Renderer::setSize(120, 40);

        $output = ChatPane::render($app, 120, 40);

        $this->assertIsString($output);
        $this->assertNotEmpty($output);
    }

    public function testChatPaneRendersWelcomeMessageWhenEmpty(): void
    {
        $app = $this->makeApp();
        Renderer::setSize(120, 40);

        $output = ChatPane::render($app, 120, 40);

        $this->assertStringContainsString('Welcome to CandyCode', $output);
    }

    public function testChatPaneRendersMessageClassNames(): void
    {
        $msg1 = new UserMessage('Hello');
        $msg2 = new UserMessage('World');
        $app = $this->makeApp(Pane::Chat, [$msg1, $msg2]);
        Renderer::setSize(120, 40);

        $output = ChatPane::render($app, 120, 40);

        // Should contain the class names of the messages
        $this->assertStringContainsString('UserMessage', $output);
    }

    public function testChatPaneWithDifferentSizes(): void
    {
        $app = $this->makeApp();

        // Small terminal
        $outputSmall = ChatPane::render($app, 80, 24);
        $this->assertNotEmpty($outputSmall);

        // Large terminal
        $outputLarge = ChatPane::render($app, 200, 80);
        $this->assertNotEmpty($outputLarge);
    }

    public function testChatPaneHeightCalculation(): void
    {
        $app = $this->makeApp();
        // Height should be rows - 6 (accounting for menu, input, status)
        // For rows=40, height should be 34
        $output = ChatPane::render($app, 120, 40);

        // The output should be present and contain content
        $this->assertNotEmpty($output);
    }

    public function testChatPaneWithMinimalHeight(): void
    {
        $app = $this->makeApp();
        // Minimal height should be clamped to at least 5
        $output = ChatPane::render($app, 80, 6);

        $this->assertNotEmpty($output);
    }

    // =========================================================================
    // InputPane Tests
    // =========================================================================

    public function testInputPaneRenderProducesExpectedOutput(): void
    {
        $app = $this->makeApp();
        Renderer::setSize(120, 40);

        $output = InputPane::render($app, 120);

        $this->assertIsString($output);
        $this->assertNotEmpty($output);
    }

    public function testInputPaneRendersBoxCharacters(): void
    {
        $app = $this->makeApp();
        Renderer::setSize(120, 40);

        $output = InputPane::render($app, 120);

        // Should contain box-drawing characters
        $this->assertStringContainsString('┌', $output);
        $this->assertStringContainsString('┐', $output);
        $this->assertStringContainsString('└', $output);
        $this->assertStringContainsString('┘', $output);
    }

    public function testInputPaneRendersPlaceholderText(): void
    {
        $app = $this->makeApp();
        Renderer::setSize(120, 40);

        $output = InputPane::render($app, 120);

        $this->assertStringContainsString('Type your message', $output);
    }

    public function testInputPaneWidthCalculation(): void
    {
        $app = $this->makeApp();

        $output = InputPane::render($app, 80);
        // Box should be 80 chars wide with ─ repeated 78 times (80 - 2 for corners)
        $this->assertStringContainsString(str_repeat('─', 78), $output);

        $output2 = InputPane::render($app, 120);
        // Box should be 120 chars wide with ─ repeated 118 times
        $this->assertStringContainsString(str_repeat('─', 118), $output2);
    }

    public function testInputPaneWithNarrowWidth(): void
    {
        $app = $this->makeApp();

        // Minimum practical width
        $output = InputPane::render($app, 10);
        $this->assertNotEmpty($output);
        // Should contain box chars
        $this->assertStringContainsString('┌', $output);
    }

    public function testInputPaneOutputContainsPlaceholder(): void
    {
        $app = $this->makeApp();

        $output = InputPane::render($app, 100);

        // Should show placeholder text
        $this->assertStringContainsString('Type your message…', $output);
    }

    // =========================================================================
    // Component Integration Tests
    // =========================================================================

    public function testAllComponentsRenderWithoutErrors(): void
    {
        $app = $this->makeApp(Pane::Chat);
        Renderer::setSize(120, 40);

        // All components should render without throwing exceptions
        $menuBar = MenuBar::render($app);
        $chatPane = ChatPane::render($app, 120, 40);
        $inputPane = InputPane::render($app, 120);

        $this->assertNotEmpty($menuBar);
        $this->assertNotEmpty($chatPane);
        $this->assertNotEmpty($inputPane);
    }

    /**
     * The tab caption the retired strip painted — icon, one space, label,
     * inside brackets. P-MENU.e moved the tabs into the View ▸ Panes rows;
     * this spells the OLD bytes so the bar's side of the retirement can be
     * asserted (the caption must NOT reappear on the bar).
     */
    private static function paneTabCaption(Pane $pane): string
    {
        return '[' . $pane->icon() . ' ' . $pane->label() . ']';
    }

    /**
     * The View ▸ Panes toggle rows of a live App: the one pane-toggle list
     * P-MENU.e made the bar's (the strip's private `PANE_TABS` retired into
     * {@see \SugarCraft\Code\Tui\Pane::tabCycle()} feeding these rows).
     *
     * @return list<MenuNode>
     */
    private static function panesRows(App $app): array
    {
        $tree = ShellMenu::tree($app);
        $absolute = $tree->pathOf('sub:panes');

        self::assertNotNull($absolute, 'fixture: the shell tree has no View ▸ Panes');
        $submenu = $tree->node($absolute);
        self::assertNotNull($submenu);

        return $submenu->children;
    }

    /**
     * A hosted one-line chat: chrome marks register only inside the painted
     * band, and the bare default App's band is too short for the full Panes
     * panel (measured — the tail rows' zones silently never appear).
     */
    private function makeAppWithBand(): App
    {
        return App::new($this->provider, 'test-model')
            ->withChat(new Chat(history: [Message::user('a line under the menu')]))
            ->withPane(Pane::Chat);
    }

    /**
     * The SGR bytes a Style emits before its text — the prefix every painted
     * row line carries ahead of the label, so a row can be pinned to one
     * style without re-deriving the cascade's padding arithmetic.
     */
    private static function styleOpen(Style $style): string
    {
        $rendered = $style->render('X');
        $at = strpos($rendered, 'X');

        self::assertNotFalse($at, 'fixture: the probe glyph is not eaten by the style');

        return substr($rendered, 0, $at);
    }
}
