<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Tui\Renderer as TuiRenderer;

/**
 * Locks in W3.S6a's decision (crush_feat.md §5E item 7): the second,
 * App-keyed TUI pane layer is DELETED, and the pieces that were genuinely
 * live are NOT.
 *
 * Every assertion here fails against the pre-W3.S6a tree, where all of the
 * retired symbols existed and `Tui\Renderer::render(App)` composed them into
 * a full screen that nothing reachable from `bin/sugarcrush` ever called.
 */
final class PaneLayerRetirementTest extends TestCase
{
    /**
     * Every class in the retired App-keyed presentation + keyboard shell is
     * gone. Listed by FQN rather than globbed so re-adding one under the old
     * name is a deliberate, test-breaking act.
     *
     * @return array<string, array{0: string}>
     */
    public static function retiredClasses(): array
    {
        $names = [
            'Tui\KeyboardHandler',
            'Tui\Components\MenuBar',
            'Tui\Components\MenuSelectedMsg',
            'Tui\Components\ChatPane',
            'Tui\Components\InputPane',
            'Tui\Components\FilesPane',
            'Tui\Components\ToolsPane',
            'Tui\Components\SkillsPane',
            'Tui\Components\AgentsPane',
            'Tui\Commands\KeyCmd',
            'Tui\Commands\CancelCmd',
            'Tui\Commands\CancelAgentCmd',
            'Tui\Commands\CommandPaletteCmd',
            'Tui\Commands\GroupInputCmd',
            'Tui\Commands\NewSessionCmd',
            'Tui\Commands\ProviderSelectCmd',
            'Tui\Commands\QuitAgentViewCmd',
            'Tui\Commands\ResumeAgentCmd',
            'Tui\Commands\SourceSkillCmd',
            'Tui\Commands\StopAllAgentsCmd',
        ];

        $cases = [];
        foreach ($names as $short) {
            $cases[$short] = ['SugarCraft\\Crush\\' . $short];
        }

        return $cases;
    }

    /**
     * @dataProvider retiredClasses
     */
    public function testRetiredPaneLayerClassDoesNotExist(string $fqn): void
    {
        $this->assertFalse(
            class_exists($fqn) || interface_exists($fqn) || enum_exists($fqn),
            $fqn . ' is part of the retired App-keyed pane layer and must not come back.',
        );
    }

    /** The App-keyed full-screen composition entry point is gone. */
    public function testTuiRendererNoLongerExposesAnAppKeyedRender(): void
    {
        $this->assertFalse(
            method_exists(TuiRenderer::class, 'render'),
            'Tui\Renderer::render(App) was the dead second renderer; the live one is SugarCraft\Crush\Renderer.',
        );
        $this->assertFalse(method_exists(TuiRenderer::class, 'statusBar'));
    }

    /**
     * The source tree carries no leftover pane/keyboard-shell files.
     *
     * `Tui/Components` is checked file-by-file rather than banned wholesale:
     * §5E item 5 (W3.S5b) deliberately adds a NEW `AgentDashboardPane` there,
     * so the directory itself is expected to come back — only the retired
     * names must stay gone.
     */
    public function testRetiredSourceDirectoriesAreGone(): void
    {
        $src = dirname(__DIR__, 2) . '/src';

        foreach (['MenuBar', 'ChatPane', 'InputPane', 'FilesPane', 'ToolsPane', 'SkillsPane', 'AgentsPane'] as $pane) {
            $this->assertFileDoesNotExist($src . '/Tui/Components/' . $pane . '.php');
        }

        $this->assertDirectoryDoesNotExist($src . '/Tui/Commands');
        $this->assertFileDoesNotExist($src . '/Tui/KeyboardHandler.php');
    }

    /**
     * The retirement stops exactly at the pane layer: `App` is the LIVE
     * engine's state object (`Runtime::run()` takes one), so it — and the
     * `Tui\Pane`/`Tui\AgentViewMode` enums typing its fields — stay.
     */
    public function testLiveEngineStateSurvivesTheRetirement(): void
    {
        $this->assertTrue(class_exists(App::class));
        $this->assertTrue(enum_exists(\SugarCraft\Crush\Tui\Pane::class));
        $this->assertTrue(enum_exists(\SugarCraft\Crush\Tui\AgentViewMode::class));

        $param = (new \ReflectionMethod(Runtime::class, 'run'))->getParameters()[0];
        $this->assertSame(App::class, (string) $param->getType());
    }

    /**
     * The surviving half of Tui\Renderer is the terminal-size cache the live
     * `Chat` falls back to before the first WindowSizeMsg, plus the
     * multiplexer split helpers §5E item 9 builds on.
     */
    public function testSurvivingTuiRendererSurfaceStillWorks(): void
    {
        TuiRenderer::resetSizeCache();
        TuiRenderer::setSize(101, 37);

        $this->assertSame(['rows' => 37, 'cols' => 101], TuiRenderer::getTerminalSize());
        $this->assertTrue(method_exists(TuiRenderer::class, 'renderWithSplit'));
        $this->assertTrue(method_exists(TuiRenderer::class, 'renderForCurrentEnvironment'));

        TuiRenderer::resetSizeCache();
    }
}
