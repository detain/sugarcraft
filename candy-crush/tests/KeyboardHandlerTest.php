<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Tui\Commands\CancelCmd;
use SugarCraft\Crush\Tui\Commands\CommandPaletteCmd;
use SugarCraft\Crush\Tui\Commands\GroupInputCmd;
use SugarCraft\Crush\Tui\Commands\KeyCmd;
use SugarCraft\Crush\Tui\Commands\NewSessionCmd;
use SugarCraft\Crush\Tui\Commands\ProviderSelectCmd;
use SugarCraft\Crush\Tui\Commands\SourceSkillCmd;
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

        $this->assertSame(Pane::Input, $nextApp->pane);
        $this->assertNull($cmd);
    }

    /**
     * @see KeyboardHandler::handle()
     */
    public function testTabCyclesThroughAllPanes(): void
    {
        // Chat -> Input
        $app = $this->createApp(Pane::Chat);
        [$app] = $this->handler->handle('tab', $app);
        $this->assertSame(Pane::Input, $app->pane);

        // Input -> Files
        [$app] = $this->handler->handle('tab', $app);
        $this->assertSame(Pane::Files, $app->pane);

        // Files -> Tools
        [$app] = $this->handler->handle('tab', $app);
        $this->assertSame(Pane::Tools, $app->pane);

        // Tools -> Skills
        [$app] = $this->handler->handle('tab', $app);
        $this->assertSame(Pane::Skills, $app->pane);

        // Skills -> Agents
        [$app] = $this->handler->handle('tab', $app);
        $this->assertSame(Pane::Agents, $app->pane);

        // Agents -> Settings
        [$app] = $this->handler->handle('tab', $app);
        $this->assertSame(Pane::Settings, $app->pane);

        // Settings -> Help
        [$app] = $this->handler->handle('tab', $app);
        $this->assertSame(Pane::Help, $app->pane);

        // Help -> Chat (cycle complete)
        [$app] = $this->handler->handle('tab', $app);
        $this->assertSame(Pane::Chat, $app->pane);
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
}
