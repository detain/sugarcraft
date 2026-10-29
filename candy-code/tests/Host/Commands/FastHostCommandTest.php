<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Config\Settings\SettingsWriter;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\CommandEffectKind;
use SugarCraft\Code\Host\Commands\FastHostCommand;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Providers\AnthropicProvider;
use SugarCraft\Code\Providers\ShapesServiceTier;
use SugarCraft\Code\Tests\Support\BackendSelectionEnvSandboxTrait;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tests\Support\PinsEnglishLocaleTrait;

/**
 * C-PRV-3.b: the served `/fast` is the same dial the terminal turns, with one
 * deliberate difference — it never writes settings. The terminal owns the
 * saved word (the 0300-model doctrine the design block adopts verbatim); a
 * served switch only re-arms the session's own engine overlay for the rest of
 * the session. Every pin here therefore asserts BOTH halves of that promise:
 * the effect carries the tiered backend, and the config file is byte-untouched.
 */
final class FastHostCommandTest extends TestCase
{
    use BackendSelectionEnvSandboxTrait;
    use HomeSandboxTrait;
    use PinsEnglishLocaleTrait;

    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/candy_code_fast_host_' . uniqid('', true);
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

    public function testOnArmsTheLaneForThisSessionAndWritesNothing(): void
    {
        $configPath = Bootstrap::userConfigPath();
        $before = is_file($configPath) ? (string) file_get_contents($configPath) : null;

        $result = (new FastHostCommand())->run($this->context(), '/fast on');

        $this->assertNull($result->error);
        $this->assertHostRow('Fast mode ON on anthropic for claude-opus-5', $result);
        $this->assertHostRow('this session only; changing the saved setting stays the terminal', $result);

        $effect = $result->effect(CommandEffectKind::SetModel);
        $this->assertNotNull($effect, 'the tiered backend travels to the session on the set-model leg');
        $backend = $effect?->modelBackend();
        $this->assertInstanceOf(EngineBackend::class, $backend);
        $this->assertSame(ShapesServiceTier::WORD_FAST, $backend instanceof EngineBackend ? $backend->serviceTier() : null);
        $this->assertSame('claude-opus-5', $effect?->modelName(), 'the pair is restated, not replaced');
        $this->assertSame('anthropic', $effect?->modelProvider());

        $this->assertSame($before, is_file($configPath) ? (string) file_get_contents($configPath) : null,
            'the served dial never writes settings — that stays the terminal\'s');
    }

    public function testOffMovesToTheStandardLaneAndWritesNothing(): void
    {
        $result = (new FastHostCommand())->run($this->context(), '/fast off');

        $this->assertNull($result->error);
        $this->assertHostRow('Fast mode OFF on anthropic', $result);

        $effect = $result->effect(CommandEffectKind::SetModel);
        $backend = $effect?->modelBackend();
        $this->assertSame(ShapesServiceTier::WORD_DEFAULT, $backend instanceof EngineBackend ? $backend->serviceTier() : null);
        $this->assertArrayNotHasKey('serviceTier', Bootstrap::readUserConfig());
    }

    public function testBareStatusReadsTheSessionBackendItWasHanded(): void
    {
        $armed = EngineBackend::new($this->provider(), 'claude-opus-5')
            ->withServiceTier(ShapesServiceTier::WORD_FAST);

        $result = (new FastHostCommand())->run($this->context(backend: $armed), '/fast');
        $this->assertHostRow('Fast mode: ON on anthropic for claude-opus-5 — the premium lane is armed.', $result);

        $unset = (new FastHostCommand())->run($this->context(), '/fast');
        $this->assertHostRow('Fast mode: OFF on anthropic — unset; this pair shapes a premium lane', $unset);

        $unshaped = (new FastHostCommand())->run(
            $this->context(backend: EngineBackend::new($this->provider(), 'claude-sonnet-4-6')),
            '/fast',
        );
        $this->assertHostRow('this pair shapes no premium lane, so /fast on would refuse', $unshaped);
    }

    public function testOnAnUnshapedPairRefusesWithoutAnEffect(): void
    {
        $result = (new FastHostCommand())->run(
            $this->context(backend: EngineBackend::new($this->provider(), 'claude-sonnet-4-6')),
            '/fast on',
        );

        $this->assertHostRow('anthropic sends no premium lane for claude-sonnet-4-6, so /fast changed nothing', $result);
        $this->assertNull($result->effect(CommandEffectKind::SetModel), 'a refusal that still shipped an effect would be the half-door this command exists to ban');
        $this->assertArrayNotHasKey('serviceTier', Bootstrap::readUserConfig());
    }

    public function testReadOnlySessionsAreRefusedBeforeAnythingElse(): void
    {
        $result = (new FastHostCommand())->run($this->context(readOnly: true), '/fast on');

        $this->assertNotNull($result->error);
        $this->assertStringContainsString('/fast', (string) $result->error);
        $this->assertSame([], $result->rows);
        $this->assertNull($result->effect(CommandEffectKind::SetModel));
    }

    public function testGarbageAndExtraWordsAnswerTheUsageLine(): void
    {
        foreach (['/fast turbo', '/fast on now', '/fast   '] as $text) {
            $result = (new FastHostCommand())->run($this->context(), $text);

            if ($text === '/fast   ') {
                // Whitespace-only tail is a bare read, not a usage error — the
                // word list simply comes back empty.
                $this->assertHostRow('Fast mode: OFF on anthropic', $result);
                continue;
            }

            $this->assertHostRow('Usage: /fast <on|off>', $result);
            $this->assertNull($result->effect(CommandEffectKind::SetModel));
        }
    }

    public function testASessionWithNoEngineBackendIsToldPlainly(): void
    {
        $context = CommandContext::new(
            root: $this->root . '/project',
            backend: null,
            workspace: WorkspaceContext::new(root: $this->root . '/project'),
        );
        $result = (new FastHostCommand())->run($context, '/fast on');

        $this->assertHostRow('no engine backend', $result);
        $this->assertNull($result->effect(CommandEffectKind::SetModel));
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
