<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\App;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Tui\Components\MenuBar;
use SugarCraft\Code\Tui\Components\MenuSelectedMsg;
use SugarCraft\Code\Tui\Pane;

/**
 * The action contract on {@see MenuSelectedMsg} (P-MENU.d): a leaf raises the
 * kit id it paints — `cmd:<name>` or `pane:<value>` — and App dispatches by
 * that name, not by re-reading a label.
 *
 * The equivalence the step exists for: clicking a `menuitem:` row and pressing
 * Enter on the same row must leave the App in the SAME state. The `pane:` arm
 * therefore runs through the identical {@see App} door the `panetab:` strip
 * click runs (one extraction, both callers); the `cmd:` arm keeps the
 * settings-view rule (running `settings` from inside settings steps aside,
 * any other command closes the editor first).
 *
 * The label-lookup path stays alive byte-for-byte for pre-kit senders: a
 * message built without an action still resolves by label, which is what the
 * legacy tests in AppModelTest / SettingsCommandTest keep pinning.
 */
final class MenuActionDispatchTest extends TestCase
{
    private ProviderInterface $provider;

    protected function setUp(): void
    {
        $this->provider = $this->createMock(ProviderInterface::class);
        MenuBar::closeMenu();
        $this->resetChromeTracker();
    }

    protected function tearDown(): void
    {
        MenuBar::closeMenu();
        $this->resetChromeTracker();
    }

    private function resetChromeTracker(): void
    {
        (new \ReflectionProperty(App::class, 'chromeClickTracker'))->setValue(null, null);
    }

    private function app(): App
    {
        return App::new($this->provider, 'test-model')->withChat(new Chat());
    }

    /**
     * @return array{0: App, 1: mixed}
     */
    private function forgeChromeClick(App $app, string $zoneId): array
    {
        $door = new \ReflectionMethod(App::class, 'dispatchChromeClick');

        /** @var array{0: App, 1: null} */
        return $door->invoke($app, $zoneId);
    }

    public function testCmdActionRunsTheNamedRegistryCommand(): void
    {
        MenuBar::openMenu(1);

        [$next] = $this->app()->consumeShellCmd(new MenuSelectedMsg('Session', 'Switch session', 'cmd:sessions'));

        self::assertSame('/sessions', $next->chat->history[0]->content, 'the action name dispatched, not the label');
        self::assertSame(0, MenuBar::getActiveMenu(), 'a dispatch closes the menu');
    }

    public function testCmdActionNeedsTheHostedChatAndSaysSoWithoutIt(): void
    {
        $bare = App::new($this->provider, 'test-model');

        [$next] = $bare->consumeShellCmd(new MenuSelectedMsg('Session', 'Switch session', 'cmd:sessions'));

        self::assertSame("No chat is hosted — 'sessions' was not dispatched.", $next->status);
    }

    public function testCmdActionCanRaiseTheMediaFormDoor(): void
    {
        // The hidden /generate command's ONLY menu-facing spelling: Tools row
        // "Generate image…" carries action cmd:generate-form, whose palette
        // action emits the OpenMediaFormMsg carrier closure.
        [$next, $cmd] = $this->app()->consumeShellCmd(new MenuSelectedMsg('Tools', 'Generate image', 'cmd:generate-form'));

        self::assertInstanceOf(\Closure::class, $cmd, 'the row raises its command like the palette door does');
        [$opened] = $next->update($cmd());
        self::assertNotNull($opened->mediaForm, 'and delivering it opens the interactive generation form');
    }

    public function testCmdSettingsKeepsTheEditorOpenButAnyOtherCmdStepsAside(): void
    {
        $open = $this->app()->openSettings();

        [$again] = $open->consumeShellCmd(new MenuSelectedMsg('App', 'Settings', 'cmd:settings'));
        self::assertNotNull($again->settingsEditor, 'running settings from inside settings stays in settings');

        [$aside] = $open->consumeShellCmd(new MenuSelectedMsg('App', 'Show permission mode', 'cmd:permissions'));
        self::assertNull($aside->settingsEditor, 'any other command closes the editor first');
        self::assertSame('/permissions', $aside->chat->history[0]->content, 'and runs its own command');
    }

    public function testPaneActionLeavesExactlyThePaneTabClickState(): void
    {
        // The law of one door: View ▸ Panes row 'Files' (action pane:files)
        // and a click on the [◫ Files] strip (panetab:files) must be
        // indistinguishable in the resulting App — both fold into
        // togglePaneFromChrome().
        $app = $this->app();

        [$viaMenu] = $app->consumeShellCmd(new MenuSelectedMsg('View', 'Files', 'pane:files'));
        [$viaClick] = $this->forgeChromeClick($app, MenuBar::PANE_TAB_ZONE_PREFIX . 'files');

        self::assertSame($viaClick->dock()->toArray(), $viaMenu->dock()->toArray(), 'same dock state');
        self::assertSame($viaClick->pane, $viaMenu->pane, 'same focused pane');
        self::assertSame($viaClick->status, $viaMenu->status, 'same status line');

        // Toggle back — still one door.
        [$offMenu] = $viaMenu->consumeShellCmd(new MenuSelectedMsg('View', 'Files', 'pane:files'));
        [$offClick] = $this->forgeChromeClick($viaClick, MenuBar::PANE_TAB_ZONE_PREFIX . 'files');
        self::assertSame($offClick->dock()->toArray(), $offMenu->dock()->toArray(), 'same dock state after the second toggle');
        self::assertSame($offClick->pane, $offMenu->pane);
        self::assertSame($offClick->status, $offMenu->status);
    }

    public function testPaneActionOnChatIsTheFocusDoorNotADock(): void
    {
        [$next] = $this->app()
            ->withPane(Pane::Files)
            ->consumeShellCmd(new MenuSelectedMsg('View', 'Chat', 'pane:chat'));

        self::assertSame(Pane::Chat, $next->pane, 'Chat is never dockable; the door focuses it');
    }

    public function testUnknownPaneActionIsAnErrorNotASilentNoOp(): void
    {
        [$next] = $this->app()->consumeShellCmd(new MenuSelectedMsg('View', 'Nowhere', 'pane:nowhere'));

        self::assertStringContainsString('Nowhere', (string) $next->error);
        self::assertNotSame('', (string) $next->error, 'a leaf naming no pane reports, never shrugs');
    }

    public function testForeignActionPrefixIsAFallThrough(): void
    {
        $app = $this->app();

        [$next, $cmd] = $app->consumeShellCmd(new MenuSelectedMsg('Session', 'Something', 'weird:thing'));

        self::assertSame($app, $next, 'an id outside the cmd:/pane: vocabulary touches nothing');
        self::assertNull($cmd);
    }

    public function testActionlessMessageStillResolvesByLabel(): void
    {
        // The compatibility door the brief demands stays byte-identical:
        // pre-kit senders (AppModelTest, SettingsCommandTest,
        // MenuCommandDraftPreservationTest) build (menu, item) only.
        [$next] = $this->app()->consumeShellCmd(new MenuSelectedMsg('Session', 'Switch session'));

        self::assertSame('/sessions', $next->chat->history[0]->content);

        [$missed] = $this->app()->consumeShellCmd(new MenuSelectedMsg('Session', 'Nope'));
        self::assertStringContainsString("No command matches menu item 'Nope'.", (string) $missed->error);
    }
}
