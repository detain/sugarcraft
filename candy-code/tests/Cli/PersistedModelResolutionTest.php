<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\LaunchContext;
use SugarCraft\Code\Config\LayeredSettings;
use SugarCraft\Code\Config\Settings\SettingSource;
use SugarCraft\Code\Config\Settings\SettingsSchema;
use SugarCraft\Code\Tui\Settings\SettingsSources;
use SugarCraft\Code\Tests\Support\BackendSelectionEnvSandboxTrait;

/**
 * Decision D9: the model choice persists as `models: {"<provider>": "<model>"}`
 * on the user tier, and resolves `--model` > `$CANDY_CODE_MODEL` > the entry for
 * THIS provider > the provider default — at BOTH readers that share
 * `Bootstrap::selectedModelName()`, so the status-bar caption cannot name a
 * different model than the backend runs.
 */
final class PersistedModelResolutionTest extends TestCase
{
    use BackendSelectionEnvSandboxTrait;

    private string $tempDir;
    private string $originalHome;
    private mixed $originalServerHome;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/persisted_model_' . uniqid('', true);
        mkdir($this->tempDir . '/home/.candy-code', 0700, true);

        $this->originalHome = getenv('HOME') ?: '';
        putenv('HOME=' . $this->tempDir . '/home');
        $this->originalServerHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $this->tempDir . '/home';

        $this->clearBackendSelectionEnv();
        Bootstrap::useModel(null);
        Bootstrap::useConfigPath(null);
    }

    protected function tearDown(): void
    {
        Bootstrap::useModel(null);
        Bootstrap::useConfigPath(null);

        if ($this->originalHome !== '') {
            putenv('HOME=' . $this->originalHome);
        } else {
            putenv('HOME');
        }

        if ($this->originalServerHome === null) {
            unset($_SERVER['HOME']);
        } else {
            $_SERVER['HOME'] = $this->originalServerHome;
        }

        $this->restoreBackendSelectionEnv();
        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    public function testThePersistedModelForTheSelectedProviderIsTheCaption(): void
    {
        $this->writeConfig(['models' => ['openai' => 'gpt-persisted', 'sglang' => 'other-provider-model']]);
        putenv('CANDY_CODE_PROVIDER=openai');

        self::assertSame(['openai', 'gpt-persisted'], Bootstrap::selectedProviderLabel());
    }

    public function testThePersistedModelIsTheModelTheBackendActuallyUses(): void
    {
        $this->writeConfig(['models' => ['sglang' => 'persisted-for-sglang']]);

        $backend = Bootstrap::backendFor('sglang');

        self::assertSame('persisted-for-sglang', (new \ReflectionProperty($backend, 'model'))->getValue($backend));
    }

    /** Keyed by provider: another provider's entry never reaches this one. */
    public function testAnotherProvidersEntryIsNotUsed(): void
    {
        $this->writeConfig(['models' => ['openai' => 'gpt-persisted']]);

        $backend = Bootstrap::backendFor('sglang');

        self::assertNotSame('gpt-persisted', (new \ReflectionProperty($backend, 'model'))->getValue($backend));
    }

    public function testTheEnvironmentVariableOutranksThePersistedModel(): void
    {
        $this->writeConfig(['models' => ['openai' => 'gpt-persisted']]);
        putenv('CANDY_CODE_PROVIDER=openai');
        putenv('CANDY_CODE_MODEL=from-the-environment');

        self::assertSame('from-the-environment', Bootstrap::selectedProviderLabel()[1]);
    }

    public function testTheFlagOutranksThePersistedModel(): void
    {
        $this->writeConfig(['models' => ['openai' => 'gpt-persisted']]);
        putenv('CANDY_CODE_PROVIDER=openai');
        Bootstrap::useModel('from-the-flag');

        self::assertSame('from-the-flag', Bootstrap::selectedProviderLabel()[1]);
    }

    /** `models` is layered, so the hand-authored settings.json counts too. */
    public function testTheUserSettingsFileCanPersistTheModel(): void
    {
        file_put_contents(
            $this->tempDir . '/home/.candy-code/' . LayeredSettings::USER_FILE,
            (string) json_encode(['models' => ['openai' => 'from-settings-json']]),
        );
        putenv('CANDY_CODE_PROVIDER=openai');

        self::assertSame('from-settings-json', Bootstrap::selectedProviderLabel()[1]);
    }

    /** A malformed entry is ignored, never sent: the provider default applies. */
    public function testAMalformedEntryFallsBackToTheProviderDefault(): void
    {
        $this->writeConfig(['models' => ['sglang' => ['not' => 'a string']]]);
        $default = (new \ReflectionProperty($backend = Bootstrap::backendFor('sglang'), 'model'))->getValue($backend);

        $this->writeConfig(['models' => ['sglang' => '   ']]);
        $blank = Bootstrap::backendFor('sglang');

        self::assertSame($default, (new \ReflectionProperty($blank, 'model'))->getValue($blank));
        self::assertNotSame('   ', $default);
    }

    /** No top-level `model` key: it is not layered and nothing reads it. */
    public function testATopLevelModelKeyIsStillInert(): void
    {
        $this->writeConfig(['model' => 'a-flat-model']);
        putenv('CANDY_CODE_PROVIDER=openai');

        self::assertNotSame('a-flat-model', Bootstrap::selectedProviderLabel()[1]);
        self::assertNotContains('model', LayeredSettings::LAYERED_KEYS);
        self::assertContains('models', LayeredSettings::LAYERED_KEYS);
        self::assertNotContains('models', LayeredSettings::PROJECT_TIER_KEYS);
    }

    /**
     * AUD §2 #54: the launch reads `$CANDY_CODE_MODEL` through the same typed
     * resolver the settings view explains `models` with, so the value the view
     * shows as "set by $CANDY_CODE_MODEL" is the value the launch runs —
     * including a value with surrounding spaces, which neither path trims.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('environmentModels')]
    public function testTheEnvironmentModelResolvesIdenticallyOnTheLaunchAndTheTuiPaths(string $value): void
    {
        $this->writeConfig(['models' => ['openai' => 'gpt-persisted']]);
        putenv('CANDY_CODE_MODEL=' . $value);

        $tui = $this->tuiResolution(['CANDY_CODE_MODEL' => $value], []);

        self::assertSame(SettingSource::Env, $tui->source);
        self::assertSame($tui->value, LaunchContext::selectedModelName('openai'));
        self::assertSame($value, LaunchContext::selectedModelName('openai'));
    }

    /** @return iterable<string, array{string}> */
    public static function environmentModels(): iterable
    {
        yield 'a plain id' => ['from-the-environment'];
        yield 'surrounding spaces kept' => [' spaced-model '];
        yield 'a zero is a value, not a disable flag' => ['0'];
    }

    public function testAnEmptyEnvironmentModelIsAbsentOnBothPaths(): void
    {
        $this->writeConfig(['models' => ['openai' => 'gpt-persisted']]);
        putenv('CANDY_CODE_MODEL=');

        self::assertNotSame(SettingSource::Env, $this->tuiResolution(['CANDY_CODE_MODEL' => ''], [])->source);
        self::assertSame('gpt-persisted', LaunchContext::selectedModelName('openai'), 'empty is absence: the persisted entry answers');
    }

    public function testTheFlagOutranksTheEnvironmentOnBothPaths(): void
    {
        putenv('CANDY_CODE_MODEL=from-the-environment');
        Bootstrap::useModel('from-the-flag');

        $tui = $this->tuiResolution(['CANDY_CODE_MODEL' => 'from-the-environment'], ['--model' => 'from-the-flag']);

        self::assertSame(SettingSource::Flag, $tui->source);
        self::assertSame($tui->value, LaunchContext::selectedModelName('openai'));
    }

    /**
     * @param array<string, string> $env
     * @param array<string, mixed> $flags
     */
    private function tuiResolution(array $env, array $flags): \SugarCraft\Code\Config\Settings\ResolvedSetting
    {
        $definition = SettingsSchema::byKey('models');
        self::assertNotNull($definition);

        return SettingsSources::fromLaunch(null, null, $this->tempDir . '/home/.candy-code', Bootstrap::userConfigPath(), $env, $flags)
            ->resolver->resolve($definition);
    }

    /** @param array<string, mixed> $config */
    private function writeConfig(array $config): void
    {
        file_put_contents($this->tempDir . '/home/.candy-code/config.json', (string) json_encode($config));
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
