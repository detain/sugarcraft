<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Host;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Context\RulesState;
use SugarCraft\Crush\Diagnostics\NoticeSink;
use SugarCraft\Crush\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Crush\Host\WorkspaceContext;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\RuntimeNoticePumpMsg;
use SugarCraft\Crush\Tests\Support\BackendSelectionEnvSandboxTrait;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Tools\BuiltIn\TaskTool;

/**
 * O-2a: {@see WorkspaceContext} is the non-UI half of `Bootstrap::chat()` —
 * the bundle a host needs per project root — and the service locator the
 * later `Host\*` extractions register on so `Chat` gains no constructor state
 * per service. These cases pin the contract a host builds on: the locator's
 * typing and immutability, `backendFor()` threading the agent manager and the
 * `/rules` set on every path, and a `Chat` reading its workspace's own inbox
 * and switch factory.
 */
final class WorkspaceContextTest extends TestCase
{
    use BackendSelectionEnvSandboxTrait;
    use HomeSandboxTrait;

    private string $tempDir = '';

    /** @var string|false */
    private string|false $originalCustomKey = false;

    /** @var list<NoticeSink> */
    private array $sinks = [];

    protected function setUp(): void
    {
        parent::setUp();
        RuntimeNoticeSink::reset();
    }

    protected function tearDown(): void
    {
        foreach ($this->sinks as $sink) {
            $sink->reset();
        }
        RuntimeNoticeSink::reset();

        if ($this->tempDir !== '') {
            $this->originalCustomKey === false
                ? putenv('CUSTOM_API_KEY')
                : putenv('CUSTOM_API_KEY=' . $this->originalCustomKey);
            $this->restoreBackendSelectionEnv();
            $this->restoreHomeSandbox();
            self::removeTree($this->tempDir);
        }

        parent::tearDown();
    }

    public function testABareWorkspaceTakesTheCurrentInboxAndAFreshRulesSet(): void
    {
        $workspace = WorkspaceContext::new(root: '/nowhere');

        self::assertSame('/nowhere', $workspace->root);
        self::assertSame(RuntimeNoticeSink::current(), $workspace->notices);
        self::assertSame([], $workspace->rulesState->disabled());
        self::assertNull($workspace->agentManager);
        self::assertFalse($workspace->hasService(RulesState::class));
    }

    public function testAServiceIsRegisteredOnACopyAndReadBackByItsClassName(): void
    {
        $rules = RulesState::new(['focus']);
        $bare = WorkspaceContext::new();

        $withService = $bare->withService(RulesState::class, $rules);

        self::assertNotSame($bare, $withService);
        self::assertFalse($bare->hasService(RulesState::class), 'withService() must not touch the original');
        self::assertSame($rules, $withService->service(RulesState::class));
        self::assertNull($withService->service(EchoBackend::class));

        $later = $withService->withService('opaque.key', new \ArrayObject());
        self::assertSame($rules, $later->service(RulesState::class), 'a later registration keeps the earlier ones');
        self::assertInstanceOf(\ArrayObject::class, $later->service('opaque.key'));
    }

    public function testAServiceOfTheWrongTypeIsRefusedAtRegistration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(RulesState::class);

        WorkspaceContext::new()->withService(RulesState::class, new \ArrayObject());
    }

    public function testAnInterfaceKeyAcceptsAnImplementation(): void
    {
        $echo = new EchoBackend();

        self::assertSame($echo, WorkspaceContext::new()->withService(Backend::class, $echo)->service(Backend::class));
    }

    public function testAnEmptyServiceIdIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        WorkspaceContext::new()->withService('', new \ArrayObject());
    }

    public function testBackendForUsesTheFactoryWhenOneWasGiven(): void
    {
        $asked = [];
        $echo = new EchoBackend();
        $workspace = WorkspaceContext::new(backendFactory: static function (string $provider) use (&$asked, $echo): Backend {
            $asked[] = $provider;

            return $echo;
        });

        self::assertSame($echo, $workspace->backendFor('custom'));
        self::assertSame($echo, ($workspace->backendFactory())('sglang'));
        self::assertSame(['custom', 'sglang'], $asked);
    }

    /**
     * Appendix A's `/model` bug, closed for every host built on this type: a
     * workspace with no factory still builds the switched engine with its
     * agent manager (so `Task` stays) and its `/rules` set (by identity).
     */
    public function testBackendForWithoutAFactoryThreadsTheManagerAndTheRulesSet(): void
    {
        $this->sandbox();
        $rules = RulesState::new(['focus']);
        $workspace = WorkspaceContext::new(
            root: $this->tempDir . '/project',
            rulesState: $rules,
            agentManager: Bootstrap::agentManager($this->tempDir . '/project'),
        );

        $backend = $workspace->backendFor('custom');

        self::assertInstanceOf(EngineBackend::class, $backend);
        self::assertNotSame([], self::taskTools($backend), 'a workspace holding a manager must keep Task');
        self::assertSame($rules, $backend->rulesState());
    }

    public function testAChatSwitchesProviderThroughItsWorkspace(): void
    {
        $echo = new EchoBackend();
        $chat = new Chat(workspace: WorkspaceContext::new(
            backendFactory: static fn (string $provider): Backend => $echo,
        ));

        $drafted = (new \ReflectionMethod(Chat::class, 'withInputBuf'))->invoke($chat, '/model custom');
        [$after] = $drafted->update(new KeyMsg(KeyType::Enter));

        self::assertInstanceOf(Chat::class, $after);
        self::assertSame($echo, $after->backend(), 'the switch must come from the workspace\'s factory');
    }

    /**
     * The pump drains the WORKSPACE's inbox: a row recorded into the process
     * sink is another session's business and stays there.
     */
    public function testAChatPumpsItsWorkspacesInboxAndNoOtherSessions(): void
    {
        RuntimeNoticeSink::arm(false);
        $own = NoticeSink::new();
        $own->arm(false);
        $this->sinks[] = $own;

        $own->record('this session\'s warning');
        RuntimeNoticeSink::record('another session\'s warning');

        $chat = new Chat(drainsRuntimeNotices: true, workspace: WorkspaceContext::new(notices: $own));
        [$next] = $chat->update(new RuntimeNoticePumpMsg());

        $rows = array_values(array_map(
            static fn ($m): string => $m->content,
            array_filter($next->history, static fn ($m): bool => $m->role === Role::System),
        ));
        self::assertSame(['this session\'s warning'], $rows);
        self::assertSame(['another session\'s warning'], RuntimeNoticeSink::drain());
    }

    private function sandbox(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/sugarcrush_workspace_' . uniqid('', true);
        mkdir($this->tempDir . '/project', 0700, true);
        $this->useHomeSandbox($this->tempDir . '/home');
        $this->clearBackendSelectionEnv();

        // `custom` builds without a network round trip; it only wants a key.
        $this->originalCustomKey = getenv('CUSTOM_API_KEY');
        putenv('CUSTOM_API_KEY=test-key');
    }

    /** @return list<TaskTool> */
    private static function taskTools(EngineBackend $backend): array
    {
        return array_values(array_filter(
            $backend->tools(),
            static fn (object $tool): bool => $tool instanceof TaskTool,
        ));
    }

    private static function removeTree(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
