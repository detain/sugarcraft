<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Code\Agents\Agent;
use SugarCraft\Code\Agents\AgentManager;
use SugarCraft\Code\App\App;
use SugarCraft\Code\App\PaneAutoShow;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Events\SubAgentActivity;
use SugarCraft\Code\Host\TurnRunner;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Message;
use SugarCraft\Code\Providers\EchoProvider;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Todo\TodoItem;
use SugarCraft\Code\Todo\TodoList;
use SugarCraft\Code\Todo\TodoStatus;
use SugarCraft\Code\ToolResult;
use SugarCraft\Code\Tools\BuiltIn\Todo;
use SugarCraft\Code\Tui\Commands\ProviderSelectCmd;
use SugarCraft\Constraints\Dock\Side;
use SugarCraft\Code\Tui\Pane;
use SugarCraft\Code\Tui\Renderer as ShellRenderer;

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

    // ── ISS-049: the width guard ──────────────────────────────────────────

    public function testTheGuardSkipsAWidthStarvingDockAndRetriesAfterWidening(): void
    {
        $chat = $this->chatWithManager($this->managerOneRun());
        $app = App::new(new EchoProvider(), 'test-model')->withChat($chat);
        [$narrow] = $app->update(new WindowSizeMsg(120, 40));
        \assert($narrow instanceof App);

        [$blocked] = $this->typeChar($narrow);
        $this->assertFalse($blocked->isDocked(Pane::Agents), 'a right dock at 120 cols leaves chat 60 — auto-dock stands down');
        $this->assertFalse($blocked->paneAutoShow->settled(Pane::Agents), 'the skip is not a verdict: the pane stays pending');

        [$wide] = $blocked->update(new WindowSizeMsg(200, 40));
        \assert($wide instanceof App);
        [$docked] = $this->typeChar($wide);
        $this->assertTrue($docked->isDocked(Pane::Agents), 'the pending dock lands once the frame can host it');
        $this->assertSame(['agents'], $this->slotIds($docked, Side::Right));
    }

    public function testTheGuardMeasuresTheResultingChatColumnNotTheSlotCount(): void
    {
        // Tools joins Files on the SAME left column: one 30-col side leaves
        // chat 90 of 120, so the auto-dock proceeds where the right side,
        // stacked against the seeded left, would not.
        $chat = new Chat(history: [
            Message::user('read it'),
            Message::assistant('')->withToolResults([ToolResult::ok(Todo::NAME, Todo::UPDATED . "\n\ntodo 0/1", 'c1')]),
        ]);
        $app = App::new(new EchoProvider(), 'test-model')->withChat($chat);
        [$measured] = $app->update(new WindowSizeMsg(120, 40));
        \assert($measured instanceof App);

        [$next] = $this->typeChar($measured);

        $this->assertTrue($next->isDocked(Pane::Tools), 'chat keeps 90 of the 120-col band: the guard allows it');
        $this->assertSame(['files', 'tools'], $this->slotIds($next, Side::Left));
        $this->assertSame([], $this->slotIds($next, Side::Right));
    }

    public function testANarrowFrameAutoDocksNothing(): void
    {
        // The issue's 80x24 failure shape: even the single-column Tools dock
        // would starve chat to 60 columns, so nothing new docks at all.
        $chat = new Chat(history: [
            Message::user('read it'),
            Message::assistant('')->withToolResults([ToolResult::ok(Todo::NAME, Todo::UPDATED . "\n\ntodo 0/1", 'c1')]),
        ]);
        $app = App::new(new EchoProvider(), 'test-model')->withChat($chat);
        [$measured] = $app->update(new WindowSizeMsg(80, 24));
        \assert($measured instanceof App);

        [$next] = $this->typeChar($measured);

        $this->assertSame(['files'], $this->slotIds($next, Side::Left), 'the default dock stays; automation adds nothing');
        $this->assertFalse($next->paneAutoShow->settled(Pane::Tools));
    }

    public function testAChatColumnExactlyAtTheFloorIsInsideTheGuard(): void
    {
        // rv6 minor B: the guard's comparison is `>=`, and this is the width
        // where that boundary is REAL, not theoretical. Measured against the
        // shipped share seeding: a 158-column band leaves the trial dock
        // (agents, right) resolving the chat column to exactly
        // AUTO_DOCK_MIN_CHAT_COLS — chat 80 of 158. The twin below lands one
        // column narrower. The pair discriminates the comparison: a flip to
        // `>` stops this frame docking (reddening the assertion below), and
        // the standing width-starvation tests redden any looser flip.
        $chat = $this->chatWithManager($this->managerOneRun());
        $app = App::new(new EchoProvider(), 'test-model')->withChat($chat);
        [$sized] = $app->update(new WindowSizeMsg(158, 40));
        \assert($sized instanceof App);

        [$docked] = $this->typeChar($sized);

        $dock = $docked->dock();
        $chatCols = $dock->resolve(new \SugarCraft\Constraints\Region(0, 0, 158, 40))
            ->regionFor($dock->centerPaneId)?->width ?? -1;
        $this->assertSame(App::AUTO_DOCK_MIN_CHAT_COLS, $chatCols, 'fixture: the frame must land chat EXACTLY on the floor or this proves nothing');
        $this->assertTrue($docked->isDocked(Pane::Agents), 'chat exactly at the floor satisfies the guard: >= docks');
    }

    public function testOneColumnBelowThatBoundaryStandsDownPending(): void
    {
        // The twin half of the boundary: 157 cols starves the chat column
        // under the floor, and the guard's `>=` (not `>`) is what separates
        // this frame from the docking one above.
        $chat = $this->chatWithManager($this->managerOneRun());
        $app = App::new(new EchoProvider(), 'test-model')->withChat($chat);
        [$sized] = $app->update(new WindowSizeMsg(157, 40));
        \assert($sized instanceof App);

        [$blocked] = $this->typeChar($sized);

        $this->assertFalse($blocked->isDocked(Pane::Agents));
        $this->assertFalse($blocked->paneAutoShow->settled(Pane::Agents), 'the skip must stay pending so a wider frame re-offers the dock');
    }

    // ── ISS-049: the verdict outlives the process ─────────────────────────

    public function testTheUserToggledListRidesTheLayoutBlobAndReseedsQuietly(): void
    {
        $captured = [];
        $chat = $this->chatWithManager($this->managerOneRun());
        $app = App::new(new EchoProvider(), 'test-model')
            ->withChat($chat)
            ->withOnLayoutChange(static function (array $blob) use (&$captured): void {
                $captured[] = $blob;
            });
        [$measured] = $app->update(new WindowSizeMsg(200, 40));
        \assert($measured instanceof App);

        [$first] = $this->typeChar($measured);
        $this->assertTrue($first->isDocked(Pane::Agents), 'fixture: 200 cols host the right dock');
        $this->assertArrayNotHasKey('userToggled', end($captured), 'an auto-dock alone writes no verdict list');

        $closed = $first->togglePaneDocking(Pane::Agents);
        $this->assertFalse($closed->isDocked(Pane::Agents));
        $this->assertSame(['agents'], end($captured)['userToggled'], 'the manual close persists through the layout channel');

        // A launch that re-seeds the stored list keeps the pane shut even
        // though its content is live again — the issue's re-dock scenario.
        $relaunched = App::new(new EchoProvider(), 'test-model')
            ->withChat($this->chatWithManager($this->managerOneRun()))
            ->withPaneAutoShow(new PaneAutoShow([], end($captured)['userToggled']));
        [$again] = $relaunched->update(new WindowSizeMsg(200, 40));
        \assert($again instanceof App);
        [$second] = $this->typeChar($again);

        $this->assertFalse($second->isDocked(Pane::Agents), 'the closed pane does not ride back in on the next activity');
    }

    public function testLayoutResetHandsTheVerdictListBackToTheAutoShow(): void
    {
        $captured = [];
        $chat = $this->chatWithManager($this->managerOneRun());
        $app = App::new(new EchoProvider(), 'test-model')
            ->withChat($chat)
            ->withOnLayoutChange(static function (array $blob) use (&$captured): void {
                $captured[] = $blob;
            });
        [$measured] = $app->update(new WindowSizeMsg(200, 40));
        \assert($measured instanceof App);
        [$first] = $this->typeChar($measured);

        $reset = $first->togglePaneDocking(Pane::Agents)->layoutReset();

        $this->assertSame(App::defaultDock()->toArray(), array_diff_key(end($captured), ['userToggled' => 1]));
        $this->assertArrayNotHasKey('userToggled', end($captured), 'reset clears the stored verdicts with the layout');
        $this->assertFalse($reset->paneAutoShow->settled(Pane::Agents), 'and the in-memory memory with them');
    }

    private function sized(Chat $chat): App
    {
        return App::new(new EchoProvider(), 'test-model')->withChat($chat->withSize(120, 30));
    }

    /** @return list<string> the pane ids stacked on one side, in order */
    private function slotIds(App $app, Side $side): array
    {
        return array_map(static fn(\SugarCraft\Constraints\Dock\DockSlot $slot): string => $slot->paneId, $app->dock()->slots($side));
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
