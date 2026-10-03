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
        // The factory is Chat state: a mutate() that dropped it would fall
        // back to the embedder path on the first character typed.
        $chat = Bootstrap::chat($this->tempDir . '/project');
        [$typed] = $chat->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertInstanceOf(Chat::class, $typed);

        $factory = new \ReflectionProperty(Chat::class, 'backendFactory');
        $this->assertInstanceOf(\Closure::class, $factory->getValue($typed));
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
