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
use SugarCraft\Crush\Config\Settings\ModelChoice;
use SugarCraft\Crush\Config\Settings\SettingsWriter;
use SugarCraft\Crush\Host\WorkspaceContext;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Palette\PaletteState;
use SugarCraft\Crush\Providers\EchoProvider;
use SugarCraft\Crush\Tests\Support\BackendSelectionEnvSandboxTrait;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * N-P3b: `/model` switches the MODEL, not just the provider.
 *
 * `/model <provider> <model>` saves `models[provider]` through the workspace's
 * {@see SettingsWriter} (decision D9), BEFORE the backend is built, so the
 * launch's factory constructs the provider on the chosen id; the engine is then
 * pinned to it with {@see EngineBackend::withModel()}.
 */
final class ModelCommandSelectsModelTest extends TestCase
{
    use BackendSelectionEnvSandboxTrait;
    use HomeSandboxTrait;

    private string $tempDir = '';

    /** @var string|false */
    private string|false $originalCustomKey = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/sugarcrush_model_axis_' . uniqid('', true);
        mkdir($this->tempDir . '/project', 0700, true);
        $this->useHomeSandbox($this->tempDir . '/home');
        $this->clearBackendSelectionEnv();
        Bootstrap::useConfigPath(null);

        // `custom` builds without a network round trip; it only wants a key.
        $this->originalCustomKey = getenv('CUSTOM_API_KEY');
        putenv('CUSTOM_API_KEY=test-key');
    }

    protected function tearDown(): void
    {
        $this->originalCustomKey === false
            ? putenv('CUSTOM_API_KEY')
            : putenv('CUSTOM_API_KEY=' . $this->originalCustomKey);
        Bootstrap::useConfigPath(null);
        $this->restoreBackendSelectionEnv();
        $this->restoreHomeSandbox();
        $this->removeTree($this->tempDir);

        parent::tearDown();
    }

    public function testProviderAndModelSwitchesTheModelAndSavesIt(): void
    {
        $provided = [];
        $chat = $this->chat()->withOnConfigChange(function (string $k, string $v) use (&$provided): void {
            $provided[] = [$k, $v];
        });

        $after = $this->submit($chat, '/model custom my-model-1');
        $backend = $after->backend();

        $this->assertInstanceOf(EngineBackend::class, $backend);
        $this->assertSame('my-model-1', $backend->model(), 'the engine must send the chosen model');
        $this->assertSame(
            'my-model-1',
            (new \ReflectionProperty($backend->provider(), 'model'))->getValue($backend->provider()),
            'the provider must be BUILT on the chosen id (saved first, then resolved by the factory), not relabelled',
        );
        $this->assertSame(['custom' => 'my-model-1'], $this->savedModels());
        $this->assertSame([['provider', 'custom']], $provided, 'the provider still goes through onConfigChange, and only it');

        $reply = $this->lastReply($after);
        $this->assertStringContainsString("Switched to provider 'custom', model 'my-model-1'", $reply);
        $this->assertStringContainsString('saved as models.custom', $reply);
    }

    public function testTheSaveCarriesTheOtherProvidersEntries(): void
    {
        Bootstrap::writeUserConfig(['models' => ['openai' => 'gpt-4o']]);

        $this->submit($this->chat(), '/model custom my-model-2');

        $this->assertSame(['custom' => 'my-model-2', 'openai' => 'gpt-4o'], $this->savedModels());
    }

    public function testTheSaveCarriesASettingsJsonMapRatherThanMaskingIt(): void
    {
        $dir = $this->tempDir . '/home/.sugar-crush';
        is_dir($dir) || mkdir($dir, 0700, true);
        file_put_contents($dir . '/settings.json', json_encode(['models' => ['anthropic' => 'claude-x']]));

        $this->submit($this->chat(), '/model custom my-model-3');

        $this->assertSame(
            ['anthropic' => 'claude-x', 'custom' => 'my-model-3'],
            $this->savedModels(),
            'config.json masks settings.json key-wise, so the entry settings.json held must be carried into it',
        );
    }

    public function testTheChosenModelWinsThisSessionOverAnEnvironmentPin(): void
    {
        putenv('SUGARCRUSH_MODEL=env-model');

        $after = $this->submit($this->chat(), '/model custom picked-model');

        $backend = $after->backend();
        $this->assertInstanceOf(EngineBackend::class, $backend);
        $this->assertSame('picked-model', $backend->model());
        $this->assertSame(['custom' => 'picked-model'], $this->savedModels(), 'and it is still saved for the next launch');
    }

    public function testWithoutAWriterTheModelSwitchesForThisSessionOnly(): void
    {
        $chat = new Chat(backend: new EchoBackend(), projectRoot: $this->tempDir . '/project');

        $after = $this->submit($chat, '/model custom session-model');

        $backend = $after->backend();
        $this->assertInstanceOf(EngineBackend::class, $backend);
        $this->assertSame('session-model', $backend->model());
        $this->assertSame([], $this->savedModels());
        $this->assertStringContainsString('for this session only', $this->lastReply($after));
    }

    public function testAnUnknownProviderSavesNothingAndSwitchesNothing(): void
    {
        $chat = $this->chat();

        $after = $this->submit($chat, '/model no-such-provider some-model');

        $this->assertSame($chat->backend(), $after->backend());
        $this->assertSame([], $this->savedModels(), 'a model is never saved under a name that is not a provider');
        $this->assertStringContainsString("Could not switch to provider 'no-such-provider'", $this->lastReply($after));
    }

    public function testMoreThanTwoWordsAnswersWithTheUsage(): void
    {
        $after = $this->submit($this->chat(), '/model custom a b');

        $this->assertStringContainsString('Usage: /model [provider [model]]', $this->lastReply($after));
        $this->assertSame([], $this->savedModels());
    }

    public function testWithModelIsACopyAndRefusesABlankId(): void
    {
        $engine = EngineBackend::new(new EchoProvider(), 'first');
        $switched = $engine->withModel('second');

        $this->assertSame('first', $engine->model());
        $this->assertSame('second', $switched->model());

        $this->expectException(\InvalidArgumentException::class);
        $engine->withModel('  ');
    }

    public function testModelChoiceMergesAndListsPickerRows(): void
    {
        $this->assertSame(
            ['a' => 'x', 'b' => 'new', 'c' => 'z'],
            ModelChoice::merged(['a' => 'x', 'b' => 'old'], ['c' => 'z', 'b' => 'file'], 'b', 'new'),
        );
        $this->assertSame(['run', 'saved', 'default'], ModelChoice::paletteLabels('run', 'saved', 'default'));
        $this->assertSame(['same', 'default'], ModelChoice::paletteLabels('same', 'same', 'default'));
        $this->assertSame(['default'], ModelChoice::paletteLabels(null, ' ', 'default'));
    }

    public function testThePaletteCarriesTheProviderOfAModelList(): void
    {
        $models = PaletteState::root()->withModelsOf('custom');

        $this->assertSame('models', $models->mode);
        $this->assertSame('custom', $models->provider);
        $this->assertTrue($models->isPicker());
        $this->assertSame('custom', $models->withQuery('gp')->withSelectedIndex(1)->withQueryCursor(1)->provider);
        $this->assertNull($models->withMode('providers')->provider, 'leaving the list drops its provider');
        $this->assertFalse(PaletteState::root()->isPicker());
    }

    private function chat(): Chat
    {
        $writer = SettingsWriter::new(
            Bootstrap::userConfigPath(),
            static fn(array $set, array $unset) => Bootstrap::writeUserConfig($set, $unset),
        );

        return new Chat(
            backend: new EchoBackend(),
            projectRoot: $this->tempDir . '/project',
            workspace: WorkspaceContext::new(root: $this->tempDir . '/project')
                ->withService(SettingsWriter::class, $writer),
        );
    }

    private function submit(Chat $chat, string $line): Chat
    {
        $drafted = (new \ReflectionMethod(Chat::class, 'withInputBuf'))->invoke($chat, $line);
        [$next] = $drafted->update(new KeyMsg(KeyType::Enter));
        $this->assertInstanceOf(Chat::class, $next);

        return $next;
    }

    /** @return array<string, string> */
    private function savedModels(): array
    {
        $path = Bootstrap::userConfigPath();
        if (!is_file($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true);

        return \is_array($data) && \is_array($data['models'] ?? null) ? $data['models'] : [];
    }

    private function lastReply(Chat $chat): string
    {
        $history = $chat->history;
        $last = end($history);
        $this->assertInstanceOf(Message::class, $last);

        return $last->content;
    }

    private function removeTree(string $dir): void
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
