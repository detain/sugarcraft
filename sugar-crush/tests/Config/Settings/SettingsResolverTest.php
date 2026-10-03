<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Config\Settings;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Config\LayeredSettings;
use SugarCraft\Crush\Config\Settings\SettingSource;
use SugarCraft\Crush\Config\Settings\SettingsResolver;
use SugarCraft\Crush\Config\Settings\SettingsSchema;

/**
 * Provenance: which source wins for a key, which file that was, what it
 * shadowed, and which keys an environment variable or flag locks. Every case
 * asserts on a VALUE that came out of a named source, because the failure this
 * guards is a source that is read but loses, or wins when it should not.
 */
final class SettingsResolverTest extends TestCase
{
    private string $tmpRoot;
    private string $projectRoot;
    private string $userDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/sugarcrush_resolver_' . uniqid('', true);
        $this->projectRoot = $this->tmpRoot . '/repo';
        $this->userDir = $this->tmpRoot . '/home/.sugar-crush';

        mkdir($this->projectRoot . '/' . LayeredSettings::dir(), 0o700, true);
        mkdir($this->userDir, 0o700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpRoot);

        parent::tearDown();
    }

    public function testAKeyNoSourceSetsResolvesToTheSchemaDefault(): void
    {
        $resolved = SettingsResolver::new()->resolve($this->definition('parallelToolDeadlineSeconds'));

        self::assertSame(SettingSource::Default, $resolved->source);
        self::assertTrue($resolved->isDefault());
        self::assertSame($this->definition('parallelToolDeadlineSeconds')->default, $resolved->value);
        self::assertFalse($resolved->locked);
        self::assertSame([], $resolved->shadowed);
    }

    /** The four files, highest first: config.json > settings.json > local > shared. */
    public function testTheFileLayersRankAsTheMergeRanksThem(): void
    {
        $this->writeProject(LayeredSettings::SHARED_PATH, ['theme' => 'from-shared']);
        $this->writeProject(LayeredSettings::LOCAL_PATH, ['theme' => 'from-local']);
        $this->writeUser(LayeredSettings::USER_FILE, ['theme' => 'from-user-settings']);
        $this->writeUser('config.json', ['theme' => 'from-config']);

        $resolved = $this->fromFiles(trusted: true)->resolve($this->definition('theme'));
        self::assertSame('from-config', $resolved->value);
        self::assertSame(SettingSource::UserConfig, $resolved->source);
        self::assertSame($this->userDir . '/config.json', $resolved->sourcePath);
        self::assertSame(
            [SettingSource::UserSettings, SettingSource::ProjectLocal, SettingSource::ProjectShared],
            $resolved->shadowed,
        );

        unlink($this->userDir . '/config.json');
        unlink($this->userDir . '/' . LayeredSettings::USER_FILE);
        $resolved = $this->fromFiles(trusted: true)->resolve($this->definition('theme'));
        self::assertSame('from-local', $resolved->value);
        self::assertSame(SettingSource::ProjectLocal, $resolved->source);
        self::assertSame($this->projectRoot . '/' . LayeredSettings::LOCAL_PATH, $resolved->sourcePath);
    }

    public function testAnUntrustedProjectContributesNothing(): void
    {
        $this->writeProject(LayeredSettings::SHARED_PATH, ['theme' => 'from-shared', 'disabledTools' => ['Bash']]);

        $resolver = $this->fromFiles(trusted: false);

        self::assertTrue($resolver->resolve($this->definition('theme'))->isDefault());
        self::assertTrue($resolver->resolve($this->definition('disabledTools'))->isDefault());
    }

    /** A trusted project naming a user-tier-only key is ignored, not obeyed. */
    public function testATrustedProjectCannotSetAUserTierOnlyKey(): void
    {
        $this->writeProject(LayeredSettings::SHARED_PATH, [
            'provider' => 'project-host',
            'permissionMode' => 'bypass-permissions',
            LayeredSettings::PROJECT_SETTINGS_TRUST_KEY => ['/anywhere'],
        ]);

        $resolver = $this->fromFiles(trusted: true);

        self::assertTrue($resolver->resolve($this->definition('provider'))->isDefault());
        self::assertTrue($resolver->resolve($this->definition('permissionMode'))->isDefault());
        self::assertTrue($resolver->resolve($this->definition(LayeredSettings::PROJECT_SETTINGS_TRUST_KEY))->isDefault());
    }

    /**
     * `settings.json` answers the layered keys and the strict permission pair,
     * and nothing else — a trust list there is inert, as it is at launch.
     */
    public function testTheUserSettingsFileAnswersLayeredAndStrictKeysOnly(): void
    {
        $this->writeUser(LayeredSettings::USER_FILE, [
            'permissionMode' => 'plan',
            'trustedProjectHooks' => ['/srv/repo'],
            'enabledSkillsTypo' => true,
            'disabledRules' => ['x'],
        ]);

        $resolver = $this->fromFiles(trusted: false);

        self::assertSame('plan', $resolver->resolve($this->definition('permissionMode'))->value);
        self::assertSame(SettingSource::UserSettings, $resolver->resolve($this->definition('permissionMode'))->source);
        self::assertSame(['x'], $resolver->resolve($this->definition('disabledRules'))->value);
        self::assertTrue($resolver->resolve($this->definition('trustedProjectHooks'))->isDefault());
    }

    public function testAnExplicitNullInAHigherFileStillWins(): void
    {
        $this->writeUser(LayeredSettings::USER_FILE, ['instructions' => ['AGENTS.md']]);
        $this->writeUser('config.json', ['instructions' => null]);

        $resolved = $this->fromFiles(trusted: false)->resolve($this->definition('instructions'));

        self::assertNull($resolved->value);
        self::assertSame(SettingSource::UserConfig, $resolved->source);
        self::assertSame([SettingSource::UserSettings], $resolved->shadowed);
    }

    /** An env override wins over every file and locks the field, naming itself. */
    public function testAnEnvironmentOverrideWinsAndLocksTheKey(): void
    {
        $this->writeUser('config.json', ['provider' => 'openai']);

        $resolved = $this->fromFiles(trusted: false)
            ->withEnvironment(['SUGARCRUSH_PROVIDER' => 'anthropic'])
            ->resolve($this->definition('provider'));

        self::assertSame('anthropic', $resolved->value);
        self::assertSame(SettingSource::Env, $resolved->source);
        self::assertTrue($resolved->locked);
        self::assertSame('set by SUGARCRUSH_PROVIDER', $resolved->lockReason);
        self::assertSame([SettingSource::UserConfig], $resolved->shadowed);
    }

    /** `SUGARCRUSH_DISABLE_*`: any value but empty or `0` turns the key OFF. */
    public function testADisableFlagInvertsItsBooleanAndReadsZeroAsUnset(): void
    {
        $definition = $this->definition('parallelToolCalls');

        $set = SettingsResolver::new()->withEnvironment(['SUGARCRUSH_DISABLE_PARALLEL_TOOL_CALLS' => '1'])->resolve($definition);
        self::assertFalse($set->value);
        self::assertSame(SettingSource::Env, $set->source);

        foreach (['0', ''] as $unset) {
            $resolved = SettingsResolver::new()->withEnvironment(['SUGARCRUSH_DISABLE_PARALLEL_TOOL_CALLS' => $unset])->resolve($definition);
            self::assertTrue($resolved->isDefault(), "'{$unset}' must read as not set");
        }
    }

    public function testANumericEnvironmentOverrideIsANumber(): void
    {
        $resolved = SettingsResolver::new()
            ->withEnvironment(['SUGARCRUSH_PARALLEL_TOOL_DEADLINE' => '30'])
            ->resolve($this->definition('parallelToolDeadlineSeconds'));

        self::assertSame(30, $resolved->value);
    }

    public function testAFlagOutranksTheEnvironment(): void
    {
        $resolved = SettingsResolver::new()
            ->withEnvironment(['SUGARCRUSH_PERMISSION_MODE' => 'plan'])
            ->withFlags(['--permission-mode' => 'default'])
            ->resolve($this->definition('permissionMode'));

        self::assertSame('default', $resolved->value);
        self::assertSame(SettingSource::Flag, $resolved->source);
        self::assertSame('set by --permission-mode', $resolved->lockReason);
        self::assertSame([SettingSource::Env], $resolved->shadowed);
    }

    /** The session overlay sits above the files and never reaches a frozen key. */
    public function testTheSessionOverlaySitsAboveTheFilesButNotOnFrozenKeys(): void
    {
        $this->writeUser('config.json', ['theme' => 'dark', 'trustedProjectHooks' => ['/srv/a']]);

        $resolver = $this->fromFiles(trusted: false)->withLayer(
            SettingSource::Session,
            ['theme' => 'dracula', 'trustedProjectHooks' => ['/srv/b']],
        );

        self::assertSame('dracula', $resolver->resolve($this->definition('theme'))->value);
        self::assertSame(SettingSource::Session, $resolver->resolve($this->definition('theme'))->source);
        self::assertSame(['/srv/a'], $resolver->resolve($this->definition('trustedProjectHooks'))->value);
    }

    public function testEnvAndFlagAreNotFileShapedLayers(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SettingsResolver::new()->withLayer(SettingSource::Env, ['provider' => 'x']);
    }

    public function testResolveAllAnswersEveryKeyInSchemaOrder(): void
    {
        self::assertSame(SettingsSchema::keys(), array_keys(SettingsResolver::new()->resolveAll()));
    }

    public function testAMalformedFileIsTheAbsenceOfALayer(): void
    {
        file_put_contents($this->userDir . '/config.json', '{not json');

        self::assertTrue($this->fromFiles(trusted: false)->resolve($this->definition('theme'))->isDefault());
    }

    private function definition(string $key): \SugarCraft\Crush\Config\Settings\SettingDefinition
    {
        $definition = SettingsSchema::byKey($key);
        self::assertNotNull($definition, "{$key} has no schema row");

        return $definition;
    }

    private function fromFiles(bool $trusted): SettingsResolver
    {
        return SettingsResolver::fromFiles($this->projectRoot, $trusted, $this->userDir, $this->userDir . '/config.json');
    }

    /** @param array<string, mixed> $data */
    private function writeProject(string $relative, array $data): void
    {
        file_put_contents($this->projectRoot . '/' . $relative, (string) json_encode($data));
    }

    /** @param array<string, mixed> $data */
    private function writeUser(string $file, array $data): void
    {
        file_put_contents($this->userDir . '/' . $file, (string) json_encode($data));
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
