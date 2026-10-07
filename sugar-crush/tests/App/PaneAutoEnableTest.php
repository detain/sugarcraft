<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\App\PaneAutoShow;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Events\SubAgentActivity;
use SugarCraft\Crush\Host\TurnRunner;
use SugarCraft\Crush\Host\WorkspaceContext;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\EchoProvider;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Todo\TodoItem;
use SugarCraft\Crush\Todo\TodoList;
use SugarCraft\Crush\Todo\TodoStatus;
use SugarCraft\Crush\ToolResult;
use SugarCraft\Crush\Tools\BuiltIn\Todo;
use SugarCraft\Crush\Tui\Commands\ProviderSelectCmd;
use SugarCraft\Layout\Dock\Side;
use SugarCraft\Crush\Tui\Pane;
use SugarCraft\Crush\Tui\Renderer as ShellRenderer;

/**
 * CL-2 FIX 1 — a sidepane docks itself the first time its content appears
 * (per pane, per process), on its default side. A pane the user touched by
 * hand — closed included — is settled and never auto-docks again. The dock
 * write rides the same persistDock channel as a manual toggle, so layout
 * persistence stays ordinary.
 */
final class PaneAutoEnableTest extends TestCase
{
    protected function setUp(): void
    {
        ShellRenderer::resetSizeCache();
    }

    protected function tearDown(): void
    {
        ShellRenderer::resetSizeCache();
    }

    public function testAutoShowStartsUnsettledAndFluent(): void
    {
        $fresh = PaneAutoShow::new();
        $this->assertFalse($fresh->settled(Pane::Agents));

        $shown = $fresh->withAutoShown(Pane::Agents);
        $this->assertTrue($shown->settled(Pane::Agents));
        $this->assertFalse($fresh->settled(Pane::Agents), 'the original instance is untouched');
        $this->assertTrue($shown->withAutoShown(Pane::Agents) === $shown, 'marking twice is a no-op');

        $toggled = $shown->withUserToggled(Pane::Todo);
        $this->assertTrue($toggled->settled(Pane::Todo));
        $this->assertFalse($shown->settled(Pane::Todo));
    }

    public function testATransitionWithNoContentNeverTouchesTheDefaultDock(): void
    {
        $app = $this->sized(new Chat(history: [Message::user('hello')]))->autoEnablePanes();

        $this->assertSame(['files'], $this->slotIds($app, Side::Left));
        $this->assertSame([], $this->slotIds($app, Side::Right));
    }

    public function testTheFirstAgentRunDocksTheAgentsPaneOnItsDefaultSide(): void
    {
        $chat = $this->chatWithManager($this->managerOneRun());
        $app = $this->sized($chat);
        $this->assertFalse($app->isDocked(Pane::Agents), 'undocked while the run predates any transition');

        [$next] = $this->typeChar($app);

        $this->assertTrue($next->isDocked(Pane::Agents), 'the choke saw the content land');
        $this->assertSame(['agents'], $this->slotIds($next, Side::Right));
        $this->assertTrue($next->paneAutoShow->settled(Pane::Agents));
    }

    public function testTheFirstTodoListDocksTheTodoPane(): void
    {
        $runner = TurnRunner::new();
        $runner->saveTodos(null, null, TodoList::new(TodoItem::new('Wire the pane', TodoStatus::InProgress)));
        $chat = new Chat(
            history: [Message::user('plan it')],
            workspace: WorkspaceContext::new()->withService(TurnRunner::class, $runner),
        );

        [$next] = $this->typeChar($this->sized($chat));

        $this->assertTrue($next->isDocked(Pane::Todo));
        $this->assertSame(['todo'], $this->slotIds($next, Side::Right));
    }

    public function testTheFirstToolCallDocksTheToolsPane(): void
    {
        $chat = new Chat(history: [
            Message::user('read it'),
            Message::assistant('')->withToolResults([ToolResult::ok(Todo::NAME, Todo::UPDATED . "\n\ntodo 0/1", 'c1')]),
        ]);

        [$next] = $this->typeChar($this->sized($chat));

        $this->assertTrue($next->isDocked(Pane::Tools), 'the pane would list the call, so it shows');
        $this->assertSame(['files', 'tools'], $this->slotIds($next, Side::Left), 'appended after the default files slot');
    }

    public function testFilesActivityNeverDoublesTheDefaultSlot(): void
    {
        $app = $this->sized(new Chat(history: [Message::user('hi')]))
            ->withContextFiles(['src/Chat.php']);

        [$next] = $this->typeChar($app);

        $this->assertSame(['files'], $this->slotIds($next, Side::Left), 'already docked: the predicate no-ops');
    }

    public function testAUserClosedPaneIsHonoredForTheRestOfTheProcess(): void
    {
        $chat = $this->chatWithManager($this->managerOneRun());
        $app = $this->sized($chat)
            ->withContextFiles(['src/Chat.php'])
            ->togglePaneDocking(Pane::Files);

        [$opened] = $this->typeChar($app);
        $this->assertTrue($opened->isDocked(Pane::Agents), 'fixture: the run auto-docked Agents');

        $closed = $opened->togglePaneDocking(Pane::Agents);
        $this->assertFalse($closed->isDocked(Pane::Agents));

        [$again] = $this->typeChar($closed);
        $this->assertFalse($again->isDocked(Pane::Agents), 'the manual close outlives the content');
        $this->assertFalse($again->isDocked(Pane::Files), 'and so does the closed default pane');
    }

    public function testTheAutoDockFiresOnlyOnTheFirstTransitionThatShowsContent(): void
    {
        $chat = $this->chatWithManager($this->managerOneRun());
        $app = $this->sized($chat);

        [$first] = $this->typeChar($app);
        [$second] = $this->typeChar($first);

        $this->assertTrue($second->isDocked(Pane::Agents));
        $this->assertSame(
            ['agents'],
            $this->slotIds($second, Side::Right),
            'no duplicate slot, no re-seeding on later transitions',
        );
    }

    public function testARegistryCommandCrossesTheSameChokeAsAKeystroke(): void
    {
        $chat = $this->chatWithManager($this->managerOneRun());
        $app = $this->sized($chat);
        $this->assertFalse($app->isDocked(Pane::Agents), 'fixture: the run awaits its first transition');

        // REV-A MINOR-b: the slash/palette result path built its own withChat
        // beside delegateToChat's choke, so a command's answer side-logged the
        // dock until some later keystroke. /model on an idle chat returns a
        // new Chat (the providers palette opens), so this exercises exactly
        // that arm of runRegistryCommand().
        [$next] = $app->consumeShellCmd(new ProviderSelectCmd());

        $this->assertNotNull($next->chat->palette(), 'fixture: the command really ran');
        $this->assertTrue($next->isDocked(Pane::Agents), 'the command transition docks pending content itself');
        $this->assertSame(['agents'], $this->slotIds($next, Side::Right));
    }

    private function sized(Chat $chat): App
    {
        return App::new(new EchoProvider(), 'test-model')->withChat($chat->withSize(120, 30));
    }

    /** @return list<string> the pane ids stacked on one side, in order */
    private function slotIds(App $app, Side $side): array
    {
        return array_map(static fn(\SugarCraft\Layout\Dock\DockSlot $slot): string => $slot->paneId, $app->dock()->slots($side));
    }

    /**
     * One keystroke through the shell: an unclaimed Char reaches Chat, and a
     * Chat that changed routes the lineage through delegateToChat's choke.
     *
     * @return array{0: App, 1: mixed}
     */
    private function typeChar(App $app): array
    {
        return $app->update(new KeyMsg(KeyType::Char, 'x'));
    }

    private function chatWithManager(AgentManager $manager): Chat
    {
        return new Chat(history: [Message::user('go')], agentManager: $manager);
    }

    private function managerOneRun(): AgentManager
    {
        $manager = new AgentManager(new EchoProvider(), new SkillRegistry());
        $manager->register(new Agent(
            name: 'reviewer',
            description: 'Reviews code for bugs',
            prompt: 'You are a reviewer.',
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: false,
        ));
        $manager->projectRemoteSubAgent(new SubAgentActivity(
            SubAgentActivity::OP_STARTED,
            'rid-auto',
            'reviewer',
            'Check the auto dock',
            1,
            '',
        ));

        return $manager;
    }
}
