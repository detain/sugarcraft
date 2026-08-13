<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Providers\ProviderInterface;
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
    private ProviderInterface $provider;
    private KeyboardHandler $handler;

    protected function setUp(): void
    {
        $this->provider = $this->createMock(ProviderInterface::class);
        $this->handler = new KeyboardHandler();
        // Reset MenuBar static state
        $this->resetMenuBarState();
    }

    /**
     * MenuBar::$activeMenu is process-global static state, so a test that
     * opens a menu would otherwise leak an "a menu is open" world into every
     * later test class (and make the shell claim keys that belong to Chat).
     */
    protected function tearDown(): void
    {
        $this->resetMenuBarState();
    }

    private function resetMenuBarState(): void
    {
        // Use reflection to reset the static $activeMenu property
        $reflection = new ReflectionClass(MenuBar::class);
        $property = $reflection->getProperty('activeMenu');
        $property->setAccessible(true);
        $property->setValue(null, 0);
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

        // p = palette, o = expand tool output, a = /agents, w = word delete,
        // c = quit. See KeyboardHandler::CHAT_CTRL_RUNES.
        foreach (['p', 'o', 'a', 'w', 'c'] as $rune) {
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
