<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Commands\KeyBindingRegistry;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Tests\Commands\ResetsDerivedRuneSets;
use SugarCraft\Crush\Tui\AgentViewMode;
use SugarCraft\Crush\Tui\Commands\CancelAgentCmd;
use SugarCraft\Crush\Tui\Commands\CancelCmd;
use SugarCraft\Crush\Tui\Commands\CommandPaletteCmd;
use SugarCraft\Crush\Tui\Commands\GroupInputCmd;
use SugarCraft\Crush\Tui\Commands\KeyCmd;
use SugarCraft\Crush\Tui\Commands\NewSessionCmd;
use SugarCraft\Crush\Tui\Commands\ProviderSelectCmd;
use SugarCraft\Crush\Tui\Commands\QuitAgentViewCmd;
use SugarCraft\Crush\Tui\Commands\ResumeAgentCmd;
use SugarCraft\Crush\Tui\Commands\SourceSkillCmd;
use SugarCraft\Crush\Tui\Commands\StopAllAgentsCmd;
use SugarCraft\Crush\Tui\KeyboardHandler;
use SugarCraft\Crush\Tui\Pane;
use SugarCraft\Crush\Tui\Components\MenuBar;
use ReflectionClass;

/**
 * @see KeyboardHandler
 * @see KeyCmd
 * @see NewSessionCmd
 * @see CancelCmd
 * @see GroupInputCmd
 * @see CommandPaletteCmd
 * @see SourceSkillCmd
 * @see ProviderSelectCmd
 * @see AgentViewMode
 * @see CancelAgentCmd
 * @see ResumeAgentCmd
 * @see StopAllAgentsCmd
 * @see QuitAgentViewCmd
 */
final class KeyboardHandlerTest extends TestCase
{
    use ResetsDerivedRuneSets;

    private ProviderInterface $provider;
    private KeyboardHandler $handler;

    protected function setUp(): void
    {
        $this->provider = $this->createMock(ProviderInterface::class);
        $this->handler = new KeyboardHandler();
        // Reset MenuBar static state
        $this->resetMenuBarState();
        // …and the registry's derived-set memos, for the same reason: they are
        // process-global statics with no production reset, so without this the
        // first keypress of the run warms them for every later test and no test
        // can observe a cold derivation. See ResetsDerivedRuneSets.
        $this->resetDerivedRuneSets();
    }

    /**
     * MenuBar's menu state is process-global static, so a test that opens a
     * menu would otherwise leak an "a menu is open" world into every later test
     * class (and make the shell claim keys that belong to Chat).
     */
    protected function tearDown(): void
    {
        $this->resetMenuBarState();
    }

    /**
     * BOTH of MenuBar's statics, not just the menu index: `$activeItem` is the
     * row cursor inside the open dropdown, and a test that moved it used to
     * leak that row into every later test — the same leak this method exists to
     * stop, one property over.
     */
    private function resetMenuBarState(): void
    {
        $reflection = new ReflectionClass(MenuBar::class);
        foreach (['activeMenu', 'activeItem'] as $name) {
            $reflection->getProperty($name)->setValue(null, 0);
        }
    }

    private function createApp(Pane $pane = Pane::Chat): App
    {
        return App::new($this->provider, 'gpt-4')->withPane($pane);
    }

    // =========================================================================
    // KeyboardHandler::handle() Tests
    // =========================================================================

    /**
     * @see KeyboardHandler::handle()
     */
    public function testTabCyclesToNextPane(): void
    {
        $app = $this->createApp(Pane::Chat);
        [$nextApp, $cmd] = $this->handler->handle('tab', $app);

        $this->assertSame(Pane::Files, $nextApp->pane);
        $this->assertNull($cmd);
    }

    /**
     * @see KeyboardHandler::handle()
     */
    public function testTabCyclesThroughAllPanes(): void
    {
        // Exactly the panes the tab strip advertises, in strip order. Tab
        // used to walk Input/Help too -- panes absent from the strip with no
        // renderer behind them. Settings is in the strip again now that
        // SettingsPane renders it.
        $app = $this->createApp(Pane::Chat);

        foreach ([Pane::Files, Pane::Tools, Pane::Skills, Pane::Agents, Pane::Settings, Pane::Chat] as $expected) {
            [$app] = $this->handler->handle('tab', $app);
            $this->assertSame($expected, $app->pane);
        }
    }

    /**
     * @see KeyboardHandler::handle()
     */
    public function testEscapeClosesMenuAndReturnsToChatPane(): void
    {
        // Set an active menu first
        $app = $this->createApp(Pane::Skills);

        [$nextApp, $cmd] = $this->handler->handle('escape', $app);

        $this->assertSame(Pane::Chat, $nextApp->pane);
        $this->assertNull($cmd);
        $this->assertSame(0, MenuBar::getActiveMenu());
    }

    /**
     * @see KeyboardHandler::handle()
     */
    public function testEscapeWithNoActiveMenuReturnsToChatPane(): void
    {
        // No menu active (default state)
        $app = $this->createApp(Pane::Agents);

        [$nextApp, $cmd] = $this->handler->handle('escape', $app);

        $this->assertSame(Pane::Chat, $nextApp->pane);
        $this->assertNull($cmd);
    }

    /**
     * @see KeyboardHandler::handle()
     */
    public function testCtrlNReturnsNewSessionCmd(): void
    {
        $app = $this->createApp();

        [$nextApp, $cmd] = $this->handler->handle('ctrl+n', $app);

        $this->assertSame($app, $nextApp);
        $this->assertInstanceOf(NewSessionCmd::class, $cmd);
    }

    /**
     * @see KeyboardHandler::handle()
     */
    public function testCtrlCReturnsCancelCmd(): void
    {
        $app = $this->createApp();

        [$nextApp, $cmd] = $this->handler->handle('ctrl+c', $app);

        $this->assertSame($app, $nextApp);
        $this->assertInstanceOf(CancelCmd::class, $cmd);
    }

    /**
     * @see KeyboardHandler::handle()
     */
    public function testCtrlGReturnsGroupInputCmd(): void
    {
        $app = $this->createApp();

        [$nextApp, $cmd] = $this->handler->handle('ctrl+g', $app);

        $this->assertSame($app, $nextApp);
        $this->assertInstanceOf(GroupInputCmd::class, $cmd);
    }

    /**
     * @see KeyboardHandler::handle()
     */
    public function testCtrlKReturnsCommandPaletteCmd(): void
    {
        $app = $this->createApp();

        [$nextApp, $cmd] = $this->handler->handle('ctrl+k', $app);

        $this->assertSame($app, $nextApp);
        $this->assertInstanceOf(CommandPaletteCmd::class, $cmd);
    }

    /**
     * @see KeyboardHandler::handle()
     */
    public function testCtrlSReturnsSourceSkillCmd(): void
    {
        $app = $this->createApp();

        [$nextApp, $cmd] = $this->handler->handle('ctrl+s', $app);

        $this->assertSame($app, $nextApp);
        $this->assertInstanceOf(SourceSkillCmd::class, $cmd);
    }

    /**
     * @see KeyboardHandler::handle()
     */
    public function testCtrlPReturnsProviderSelectCmd(): void
    {
        $app = $this->createApp();

        [$nextApp, $cmd] = $this->handler->handle('ctrl+p', $app);

        $this->assertSame($app, $nextApp);
        $this->assertInstanceOf(ProviderSelectCmd::class, $cmd);
    }

    /**
     * @see KeyboardHandler::handle()
     */
    public function testCtrlAReturnsAppWithAgentsPane(): void
    {
        $app = $this->createApp(Pane::Chat);

        [$nextApp, $cmd] = $this->handler->handle('ctrl+a', $app);

        $this->assertNotSame($app, $nextApp);
        $this->assertSame(Pane::Agents, $nextApp->pane);
        $this->assertNull($cmd);
    }

    /**
     * @see KeyboardHandler::handle()
     */
    public function testCtrlCommaReturnsAppWithSettingsPane(): void
    {
        $app = $this->createApp(Pane::Chat);

        [$nextApp, $cmd] = $this->handler->handle('ctrl+,', $app);

        $this->assertNotSame($app, $nextApp);
        $this->assertSame(Pane::Settings, $nextApp->pane);
        $this->assertNull($cmd);
    }

    /**
     * @see KeyboardHandler::handle()
     */
    public function testUnknownCtrlKeyReturnsAppWithNullCmd(): void
    {
        $app = $this->createApp();

        [$nextApp, $cmd] = $this->handler->handle('ctrl+?', $app);

        $this->assertSame($app, $nextApp);
        $this->assertNull($cmd);
    }

    /**
     * @see KeyboardHandler::handle()
     */
    public function testUnknownKeyReturnsAppWithNullCmd(): void
    {
        $app = $this->createApp();

        [$nextApp, $cmd] = $this->handler->handle('unknown-key', $app);

        $this->assertSame($app, $nextApp);
        $this->assertNull($cmd);
    }

    /**
     * @see KeyboardHandler::handle()
     */
    public function testArrowKeysReturnAppWithNullCmd(): void
    {
        $app = $this->createApp();

        foreach (['up', 'k', 'down', 'j', 'left', 'h', 'right', 'l'] as $key) {
            [$nextApp, $cmd] = $this->handler->handle($key, $app);
            $this->assertSame($app, $nextApp, "Arrow key '$key' should return same app");
            $this->assertNull($cmd, "Arrow key '$key' should return null cmd");
        }
    }

    // =========================================================================
    // Command Class Interface and Modifier Tests
    // =========================================================================

    /**
     * @see KeyCmd
     */
    public function testNewSessionCmdImplementsKeyCmd(): void
    {
        $this->assertInstanceOf(KeyCmd::class, new NewSessionCmd());
    }

    /**
     * @see KeyCmd
     */
    public function testCancelCmdImplementsKeyCmd(): void
    {
        $this->assertInstanceOf(KeyCmd::class, new CancelCmd());
    }

    /**
     * @see KeyCmd
     */
    public function testGroupInputCmdImplementsKeyCmd(): void
    {
        $this->assertInstanceOf(KeyCmd::class, new GroupInputCmd());
    }

    /**
     * @see KeyCmd
     */
    public function testCommandPaletteCmdImplementsKeyCmd(): void
    {
        $this->assertInstanceOf(KeyCmd::class, new CommandPaletteCmd());
    }

    /**
     * @see KeyCmd
     */
    public function testSourceSkillCmdImplementsKeyCmd(): void
    {
        $this->assertInstanceOf(KeyCmd::class, new SourceSkillCmd());
    }

    /**
     * @see KeyCmd
     */
    public function testProviderSelectCmdImplementsKeyCmd(): void
    {
        $this->assertInstanceOf(KeyCmd::class, new ProviderSelectCmd());
    }

    /**
     * Verifies NewSessionCmd is final and readonly.
     */
    public function testNewSessionCmdIsFinalReadonly(): void
    {
        $reflection = new ReflectionClass(NewSessionCmd::class);
        $this->assertTrue($reflection->isFinal(), 'NewSessionCmd must be final');
        $this->assertTrue($reflection->isReadOnly(), 'NewSessionCmd must be readonly');
    }

    /**
     * Verifies CancelCmd is final and readonly.
     */
    public function testCancelCmdIsFinalReadonly(): void
    {
        $reflection = new ReflectionClass(CancelCmd::class);
        $this->assertTrue($reflection->isFinal(), 'CancelCmd must be final');
        $this->assertTrue($reflection->isReadOnly(), 'CancelCmd must be readonly');
    }

    /**
     * Verifies GroupInputCmd is final and readonly.
     */
    public function testGroupInputCmdIsFinalReadonly(): void
    {
        $reflection = new ReflectionClass(GroupInputCmd::class);
        $this->assertTrue($reflection->isFinal(), 'GroupInputCmd must be final');
        $this->assertTrue($reflection->isReadOnly(), 'GroupInputCmd must be readonly');
    }

    /**
     * Verifies CommandPaletteCmd is final and readonly.
     */
    public function testCommandPaletteCmdIsFinalReadonly(): void
    {
        $reflection = new ReflectionClass(CommandPaletteCmd::class);
        $this->assertTrue($reflection->isFinal(), 'CommandPaletteCmd must be final');
        $this->assertTrue($reflection->isReadOnly(), 'CommandPaletteCmd must be readonly');
    }

    /**
     * Verifies SourceSkillCmd is final and readonly.
     */
    public function testSourceSkillCmdIsFinalReadonly(): void
    {
        $reflection = new ReflectionClass(SourceSkillCmd::class);
        $this->assertTrue($reflection->isFinal(), 'SourceSkillCmd must be final');
        $this->assertTrue($reflection->isReadOnly(), 'SourceSkillCmd must be readonly');
    }

    /**
     * Verifies ProviderSelectCmd is final and readonly.
     */
    public function testProviderSelectCmdIsFinalReadonly(): void
    {
        $reflection = new ReflectionClass(ProviderSelectCmd::class);
        $this->assertTrue($reflection->isFinal(), 'ProviderSelectCmd must be final');
        $this->assertTrue($reflection->isReadOnly(), 'ProviderSelectCmd must be readonly');
    }

    // =========================================================================
    // Immutability Tests
    // =========================================================================

    /**
     * Verifies that handle() returns a new App instance when pane changes.
     */
    public function testHandleReturnsNewAppInstanceWhenPaneChanges(): void
    {
        $app = $this->createApp(Pane::Chat);

        [$nextApp] = $this->handler->handle('tab', $app);

        $this->assertNotSame($app, $nextApp);
        $this->assertSame(Pane::Chat, $app->pane); // Original unchanged
    }

    /**
     * Verifies that handle() returns same App instance when only cmd is returned.
     */
    public function testHandleReturnsSameAppInstanceWhenOnlyCmdReturned(): void
    {
        $app = $this->createApp();

        [$nextApp] = $this->handler->handle('ctrl+n', $app);

        $this->assertSame($app, $nextApp);
    }

    // =========================================================================
    // Agent View Keyboard Handling Tests
    // =========================================================================

    private function createAgentViewApp(
        AgentViewMode $mode = AgentViewMode::List,
        int $selectedIndex = -1,
    ): App {
        return App::new($this->provider, 'gpt-4')
            ->withPane(Pane::Agents)
            ->withAgentViewMode($mode)
            ->withSelectedAgentIndex($selectedIndex);
    }

    /**
     * @see KeyboardHandler::handleAgentListNavigation()
     */
    public function testDownArrowSelectsFirstAgentInListMode(): void
    {
        $app = $this->createAgentViewApp(AgentViewMode::List, -1);

        [$nextApp, $cmd] = $this->handler->handle('down', $app);

        $this->assertSame(0, $nextApp->selectedAgentIndex);
        $this->assertNull($cmd);
    }

    /**
     * @see KeyboardHandler::handleAgentListNavigation()
     */
    public function testJKeySelectsFirstAgentInListMode(): void
    {
        $app = $this->createAgentViewApp(AgentViewMode::List, -1);

        [$nextApp, $cmd] = $this->handler->handle('j', $app);

        $this->assertSame(0, $nextApp->selectedAgentIndex);
        $this->assertNull($cmd);
    }

    /**
     * @see KeyboardHandler::handleAgentListNavigation()
     */
    public function testDownArrowNavigatesToNextAgent(): void
    {
        $app = $this->createAgentViewApp(AgentViewMode::List, 0);

        [$nextApp, $cmd] = $this->handler->handle('down', $app);

        $this->assertSame(1, $nextApp->selectedAgentIndex);
        $this->assertNull($cmd);
    }

    /**
     * @see KeyboardHandler::handleAgentListNavigation()
     */
    public function testJKeyNavigatesToNextAgent(): void
    {
        $app = $this->createAgentViewApp(AgentViewMode::List, 1);

        [$nextApp, $cmd] = $this->handler->handle('j', $app);

        $this->assertSame(2, $nextApp->selectedAgentIndex);
        $this->assertNull($cmd);
    }

    /**
     * @see KeyboardHandler::handleAgentListNavigation()
     */
    public function testUpArrowNavigatesToPreviousAgent(): void
    {
        $app = $this->createAgentViewApp(AgentViewMode::List, 2);

        [$nextApp, $cmd] = $this->handler->handle('up', $app);

        $this->assertSame(1, $nextApp->selectedAgentIndex);
        $this->assertNull($cmd);
    }

    /**
     * @see KeyboardHandler::handleAgentListNavigation()
     */
    public function testKKeyNavigatesToPreviousAgent(): void
    {
        $app = $this->createAgentViewApp(AgentViewMode::List, 1);

        [$nextApp, $cmd] = $this->handler->handle('k', $app);

        $this->assertSame(0, $nextApp->selectedAgentIndex);
        $this->assertNull($cmd);
    }

    /**
     * @see KeyboardHandler::handleAgentListNavigation()
     */
    public function testUpArrowAtIndexZeroDoesNothing(): void
    {
        $app = $this->createAgentViewApp(AgentViewMode::List, 0);

        [$nextApp, $cmd] = $this->handler->handle('up', $app);

        $this->assertSame(0, $nextApp->selectedAgentIndex);
        $this->assertNull($cmd);
    }

    /**
     * @see KeyboardHandler::handleAgentListNavigation()
     */
    public function testKKeyAtIndexZeroDoesNothing(): void
    {
        $app = $this->createAgentViewApp(AgentViewMode::List, 0);

        [$nextApp, $cmd] = $this->handler->handle('k', $app);

        $this->assertSame(0, $nextApp->selectedAgentIndex);
        $this->assertNull($cmd);
    }

    /**
     * @see KeyboardHandler::handleAgentListKey()
     */
    public function testEnterInListModeWithSelectionTransitionsToPeek(): void
    {
        $app = $this->createAgentViewApp(AgentViewMode::List, 0);

        [$nextApp, $cmd] = $this->handler->handle('enter', $app);

        $this->assertSame(AgentViewMode::Peek, $nextApp->agentViewMode);
        $this->assertNull($cmd);
    }

    /**
     * @see KeyboardHandler::handleAgentListKey()
     */
    public function testEnterInListModeWithoutSelectionDoesNothing(): void
    {
        $app = $this->createAgentViewApp(AgentViewMode::List, -1);

        [$nextApp, $cmd] = $this->handler->handle('enter', $app);

        $this->assertSame(AgentViewMode::List, $nextApp->agentViewMode);
        $this->assertNull($cmd);
    }

    /**
     * @see KeyboardHandler::handleAgentPeekKey()
     */
    public function testEnterInPeekModeTransitionsToAttach(): void
    {
        $app = $this->createAgentViewApp(AgentViewMode::Peek, 0);

        [$nextApp, $cmd] = $this->handler->handle('enter', $app);

        $this->assertSame(AgentViewMode::Attach, $nextApp->agentViewMode);
        $this->assertNull($cmd);
    }

    /**
     * @see KeyboardHandler::handleAgentAttachKey()
     */
    public function testEscapeInAttachModeReturnsToList(): void
    {
        $app = $this->createAgentViewApp(AgentViewMode::Attach, 0);

        [$nextApp, $cmd] = $this->handler->handle('escape', $app);

        $this->assertSame(AgentViewMode::List, $nextApp->agentViewMode);
        $this->assertNull($cmd);
    }

    /**
     * @see KeyboardHandler::handleAgentPeekKey()
     */
    public function testEscapeInPeekModeReturnsToList(): void
    {
        $app = $this->createAgentViewApp(AgentViewMode::Peek, 0);

        [$nextApp, $cmd] = $this->handler->handle('escape', $app);

        $this->assertSame(AgentViewMode::List, $nextApp->agentViewMode);
        $this->assertNull($cmd);
    }

    /**
     * @see KeyboardHandler::handleAgentListKey()
     */
    public function testEscapeInListModeWithSelectionDeselects(): void
    {
        $app = $this->createAgentViewApp(AgentViewMode::List, 2);

        [$nextApp, $cmd] = $this->handler->handle('escape', $app);

        $this->assertSame(-1, $nextApp->selectedAgentIndex);
        $this->assertSame(Pane::Agents, $nextApp->pane);
        $this->assertNull($cmd);
    }

    /**
     * @see KeyboardHandler::handleAgentListKey()
     */
    public function testEscapeInListModeWithoutSelectionReturnsToChat(): void
    {
        $app = $this->createAgentViewApp(AgentViewMode::List, -1);

        [$nextApp, $cmd] = $this->handler->handle('escape', $app);

        $this->assertSame(Pane::Chat, $nextApp->pane);
        $this->assertNull($cmd);
    }

    /**
     * @see KeyboardHandler::handleAgentViewKey()
     */
    public function testCKeyWithSelectionEmitsCancelAgentCmd(): void
    {
        $app = $this->createAgentViewApp(AgentViewMode::List, 3);

        [$nextApp, $cmd] = $this->handler->handle('c', $app);

        $this->assertSame($app, $nextApp);
        $this->assertInstanceOf(CancelAgentCmd::class, $cmd);
        $this->assertSame(3, $cmd->agentIndex);
    }

    /**
     * @see KeyboardHandler::handleAgentViewKey()
     */
    public function testCKeyWithoutSelectionDoesNothing(): void
    {
        $app = $this->createAgentViewApp(AgentViewMode::List, -1);

        [$nextApp, $cmd] = $this->handler->handle('c', $app);

        $this->assertSame($app, $nextApp);
        $this->assertNull($cmd);
    }

    /**
     * @see KeyboardHandler::handleAgentViewKey()
     */
    public function testRKeyWithSelectionEmitsResumeAgentCmd(): void
    {
        $app = $this->createAgentViewApp(AgentViewMode::List, 2);

        [$nextApp, $cmd] = $this->handler->handle('r', $app);

        $this->assertSame($app, $nextApp);
        $this->assertInstanceOf(ResumeAgentCmd::class, $cmd);
        $this->assertSame(2, $cmd->agentIndex);
    }

    /**
     * @see KeyboardHandler::handleAgentViewKey()
     */
    public function testRKeyWithoutSelectionDoesNothing(): void
    {
        $app = $this->createAgentViewApp(AgentViewMode::List, -1);

        [$nextApp, $cmd] = $this->handler->handle('r', $app);

        $this->assertSame($app, $nextApp);
        $this->assertNull($cmd);
    }

    /**
     * @see KeyboardHandler::handleAgentViewKey()
     */
    public function testSKeyEmitsStopAllAgentsCmd(): void
    {
        $app = $this->createAgentViewApp(AgentViewMode::List, 0);

        [$nextApp, $cmd] = $this->handler->handle('s', $app);

        $this->assertSame($app, $nextApp);
        $this->assertInstanceOf(StopAllAgentsCmd::class, $cmd);
    }

    /**
     * @see KeyboardHandler::handleAgentViewKey()
     */
    public function testQKeyReturnsToChatPane(): void
    {
        $app = $this->createAgentViewApp(AgentViewMode::List, 0);

        [$nextApp, $cmd] = $this->handler->handle('q', $app);

        $this->assertSame(Pane::Chat, $nextApp->pane);
        $this->assertInstanceOf(QuitAgentViewCmd::class, $cmd);
    }

    /**
     * @see KeyboardHandler::handleAgentViewKey()
     */
    public function testQKeyResetsAgentViewStateInListMode(): void
    {
        // "q" is a global quick action only outside Attach mode.
        $app = $this->createAgentViewApp(AgentViewMode::List, 5);

        [$nextApp, $cmd] = $this->handler->handle('q', $app);

        $this->assertSame(Pane::Chat, $nextApp->pane);
        $this->assertSame(-1, $nextApp->selectedAgentIndex);
        $this->assertSame(AgentViewMode::List, $nextApp->agentViewMode);
        $this->assertInstanceOf(QuitAgentViewCmd::class, $cmd);
    }

    // =========================================================================
    // P5.S4 regression: Attach-mode key-leak fix. AgentViewMode::Attach's own
    // docblock says "all keyboard input goes to the selected agent" — these
    // prove c/r/s/q are no longer hijacked by the global quick actions when
    // the view is in Attach mode, unlike before this fix (see
    // KeyboardHandler::handleAgentViewKey()).
    // =========================================================================

    /**
     * @see KeyboardHandler::handleAgentAttachKey()
     */
    public function testCKeyInAttachModeIsNotHijackedByCancelAgentCmd(): void
    {
        $app = $this->createAgentViewApp(AgentViewMode::Attach, 3);

        [$nextApp, $cmd] = $this->handler->handle('c', $app);

        $this->assertSame($app, $nextApp);
        $this->assertNull($cmd);
        $this->assertSame(AgentViewMode::Attach, $nextApp->agentViewMode);
        $this->assertSame(3, $nextApp->selectedAgentIndex);
    }

    /**
     * @see KeyboardHandler::handleAgentAttachKey()
     */
    public function testRKeyInAttachModeIsNotHijackedByResumeAgentCmd(): void
    {
        $app = $this->createAgentViewApp(AgentViewMode::Attach, 2);

        [$nextApp, $cmd] = $this->handler->handle('r', $app);

        $this->assertSame($app, $nextApp);
        $this->assertNull($cmd);
        $this->assertSame(AgentViewMode::Attach, $nextApp->agentViewMode);
    }

    /**
     * @see KeyboardHandler::handleAgentAttachKey()
     */
    public function testSKeyInAttachModeIsNotHijackedByStopAllAgentsCmd(): void
    {
        $app = $this->createAgentViewApp(AgentViewMode::Attach, 0);

        [$nextApp, $cmd] = $this->handler->handle('s', $app);

        $this->assertSame($app, $nextApp);
        $this->assertNull($cmd);
        $this->assertSame(AgentViewMode::Attach, $nextApp->agentViewMode);
    }

    /**
     * @see KeyboardHandler::handleAgentAttachKey()
     */
    public function testQKeyInAttachModeIsNotHijackedByQuitAgentViewCmd(): void
    {
        // Before the P5.S4 fix, "q" pressed while attached would quit the
        // agent view entirely (Pane::Chat, selection cleared) instead of
        // being treated as input meant for the attached agent.
        $app = $this->createAgentViewApp(AgentViewMode::Attach, 5);

        [$nextApp, $cmd] = $this->handler->handle('q', $app);

        $this->assertSame($app, $nextApp);
        $this->assertNull($cmd);
        $this->assertSame(Pane::Agents, $nextApp->pane);
        $this->assertSame(AgentViewMode::Attach, $nextApp->agentViewMode);
        $this->assertSame(5, $nextApp->selectedAgentIndex);
    }

    /**
     * @see KeyboardHandler::handleAgentAttachKey()
     */
    public function testEscapeStillDetachesInAttachModeAfterFix(): void
    {
        // Escape remains the one key Attach mode intercepts for itself.
        $app = $this->createAgentViewApp(AgentViewMode::Attach, 1);

        [$nextApp, $cmd] = $this->handler->handle('escape', $app);

        $this->assertSame(AgentViewMode::List, $nextApp->agentViewMode);
        $this->assertNull($cmd);
    }

    // =========================================================================
    // Agent View Command Class Interface and Modifier Tests
    // =========================================================================

    /**
     * @see KeyCmd
     */
    public function testCancelAgentCmdImplementsKeyCmd(): void
    {
        $this->assertInstanceOf(KeyCmd::class, new CancelAgentCmd(0));
    }

    /**
     * @see KeyCmd
     */
    public function testResumeAgentCmdImplementsKeyCmd(): void
    {
        $this->assertInstanceOf(KeyCmd::class, new ResumeAgentCmd(0));
    }

    /**
     * @see KeyCmd
     */
    public function testStopAllAgentsCmdImplementsKeyCmd(): void
    {
        $this->assertInstanceOf(KeyCmd::class, new StopAllAgentsCmd());
    }

    /**
     * @see KeyCmd
     */
    public function testQuitAgentViewCmdImplementsKeyCmd(): void
    {
        $this->assertInstanceOf(KeyCmd::class, new QuitAgentViewCmd());
    }

    /**
     * Verifies CancelAgentCmd is final and readonly.
     */
    public function testCancelAgentCmdIsFinalReadonly(): void
    {
        $reflection = new ReflectionClass(CancelAgentCmd::class);
        $this->assertTrue($reflection->isFinal(), 'CancelAgentCmd must be final');
        $this->assertTrue($reflection->isReadOnly(), 'CancelAgentCmd must be readonly');
    }

    /**
     * Verifies ResumeAgentCmd is final and readonly.
     */
    public function testResumeAgentCmdIsFinalReadonly(): void
    {
        $reflection = new ReflectionClass(ResumeAgentCmd::class);
        $this->assertTrue($reflection->isFinal(), 'ResumeAgentCmd must be final');
        $this->assertTrue($reflection->isReadOnly(), 'ResumeAgentCmd must be readonly');
    }

    /**
     * Verifies StopAllAgentsCmd is final and readonly.
     */
    public function testStopAllAgentsCmdIsFinalReadonly(): void
    {
        $reflection = new ReflectionClass(StopAllAgentsCmd::class);
        $this->assertTrue($reflection->isFinal(), 'StopAllAgentsCmd must be final');
        $this->assertTrue($reflection->isReadOnly(), 'StopAllAgentsCmd must be readonly');
    }

    /**
     * Verifies QuitAgentViewCmd is final and readonly.
     */
    public function testQuitAgentViewCmdIsFinalReadonly(): void
    {
        $reflection = new ReflectionClass(QuitAgentViewCmd::class);
        $this->assertTrue($reflection->isFinal(), 'QuitAgentViewCmd must be final');
        $this->assertTrue($reflection->isReadOnly(), 'QuitAgentViewCmd must be readonly');
    }

    // =========================================================================
    // KeyboardHandler::handleKeyMsg() -- the KeyMsg bridge (crush_feat.md
    // section 5 E7, merge branch). Claimed keys are the shell's; unclaimed
    // keys return null so App::update() can fall through to Chat.
    // =========================================================================

    /**
     * @see KeyboardHandler::handleKeyMsg()
     */
    public function testPlainTabIsClaimedAndCyclesPanes(): void
    {
        $handled = $this->handler->handleKeyMsg(new KeyMsg(KeyType::Tab), $this->createApp(Pane::Chat));

        $this->assertNotNull($handled);
        $this->assertSame(Pane::Files, $handled[0]->pane);
        $this->assertNull($handled[1]);
    }

    /**
     * Ctrl+Tab / Ctrl+Shift+Tab are Chat's session cycling (section 5 E2).
     * Claiming them for the pane cycler would make the binding unreachable
     * the moment the shell hosts the chat.
     *
     * @see KeyboardHandler::handleKeyMsg()
     */
    public function testCtrlTabIsLeftToChat(): void
    {
        $app = $this->createApp(Pane::Chat);

        $this->assertNull($this->handler->handleKeyMsg(new KeyMsg(KeyType::Tab, ctrl: true), $app));
        $this->assertNull(
            $this->handler->handleKeyMsg(new KeyMsg(KeyType::Tab, ctrl: true, shift: true), $app),
        );
    }

    /**
     * The regression this whole bridge exists to prevent: before it, the
     * only entry point answered EVERY key with [App, null], so a typed
     * letter looked handled and never reached the chat input.
     *
     * @see KeyboardHandler::handleKeyMsg()
     */
    public function testTypedTextIsNeverClaimedInTheChatPane(): void
    {
        $app = $this->createApp(Pane::Chat);

        foreach (['c', 'r', 's', 'q', 'h', 'j', 'k', 'l', 'o'] as $rune) {
            $this->assertNull(
                $this->handler->handleKeyMsg(new KeyMsg(KeyType::Char, $rune), $app),
                "typing '{$rune}' in the chat pane must fall through to Chat",
            );
        }

        $this->assertNull($this->handler->handleKeyMsg(new KeyMsg(KeyType::Enter), $app));
        $this->assertNull($this->handler->handleKeyMsg(new KeyMsg(KeyType::Up), $app));
        $this->assertNull($this->handler->handleKeyMsg(new KeyMsg(KeyType::Backspace), $app));
    }

    /**
     * @see KeyboardHandler::handleKeyMsg()
     */
    public function testChatOwnedCtrlChordsAreNeverClaimed(): void
    {
        $app = $this->createApp(Pane::Chat);

        // p = palette, o = expand tool output, a = /agents, r = session picker,
        // w = word delete, c = quit. The set is derived —
        // see KeyBindingRegistry::chatCtrlRunes().
        foreach (['p', 'o', 'a', 'r', 'w', 'c'] as $rune) {
            $this->assertNull(
                $this->handler->handleKeyMsg(new KeyMsg(KeyType::Char, $rune, ctrl: true), $app),
                "ctrl+{$rune} belongs to Chat",
            );
        }

        // Most terminals deliver Ctrl+C as a bare \x03 rune with no flag.
        $this->assertNull($this->handler->handleKeyMsg(new KeyMsg(KeyType::Char, "\x03"), $app));
    }

    /**
     * Even inside the agent view, where the shell otherwise owns the
     * keyboard, the content model keeps its own chords.
     *
     * @see KeyboardHandler::handleKeyMsg()
     */
    public function testChatOwnedChordsSurviveTheAgentsPane(): void
    {
        $app = $this->createApp(Pane::Agents);

        $this->assertNull($this->handler->handleKeyMsg(new KeyMsg(KeyType::Char, 'p', ctrl: true), $app));
        $this->assertNull($this->handler->handleKeyMsg(new KeyMsg(KeyType::Tab, ctrl: true), $app));
    }

    /**
     * The one declared exception to "content wins outright"
     * ({@see KeyBindingRegistry::chatCtrlRunesYieldedToShell()}): Ctrl+R opens
     * a picker Chat paints and drives with up/down/enter, all of which the
     * shell's own keyboard-owning views take — so from those three states the
     * shell keeps the chord and answers it with a no-op instead of letting a
     * modal open where it can be neither seen nor moved.
     *
     * @see KeyboardHandler::shellOwnsKeyboard()
     */
    public function testTheSessionPickerChordIsKeptByTheShellsOwnKeyboardOwningViews(): void
    {
        $skill = $this->skill('alpha');
        $states = [
            'the agent view' => $this->createApp(Pane::Agents),
            'an open skill picker' => $this->createApp(Pane::Skills)->withSkillPickerOptions([$skill, $skill]),
        ];

        foreach ($states as $where => $app) {
            $handled = $this->handler->handleKeyMsg(new KeyMsg(KeyType::Char, 'r', ctrl: true), $app);
            $this->assertNotNull($handled, "Ctrl+R must not reach Chat from {$where}");
            $this->assertNull($handled[1], "and must stay a no-op in {$where}");
            $this->assertSame($app->pane, $handled[0]->pane, "and must not move the pane in {$where}");
        }

        // Same again with the F10 menu open, from the chat pane: the menu bar
        // claims every key while it is up and has no ctrl+r arm of its own.
        MenuBar::openMenu(1);
        $withMenu = $this->handler->handleKeyMsg(
            new KeyMsg(KeyType::Char, 'r', ctrl: true),
            $this->createApp(Pane::Chat),
        );
        $this->assertNotNull($withMenu, 'Ctrl+R must not reach Chat with the menu open');
        $this->assertNull($withMenu[1]);
    }

    /**
     * And nowhere else. An ordinary pane — including Skills with no picker
     * open — still hands Ctrl+R to Chat, which is where the session picker
     * lives.
     */
    public function testTheSessionPickerChordReachesChatFromEveryOrdinaryPane(): void
    {
        foreach ([Pane::Chat, Pane::Files, Pane::Tools, Pane::Skills, Pane::Settings] as $pane) {
            $this->assertNull(
                $this->handler->handleKeyMsg(
                    new KeyMsg(KeyType::Char, 'r', ctrl: true),
                    $this->createApp($pane),
                ),
                "Ctrl+R must fall through to Chat in {$pane->name}",
            );
        }
    }

    /**
     * TWO of the three states {@see KeyboardHandler::shellOwnsKeyboard()} names,
     * built fresh so a caller cannot leak one state's static menu into another.
     *
     * The third — an open F10 menu — is not here because it is not a property of
     * an `App` at all: it lives in `MenuBar`'s process-global menu, so it cannot
     * be handed over as a value and every caller sets it up (and tears it down)
     * itself. {@see keyboardOwningSubStates()} is the list that covers all three,
     * as closures, for exactly that reason.
     *
     * @return array<string, App>
     */
    private function keyboardOwningStates(): array
    {
        $skill = $this->skill('alpha');

        return [
            'the agent view' => $this->createApp(Pane::Agents),
            'an open skill picker' => $this->createApp(Pane::Skills)
                ->withSkillPickerOptions([$skill, $skill]),
        ];
    }

    /**
     * All three {@see KeyboardHandler::shellOwnsKeyboard()} states — including
     * the F10 menu, which {@see keyboardOwningStates()} cannot carry — swept
     * across the sub-states a user actually reaches inside them rather than at
     * one fixture value each.
     *
     * One fixture per state is not "all three states". `createApp(Pane::Agents)`
     * leaves `selectedAgentIndex = -1` and `agentViewMode = List`, and a
     * selected dashboard row is the NORMAL state after one `Down` — measured, a
     * `ctrl+r` arm guarded on `selectedAgentIndex >= 0` is invisible to a sweep
     * that only ever visits the unselected List state. Same for the skill
     * picker's highlight and the menu's row cursor: each is process-global or
     * per-App sub-state a real session moves before pressing anything else.
     *
     * Every sub-state is reached by DRIVING the real handler (one `Down`)
     * rather than by assembling an App, so it is a state the shell can actually
     * be in. Each entry is a closure because two of them also have to set up
     * `MenuBar`'s process-global menu state, which must not leak between
     * entries — and because a closure can be re-run: a caller that presses more
     * than one key must rebuild between presses, since the two menu entries are
     * DESTROYED by any press the menu answers with a close (`q`, `escape`).
     *
     * Domain, stated so the next reader knows what the sweep does and does not
     * cover: 8 states — the agent view in List (with and without a selection),
     * Peek and Attach mode; the skill picker on its first row and with the
     * highlight moved; the F10 menu on its first row and with the row cursor
     * moved. `AgentViewMode` is covered exhaustively (3 of 3 cases); the
     * selection index is covered at -1 and 0, not at every index.
     *
     * @return array<string, \Closure(): App>
     */
    private function keyboardOwningSubStates(): array
    {
        $skill = $this->skill('alpha');
        $agents = fn(): App => $this->createApp(Pane::Agents);
        $picker = fn(): App => $this->createApp(Pane::Skills)->withSkillPickerOptions([$skill, $skill]);

        return [
            'the agent view with nothing selected' => $agents,
            'the agent view with a selected row' => function () use ($agents): App {
                [$moved] = $this->handler->handle('down', $agents());
                $this->assertSame(0, $moved->selectedAgentIndex, 'fixture: one Down selects the first row');

                return $moved;
            },
            'the agent view peeking at a selection' => function () use ($agents): App {
                [$moved] = $this->handler->handle('down', $agents());
                [$peeking] = $this->handler->handle('enter', $moved);
                $this->assertSame(AgentViewMode::Peek, $peeking->agentViewMode, 'fixture: Enter peeks');

                return $peeking;
            },
            'the agent view attached to an agent' => function () use ($agents): App {
                [$moved] = $this->handler->handle('down', $agents());
                [$peeking] = $this->handler->handle('enter', $moved);
                [$attached] = $this->handler->handle('enter', $peeking);
                $this->assertSame(AgentViewMode::Attach, $attached->agentViewMode, 'fixture: Enter attaches');

                return $attached;
            },
            'an open skill picker on its first row' => $picker,
            'an open skill picker with the highlight moved' => function () use ($picker): App {
                [$moved] = $this->handler->handle('down', $picker());
                $this->assertSame(1, $moved->skillPickerIndex, 'fixture: one Down moves the highlight');

                return $moved;
            },
            'an open F10 menu on its first row' => function (): App {
                MenuBar::openMenu(1);

                return $this->createApp(Pane::Chat);
            },
            'an open F10 menu with the row cursor moved' => function (): App {
                MenuBar::openMenu(1);
                $app = $this->createApp(Pane::Chat);
                [$app] = $this->handler->handle('down', $app);
                $this->assertGreaterThan(0, MenuBar::getActiveItem(), 'fixture: one Down moves the row cursor');

                return $app;
            },
        ];
    }

    /**
     * Every process-global static in the pane-shell subsystem (`src/Tui/` and
     * `src/Commands/`), as a value comparable with `assertSame`.
     *
     * DISCOVERED rather than listed, so a static added to any class in the
     * subsystem is swept the day it is added instead of the day someone
     * remembers to extend a list. Objects compare by identity, which is what a
     * "was anything reassigned?" check wants.
     *
     * What it finds today, and the sweep behind that number: 7 statics —
     * `MenuBar::$activeMenu`/`$activeItem`, `Tui\Renderer::$chromeScanner`/
     * `$terminalSize`, `TerminalBackground::$observed`, and
     * `KeyBindingRegistry::$ctrlRuneMemo`/`$yieldedRuneMemo`. Cross-checked
     * against what the handler can actually write: its only mutating static
     * collaborators are `MenuBar::openMenu()`/`activateMenu()`/`closeMenu()`/
     * `handleKey()`, all four of which write only MenuBar's two — and
     * `KeyboardHandler` holds no instance state, touches no superglobal, and
     * writes no file or env var (`grep` for `$GLOBALS`, `static $`,
     * `file_put_contents`, `putenv`, `session_`: none). So for this subsystem
     * the three channels really are all of them.
     *
     * This is the third channel through which a keypress can have an effect. A
     * returned `[$app identical, null cmd]` covers the other two and misses this
     * one entirely: `handleKeyMsg()` itself returns `[$app, null]` immediately
     * AFTER calling `MenuBar::openMenu()` on the F10 path, so "unchanged App
     * plus null command" is demonstrably compatible with a visible effect inside
     * this very class.
     *
     * @return array<string, mixed>
     */
    private static function shellSubsystemStatics(): array
    {
        $src = \dirname(__DIR__, 2) . '/src/';
        $snapshot = [];

        foreach (['Tui', 'Commands'] as $dir) {
            /** @var iterable<\SplFileInfo> $files */
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($src . $dir, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $class = 'SugarCraft\\Crush\\' . str_replace(
                    '/',
                    '\\',
                    substr($file->getPathname(), \strlen($src), -4),
                );
                if (!class_exists($class)) {
                    continue;
                }
                foreach ((new ReflectionClass($class))->getProperties(\ReflectionProperty::IS_STATIC) as $property) {
                    // Inherited statics would otherwise be snapshotted once per
                    // subclass under a different key.
                    if ($property->getDeclaringClass()->getName() !== $class) {
                        continue;
                    }
                    $snapshot[$class . '::$' . $property->getName()] = $property->getValue();
                }
            }
        }

        ksort($snapshot);

        return $snapshot;
    }

    /**
     * Condition 2 of the yield criterion
     * ({@see KeyBindingRegistry::chatCtrlRunesYieldedToShell()}), as a property
     * of the derived set rather than a sentence about `Ctrl+R`: a chord the
     * shell takes back must be one the shell answers with NOTHING.
     *
     * This is the test that makes the criterion enforceable. Add `p` to the
     * yielded set and it fails, because the shell answers `ctrl+p` with
     * `ProviderSelectCmd` — measured, {@see App::consumeShellCmd()} runs that
     * as `/model` — so yielding it would REBIND the chord in exactly the states
     * where an overlay cannot be seen, rather than swallow it.
     *
     * "Nothing happens" is checked across all THREE channels this subsystem has,
     * not the two a returned tuple shows:
     *
     * 1. the returned `App` is the SAME instance (identity, so a new-but-equal
     *    App fails too);
     * 2. the returned command is null;
     * 3. no process-global static in `src/Tui/`/`src/Commands/` moved —
     *    {@see shellSubsystemStatics()}.
     *
     * Channel 3 is not hypothetical and not a second copy of channel 1.
     * `handleKeyMsg()` returns `[$app, null]` immediately after calling
     * `MenuBar::openMenu()` on its F10 path, so this class already contains a
     * key with an unchanged App, a null command, and a menu bar that visibly
     * pops open. `MenuBar::$activeMenu` and `$activeItem` are the two statics
     * the shell routes real effects through today; the sweep is by discovery
     * rather than by name so the next one is covered before it is written.
     *
     * The derived-set memos are warmed BEFORE the snapshot on purpose: filling
     * a pure-function cache is a legitimate effect of a process's first press
     * and is not what "nothing happens" is about — see
     * {@see KeyBindingRegistry::$ctrlRuneMemo}.
     *
     * Domain: every rune in the derived yielded set × the 8 sub-states of
     * {@see keyboardOwningSubStates()}.
     */
    public function testEveryYieldedChordIsAnsweredByANoOp(): void
    {
        $yielded = KeyBindingRegistry::chatCtrlRunesYieldedToShell();
        $this->assertNotSame([], $yielded, 'fixture: the criterion has at least one row to check');

        foreach ($yielded as $rune) {
            foreach ($this->keyboardOwningSubStates() as $where => $build) {
                $this->resetMenuBarState();
                $app = $build();

                KeyBindingRegistry::chatCtrlRunes();
                KeyBindingRegistry::shellCtrlRunes();
                KeyBindingRegistry::chatCtrlRunesYieldedToShell();
                $before = self::shellSubsystemStatics();
                $this->assertArrayHasKey(
                    MenuBar::class . '::$activeMenu',
                    $before,
                    'fixture: the static sweep must find the statics the shell routes effects through',
                );
                $this->assertArrayHasKey(MenuBar::class . '::$activeItem', $before, 'fixture');

                $handled = $this->handler->handleKeyMsg(new KeyMsg(KeyType::Char, $rune, ctrl: true), $app);

                $this->assertNotNull($handled, "ctrl+{$rune} must not reach Chat from {$where}");
                $this->assertNull(
                    $handled[1],
                    "the shell answers ctrl+{$rune} with a command in {$where}, so yielding the chord "
                    . 'rebinds it instead of swallowing it — that fails condition 2 of the yield criterion',
                );
                $this->assertSame($app, $handled[0], "and must leave the shell untouched in {$where}");
                $this->assertSame(
                    $before,
                    self::shellSubsystemStatics(),
                    "ctrl+{$rune} moved process-global shell state in {$where}, so taking the chord back "
                    . 'there means "something else happens" rather than "nothing happens" — that fails '
                    . 'condition 2 of the yield criterion just as surely as returning a command would',
                );
            }
        }

        $this->resetMenuBarState();
    }

    /**
     * The derivation accounting in {@see KeyBindingRegistry::$ctrlRuneMemo}'s
     * docblock, re-measured — because the version this replaced was counted off
     * the call sites and was wrong in both directions.
     *
     * Instrument: both memos cleared, ONE `handleKeyMsg()` call, the filled memo
     * slots counted ({@see ResetsDerivedRuneSets::derivedRuneSetCount()}). A
     * slot is filled exactly once per derivation and nothing else fills one.
     *
     * Domain, in three parts:
     *
     * 1. the 8 table rows below, one cold press each;
     * 2. an exhaustive sweep of 2 menu states × 9 panes × 95 printable runes ×
     *    Ctrl on/off = 3420 cold presses, with the panes at their DEFAULT
     *    sub-state (no skill-picker options, no agent selection);
     * 3. the same rune × Ctrl sweep across the 8 keyboard-owning sub-states of
     *    {@see keyboardOwningSubStates()} = 1520 more cold presses.
     *
     * Part 3 exists because the sentence that used to stand in its place was
     * false. It said the sub-states do not affect which rune SETS are read, only
     * which branch reads them — but opening the skill picker in `Pane::Skills`
     * flips {@see KeyboardHandler::shellOwnsKeyboard()}, and that predicate IS
     * the switch selecting which sets derive: measured, `ctrl+r` in
     * `Pane::Skills` goes from `{Chat}` to `{Chat, YIELDED}` when the picker
     * opens, and `ctrl+x` goes from `{Chat, Panes & windows}` to `{Chat}`. The
     * ceiling of two and its structural argument survive that (the shell set and
     * the yielded set are read on complementary keypresses, so no state can
     * reach three), which is why the conclusion did not move — but the domain
     * did, and it is swept now rather than argued away.
     *
     * Non-`Char` key types are covered only by the `Enter` row.
     *
     * Both histograms are corpus-specific in a way worth being explicit about:
     * they count how many presses land on a rune THIS registry declares, so
     * adding a `Ctrl+<rune>` row anywhere moves them. It is the ceiling, not the
     * distribution, that is a property of the routing rule.
     */
    public function testTheHotPathNeverDerivesMoreThanTwoRuneSets(): void
    {
        $ordinary = $this->createApp(Pane::Chat);
        $agents = $this->createApp(Pane::Agents);

        $rows = [
            ['an ordinary letter', new KeyMsg(KeyType::Char, 'a'), $ordinary, 0],
            ['Enter', new KeyMsg(KeyType::Enter), $ordinary, 0],
            ['ctrl+r in an ordinary pane', new KeyMsg(KeyType::Char, 'r', ctrl: true), $ordinary, 1],
            ['ctrl+n in the agent view', new KeyMsg(KeyType::Char, 'n', ctrl: true), $agents, 1],
            ['ctrl+n in an ordinary pane', new KeyMsg(KeyType::Char, 'n', ctrl: true), $ordinary, 2],
            ['ctrl+z in an ordinary pane', new KeyMsg(KeyType::Char, 'z', ctrl: true), $ordinary, 2],
            ['ctrl+r in the agent view', new KeyMsg(KeyType::Char, 'r', ctrl: true), $agents, 2],
            ['ctrl+w in the agent view', new KeyMsg(KeyType::Char, 'w', ctrl: true), $agents, 2],
        ];

        foreach ($rows as [$what, $msg, $app, $expected]) {
            $this->resetDerivedRuneSets();
            $this->handler->handleKeyMsg($msg, $app);
            $this->assertSame(
                $expected,
                $this->derivedRuneSetCount(),
                "{$what} must derive exactly {$expected} rune set(s) — update the table in "
                . 'KeyBindingRegistry::$ctrlRuneMemo if this changes',
            );
        }

        // Ordinary typing costs nothing, which the call-site count overstated.
        $this->resetDerivedRuneSets();
        foreach (['h', 'e', 'l', 'l', 'o'] as $rune) {
            $this->handler->handleKeyMsg(new KeyMsg(KeyType::Char, $rune), $ordinary);
        }
        $this->assertSame(0, $this->derivedRuneSetCount(), 'typing a word must derive nothing at all');

        // And two is a ceiling, not a high-water mark: the shell set is read
        // only when shellOwnsKeyboard() is false and the yielded set only when
        // it is true, so the third derivation is unreachable by construction.
        $printable = [];
        for ($c = 32; $c < 127; $c++) {
            $printable[] = chr($c);
        }
        $this->assertCount(95, $printable, 'fixture: the sweep width the docblock states');
        $this->assertCount(9, Pane::cases(), 'fixture: the pane count the docblock states');

        $presses = 0;
        $histogram = [0 => 0, 1 => 0, 2 => 0];
        foreach ([0, 1] as $menu) {
            foreach (Pane::cases() as $pane) {
                $app = $this->createApp($pane);
                foreach ($printable as $rune) {
                    foreach ([false, true] as $ctrl) {
                        $this->resetMenuBarState();
                        if ($menu > 0) {
                            MenuBar::openMenu($menu);
                        }
                        $this->resetDerivedRuneSets();
                        $this->handler->handleKeyMsg(new KeyMsg(KeyType::Char, $rune, ctrl: $ctrl), $app);
                        $derived = $this->derivedRuneSetCount();
                        $this->assertLessThanOrEqual(
                            2,
                            $derived,
                            "ctrl={$ctrl} rune '{$rune}' in {$pane->name} (menu {$menu}) derived {$derived} "
                            . 'rune sets — the ceiling of two is what the memo docblock rests on',
                        );
                        $histogram[$derived]++;
                        $presses++;
                    }
                }
            }
        }

        $this->resetMenuBarState();
        $this->assertSame(3420, $presses, '2 menu states x 9 panes x 95 runes x ctrl on/off');
        $this->assertSame(
            [0 => 1710, 1 => 938, 2 => 772],
            $histogram,
            'the distribution the memo docblock states, over the sweep it names',
        );

        // Part 3: the same rune sweep inside the sub-states, because
        // shellOwnsKeyboard() is exactly the predicate that decides WHICH set
        // derives, and a pane at its default sub-state only has it true for
        // Pane::Agents.
        //
        // The sub-state is rebuilt for EVERY press, not once per state: two of
        // the eight live in MenuBar's process-global menu, and a swept rune of
        // 'q' or 'escape' closes that menu — measured, building once let the
        // first such rune turn the remaining presses into ordinary-pane presses
        // and pulled shellCtrlRunes() into the histogram, which is exactly the
        // leak the 3420-press sweep above resets per press to avoid.
        $subStates = $this->keyboardOwningSubStates();
        $this->assertCount(8, $subStates, 'fixture: the sub-state count the docblock states');

        $subPresses = 0;
        $subHistogram = [0 => 0, 1 => 0, 2 => 0];
        foreach ($subStates as $where => $build) {
            foreach ($printable as $rune) {
                foreach ([false, true] as $ctrl) {
                    $this->resetMenuBarState();
                    $app = $build();
                    $this->resetDerivedRuneSets();
                    $this->handler->handleKeyMsg(new KeyMsg(KeyType::Char, $rune, ctrl: $ctrl), $app);
                    $derived = $this->derivedRuneSetCount();
                    $this->assertLessThanOrEqual(
                        2,
                        $derived,
                        "ctrl={$ctrl} rune '{$rune}' in {$where} derived {$derived} rune sets — the "
                        . 'ceiling of two is what the memo docblock rests on, in the sub-states too',
                    );
                    $subHistogram[$derived]++;
                    $subPresses++;
                }
            }
        }

        $this->resetMenuBarState();
        $this->assertSame(1520, $subPresses, '8 keyboard-owning sub-states x 95 runes x ctrl on/off');
        $this->assertSame(
            [0 => 760, 1 => 712, 2 => 48],
            $subHistogram,
            'the sub-state distribution: nothing for ordinary typing, one set for a Ctrl chord this '
            . 'registry does not give Chat, two when it does and the yielded set has to be consulted',
        );
    }

    /**
     * The `shellOwnsKeyboard($app)` conjunct inside `chatOwns()`, pinned at the
     * predicate because routing cannot see it: claim rule 2 already claims
     * every key in exactly these three states, and
     * `KeyBindingRegistryTest::testTheTwoClaimSetsAreDisjoint()` keeps a
     * yielded rune out of the shell's own set, so a mutation that yields the
     * chord unconditionally produces byte-identical routing everywhere.
     *
     * It is defence in depth against rule 2 narrowing — see
     * {@see KeyboardHandler::shellOwnsKeyboard()} — and a guard nothing can
     * detect is a guard that gets "simplified" away, so this reads it directly.
     */
    public function testChatOwnsYieldsTheChordOnlyWhileTheShellOwnsTheKeyboard(): void
    {
        $chatOwns = new \ReflectionMethod(KeyboardHandler::class, 'chatOwns');

        foreach (KeyBindingRegistry::chatCtrlRunesYieldedToShell() as $rune) {
            $msg = new KeyMsg(KeyType::Char, $rune, ctrl: true);

            foreach ([Pane::Chat, Pane::Files, Pane::Tools, Pane::Skills, Pane::Settings] as $pane) {
                $this->assertTrue(
                    $chatOwns->invoke(null, $msg, $this->createApp($pane)),
                    "chatOwns() must keep ctrl+{$rune} for Chat in {$pane->name}: the yield is "
                    . 'conditional on the shell owning the keyboard, not unconditional',
                );
            }

            foreach ($this->keyboardOwningStates() as $where => $app) {
                $this->assertFalse(
                    $chatOwns->invoke(null, $msg, $app),
                    "chatOwns() must give ctrl+{$rune} back to the shell in {$where}",
                );
            }

            MenuBar::openMenu(1);
            $this->assertFalse($chatOwns->invoke(null, $msg, $this->createApp(Pane::Chat)));
            $this->resetMenuBarState();
        }
    }

    /**
     * The gap condition 2 deliberately leaves open, pinned as the CURRENT
     * behaviour rather than as the desired one — tracker #85.
     *
     * `Ctrl+P` meets condition 1 of the yield criterion and fails condition 2,
     * so it keeps reaching Chat from `Pane::Agents`, where the dashboard
     * replaces the whole content band: the palette opens, nothing paints it,
     * and `↑`/`↓`/`Enter` drive the dashboard. Leaving the pane reveals it.
     * Routing here is unchanged from before the claim sets were derived from
     * {@see KeyBindingRegistry} — this is not a regression, it is the state the
     * criterion's own first half condemns and which yielding the chord would
     * make worse (`ProviderSelectCmd`, i.e. `/model`, instead of a no-op).
     *
     * When the shell learns to composite or stand down for a hosted overlay,
     * THIS is the test that says the gap is closed.
     */
    public function testTheAgentViewTakesAPaletteItNeitherPaintsNorDrives(): void
    {
        $chat = new Chat(
            history: [Message::user('hello'), Message::assistant('hi')],
            backend: new EchoBackend(),
        );
        [$app] = App::new($this->provider, 'gpt-4')
            ->withChat($chat)
            ->withPane(Pane::Agents)
            ->update(new WindowSizeMsg(100, 30));

        [$open] = $app->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
        $this->assertNotNull($open->chat?->palette(), 'Ctrl+P still reaches the hosted Chat here');
        // renderPalette()'s query line is the palette's own signature; the
        // dashboard frame carries no part of the hosted chat's frame at all.
        $this->assertStringNotContainsString('🔍', self::body($open), 'and nothing paints it');

        [$down] = $open->update(new KeyMsg(KeyType::Down));
        $this->assertSame(
            0,
            $down->chat?->palette()?->selectedIndex,
            'Down cannot move a palette the dashboard is driving',
        );
        $this->assertSame(0, $down->selectedAgentIndex, 'it moves the dashboard selection instead');

        // The one way out: leaving the pane reveals the palette that was open
        // all along. Escape first drops the dashboard selection, then leaves.
        [$escaped] = $down->update(new KeyMsg(KeyType::Escape));
        [$escaped] = $escaped->update(new KeyMsg(KeyType::Escape));
        $this->assertSame(Pane::Chat, $escaped->pane);
        $this->assertStringContainsString('🔍', self::body($escaped));
    }

    private static function body(App $app): string
    {
        $view = $app->view();

        return is_string($view) ? $view : $view->body;
    }

    private function skill(string $name): Skill
    {
        return new Skill(
            name: $name,
            description: 'A skill',
            userInvocable: true,
            disableModelInvocation: false,
            allowedTools: null,
            disallowedTools: null,
            model: null,
            effort: 'medium',
            context: 'inline',
            paths: [],
            content: 'Do the thing.',
            sourcePath: sys_get_temp_dir() . '/' . $name . '.md',
        );
    }

    /**
     * @see KeyboardHandler::handleKeyMsg()
     */
    public function testShellCtrlChordsAreClaimed(): void
    {
        $app = $this->createApp(Pane::Chat);

        $expected = [
            'n' => NewSessionCmd::class,
            'g' => GroupInputCmd::class,
            'k' => CommandPaletteCmd::class,
            's' => SourceSkillCmd::class,
        ];

        foreach ($expected as $rune => $cmdClass) {
            $handled = $this->handler->handleKeyMsg(new KeyMsg(KeyType::Char, $rune, ctrl: true), $app);
            $this->assertNotNull($handled, "ctrl+{$rune} must be claimed by the shell");
            $this->assertInstanceOf($cmdClass, $handled[1]);
        }

        $settings = $this->handler->handleKeyMsg(new KeyMsg(KeyType::Char, ',', ctrl: true), $app);
        $this->assertNotNull($settings);
        $this->assertSame(Pane::Settings, $settings[0]->pane);
    }

    /**
     * @see KeyboardHandler::handleKeyMsg()
     */
    public function testAgentsPaneClaimsItsQuickActionKeys(): void
    {
        $app = $this->createApp(Pane::Agents)->withSelectedAgentIndex(2);

        $cancel = $this->handler->handleKeyMsg(new KeyMsg(KeyType::Char, 'c'), $app);
        $this->assertNotNull($cancel);
        $this->assertInstanceOf(CancelAgentCmd::class, $cancel[1]);

        $resume = $this->handler->handleKeyMsg(new KeyMsg(KeyType::Char, 'r'), $app);
        $this->assertNotNull($resume);
        $this->assertInstanceOf(ResumeAgentCmd::class, $resume[1]);

        $stop = $this->handler->handleKeyMsg(new KeyMsg(KeyType::Char, 's'), $app);
        $this->assertNotNull($stop);
        $this->assertInstanceOf(StopAllAgentsCmd::class, $stop[1]);

        $quit = $this->handler->handleKeyMsg(new KeyMsg(KeyType::Char, 'q'), $app);
        $this->assertNotNull($quit);
        $this->assertInstanceOf(QuitAgentViewCmd::class, $quit[1]);
        $this->assertSame(Pane::Chat, $quit[0]->pane);
    }

    /**
     * @see KeyboardHandler::handleKeyMsg()
     */
    public function testAgentsPaneClaimsListNavigation(): void
    {
        $app = $this->createApp(Pane::Agents);

        $down = $this->handler->handleKeyMsg(new KeyMsg(KeyType::Down), $app);
        $this->assertNotNull($down);
        $this->assertSame(0, $down[0]->selectedAgentIndex);

        $again = $this->handler->handleKeyMsg(new KeyMsg(KeyType::Down), $down[0]);
        $this->assertNotNull($again);
        $this->assertSame(1, $again[0]->selectedAgentIndex);
    }

    /**
     * Escape means "cancel the running turn" / "close the palette" to Chat,
     * so the shell only claims it when it has a pane to return FROM.
     *
     * @see KeyboardHandler::handleKeyMsg()
     */
    public function testEscapeIsClaimedOnlyOutsideTheChatPane(): void
    {
        $this->assertNull(
            $this->handler->handleKeyMsg(new KeyMsg(KeyType::Escape), $this->createApp(Pane::Chat)),
        );

        $handled = $this->handler->handleKeyMsg(new KeyMsg(KeyType::Escape), $this->createApp(Pane::Settings));
        $this->assertNotNull($handled);
        $this->assertSame(Pane::Chat, $handled[0]->pane);
    }

    /**
     * @see KeyboardHandler::handleKeyMsg()
     */
    public function testAnOpenMenuOwnsTheKeyboard(): void
    {
        $reflection = new ReflectionClass(MenuBar::class);
        $property = $reflection->getProperty('activeMenu');
        $property->setAccessible(true);
        $property->setValue(null, 1);

        // A plain letter that would otherwise be typed into the chat input.
        $handled = $this->handler->handleKeyMsg(new KeyMsg(KeyType::Char, 'z'), $this->createApp(Pane::Chat));
        $this->assertNotNull($handled, 'an open menu claims every non-Chat-owned key');
    }
}
