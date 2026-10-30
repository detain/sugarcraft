<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Commands;

use OpenAI\Contracts\ClientContract;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Commands\EffortDial;
use SugarCraft\Code\Commands\Specs\BuiltInCommands;
use SugarCraft\Code\Config\Settings\SettingsWriter;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\CommandEffectKind;
use SugarCraft\Code\Host\Commands\EffortHostCommand;
use SugarCraft\Code\Host\Commands\HostCommand;
use SugarCraft\Code\Host\Commands\PermissionsCommand;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Providers\AnthropicProvider;
use SugarCraft\Code\Providers\OpenAIProvider;
use SugarCraft\Code\Tests\Support\BackendSelectionEnvSandboxTrait;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tests\Support\PinsEnglishLocaleTrait;

/**
 * C-WEB-2.f: the served `/effort` answers the SAME rows as the terminal's
 * (one shared {@see EffortDial} makes that structural, not aspirational) and
 * — by deliberate contrast with the `/fast` sibling, whose session-only save
 * the 0300 doctrine states — the tier here PERSISTS through the workspace's
 * SettingsWriter, the same door the terminal's `Chat::persistEffort()` opens
 * and `ModelHostCommand` opened for the model choice. Every pin asserts both
 * halves: the dialled backend rides the unchanged-names `SetModel` effect to
 * the session, AND the config file carries (or, for `auto`, loses) the key.
 *
 * The refusal and read branches must never touch the writer: the banned
 * failure has always been a saved key no wire reads, so a pair that shapes
 * nothing lands nowhere at all — on either surface.
 */
final class EffortHostCommandTest extends TestCase
{
    use BackendSelectionEnvSandboxTrait;
    use HomeSandboxTrait;
    use PinsEnglishLocaleTrait;

    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/candy_code_effort_host_' . uniqid('', true);
        mkdir($this->root . '/project', 0700, true);
        $this->useHomeSandbox($this->root . '/home');
        $this->clearBackendSelectionEnv();
        Bootstrap::useConfigPath(null);
    }

    protected function tearDown(): void
    {
        Bootstrap::useConfigPath(null);
        $this->restoreBackendSelectionEnv();
        $this->restoreHomeSandbox();
        $this->removeHostTree($this->root);

        parent::tearDown();
    }

    public function testTheSpellingCarriesTheHostBodyAndItsHandler(): void
    {
        $spec = BuiltInCommands::forSpelling('effort');

        $this->assertNotNull($spec);
        $this->assertSame(EffortHostCommand::class, $spec?->hostCommand,
            'command.list derives runsIn from this field - a served /effort moves client to server');
        $this->assertSame('handleEffortCommand', $spec?->handler,
            'the terminal keeps its typed route; both ride the same dial');
        $this->assertInstanceOf(HostCommand::class, $spec?->instantiateHostCommand());
    }

    public function testAServedHighDialsTheSessionBackendAndSavesTheTier(): void
    {
        $result = (new EffortHostCommand())->run($this->context(), '/effort high');

        $this->assertNull($result->error);
        $this->assertHostRow('Effort set to high on anthropic', $result);
        $this->assertHostRow('saved as reasoningEffort', $result);

        $effect = $result->effect(CommandEffectKind::SetModel);
        $this->assertNotNull($effect, 'the dialled backend travels on the set-model leg');
        $backend = $effect?->modelBackend();
        $this->assertInstanceOf(EngineBackend::class, $backend);
        $this->assertSame('high', $backend instanceof EngineBackend ? $backend->reasoningEffort() : null);
        $this->assertSame('claude-opus-5', $effect?->modelName(), 'the pair is restated, not replaced');
        $this->assertSame('anthropic', $effect?->modelProvider());

        $this->assertSame('high', Bootstrap::readUserConfig()['reasoningEffort'] ?? null,
            'the saved tier is the point of the design - same key the terminal saves');
    }

    public function testTheSavedRowIsTheTerminalRowWordForWord(): void
    {
        $result = (new EffortHostCommand())->run($this->context(), '/effort high');

        // The terminal prints Lang::t('chat.effort.set', level, provider, note)
        // with note = chat.effort.note_saved(path) - the same authorities the
        // host row must reproduce byte for byte, so the two surfaces cannot
        // quietly fork their wording while sharing the decision.
        $expected = Lang::t('chat.effort.set', [
            'level' => 'high',
            'provider' => 'anthropic',
            'note' => Lang::t('chat.effort.note_saved', [
                'path' => PermissionsCommand::reportField(Bootstrap::userConfigPath()),
            ]),
        ]);

        $rows = $result->rows;
        $last = end($rows);
        $this->assertSame($expected, (string) ($last->content ?? ''));
    }

    public function testAutoDeletesTheSavedKeyAndMovesTheOverlayToNull(): void
    {
        $command = new EffortHostCommand();

        $command->run($this->context(), '/effort high');
        $this->assertSame('high', Bootstrap::readUserConfig()['reasoningEffort'] ?? null);

        $result = $command->run($this->context(), '/effort auto');

        $this->assertHostRow('Effort cleared on anthropic', $result);
        $this->assertHostRow('removed reasoningEffort from', $result);

        $effect = $result->effect(CommandEffectKind::SetModel);
        $this->assertNull($effect?->modelBackend()?->reasoningEffort());
        $this->assertArrayNotHasKey('reasoningEffort', Bootstrap::readUserConfig(),
            'auto hands the tier back to lower layers - a written empty string would shadow them');
    }

    public function testAnUnshapedPairOnARosterProviderRefusesAndWritesNothing(): void
    {
        $configPath = Bootstrap::userConfigPath();
        $before = is_file($configPath) ? (string) file_get_contents($configPath) : null;

        $result = (new EffortHostCommand())->run(
            $this->context(backend: new EngineBackend(
                new OpenAIProvider($this->createMock(ClientContract::class), 'gpt-4o'),
                'gpt-4o',
            )),
            '/effort high',
        );

        $this->assertHostRow('openai sends no reasoning-effort field for gpt-4o', $result);
        $this->assertNull($result->effect(CommandEffectKind::SetModel),
            'a refusal that shipped an effect would be the set-but-ignored lie this step exists to ban');
        $this->assertSame($before, is_file($configPath) ? (string) file_get_contents($configPath) : null);
    }

    public function testBareReadsTheLiveOverlayWithoutAnyEffectOrWrite(): void
    {
        $armed = EngineBackend::new($this->provider(), 'claude-opus-5')->withReasoningEffort('medium');

        $result = (new EffortHostCommand())->run($this->context(backend: $armed), '/effort');

        $this->assertNull($result->error);
        $this->assertHostRow('Reasoning effort: medium on anthropic', $result);
        $this->assertNull($result->effect(CommandEffectKind::SetModel));
        $this->assertArrayNotHasKey('reasoningEffort', Bootstrap::readUserConfig());
    }

    public function testAReadOnlySessionIsRefusedBeforeAnythingElse(): void
    {
        $result = (new EffortHostCommand())->run($this->context(readOnly: true), '/effort high');

        $this->assertNotNull($result->error);
        $this->assertStringContainsString('/effort', (string) $result->error);
        $this->assertSame([], $result->rows);
        $this->assertNull($result->effect(CommandEffectKind::SetModel));
        $this->assertArrayNotHasKey('reasoningEffort', Bootstrap::readUserConfig());
    }

    public function testAReadOnlySessionCanStillReadTheDialBare(): void
    {
        $result = (new EffortHostCommand())->run($this->context(readOnly: true), '/effort');

        $this->assertNull($result->error, 'a bare read changes nothing; refusing it would hide the session state');
        $this->assertHostRow('Reasoning effort: unset on anthropic', $result);
    }

    public function testExtraWordsAnswerTheUsageLineAndNothingElse(): void
    {
        $result = (new EffortHostCommand())->run($this->context(), '/effort very high');

        $this->assertHostRow('Usage: /effort', $result);
        $this->assertNull($result->effect(CommandEffectKind::SetModel));
        $this->assertArrayNotHasKey('reasoningEffort', Bootstrap::readUserConfig());
    }

    public function testASessionWithNoEngineBackendIsToldPlainlyAndTheDeleteStillLands(): void
    {
        $command = new EffortHostCommand();
        $noEngine = CommandContext::new(
            root: $this->root . '/project',
            backend: null,
            workspace: WorkspaceContext::new(root: $this->root . '/project')->withService(
                SettingsWriter::class,
                SettingsWriter::new(
                    Bootstrap::userConfigPath(),
                    static fn (array $set, array $unset) => Bootstrap::writeUserConfig($set, $unset),
                ),
            ),
        );

        $this->assertHostRow('no engine backend', $command->run($noEngine, '/effort'));
        $this->assertNull($command->run($noEngine, '/effort high')->effect(CommandEffectKind::SetModel));

        $command->run($this->context(), '/effort high');
        $cleared = $command->run($noEngine, '/effort auto');

        $this->assertHostRow('Effort cleared on this backend', $cleared,
            'with no provider to name, the row names the backend class plainly - and the key still goes');
        $this->assertArrayNotHasKey('reasoningEffort', Bootstrap::readUserConfig());
    }

    // ---- harness ----------------------------------------------------------

    private function provider(): AnthropicProvider
    {
        return new AnthropicProvider('sk-test', 'https://api.anthropic.com', 'claude-opus-5');
    }

    private function context(?EngineBackend $backend = null, bool $readOnly = false): CommandContext
    {
        return CommandContext::new(
            root: $this->root . '/project',
            readOnly: $readOnly,
            backend: $backend ?? EngineBackend::new($this->provider(), 'claude-opus-5'),
            workspace: WorkspaceContext::new(root: $this->root . '/project')->withService(
                SettingsWriter::class,
                SettingsWriter::new(
                    Bootstrap::userConfigPath(),
                    static fn (array $set, array $unset) => Bootstrap::writeUserConfig($set, $unset),
                ),
            ),
        );
    }

    private function assertHostRow(string $needle, object $result, string $why = ''): void
    {
        $haystack = implode("\n", array_map(
            static fn ($row): string => (string) (is_object($row) ? ($row->content ?? '') : (string) $row),
            $result->rows ?? [],
        ));

        $this->assertStringContainsString($needle, $haystack, 'rows were: ' . $haystack . ($why === '' ? '' : ' — ' . $why));
    }

    private function removeHostTree(string $dir): void
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
