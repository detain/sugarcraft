<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Tui\Components\ChatPane;
use SugarCraft\Crush\Tui\Components\InputPane;
use SugarCraft\Crush\Tui\Components\MenuBar;
use SugarCraft\Crush\Tui\Pane;
use SugarCraft\Crush\Tui\Renderer;
use SugarCraft\Layout\Dock\DockLayout;
use SugarCraft\Sprinkles\Style;

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

    public function testMenuBarContainsAllPaneTabs(): void
    {
        $app = $this->makeApp(Pane::Chat);

        $output = MenuBar::render($app);

        // Menu bar should list all available panes as tabs
        $this->assertStringContainsString('[Chat]', $output);
        $this->assertStringContainsString('[Files]', $output);
        $this->assertStringContainsString('[Tools]', $output);
        $this->assertStringContainsString('[Skills]', $output);
        $this->assertStringContainsString('[Agents]', $output);
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
    // MenuBar pane-tab visual states (docking L2)
    // =========================================================================

    /**
     * The strip carries three legible states (docking L2 feature 2):
     * docked+focused = primary bold underline, docked = plain foreground,
     * undocked = muted. The expected bytes are re-spelled here per state —
     * same Style API, independently chosen chain — so a src mutation that
     * swaps one state's styling for another's reddens exactly here.
     */
    public function testPaneTabStatesAreHighlightedFocusedAndDimmedWhenDisabled(): void
    {
        // Default dock: Files left, nothing else; focus is Chat.
        $app = $this->makeApp(Pane::Chat);
        $theme = $app->theme();
        $output = MenuBar::render($app);

        $focused = Style::new()->foreground($theme->shellPrimary)->bold()->underline();
        $enabled = Style::new()->foreground($theme->shellForeground);
        $disabled = Style::new()->foreground($theme->shellMuted);

        $this->assertStringContainsString($focused->render('[Chat]'), $output, 'Chat focused = primary+bold+underline');
        $this->assertStringContainsString($enabled->render('[Files]'), $output, 'docked Files = enabled highlight');
        $this->assertStringContainsString($disabled->render('[Tools]'), $output, 'undocked Tools = dimmed');
        $this->assertStringContainsString($disabled->render('[Settings]'), $output, 'undocked Settings = dimmed');

        $this->assertStringNotContainsString($disabled->render('[Files]'), $output, 'docked Files must NOT read as disabled');
        $this->assertStringNotContainsString($enabled->render('[Tools]'), $output, 'undocked Tools must NOT read as enabled');
    }

    public function testFocusingADockedPaneMovesTheFocusDecorationWithThePane(): void
    {
        $app = $this->makeApp(Pane::Files);
        $theme = $app->theme();
        $output = MenuBar::render($app);

        $focused = Style::new()->foreground($theme->shellPrimary)->bold()->underline();

        $this->assertStringContainsString($focused->render('[Files]'), $output);
        // Chat is always enabled but no longer focused: the enabled state,
        // never the muted one.
        $this->assertStringContainsString(Style::new()->foreground($theme->shellForeground)->render('[Chat]'), $output);
        $this->assertStringNotContainsString(Style::new()->foreground($theme->shellMuted)->render('[Chat]'), $output);
    }

    public function testChatTabIsNeverDimmedEvenWithAnEmptyDock(): void
    {
        // Every dockable pane undocked: the center column is still there,
        // so Chat keeps the enabled styling.
        $app = $this->makeApp(Pane::Chat)->withDock(DockLayout::new('chat'));
        $output = MenuBar::render($app);
        $theme = $app->theme();

        $muted = Style::new()->foreground($theme->shellMuted);
        foreach ([Pane::Files, Pane::Tools, Pane::Skills, Pane::Agents, Pane::Settings] as $pane) {
            $this->assertStringContainsString($muted->render('[' . $pane->label() . ']'), $output, $pane->label() . ' undocked = dimmed');
        }
        $this->assertStringNotContainsString($muted->render('[Chat]'), $output);
    }

    public function testMarkedBarCarriesPaneTabZonesAndPaintedBarCarriesNone(): void
    {
        $app = $this->makeApp(Pane::Chat);

        $marked = MenuBar::renderMarked($app);
        foreach (Pane::tabCycle() as $pane) {
            $this->assertStringContainsString(
                MenuBar::PANE_TAB_ZONE_PREFIX . $pane->value,
                $marked,
                'scan bar marks ' . $pane->value,
            );
        }

        // The painted pass stays sentinel-free — the frame must reach the
        // terminal without Private-Use cells.
        $this->assertStringNotContainsString(MenuBar::PANE_TAB_ZONE_PREFIX, MenuBar::render($app));
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

        $this->assertStringContainsString('Welcome to SugarCrush', $output);
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
        $this->assertStringContainsString('Type your message...', $output);
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
}
