<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Context\RulesState;
use SugarCraft\Crush\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Crush\Host\WorkspaceContext;
use SugarCraft\Crush\Tests\Support\BackendSelectionEnvSandboxTrait;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Tools\BuiltIn\TaskTool;

/**
 * N-P3a: a provider switch (`/model <provider>`, Ctrl+P "Switch model") must
 * hand the new engine the launch's collaborators.
 *
 * `Chat::selectPaletteProvider()` used to call `Bootstrap::backendFor()` with
 * only the root and the gate. The replacement engine then had no task manager
 * — `Bootstrap::tools()` adds `Task` only when it has one, so the tool left
 * the model's list mid-session — and no `RulesState`, so every `/rules` toggle
 * made before or after the switch stopped reaching the prompt while the
 * transcript went on reporting it.
 *
 * ONE `Bootstrap::chat()` launch for the class's launch half (see
 * {@see \SugarCraft\Crush\Tests\Cli\RulesStateWiringTest}'s docblock for why a
 * launch is paid for sparingly); the embedder half builds its Chat by hand.
 */
final class ProviderSwitchKeepsTaskToolTest extends TestCase
{
    use BackendSelectionEnvSandboxTrait;
    use HomeSandboxTrait;

    private string $tempDir = '';

    /** @var string|false */
    private string|false $originalCustomKey = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/sugarcrush_provider_switch_' . uniqid('', true);
        mkdir($this->tempDir . '/project', 0700, true);
        $this->useHomeSandbox($this->tempDir . '/home');
        $this->clearBackendSelectionEnv();

        // `custom` builds without a network round trip; it only wants a key.
        $this->originalCustomKey = getenv('CUSTOM_API_KEY');
        putenv('CUSTOM_API_KEY=test-key');
    }

    protected function tearDown(): void
    {
        $this->originalCustomKey === false
            ? putenv('CUSTOM_API_KEY')
            : putenv('CUSTOM_API_KEY=' . $this->originalCustomKey);
        $this->restoreBackendSelectionEnv();
        $this->restoreHomeSandbox();
        $this->removeProviderSwitchFixture($this->tempDir);

        parent::tearDown();
    }

    public function testALaunchedSwitchKeepsTaskTheRulesSetAndItsToggles(): void
    {
        $chat = Bootstrap::chat($this->tempDir . '/project');
        $rules = $chat->rulesState();

        // O-2a: the launch's collaborators are ONE bundle — the workspace the
        // Chat holds is the one its rules set, manager and inbox came from.
        $workspace = (new \ReflectionProperty(Chat::class, 'workspace'))->getValue($chat);
        $this->assertInstanceOf(WorkspaceContext::class, $workspace);
        $this->assertSame($rules, $workspace->rulesState);
        $this->assertSame($chat->agentManager(), $workspace->agentManager);
        $this->assertSame(RuntimeNoticeSink::process(), $workspace->notices, 'a TUI launch runs on the process inbox');
        $this->assertTrue($workspace->notices->isArmed(), 'workspace() must arm the inbox before any fork');

        // A toggle made BEFORE the switch has to survive it.
        $rules->toggle('focus');

        $after = $this->submit($chat, '/model custom');
        $backend = $after->backend();

        $this->assertInstanceOf(EngineBackend::class, $backend);
        $this->assertNotSame($chat->backend(), $backend, 'the switch must actually replace the backend');
        $this->assertNotSame([], $this->taskTools($backend), 'the switched engine must still offer Task');
        $this->assertSame($rules, $backend->rulesState(), 'the switched engine must read the session set, not a copy or nothing');
        $this->assertTrue($backend->rulesState()->isDisabled('focus'), 'a /rules toggle made before the switch must survive it');

        // ...and one made AFTER it reaches the new engine by identity.
        $after->rulesState()->toggle('terse');
        $this->assertTrue($backend->rulesState()->isDisabled('terse'));
    }

    public function testTheSwitchSurvivesAKeystrokeBetweenLaunchAndSwitch(): void
    {
        // The factory is Chat state — on the workspace since O-2a: a mutate()
        // that dropped the workspace would fall back to the embedder path on
        // the first character typed.
        $chat = Bootstrap::chat($this->tempDir . '/project');
        [$typed] = $chat->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertInstanceOf(Chat::class, $typed);

        $workspace = (new \ReflectionProperty(Chat::class, 'workspace'))->getValue($typed);
        $this->assertInstanceOf(WorkspaceContext::class, $workspace);
        $this->assertSame(
            (new \ReflectionProperty(Chat::class, 'workspace'))->getValue($chat),
            $workspace,
            'the keystroke clone must carry the launch\'s workspace by identity',
        );
    }

    public function testAnEmbedderSwitchCarriesTheChatsOwnManagerAndRulesSet(): void
    {
        $rules = RulesState::new(['focus']);
        $chat = new Chat(
            backend: new EchoBackend(),
            agentManager: Bootstrap::agentManager($this->tempDir . '/project'),
            projectRoot: $this->tempDir . '/project',
            rulesState: $rules,
        );

        $after = $this->submit($chat, '/model custom');
        $backend = $after->backend();

        $this->assertInstanceOf(EngineBackend::class, $backend);
        $this->assertNotSame([], $this->taskTools($backend), 'a Chat holding a manager must keep Task across the switch');
        $this->assertSame($rules, $backend->rulesState());
        $this->assertTrue($backend->rulesState()->isDisabled('focus'));
    }

    /**
     * W1-h's carried half (N-P3): the Chat's OWN pool config — the one
     * {@see Chat::executeAgents()} builds its process-executor pool from —
     * used to keep the launch provider's `workerProvider` spec after a switch,
     * so an agent on that path still ran on the provider just left.
     */
    public function testASwitchRebuildsTheChatsOwnPoolWorkerSpec(): void
    {
        $chat = new Chat(
            backend: new EchoBackend(),
            agentPoolConfig: new \SugarCraft\Crush\Agents\AgentPoolConfig(workerProvider: ['type' => 'echo']),
            projectRoot: $this->tempDir . '/project',
        );

        $after = $this->submit($chat, '/model custom');
        $backend = $after->backend();
        $this->assertInstanceOf(EngineBackend::class, $backend);

        $spec = $after->agentPoolConfig()?->workerProvider;
        $this->assertIsArray($spec, 'a switch to a real provider must leave a spec a worker can build');
        $this->assertSame('custom', $spec['type'] ?? null, 'the spec names the provider switched TO');
        $this->assertSame($backend->model(), $spec['model'] ?? null, 'and the model that engine runs');
    }

    private function submit(Chat $chat, string $line): Chat
    {
        // The draft is set the way a paste lands it; the submit is a real Enter.
        $drafted = (new \ReflectionMethod(Chat::class, 'withInputBuf'))->invoke($chat, $line);
        [$next] = $drafted->update(new KeyMsg(KeyType::Enter));
        $this->assertInstanceOf(Chat::class, $next);

        return $next;
    }

    /** @return list<TaskTool> */
    private function taskTools(EngineBackend $backend): array
    {
        return array_values(array_filter(
            $backend->tools(),
            static fn(object $tool): bool => $tool instanceof TaskTool,
        ));
    }

    private function removeProviderSwitchFixture(string $dir): void
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
