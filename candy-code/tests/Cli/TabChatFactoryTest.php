<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\Live\AgentLiveRegistry;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\LaunchNotices;
use SugarCraft\Code\Config\StatusLineCommand;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Message;
use SugarCraft\Code\Role;
use SugarCraft\Code\Tests\Support\BackendSelectionEnvSandboxTrait;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\ToolResult;

/**
 * P-TABS.d: `Bootstrap::tabChat()` is the tab strip's session factory.
 *
 * The design law it exists to pin: a tab rides the launch's workspace (ONE
 * described {@see \SugarCraft\Code\Host\WorkspaceContext} — no re-scan of
 * config, skills, hooks or agents), takes its own single-writer lock (so a
 * session another window holds opens through Chat's existing read-only
 * window), and carries NONE of the launch's traffic: no seeded launch notices,
 * no drain of the shared runtime-notice inbox, and no re-run of the three
 * process decisions the launch made (status line, media render mode, static
 * spinner) — each of those CLEARS as well as sets, and the tab's brief names
 * re-running them as the defect to pin against. Each Chat is built with its
 * own `liveToolEvents` ArrayObject, so pump lineages stay separate.
 *
 * Every case sandboxes HOME first: `workspace()`, the store, the lock
 * directory and the notice lists all resolve through it.
 */
final class TabChatFactoryTest extends TestCase
{
    use BackendSelectionEnvSandboxTrait;
    use HomeSandboxTrait;

    private string $sandboxDir = '';

    private string $projectRoot = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandboxDir = sys_get_temp_dir() . '/crush-tabchat-' . bin2hex(random_bytes(6));
        mkdir($this->sandboxDir . '/home', 0700, true);
        $this->projectRoot = $this->sandboxDir . '/project';
        mkdir($this->projectRoot, 0700, true);
        $this->useHomeSandbox($this->sandboxDir . '/home');
        // The launch's workspace resolves a backend through the provider
        // chain; an ambient CANDY_CODE_* export would steer it (and the
        // provider label its lazy mint records) away from the sandbox.
        $this->clearBackendSelectionEnv();
    }

    protected function tearDown(): void
    {
        // Undo the three process decisions any case touched, whatever the
        // assertions did in between — the next test's launch must start from
        // the same clean slate this one did.
        StatusLineCommand::reset();
        ToolResult::setRenderMode('auto');
        AgentLiveRegistry::setStaticSpinner(false);

        $this->restoreBackendSelectionEnv();
        $this->restoreHomeSandbox();
        $this->deleteTabFactorySandbox($this->sandboxDir);

        parent::tearDown();
    }

    public function testALaunchAndItsTabRideOneWorkspaceAndGetSeparateInboxes(): void
    {
        $launch = Bootstrap::chat($this->projectRoot);
        $tab = Bootstrap::tabChat($this->projectRoot, null);

        // THE memo law: the tab's workspace is the launch's instance, not a
        // second describe beside it.
        self::assertSame($launch->workspace(), $tab->workspace());
        self::assertNotSame($launch, $tab);

        // Each Chat pumps on its OWN liveToolEvents inbox (identity via
        // reflection — Chat's accessor hands out a copy of the rows).
        $events = new \ReflectionProperty(Chat::class, 'liveToolEvents');
        self::assertNotSame(
            $events->getValue($launch),
            $events->getValue($tab),
            'a tab gets a fresh liveToolEvents ArrayObject, so it has its own pump lineage',
        );
    }

    public function testTheAppointedDrainerStaysWithTheLaunchAlone(): void
    {
        $launch = Bootstrap::chat($this->projectRoot);
        $tab = Bootstrap::tabChat($this->projectRoot, null);

        $drains = new \ReflectionProperty(Chat::class, 'drainsRuntimeNotices');
        self::assertTrue($drains->getValue($launch), 'the launch keeps its E171 appointment');
        self::assertFalse($drains->getValue($tab), 'a tab never polls the shared inbox');
    }

    public function testTabChatLoadsAnExistingSessionsTranscriptAndName(): void
    {
        $store = Bootstrap::sessionStore(prune: false);
        $store->createSession('tabsess1', 'candy-code', 'test-model', null, 'Tabbed work');
        $store->saveTranscript('tabsess1', [
            Message::user('the earlier question'),
            Message::assistant('the earlier answer'),
        ]);

        $tab = Bootstrap::tabChat($this->projectRoot, 'tabsess1');

        self::assertSame('tabsess1', $tab->currentSessionId());
        self::assertSame('the earlier question', $tab->history[0]->content);
        self::assertSame('the earlier answer', $tab->history[1]->content);
        self::assertFalse($tab->isReadOnlySession(), 'the first window on a session writes');
    }

    public function testTheSecondTabOnAnAlreadyLockedSessionOpensReadOnly(): void
    {
        $store = Bootstrap::sessionStore(prune: false);
        $store->createSession('shared', 'candy-code', 'test-model', null, 'Shared work');
        $store->saveTranscript('shared', [Message::user('held question')]);

        $first = Bootstrap::tabChat($this->projectRoot, 'shared');
        $second = Bootstrap::tabChat($this->projectRoot, 'shared');

        self::assertFalse($first->isReadOnlySession());
        self::assertTrue($second->isReadOnlySession(), 'the lock the first tab took refuses the second, exactly as two TUIs');

        $last = $second->history[\count($second->history) - 1];
        self::assertSame(Role::System, $last->role);
        self::assertStringContainsString('/branch', $last->content, 'the read-only window still offers the fork');
        self::assertSame('held question', $second->history[0]->content, 'the transcript is still shown');
    }

    public function testTabChatMintsItsSessionRowLazily(): void
    {
        $tab = Bootstrap::tabChat($this->projectRoot, null);

        $id = $tab->currentSessionId();
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $id);

        $store = Bootstrap::sessionStore(prune: false);
        self::assertNull($store->session($id), 'an untouched tab leaves no empty record behind (ISS-102 shape)');

        $mint = new \ReflectionProperty(Chat::class, 'sessionMint');
        $minter = $mint->getValue($tab);
        self::assertInstanceOf(\Closure::class, $minter);
        $minter();
        self::assertNotNull($store->session($id), 'the first dispatch mints the row the id already names');
    }

    public function testTabChatSeedsNoLaunchNoticesAndClearsTheLaunchsNone(): void
    {
        // Warm the memo, then record a launch warning the way the launch
        // machinery does — the tab path may neither seed it into a transcript
        // nor wipe the list it lives in (workspace() resets that list, which
        // is exactly what the tab must not re-run).
        Bootstrap::tabChat($this->projectRoot, null);
        $sentinel = 'tab-factory sentinel ' . bin2hex(random_bytes(4));
        LaunchNotices::record($sentinel, 50);

        $tab = Bootstrap::tabChat($this->projectRoot, null);

        self::assertSame([$sentinel], Bootstrap::launchNotices(), 'the tab did not re-describe the workspace');
        foreach ($tab->history as $row) {
            self::assertNotSame($sentinel, $row->content, 'a tab carries no launch notices');
        }
    }

    public function testTabChatLeavesTheThreeLaunchProcessDecisionsAlone(): void
    {
        $launch = Bootstrap::chat($this->projectRoot);

        // Simulate the mid-session re-sets these surfaces take after launch
        // (the settings view re-configures the status line; a mode command
        // moves the render decision; reduced motion flips the spinner). A
        // tab built afterwards must not roll any of them back.
        StatusLineCommand::configure(
            [StatusLineCommand::KEY => ['type' => StatusLineCommand::TYPE_COMMAND, 'command' => 'echo tab-pinned line']],
            $this->projectRoot,
        );
        $pinned = StatusLineCommand::active();
        self::assertNotNull($pinned);
        ToolResult::setRenderMode('kitty');
        AgentLiveRegistry::setStaticSpinner(true);

        $tab = Bootstrap::tabChat($this->projectRoot, null);

        self::assertSame($launch->workspace(), $tab->workspace());
        self::assertSame($pinned, StatusLineCommand::active(), 'tabChat must not re-run configure() — it clears as well as sets');
        self::assertSame('kitty', ToolResult::renderMode(), 'tabChat must not re-apply the launch-time media mode');
        self::assertTrue(AgentLiveRegistry::staticSpinner(), 'tabChat must not re-read the reduced-motion env');
    }

    public function testAppCarriesTheTabFactoryAndMutatesItOnward(): void
    {
        $app = \SugarCraft\Code\App\App::new(new \SugarCraft\Code\Providers\EchoProvider(), 'test-model');

        self::assertNull($app->tabFactory);

        $factory = static fn (?string $sessionId): Chat => Bootstrap::tabChat('/fixture/root', $sessionId);
        $with = $app->withTabFactory($factory);

        self::assertNotSame($app, $with, 'the builder is fluent, not mutative');
        self::assertSame($factory, $with->tabFactory);

        // Rides every mutate: an unrelated change keeps the factory.
        $moved = $with->withPane(\SugarCraft\Code\Tui\Pane::Agents);
        self::assertSame($factory, $moved->tabFactory);
    }

    public function testLaunchContextAppInstallsTheTabFactory(): void
    {
        // app() is the one caller allowed to build the factory; P-TABS.d
        // ships the field, so the closure it installs must produce real
        // tabs on the app's own root.
        $app = Bootstrap::app($this->projectRoot);

        $factory = $app->tabFactory;
        self::assertIsCallable($factory);

        $tab = $factory(null);
        self::assertInstanceOf(Chat::class, $tab);
        self::assertSame($app->chat->workspace(), $tab->workspace(), 'the factory rides the launch workspace app() built');
    }

    private function deleteTabFactorySandbox(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            /** @var \SplFileInfo $entry */
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($dir);
    }
}
