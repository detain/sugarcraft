<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\LayeredSettings;
use SugarCraft\Crush\Support\ProcessContainment;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * Audit F-E1, the settings half: `secretEnvAllowlist` is read from the USER
 * tier by the two builders of the scrubbed spawn sites — {@see Bootstrap::tools()}
 * (Bash, Grep) and `Bootstrap::hooks()` (script hooks) — and installed on
 * {@see ProcessContainment::useSecretEnvAllowlist()}. A project file may not
 * set it at any trust level: `["*"]` from a clone would hand the model every
 * credential in the operator's shell.
 *
 * Runs against a scratch HOME (never the real `~/.sugar-crush`).
 */
final class BootstrapSecretEnvAllowlistTest extends TestCase
{
    use HomeSandboxTrait;

    private string $tmpDir = '';
    private string $configDir = '';
    private string $projectRoot = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/bootstrap_secret_env_' . uniqid('', true);
        $home = $this->tmpDir . '/home';
        $this->configDir = $home . '/.sugar-crush';
        $this->projectRoot = $this->tmpDir . '/repo';

        mkdir($this->configDir, 0o700, true);
        mkdir($this->projectRoot . '/' . LayeredSettings::dir(), 0o700, true);

        $this->useHomeSandbox($home);
        ProcessContainment::useSecretEnvAllowlist(['LEFT_OVER_TOKEN']);
    }

    protected function tearDown(): void
    {
        Bootstrap::useProjectRootForSettings(null);
        Bootstrap::useConfigPath(null);
        ProcessContainment::useSecretEnvAllowlist([]);
        $this->restoreHomeSandbox();
        $this->removeTree($this->tmpDir);

        parent::tearDown();
    }

    public function testTheKeyIsLayeredAndUserTierOnly(): void
    {
        self::assertContains('secretEnvAllowlist', LayeredSettings::LAYERED_KEYS);
        self::assertNotContains('secretEnvAllowlist', LayeredSettings::PROJECT_TIER_KEYS);
        self::assertContains('secretEnvAllowlist', LayeredSettings::userTierOnlyKeys());
    }

    public function testToolsInstallsTheUserSettingsAllowlist(): void
    {
        $this->writeUserSettingsJson(['secretEnvAllowlist' => ['GITHUB_TOKEN', 'NPM_*']]);

        Bootstrap::tools($this->projectRoot);

        self::assertSame(['GITHUB_TOKEN', 'NPM_*'], ProcessContainment::secretEnvAllowlist());
    }

    public function testTheWrittenConfigIsUserTierToo(): void
    {
        $this->writeUserConfigFile(['secretEnvAllowlist' => ['GH_TOKEN']]);

        Bootstrap::tools($this->projectRoot);

        self::assertSame(['GH_TOKEN'], ProcessContainment::secretEnvAllowlist());
    }

    public function testHooksInstallsItBeforeAnyScriptHookCanRun(): void
    {
        $this->writeUserSettingsJson(['secretEnvAllowlist' => ['SLACK_TOKEN']]);

        $hooks = new \ReflectionMethod(Bootstrap::class, 'hooks');
        $hooks->invoke(null, null, $this->projectRoot);

        self::assertSame(['SLACK_TOKEN'], ProcessContainment::secretEnvAllowlist());
    }

    /**
     * Unset — and anything but a list — restores the full scrub rather than
     * leaving a previous install in force.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function unusableValues(): iterable
    {
        yield 'a bare string' => ['GITHUB_TOKEN'];
        yield 'a map' => [['GITHUB_TOKEN' => true]];
        yield 'a number' => [7];
    }

    /**
     * @dataProvider unusableValues
     */
    public function testAnUnusableValueScrubsEverything(mixed $value): void
    {
        $this->writeUserSettingsJson(['secretEnvAllowlist' => $value]);

        Bootstrap::tools($this->projectRoot);

        self::assertSame([], ProcessContainment::secretEnvAllowlist());
    }

    public function testAnUnsetKeyClearsAnEarlierInstall(): void
    {
        Bootstrap::tools($this->projectRoot);

        self::assertSame([], ProcessContainment::secretEnvAllowlist());
    }

    /**
     * The point of the tiering: even a project the operator TRUSTED for
     * settings cannot widen what the model may read.
     */
    public function testATrustedProjectCannotSetIt(): void
    {
        $canonical = realpath($this->projectRoot);
        self::assertIsString($canonical);
        $this->writeUserConfigFile([LayeredSettings::PROJECT_SETTINGS_TRUST_KEY => [$canonical]]);
        file_put_contents(
            $this->projectRoot . '/' . LayeredSettings::SHARED_PATH,
            (string) json_encode(['secretEnvAllowlist' => ['*'], 'theme' => 'from-project']),
        );
        Bootstrap::useProjectRootForSettings($this->projectRoot);

        self::assertSame('from-project', Bootstrap::readUserConfig()['theme'] ?? null, 'the fixture project must really be trusted');

        Bootstrap::tools($this->projectRoot);

        self::assertSame([], ProcessContainment::secretEnvAllowlist());
    }

    /** @param array<string, mixed> $data */
    private function writeUserConfigFile(array $data): void
    {
        file_put_contents($this->configDir . '/config.json', (string) json_encode($data));
        chmod($this->configDir . '/config.json', 0o600);
    }

    /** @param array<string, mixed> $data */
    private function writeUserSettingsJson(array $data): void
    {
        file_put_contents($this->configDir . '/' . LayeredSettings::USER_FILE, (string) json_encode($data));
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var \SplFileInfo $entry */
        foreach ($entries as $entry) {
            $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($dir);
    }
}
