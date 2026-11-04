<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Config\LayeredSettings;
use SugarCraft\Code\Config\Settings\SessionSettings;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * Pins for the managed tier G1-L7 (CCOPS-6, owner ruling OD-29): a read-only
 * machine-level settings file that outranks every file a user or a project can
 * write, while the unlayered security keys stay answered by the strict side
 * alone. Fixture shape mirrors tests/Cli/BootstrapLayeredSettingsTest.php
 * byte-for-byte where helpers are shared, per the duplication discipline.
 *
 * Each pin names the half it polices:
 *  - the alphabet half: {@see LayeredSettings::systemLayer()} answers only with
 *    LAYERED_KEYS, so a managed file cannot smuggle permissionMode past the
 *    "answered by layer 4 ALONE" boundary;
 *  - the ordering half: managed outranks the CLI-written file, the hand-written
 *    settings.json, a trusted project file, and the session tier;
 *  - the silence half: an absent managed file costs the read and the audit
 *    nothing, byte-for-byte (the cascade is what it was before the tier existed);
 *  - the loud half: a corrupt managed file degrades like the corrupt project
 *    file at v1 F-4 — the read answers from lower tiers, the audit names it.
 */
final class ManagedTierSettingsTest extends TestCase
{
    use HomeSandboxTrait;

    private string $tmpDir = '';
    private string $home = '';
    private string $configDir = '';
    private string $projectRoot = '';
    private string $managedPath = '';
    /** @var string|false */
    private string|false $originalPermissionMode = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalPermissionMode = getenv('CANDY_CODE_PERMISSION_MODE');
        putenv('CANDY_CODE_PERMISSION_MODE');
        $this->tmpDir = sys_get_temp_dir() . '/managed_tier_' . uniqid('', true);
        $this->home = $this->tmpDir . '/home';
        $this->configDir = $this->home . '/.candy-code';
        $this->projectRoot = $this->tmpDir . '/repo';
        $managedDir = $this->tmpDir . '/etc/candy-code';
        mkdir($this->configDir, 0o700, true);
        mkdir($this->projectRoot . '/' . LayeredSettings::dir(), 0o700, true);
        mkdir($managedDir, 0o755, true);
        $this->managedPath = $managedDir . '/config.json';
        $this->useHomeSandbox($this->home);
        Bootstrap::useMaxTurns(null);
    }

    protected function tearDown(): void
    {
        Bootstrap::useSystemConfigPath(null);
        SessionSettings::reset();
        Bootstrap::useProjectRootForSettings(null);
        Bootstrap::useConfigPath(null);
        Bootstrap::useMaxTurns(null);
        $this->restoreHomeSandbox();
        if (is_string($this->originalPermissionMode)) {
            putenv('CANDY_CODE_PERMISSION_MODE=' . $this->originalPermissionMode);
        }
        $this->removeTree($this->tmpDir);
        parent::tearDown();
    }

    public function testTheManagedLayerIsFilteredToTheLayeredKeys(): void
    {
        $this->pinManaged([
            'theme' => 'managed-theme',
            'disabledTools' => ['Web'],
            'permissionMode' => 'dontAsk',
            'trustedProjectSettings' => ['/somewhere'],
            'notAKeyAtAll' => 1,
        ]);

        $layer = LayeredSettings::systemLayer($this->managedPath);

        self::assertSame(
            ['disabledTools' => ['Web'], 'theme' => 'managed-theme'],
            $layer,
            'the managed door answers with the layered alphabet and nothing else'
        );
    }

    public function testTheManagedLayerKeepsAnExplicitNullAsAStatement(): void
    {
        $this->pinManaged(['theme' => null]);

        $layer = LayeredSettings::systemLayer($this->managedPath);

        self::assertSame(
            ['theme' => null],
            $layer,
            'an explicit null in the managed file outranks a lower layer that sets the key'
        );
    }

    #[DataProvider('unusableManagedFiles')]
    public function testAManagedFileTheCodeCannotUseIsTheAbsenceOfALayer(string $contents): void
    {
        file_put_contents($this->managedPath, $contents);

        self::assertSame([], LayeredSettings::systemLayer($this->managedPath));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unusableManagedFiles(): iterable
    {
        yield 'not valid json' => ['{{{ not json'];
        yield 'empty file' => [''];
        yield 'json null' => ['null'];
        yield 'json string' => ['"a string"'];
        yield 'json list' => ['[1,2,3]'];
    }

    public function testAMissingManagedFileIsTheAbsenceOfALayer(): void
    {
        Bootstrap::useSystemConfigPath($this->tmpDir . '/etc/candy-code/absent.json');

        self::assertSame([], LayeredSettings::systemLayer(Bootstrap::systemConfigPath()));
    }

    public function testTheManagedTierOutranksEveryOtherFileTier(): void
    {
        $this->writeUserSettings(['theme' => 'user-layer']);
        $canonical = realpath($this->projectRoot);
        self::assertIsString($canonical);
        $this->writeUserConfigFile([
            'theme' => 'cli-file',
            LayeredSettings::PROJECT_SETTINGS_TRUST_KEY => [$canonical],
        ]);
        $this->writeProjectSettings(LayeredSettings::SHARED_PATH, ['theme' => 'project-layer']);
        $this->pinManaged(['theme' => 'managed-pin']);
        Bootstrap::useProjectRootForSettings($this->projectRoot);

        self::assertSame(
            'managed-pin',
            Bootstrap::readUserConfig()['theme'] ?? null,
            'the managed file outranks the CLI-written file, the hand-written user file, and a trusted project file'
        );

        // With the managed file absent, the cascade below it is untouched —
        // the same read answers as it did before the tier existed.
        Bootstrap::useSystemConfigPath($this->tmpDir . '/etc/candy-code/absent.json');

        self::assertSame('cli-file', Bootstrap::readUserConfig()['theme'] ?? null);
    }

    public function testTheManagedTierOutranksTheSessionTierAndAbsenceLetsTheSessionWin(): void
    {
        $this->writeUserSettings(['theme' => 'user-layer']);
        SessionSettings::apply(['theme' => 'session-pick']);

        // No managed file: the session tier sits on top of the files, as before.
        Bootstrap::useSystemConfigPath($this->tmpDir . '/etc/candy-code/absent.json');
        self::assertSame('session-pick', Bootstrap::readUserConfig()['theme'] ?? null);

        // Managed file present: a re-choosing the operator has pinned is not
        // undone by a live /model-style repaint.
        $this->pinManaged(['theme' => 'managed-pin']);
        Bootstrap::useSystemConfigPath($this->managedPath);
        self::assertSame('managed-pin', Bootstrap::readUserConfig()['theme'] ?? null);
    }

    public function testAManagedPermissionModeNeverAnswersTheReadButDoesAnswerTheAudit(): void
    {
        $this->pinManaged(['permissionMode' => 'dontAsk']);

        self::assertArrayNotHasKey(
            'permissionMode',
            Bootstrap::readUserConfig(),
            'the strict side is read separately; the managed door cannot widen it'
        );

        $rows = LayeredSettings::keyAuditRows($this->configDir);
        self::assertNotSame(
            [],
            array_values(array_filter(
                $rows,
                static fn(string $row): bool => str_contains($row, 'permissionMode')
                    && str_contains($row, 'candy-code')
            )),
            'a permission key in the managed file earns the wrong-tier audit row naming the file'
        );
    }

    public function testTheAuditNamesACorruptManagedFileWhileTheApplyPathStaysSilent(): void
    {
        $this->writeUserConfigFile(['theme' => 'cli-file']);
        file_put_contents($this->managedPath, '{{{ not json');
        Bootstrap::useSystemConfigPath($this->managedPath);

        self::assertSame(
            'cli-file',
            Bootstrap::readUserConfig()['theme'] ?? null,
            'the read degrades to the lower tiers instead of failing the launch'
        );

        $rows = LayeredSettings::keyAuditRows($this->configDir);
        $managed = $this->managedPath;
        $naming = array_values(array_filter(
            $rows,
            static fn(string $row): bool => str_contains($row, $managed)
        ));
        self::assertNotSame([], $naming, 'a corrupt managed pin must not silently un-apply');
        self::assertSame(1, count($naming), 'exactly the managed file names itself, once');
    }

    public function testAMissingManagedFileCostsTheAuditNothingByteForByte(): void
    {
        $this->writeUserSettings(['theme' => 'user-layer']);
        $canonical = realpath($this->projectRoot);
        self::assertIsString($canonical);
        $this->writeUserConfigFile([
            'theme' => 'cli-file',
            LayeredSettings::PROJECT_SETTINGS_TRUST_KEY => [$canonical],
        ]);
        $this->writeProjectSettings(LayeredSettings::SHARED_PATH, ['theme' => 'project-layer']);
        Bootstrap::useProjectRootForSettings($this->projectRoot);

        Bootstrap::useSystemConfigPath($this->tmpDir . '/etc/candy-code/absent-a.json');
        $rowsA = LayeredSettings::keyAuditRows($this->configDir, $this->projectRoot);
        Bootstrap::useSystemConfigPath($this->tmpDir . '/somewhere-else/absent-b.json');
        $rowsB = LayeredSettings::keyAuditRows($this->configDir, $this->projectRoot);

        self::assertSame($rowsA, $rowsB, 'two absent managed files audit identically');
        self::assertSame(
            [],
            array_values(array_filter(
                $rowsB,
                static fn(string $row): bool => str_contains($row, 'absent-a')
                    || str_contains($row, 'absent-b')
            )),
            'an absent managed file never leaks its path into a row'
        );
    }

    public function testTheManagedDefaultPathIsTheFixedSystemLocation(): void
    {
        Bootstrap::useSystemConfigPath(null);

        self::assertSame(LayeredSettings::SYSTEM_CONFIG_PATH, Bootstrap::systemConfigPath());
        self::assertSame('/etc/candy-code/config.json', Bootstrap::systemConfigPath());
    }

    public function testAPinnedPathOfWhitesOnlyFallsBackToTheDefault(): void
    {
        Bootstrap::useSystemConfigPath('   ');

        self::assertSame(
            LayeredSettings::SYSTEM_CONFIG_PATH,
            Bootstrap::systemConfigPath(),
            'the blank-refusal law mirrors useProjectRootForSettings: a blank pin is no pin'
        );
    }

    private function pinManaged(array $data): void
    {
        file_put_contents($this->managedPath, (string) json_encode($data));
        Bootstrap::useSystemConfigPath($this->managedPath);
    }

    private function writeUserConfigFile(array $data): void
    {
        file_put_contents($this->configDir . '/config.json', (string) json_encode($data));
        chmod($this->configDir . '/config.json', 0o600);
    }

    private function writeUserSettings(array $data): void
    {
        file_put_contents($this->configDir . '/' . LayeredSettings::USER_FILE, (string) json_encode($data));
    }

    private function writeProjectSettings(string $relative, array $data): void
    {
        file_put_contents($this->projectRoot . '/' . $relative, (string) json_encode($data));
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($entries as $entry) {
            $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($dir);
    }
}
