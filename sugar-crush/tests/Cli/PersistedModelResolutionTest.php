<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\LayeredSettings;
use SugarCraft\Crush\Tests\Support\BackendSelectionEnvSandboxTrait;

/**
 * Decision D9: the model choice persists as `models: {"<provider>": "<model>"}`
 * on the user tier, and resolves `--model` > `$SUGARCRUSH_MODEL` > the entry for
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
        mkdir($this->tempDir . '/home/.sugar-crush', 0700, true);

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
        putenv('SUGARCRUSH_PROVIDER=openai');

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
        putenv('SUGARCRUSH_PROVIDER=openai');
        putenv('SUGARCRUSH_MODEL=from-the-environment');

        self::assertSame('from-the-environment', Bootstrap::selectedProviderLabel()[1]);
    }

    public function testTheFlagOutranksThePersistedModel(): void
    {
        $this->writeConfig(['models' => ['openai' => 'gpt-persisted']]);
        putenv('SUGARCRUSH_PROVIDER=openai');
        Bootstrap::useModel('from-the-flag');

        self::assertSame('from-the-flag', Bootstrap::selectedProviderLabel()[1]);
    }

    /** `models` is layered, so the hand-authored settings.json counts too. */
    public function testTheUserSettingsFileCanPersistTheModel(): void
    {
        file_put_contents(
            $this->tempDir . '/home/.sugar-crush/' . LayeredSettings::USER_FILE,
            (string) json_encode(['models' => ['openai' => 'from-settings-json']]),
        );
        putenv('SUGARCRUSH_PROVIDER=openai');

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
        putenv('SUGARCRUSH_PROVIDER=openai');

        self::assertNotSame('a-flat-model', Bootstrap::selectedProviderLabel()[1]);
        self::assertNotContains('model', LayeredSettings::LAYERED_KEYS);
        self::assertContains('models', LayeredSettings::LAYERED_KEYS);
        self::assertNotContains('models', LayeredSettings::PROJECT_TIER_KEYS);
    }

    /** @param array<string, mixed> $config */
    private function writeConfig(array $config): void
    {
        file_put_contents($this->tempDir . '/home/.sugar-crush/config.json', (string) json_encode($config));
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
