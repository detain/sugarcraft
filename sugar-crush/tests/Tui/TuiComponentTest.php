<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Tui\Components\AgentsPane;
use SugarCraft\Crush\Tui\Components\ChatPane;
use SugarCraft\Crush\Tui\Components\FilesPane;
use SugarCraft\Crush\Tui\Components\InputPane;
use SugarCraft\Crush\Tui\Components\MenuBar;
use SugarCraft\Crush\Tui\Components\SkillsPane;
use SugarCraft\Crush\Tui\Components\ToolsPane;
use SugarCraft\Crush\Tui\Pane;
use SugarCraft\Crush\Tui\Renderer;

/**
 * @see ChatPane
 * @see InputPane
 * @see MenuBar
 * @see SkillsPane
 * @see AgentsPane
 * @see FilesPane
 * @see ToolsPane
 */
final class TuiComponentTest extends TestCase
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

    private function makeApp(?Pane $pane = null, array $messages = [], array $contextFiles = [], array $enabledSkills = []): App
    {
        $app = App::new($this->provider, 'test-model');
        if ($pane !== null) {
            $app = $app->withPane($pane);
        }
        foreach ($messages as $msg) {
            $app = $app->withMessage($msg);
        }
        if ($contextFiles !== []) {
            $app = $app->withContextFiles($contextFiles);
        }
        if ($enabledSkills !== []) {
            $app = $app->withEnabledSkills($enabledSkills);
        }
        return $app;
    }

    // =========================================================================
    // ChatPane Tests (Extended)
    // =========================================================================

    /**
     * @testdox ChatPane::render() produces non-empty output
     */
    public function testChatPaneRenderProducesNonEmptyOutput(): void
    {
        $app = $this->makeApp();
        Renderer::setSize(120, 40);

        $output = ChatPane::render($app, 120, 40);

        $this->assertIsString($output);
        $this->assertNotEmpty($output);
    }

    /**
     * @testdox ChatPane::render() contains expected border styling
     */
    public function testChatPaneContainsExpectedBorderStyling(): void
    {
        $app = $this->makeApp(Pane::Chat);
        Renderer::setSize(120, 40);

        $output = ChatPane::render($app, 120, 40);

        // Should contain rounded border characters and 'chat' title
        $this->assertStringContainsString('┌', $output);
        $this->assertStringContainsString('┐', $output);
        $this->assertStringContainsString('└', $output);
        $this->assertStringContainsString('┘', $output);
        $this->assertStringContainsString('chat', $output);
    }

    /**
     * @testdox ChatPane::render() shows "Welcome to CandyCrush!" when no messages
     */
    public function testChatPaneShowsWelcomeMessageWhenNoMessages(): void
    {
        $app = $this->makeApp();
        Renderer::setSize(120, 40);

        $output = ChatPane::render($app, 120, 40);

        $this->assertStringContainsString('Welcome to CandyCrush', $output);
    }

    /**
     * @testdox ChatPane::render() border color changes based on pane focus
     */
    public function testChatPaneBorderColorChangesBasedOnPaneFocus(): void
    {
        Renderer::setSize(120, 40);

        // Chat pane focused - should have cyan/green border (#00ffaa)
        $appFocused = $this->makeApp(Pane::Chat);
        $outputFocused = ChatPane::render($appFocused, 120, 40);
        // The focus border uses \x1b[38;2;0;255;170m for #00ffaa
        $this->assertStringContainsString("\x1b[38;2;0;255;170m", $outputFocused);

        // Chat pane not focused - should have pink border (#ff66aa)
        $appUnfocused = $this->makeApp(Pane::Skills);
        $outputUnfocused = ChatPane::render($appUnfocused, 120, 40);
        // The unfocused border uses \x1b[38;2;255;102;170m for #ff66aa
        $this->assertStringContainsString("\x1b[38;2;255;102;170m", $outputUnfocused);
    }

    // =========================================================================
    // InputPane Tests (Extended)
    // =========================================================================

    /**
     * @testdox InputPane::render() produces non-empty output
     */
    public function testInputPaneRenderProducesNonEmptyOutput(): void
    {
        $app = $this->makeApp();
        Renderer::setSize(120, 40);

        $output = InputPane::render($app, 120);

        $this->assertIsString($output);
        $this->assertNotEmpty($output);
    }

    /**
     * @testdox InputPane::render() contains "input" in the output
     */
    public function testInputPaneContainsInputInOutput(): void
    {
        $app = $this->makeApp();
        Renderer::setSize(120, 40);

        $output = InputPane::render($app, 120);

        $this->assertStringContainsString('input', $output);
    }

    /**
     * @testdox InputPane::render() border color changes based on pane focus
     */
    public function testInputPaneBorderColorChangesBasedOnPaneFocus(): void
    {
        Renderer::setSize(120, 40);

        // Input pane focused
        $appFocused = $this->makeApp(Pane::Input);
        $outputFocused = InputPane::render($appFocused, 120);
        $this->assertStringContainsString("\x1b[38;2;0;255;170m", $outputFocused);

        // Input pane not focused
        $appUnfocused = $this->makeApp(Pane::Chat);
        $outputUnfocused = InputPane::render($appUnfocused, 120);
        $this->assertStringContainsString("\x1b[38;2;255;102;170m", $outputUnfocused);
    }

    // =========================================================================
    // MenuBar Tests
    // =========================================================================

    /**
     * @testdox MenuBar::render() produces non-empty output with menu items
     */
    public function testMenuBarRenderProducesNonEmptyOutputWithMenuItems(): void
    {
        $app = $this->makeApp(Pane::Chat);

        $output = MenuBar::render($app);

        $this->assertIsString($output);
        $this->assertNotEmpty($output);
        // Should contain expected menu items
        $this->assertStringContainsString('File', $output);
        $this->assertStringContainsString('Edit', $output);
        $this->assertStringContainsString('Session', $output);
        $this->assertStringContainsString('Provider', $output);
        $this->assertStringContainsString('Skills', $output);
        $this->assertStringContainsString('Agents', $output);
        $this->assertStringContainsString('Help', $output);
    }

    // =========================================================================
    // SkillsPane Tests
    // =========================================================================

    /**
     * @testdox SkillsPane::render() shows "(no skills enabled)" when empty
     */
    public function testSkillsPaneShowsNoSkillsEnabledWhenEmpty(): void
    {
        $app = $this->makeApp(Pane::Skills);
        Renderer::setSize(120, 40);

        $output = SkillsPane::render($app, 40, 20);

        $this->assertStringContainsString('(no skills enabled)', $output);
    }

    /**
     * @testdox SkillsPane::render() shows skills when enabled
     */
    public function testSkillsPaneShowsSkillsWhenEnabled(): void
    {
        $app = $this->makeApp(Pane::Skills, [], [], ['php-best-practices', 'scaffold-library', 'write-phpunit-test']);
        Renderer::setSize(120, 40);

        $output = SkillsPane::render($app, 40, 20);

        $this->assertStringContainsString('php-best-practices', $output);
        $this->assertStringContainsString('scaffold-library', $output);
        $this->assertStringContainsString('write-phpunit-test', $output);
        // Should NOT contain the empty state message
        $this->assertStringNotContainsString('(no skills enabled)', $output);
    }

    /**
     * @testdox SkillsPane::render() border color changes based on pane focus
     */
    public function testSkillsPaneBorderColorChangesBasedOnPaneFocus(): void
    {
        Renderer::setSize(120, 40);

        // Skills pane focused
        $appFocused = $this->makeApp(Pane::Skills);
        $outputFocused = SkillsPane::render($appFocused, 40, 20);
        $this->assertStringContainsString("\x1b[38;2;0;255;170m", $outputFocused);

        // Skills pane not focused
        $appUnfocused = $this->makeApp(Pane::Chat);
        $outputUnfocused = SkillsPane::render($appUnfocused, 40, 20);
        $this->assertStringContainsString("\x1b[38;2;255;102;170m", $outputUnfocused);
    }

    // =========================================================================
    // AgentsPane Tests
    // =========================================================================

    /**
     * @testdox AgentsPane::render() shows "(no active agents)"
     */
    public function testAgentsPaneShowsNoActiveAgents(): void
    {
        $app = $this->makeApp(Pane::Agents);
        Renderer::setSize(120, 40);

        $output = AgentsPane::render($app, 40, 20);

        $this->assertStringContainsString('(no active agents)', $output);
    }

    /**
     * @testdox AgentsPane::render() border color changes based on pane focus
     */
    public function testAgentsPaneBorderColorChangesBasedOnPaneFocus(): void
    {
        Renderer::setSize(120, 40);

        // Agents pane focused
        $appFocused = $this->makeApp(Pane::Agents);
        $outputFocused = AgentsPane::render($appFocused, 40, 20);
        $this->assertStringContainsString("\x1b[38;2;0;255;170m", $outputFocused);

        // Agents pane not focused
        $appUnfocused = $this->makeApp(Pane::Chat);
        $outputUnfocused = AgentsPane::render($appUnfocused, 40, 20);
        $this->assertStringContainsString("\x1b[38;2;255;102;170m", $outputUnfocused);
    }

    // =========================================================================
    // FilesPane Tests
    // =========================================================================

    /**
     * @testdox FilesPane::render() shows "(no files attached)" when empty
     */
    public function testFilesPaneShowsNoFilesAttachedWhenEmpty(): void
    {
        $app = $this->makeApp(Pane::Files);
        Renderer::setSize(120, 40);

        $output = FilesPane::render($app, 40, 20);

        $this->assertStringContainsString('(no files attached)', $output);
    }

    /**
     * @testdox FilesPane::render() shows files when attached
     */
    public function testFilesPaneShowsFilesWhenAttached(): void
    {
        $app = $this->makeApp(
            Pane::Files,
            [],
            ['/path/to/project/src/Component.php', '/path/to/project/tests/ComponentTest.php']
        );
        Renderer::setSize(120, 40);

        $output = FilesPane::render($app, 40, 20);

        // Should show basenames of files
        $this->assertStringContainsString('Component.php', $output);
        $this->assertStringContainsString('ComponentTest.php', $output);
        // Should NOT contain empty state message
        $this->assertStringNotContainsString('(no files attached)', $output);
    }

    /**
     * @testdox FilesPane::render() border color changes based on pane focus
     */
    public function testFilesPaneBorderColorChangesBasedOnPaneFocus(): void
    {
        Renderer::setSize(120, 40);

        // Files pane focused
        $appFocused = $this->makeApp(Pane::Files);
        $outputFocused = FilesPane::render($appFocused, 40, 20);
        $this->assertStringContainsString("\x1b[38;2;0;255;170m", $outputFocused);

        // Files pane not focused
        $appUnfocused = $this->makeApp(Pane::Chat);
        $outputUnfocused = FilesPane::render($appUnfocused, 40, 20);
        $this->assertStringContainsString("\x1b[38;2;255;102;170m", $outputUnfocused);
    }

    // =========================================================================
    // ToolsPane Tests
    // =========================================================================

    /**
     * @testdox ToolsPane::render() shows "(tool history empty)"
     */
    public function testToolsPaneShowsToolHistoryEmpty(): void
    {
        $app = $this->makeApp(Pane::Tools);
        Renderer::setSize(120, 40);

        $output = ToolsPane::render($app, 40, 20);

        $this->assertStringContainsString('(tool history empty)', $output);
    }

    /**
     * @testdox ToolsPane::render() border color changes based on pane focus
     */
    public function testToolsPaneBorderColorChangesBasedOnPaneFocus(): void
    {
        Renderer::setSize(120, 40);

        // Tools pane focused
        $appFocused = $this->makeApp(Pane::Tools);
        $outputFocused = ToolsPane::render($appFocused, 40, 20);
        $this->assertStringContainsString("\x1b[38;2;0;255;170m", $outputFocused);

        // Tools pane not focused
        $appUnfocused = $this->makeApp(Pane::Chat);
        $outputUnfocused = ToolsPane::render($appUnfocused, 40, 20);
        $this->assertStringContainsString("\x1b[38;2;255;102;170m", $outputUnfocused);
    }

    // =========================================================================
    // Integration Tests
    // =========================================================================

    /**
     * @testdox All pane components render without errors with various pane focus states
     */
    public function testAllPaneComponentsRenderWithoutErrorsWithVariousFocusStates(): void
    {
        // Keyed by backing string: PHP enums cannot be used as array keys.
        $panes = [
            Pane::Chat->value => fn() => ChatPane::render($this->makeApp(Pane::Chat), 120, 40),
            Pane::Input->value => fn() => InputPane::render($this->makeApp(Pane::Input), 120),
            Pane::Skills->value => fn() => SkillsPane::render($this->makeApp(Pane::Skills), 40, 20),
            Pane::Agents->value => fn() => AgentsPane::render($this->makeApp(Pane::Agents), 40, 20),
            Pane::Files->value => fn() => FilesPane::render($this->makeApp(Pane::Files), 40, 20),
            Pane::Tools->value => fn() => ToolsPane::render($this->makeApp(Pane::Tools), 40, 20),
            Pane::Settings->value => fn() => ChatPane::render($this->makeApp(Pane::Settings), 120, 40),
            Pane::Help->value => fn() => ChatPane::render($this->makeApp(Pane::Help), 120, 40),
        ];

        foreach ($panes as $paneName => $renderFn) {
            Renderer::resetSizeCache();
            $output = $renderFn();
            $this->assertNotEmpty($output, "Failed for pane: $paneName");
        }
    }
}
