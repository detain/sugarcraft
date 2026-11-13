<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\Live\AgentLiveRegistry;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Events\SubAgentActivity;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Providers\EchoProvider;
use SugarCraft\Code\Tui\Components\TasksPane;
use SugarCraft\Code\Tui\Pane;
use SugarCraft\Core\Util\Width;

/**
 * The dock's Tasks pane: the same background work `/tasks` lists, painted
 * from {@see \SugarCraft\Code\Commands\TasksCommand::groups()}. The pane sits
 * in the View ▸ Panes switcher like every other dockable pane.
 */
final class TasksPaneTest extends TestCase
{
    public function testAnEmptySessionSaysNoneAndKeepsTheTitle(): void
    {
        $plain = self::plain(TasksPane::render(self::tasksApp(), 60, 8));

        $this->assertStringContainsString(Pane::Tasks->icon() . ' tasks', $plain);
        $this->assertStringContainsString(Lang::t('host.tasks.none'), $plain);
        $this->assertSame([], TasksPane::lines(self::tasksApp()));
    }

    public function testAPopulatedSessionShowsTheHeadingAndTheAgentRow(): void
    {
        $agents = AgentLiveRegistry::new();
        $agents->apply(new SubAgentActivity(
            op: SubAgentActivity::OP_STARTED,
            id: 'agent-7',
            name: 'scout',
            task: 'map the parser',
            seq: 1,
            tail: '',
        ));
        $app = self::tasksApp(WorkspaceContext::new()->withService(AgentLiveRegistry::class, $agents));

        $lines = TasksPane::lines($app);
        $this->assertSame(Lang::t('host.tasks.agents_heading'), $lines[0] ?? null, 'the sub-agent heading leads its group');
        $this->assertStringContainsString('agent agent-7', implode("\n", $lines));

        $plain = self::plain(TasksPane::render($app, 48, 8));
        $this->assertStringContainsString(Lang::t('host.tasks.agents_heading'), $plain);
        $this->assertStringContainsString('agent agent-7', $plain);
        $this->assertStringNotContainsString(Lang::t('host.tasks.none'), $plain);
    }

    public function testEveryLineFitsThePaneBox(): void
    {
        $agents = AgentLiveRegistry::new();
        $agents->apply(new SubAgentActivity(
            op: SubAgentActivity::OP_STARTED,
            id: 'agent-long',
            name: str_repeat('very long agent name ', 6),
            task: 'work',
            seq: 1,
            tail: '',
        ));
        $app = self::tasksApp(WorkspaceContext::new()->withService(AgentLiveRegistry::class, $agents));

        $lines = explode("\n", TasksPane::render($app, 24, 6));
        $box = Width::string($lines[0]);
        foreach ($lines as $line) {
            $this->assertSame($box, Width::string($line), 'a row is wider than the pane border');
        }
    }

    public function testTheIconIsAFramedPaneGlyph(): void
    {
        $this->assertNotSame('', Pane::Tasks->icon());
        $this->assertSame(Pane::Tasks, Pane::from('tasks'));
    }

    public function testTheTasksPaneIsInTheTabCycleAndTheViewPanesRows(): void
    {
        $cycle = array_map(static fn (Pane $pane): string => $pane->value, Pane::tabCycle());

        $this->assertContains('tasks', $cycle);
        $this->assertSame(Pane::Tasks, Pane::Sessions->next());
    }

    public function testTheTasksPaneDocksOnTheRightLikeTheOtherWorkPanes(): void
    {
        $this->assertTrue(Pane::Tasks->dockable());
        $this->assertSame(\SugarCraft\Constraints\Dock\Side::Right, Pane::Tasks->dockSide());
    }

    private static function tasksApp(?WorkspaceContext $workspace = null): App
    {
        return App::new(new EchoProvider(), 'test-model')
            ->withChat((new Chat(history: [], workspace: $workspace))->withSize(120, 30));
    }

    /** The frame with its SGR escapes stripped, so assertions read the words. */
    private static function plain(string $rendered): string
    {
        return (string) preg_replace('/\x1b\[[0-9;]*m/', '', $rendered);
    }
}
