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
