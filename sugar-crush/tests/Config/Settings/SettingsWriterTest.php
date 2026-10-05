<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Config\Settings;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\LayeredSettings;
use SugarCraft\Crush\Config\Settings\SettingsTier;
use SugarCraft\Crush\Config\Settings\SettingsWriter;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Tests\Support\BackendSelectionEnvSandboxTrait;

/**
 * The settings editor's write door (N-P2): what it writes where, what it
 * refuses before writing, and that the "You" tier goes through the one
 * `config.json` writer the app already has.
 */
final class SettingsWriterTest extends TestCase
{
    use BackendSelectionEnvSandboxTrait;

    private string $dir;
    private string $configPath;
    private string $root;
    private string $originalHome;
    private mixed $originalServerHome;
    private string|false $originalMode;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/settings_writer_' . uniqid('', true);
        mkdir($this->dir . '/home/.sugar-crush', 0700, true);
        mkdir($this->dir . '/repo/.sugar-crush', 0700, true);
        $this->configPath = $this->dir . '/home/.sugar-crush/config.json';
        $this->root = (string) realpath($this->dir . '/repo');

        $this->originalHome = getenv('HOME') ?: '';
        putenv('HOME=' . $this->dir . '/home');
        $this->originalServerHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $this->dir . '/home';
        $this->originalMode = getenv('SUGARCRUSH_PERMISSION_MODE');
        putenv('SUGARCRUSH_PERMISSION_MODE');
        $this->clearBackendSelectionEnv();
        Bootstrap::useConfigPath(null);
        Bootstrap::usePermissionMode(null);
    }

    protected function tearDown(): void
    {
        Bootstrap::useConfigPath(null);
        Bootstrap::usePermissionMode(null);
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

        if ($this->originalMode !== false) {
            putenv('SUGARCRUSH_PERMISSION_MODE=' . $this->originalMode);
        } else {
            putenv('SUGARCRUSH_PERMISSION_MODE');
        }

        $this->restoreBackendSelectionEnv();
        $this->removeDirectory($this->dir);

        parent::tearDown();
    }

    // ── the You tier ────────────────────────────────────────────────────

    public function testTheYouTierWritesConfigJsonThroughTheInjectedDoor(): void
    {
        $calls = [];
        $writer = SettingsWriter::new($this->configPath, function (array $set, array $unset) use (&$calls): void {
            $calls[] = [$set, $unset];
            Bootstrap::writeUserConfig($set, $unset);
        });

        $path = $writer->write(SettingsTier::You, ['maxToolSteps' => 40, 'models' => ['openai' => 'gpt-x']]);

        self::assertSame($this->configPath, $path);
        self::assertSame([[['maxToolSteps' => 40, 'models' => ['openai' => 'gpt-x']], []]], $calls);
        self::assertSame(['maxToolSteps' => 40, 'models' => ['openai' => 'gpt-x']], $this->config());
        self::assertFileDoesNotExist($this->dir . '/home/.sugar-crush/' . LayeredSettings::USER_FILE, 'settings.json is never written');
    }

    public function testItMergesAndAResetRemovesTheKeyRatherThanWritingTheDefault(): void
    {
        file_put_contents($this->configPath, (string) json_encode(['theme' => 'dracula', 'maxToolSteps' => 40, 'parallelToolCalls' => false]));
        $writer = $this->userWriter();

        $writer->write(SettingsTier::You, ['maxOutputTokens' => 2048], ['maxToolSteps']);

        self::assertSame(['theme' => 'dracula', 'parallelToolCalls' => false, 'maxOutputTokens' => 2048], $this->config());
    }

    public function testResettingTheLastKeyLeavesAnObjectNotAList(): void
    {
        file_put_contents($this->configPath, (string) json_encode(['maxToolSteps' => 40]));

        $this->userWriter()->write(SettingsTier::You, [], ['maxToolSteps']);

        self::assertSame('{}', trim((string) file_get_contents($this->configPath)));
    }

    public function testTheWrittenFileIsPrivate(): void
    {
        $this->userWriter()->write(SettingsTier::You, ['maxToolSteps' => 12]);

        self::assertSame(0600, fileperms($this->configPath) & 0777);
    }

    /** `--config` moves the file the You tier writes, as it moves every reader. */
    public function testTheConfigFlagIsHonoured(): void
    {
        $alt = $this->dir . '/alt.json';
        Bootstrap::useConfigPath($alt);

        $path = SettingsWriter::new(Bootstrap::userConfigPath(), static fn (array $s, array $u) => Bootstrap::writeUserConfig($s, $u))
            ->write(SettingsTier::You, ['maxToolSteps' => 9]);

        self::assertSame($alt, $path);
        self::assertSame(['maxToolSteps' => 9], json_decode((string) file_get_contents($alt), true));
        self::assertFileDoesNotExist($this->configPath);
    }

    /** A door that silently writes nothing (a locked or unparsable config) is reported, not believed. */
    public function testADoorThatWroteNothingIsAnError(): void
    {
        $writer = SettingsWriter::new($this->configPath, static function (): void {
        });

        $this->expectException(\RuntimeException::class);
        $writer->write(SettingsTier::You, ['maxToolSteps' => 12]);
    }

    public function testAnUnparsableConfigIsNeverOverwritten(): void
    {
        file_put_contents($this->configPath, '{"permissionMode": "default",');

        $refused = null;
        try {
            $this->userWriter()->write(SettingsTier::You, ['maxToolSteps' => 12]);
        } catch (\RuntimeException $e) {
            $refused = $e;
        }
        self::assertNotNull($refused, 'expected the save to be refused');

        self::assertSame('{"permissionMode": "default",', file_get_contents($this->configPath));
        $this->expectException(\RuntimeException::class);
        $this->userWriter()->current(SettingsTier::You);
    }

    /** The strict key round-trips: what the editor writes, the next launch's gate accepts. */
    public function testAWrittenPermissionModeIsOneTheLaunchAccepts(): void
    {
        $this->userWriter()->write(SettingsTier::You, ['permissionMode' => PermissionMode::AcceptEdits->value]);

        self::assertSame(PermissionMode::AcceptEdits, Bootstrap::permissionGate(interactive: true)->mode());
    }

    // ── refusals ────────────────────────────────────────────────────────

    /** @return iterable<string, array{0: string, 1: mixed}> */
    public static function refusedOnTheYouTier(): iterable
    {
        yield 'not a setting' => ['noSuchSetting', 1];
        yield 'provider has its live command' => ['provider', 'openai'];
        yield 'theme has its live command' => ['theme', 'dracula'];
        yield 'layout is read-only' => ['layout', []];
        yield 'a trust list goes through the trust action' => ['trustedProjectSettings', ['/tmp']];
        yield 'a grant key is frozen' => ['claudeMcpBinary', '/usr/bin/claude-mcp'];
        yield 'wrong type' => ['maxToolSteps', '40'];
        yield 'below its range' => ['maxOutputTokens', 0];
        yield 'not a mode' => ['permissionMode', 'yolo'];
        yield 'models must map provider to model' => ['models', ['openai' => '']];
        yield 'models is a map' => ['models', ['gpt-4o']];
        yield 'null masks every lower layer' => ['maxToolSteps', null];
    }

    #[DataProvider('refusedOnTheYouTier')]
    public function testTheYouTierRefusesBeforeWriting(string $key, mixed $value): void
    {
        $writer = $this->userWriter();
        self::assertNotNull($writer->refusal(SettingsTier::You, $key, $value));

        try {
            $writer->write(SettingsTier::You, [$key => $value]);
            self::fail('expected a refusal');
        } catch (\InvalidArgumentException) {
        }

        self::assertFileDoesNotExist($this->configPath);
    }

    public function testSetAndResetOfOneKeyInOneSaveIsRefused(): void
    {
        self::assertArrayHasKey('maxToolSteps', $this->userWriter()->refusals(SettingsTier::You, ['maxToolSteps' => 3], ['maxToolSteps']));
    }

    // ── the project-local tier ──────────────────────────────────────────

    public function testAnUntrustedProjectCannotBeWritten(): void
    {
        $writer = $this->userWriter()->withProject($this->root, false);

        self::assertNull($writer->targetPath(SettingsTier::ProjectLocal));
        self::assertNotNull($writer->tierRefusal(SettingsTier::ProjectLocal));
        $this->expectException(\InvalidArgumentException::class);
        $writer->write(SettingsTier::ProjectLocal, ['parallelToolCalls' => false]);
    }

    public function testNoOpenProjectMeansNoProjectTier(): void
    {
        self::assertNotNull($this->userWriter()->tierRefusal(SettingsTier::ProjectLocal));
    }

    public function testATrustedProjectGetsItsLocalFileAndOnlyThat(): void
    {
        file_put_contents($this->root . '/' . LayeredSettings::LOCAL_PATH, (string) json_encode(['theme' => 'nord']));
        $writer = $this->userWriter()->withProject($this->root, true);

        $path = $writer->write(SettingsTier::ProjectLocal, ['parallelToolCalls' => false, 'disabledTools' => ['WebSearch']], ['theme']);

        self::assertSame($this->root . '/' . LayeredSettings::LOCAL_PATH, $path);
        self::assertSame(
            ['parallelToolCalls' => false, 'disabledTools' => ['WebSearch']],
            json_decode((string) file_get_contents($path), true),
        );
        self::assertFileDoesNotExist($this->root . '/' . LayeredSettings::SHARED_PATH, 'the committed file is not this tier');
        self::assertFileDoesNotExist($this->configPath);

        // And the merge reads it back, for a trusted root.
        self::assertSame(false, LayeredSettings::projectLayer($this->root, true)['parallelToolCalls']);
    }

    /** @return iterable<string, array{0: string, 1: mixed}> */
    public static function refusedOnTheProjectTier(): iterable
    {
        yield 'permission mode' => ['permissionMode', 'default'];
        yield 'a trust list' => ['trustedProjectSettings', ['/tmp']];
        yield 'a command' => ['statusLine', ['type' => 'command', 'command' => 'id']];
        yield 'spend' => ['maxToolSteps', 5];
        yield 'the model' => ['models', ['openai' => 'gpt-x']];
        yield 'prompt text' => ['instructions', ['*.md']];
    }

    #[DataProvider('refusedOnTheProjectTier')]
    public function testTheProjectTierRefusesEveryKeyAProjectMayNotSet(string $key, mixed $value): void
    {
        $writer = $this->userWriter()->withProject($this->root, true);

        self::assertNotNull($writer->refusal(SettingsTier::ProjectLocal, $key, $value));
        $this->expectException(\InvalidArgumentException::class);
        $writer->write(SettingsTier::ProjectLocal, [$key => $value]);
    }

    public function testASymlinkedSettingsDirectoryIsNotAProjectTier(): void
    {
        $this->removeDirectory($this->root . '/.sugar-crush');
        mkdir($this->dir . '/elsewhere', 0700);
        symlink($this->dir . '/elsewhere', $this->root . '/.sugar-crush');

        self::assertNull($this->userWriter()->withProject($this->root, true)->targetPath(SettingsTier::ProjectLocal));
    }

    // ── the project-shared tier (N-P5) ──────────────────────────────────

    public function testATrustedProjectGetsItsCommittedFileUnderTheLocalRules(): void
    {
        file_put_contents($this->root . '/' . LayeredSettings::SHARED_PATH, (string) json_encode(['theme' => 'nord']));
        $writer = $this->userWriter()->withProject($this->root, true);

        $path = $writer->write(SettingsTier::ProjectShared, ['parallelToolCalls' => false], ['theme']);

        self::assertSame($this->root . '/' . LayeredSettings::SHARED_PATH, $path);
        self::assertSame(['parallelToolCalls' => false], json_decode((string) file_get_contents($path), true));
        self::assertFileDoesNotExist($this->root . '/' . LayeredSettings::LOCAL_PATH, 'the local file is not this tier');
        self::assertFileDoesNotExist($this->configPath);
        self::assertSame(false, LayeredSettings::projectLayer($this->root, true)['parallelToolCalls'], 'and the merge reads it back');
    }

    public function testTheLocalFileStillOutranksASharedSave(): void
    {
        $writer = $this->userWriter()->withProject($this->root, true);
        $writer->write(SettingsTier::ProjectLocal, ['parallelToolCalls' => true]);
        $writer->write(SettingsTier::ProjectShared, ['parallelToolCalls' => false]);

        self::assertTrue(LayeredSettings::projectLayer($this->root, true)['parallelToolCalls']);
        self::assertTrue(
            SettingsTier::ProjectLocal->source()->precedence() > SettingsTier::ProjectShared->source()->precedence(),
            'which is what the preview warns about',
        );
    }

    public function testTheSharedTierRefusesWhatTheLocalTierRefuses(): void
    {
        $writer = $this->userWriter()->withProject($this->root, true);
        foreach (self::refusedOnTheProjectTier() as $label => [$key, $value]) {
            self::assertNotNull($writer->refusal(SettingsTier::ProjectShared, $key, $value), $label);
            self::assertSame(
                SettingsWriter::keyRefusal(SettingsTier::ProjectLocal, $key),
                SettingsWriter::keyRefusal(SettingsTier::ProjectShared, $key),
                $label,
            );
        }

        $untrusted = $this->userWriter()->withProject($this->root, false);
        self::assertNull($untrusted->targetPath(SettingsTier::ProjectShared));
        self::assertNotNull($untrusted->tierRefusal(SettingsTier::ProjectShared));
        self::assertNotNull($this->userWriter()->tierRefusal(SettingsTier::ProjectShared), 'no project, no tier');
        $this->expectException(\InvalidArgumentException::class);
        $untrusted->write(SettingsTier::ProjectShared, ['parallelToolCalls' => false]);
    }

    public function testASymlinkedSettingsDirectoryIsNotASharedTierEither(): void
    {
        $this->removeDirectory($this->root . '/.sugar-crush');
        mkdir($this->dir . '/elsewhere', 0700);
        symlink($this->dir . '/elsewhere', $this->root . '/.sugar-crush');

        self::assertNull($this->userWriter()->withProject($this->root, true)->targetPath(SettingsTier::ProjectShared));
    }

    public function testKeyRefusalIsTheSchemaHalfOfRefusal(): void
    {
        $writer = $this->userWriter()->withProject($this->root, true);
        foreach (SettingsTier::cases() as $tier) {
            foreach (['maxToolSteps', 'provider', 'theme', 'trustedProjectHooks', 'layout', 'parallelToolCalls', 'nope'] as $key) {
                $static = SettingsWriter::keyRefusal($tier, $key);
                if ($static !== null) {
                    self::assertSame($static, $writer->refusal($tier, $key, true), "{$tier->name} {$key}");
                }
            }
        }
    }

    // ── modelPrices (N-P5) ──────────────────────────────────────────────

    public function testAWellFormedPriceTableIsWritten(): void
    {
        $prices = ['gpt-x' => ['input' => 3, 'output' => 15], 'local' => ['input' => 0, 'output' => 0.5, 'cached' => 0]];

        self::assertNull($this->userWriter()->refusal(SettingsTier::You, 'modelPrices', $prices));
        $this->userWriter()->write(SettingsTier::You, ['modelPrices' => $prices]);
        self::assertSame($prices, $this->config()['modelPrices']);
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function malformedPrices(): iterable
    {
        yield 'a rate missing' => [['gpt-x' => ['input' => 3]]];
        yield 'a rate as text' => [['gpt-x' => ['input' => '3', 'output' => 15]]];
        yield 'a negative rate' => [['gpt-x' => ['input' => -1, 'output' => 15]]];
        yield 'an unknown rate' => [['gpt-x' => ['input' => 1, 'output' => 2, 'outptu' => 3]]];
        yield 'an entry that is not an object' => [['gpt-x' => 3]];
        yield 'an entry that is a list' => [['gpt-x' => [1, 2]]];
    }

    #[DataProvider('malformedPrices')]
    public function testAMalformedPriceTableIsRefusedBeforeItIsWritten(mixed $prices): void
    {
        self::assertStringStartsWith('modelPrices: ', (string) $this->userWriter()->refusal(SettingsTier::You, 'modelPrices', $prices));
        $this->expectException(\InvalidArgumentException::class);
        $this->userWriter()->write(SettingsTier::You, ['modelPrices' => $prices]);
    }

    // ── trust ───────────────────────────────────────────────────────────

    public function testATrustGrantAppendsTheCanonicalRootOnTheUserTierOnly(): void
    {
        file_put_contents($this->configPath, (string) json_encode(['trustedProjectSettings' => ['/already/there']]));
        $writer = $this->userWriter();

        $writer->grantTrust('trustedProjectSettings', $this->dir . '/repo/.');
        $writer->grantTrust('trustedProjectSettings', $this->root);

        self::assertSame(['/already/there', $this->root], $this->config()['trustedProjectSettings']);

        $writer->revokeTrust('trustedProjectSettings', $this->root);
        self::assertSame(['/already/there'], $this->config()['trustedProjectSettings']);
        self::assertFileDoesNotExist($this->root . '/' . LayeredSettings::LOCAL_PATH);
    }

    public function testOnlyTheTrustListsAreTrustKeys(): void
    {
        self::assertEqualsCanonicalizing(
            ['trustedProjectHooks', 'trustedProjectMcp', 'trustedProjectCommands', 'trustedProjectSettings'],
            SettingsWriter::trustKeys(),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->userWriter()->grantTrust('permissionMode', $this->root);
    }

    public function testPatchedIsTheFileTheSaveWillWrite(): void
    {
        self::assertSame(
            ['a' => 1, 'c' => 3],
            SettingsWriter::patched(['a' => 1, 'b' => 2], ['c' => 3], ['b']),
        );
    }

    private function userWriter(): SettingsWriter
    {
        return SettingsWriter::new($this->configPath, static fn (array $set, array $unset) => Bootstrap::writeUserConfig($set, $unset));
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        $data = json_decode((string) file_get_contents($this->configPath), true);
        self::assertIsArray($data);

        return $data;
    }

    private function removeDirectory(string $dir): void
    {
        if (is_link($dir)) {
            @unlink($dir);

            return;
        }

        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            is_dir($path) && !is_link($path) ? $this->removeDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
