<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Config;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Config\LayeredSettings;
use SugarCraft\Code\Config\MergedConfigMemo;
use SugarCraft\Code\Config\Settings\SessionSettings;
use SugarCraft\Code\Config\Settings\SettingsTier;
use SugarCraft\Code\Config\Settings\SettingsWriter;
use SugarCraft\Code\Config\SettingsFileWatch;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * AUD §7 rank 5: `Bootstrap::readUserConfig()` is memoised per process.
 *
 * The memo is only worth having if it is invisible: every value a reader saw
 * before it must be the value it sees now. So the pins come in two kinds — the
 * memo HITS when nothing moved (the derivation counter stays put), and every
 * way the answer can move still reaches the next read: a write through either
 * writer, an edit made outside the app (including the same-size rewrite a stat
 * gate would miss), a project file of a trusted root, and a forced look by
 * {@see SettingsFileWatch::changedKeys()}.
 */
final class MergedConfigMemoTest extends TestCase
{
    use HomeSandboxTrait;

    private string $home = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->home = sys_get_temp_dir() . '/crush-config-memo-' . bin2hex(random_bytes(6));
        mkdir($this->home . '/.candy-code', 0o700, true);
        $this->useHomeSandbox($this->home);
        Bootstrap::useConfigPath(null);
        Bootstrap::useProjectRootForSettings(null);
        Bootstrap::useSystemConfigPath($this->home . '/managed.json');
        SessionSettings::reset();
        SettingsFileWatch::resetForTesting();
        MergedConfigMemo::resetForTesting();
    }

    protected function tearDown(): void
    {
        MergedConfigMemo::resetForTesting();
        SettingsFileWatch::resetForTesting();
        SessionSettings::reset();
        Bootstrap::useProjectRootForSettings(null);
        Bootstrap::useConfigPath(null);
        Bootstrap::useSystemConfigPath(null);
        $this->restoreHomeSandbox();
        exec('rm -rf ' . escapeshellarg($this->home));

        parent::tearDown();
    }

    public function testARepeatReadOverUnchangedFilesIsServedFromTheMemo(): void
    {
        $this->writeConfig(['scrollWheelLines' => 4]);

        $first = Bootstrap::readUserConfig();
        $derived = MergedConfigMemo::computations();
        $second = Bootstrap::readUserConfig();

        self::assertSame(4, $first['scrollWheelLines'] ?? null);
        self::assertSame($first, $second, 'a memo hit answers exactly what the derivation did');
        self::assertSame($derived, MergedConfigMemo::computations(), 'nothing moved, so nothing was re-derived');
    }

    public function testTheTwoReadShapesKeepSeparateSlots(): void
    {
        $this->writeConfig(['scrollWheelLines' => 4]);

        Bootstrap::readUserConfig();
        Bootstrap::readUserConfigWithoutProjectTier();
        $derived = MergedConfigMemo::computations();
        Bootstrap::readUserConfig();
        Bootstrap::readUserConfigWithoutProjectTier();

        self::assertSame($derived, MergedConfigMemo::computations(), 'alternating shapes must not evict each other');
    }

    public function testAWriteThroughWriteUserConfigInvalidatesTheMemo(): void
    {
        Bootstrap::writeUserConfig(['scrollWheelLines' => 4]);
        self::assertSame(4, Bootstrap::readUserConfig()['scrollWheelLines'] ?? null);

        Bootstrap::writeUserConfig(['scrollWheelLines' => 6]);
        self::assertSame(6, Bootstrap::readUserConfig()['scrollWheelLines'] ?? null, 'the written value is the next read');

        // The same patch again rewrites IDENTICAL bytes, so the byte key alone
        // would hit; only the writer's own invalidation forces the re-derive.
        $derived = MergedConfigMemo::computations();
        Bootstrap::writeUserConfig(['scrollWheelLines' => 6]);
        Bootstrap::readUserConfig();

        self::assertSame($derived + 1, MergedConfigMemo::computations(), 'a write drops the memo even when the bytes are unchanged');
    }

    public function testAWriteThroughTheSettingsWriterInvalidatesTheMemo(): void
    {
        $root = $this->home . '/project';
        mkdir($root . '/.candy-code', 0o700, true);
        $this->writeConfig(['scrollWheelLines' => 4]);
        Bootstrap::readUserConfig();
        $derived = MergedConfigMemo::computations();

        // Both of the writer's project-tier doors: the plain write and the
        // read-decide-write under the lock.
        $writer = SettingsWriter::new(Bootstrap::userConfigPath(), Bootstrap::writeUserConfig(...))->withProject($root, true);
        $writer->write(SettingsTier::ProjectLocal, ['parallelToolCalls' => false]);
        Bootstrap::readUserConfig();

        self::assertFileExists($root . '/' . LayeredSettings::LOCAL_PATH);
        self::assertSame($derived + 1, MergedConfigMemo::computations(), 'a project-tier write drops the memo too');

        $writer->mutate(SettingsTier::ProjectLocal, static fn (array $current): array => [['parallelToolCalls' => true], []]);
        Bootstrap::readUserConfig();

        self::assertSame($derived + 2, MergedConfigMemo::computations(), 'and so does a project-tier mutate');
    }

    public function testAnExternalEditIsSeenAtTheNextRead(): void
    {
        $this->writeConfig(['scrollWheelLines' => 4]);
        self::assertSame(4, Bootstrap::readUserConfig()['scrollWheelLines'] ?? null);

        // Same size, same second: the rewrite a stat (mtime + size) gate would
        // sail over. The byte key cannot.
        $this->writeConfig(['scrollWheelLines' => 5]);
        self::assertSame(5, Bootstrap::readUserConfig()['scrollWheelLines'] ?? null);

        unlink(Bootstrap::userConfigPath());
        self::assertArrayNotHasKey('scrollWheelLines', Bootstrap::readUserConfig(), 'a removed file is a removed layer');
    }

    public function testAnExternalEditToTheUserSettingsFileIsSeenAtTheNextRead(): void
    {
        $settings = $this->home . '/.candy-code/' . LayeredSettings::USER_FILE;
        file_put_contents($settings, json_encode(['scrollWheelLines' => 7]));
        self::assertSame(7, Bootstrap::readUserConfig()['scrollWheelLines'] ?? null);

        file_put_contents($settings, json_encode(['scrollWheelLines' => 8]));
        self::assertSame(8, Bootstrap::readUserConfig()['scrollWheelLines'] ?? null);
    }

    public function testATrustedProjectFileEditIsSeenAtTheNextRead(): void
    {
        $root = $this->home . '/project';
        mkdir($root . '/.candy-code', 0o700, true);
        $this->writeConfig([LayeredSettings::PROJECT_SETTINGS_TRUST_KEY => [(string) realpath($root)]]);
        Bootstrap::useProjectRootForSettings($root);

        $shared = $root . '/' . LayeredSettings::SHARED_PATH;
        file_put_contents($shared, json_encode(['parallelToolCalls' => false]));
        self::assertFalse(Bootstrap::readUserConfig()['parallelToolCalls'] ?? null);
        self::assertArrayNotHasKey('parallelToolCalls', Bootstrap::readUserConfigWithoutProjectTier());

        file_put_contents($shared, json_encode(['parallelToolCalls' => true]));
        self::assertTrue(Bootstrap::readUserConfig()['parallelToolCalls'] ?? null);
    }

    public function testTheSessionTierIsLaidOnEveryReadNotMemoised(): void
    {
        $this->writeConfig(['scrollWheelLines' => 4]);
        Bootstrap::readUserConfig();

        SessionSettings::apply(['scrollWheelLines' => 9]);

        self::assertSame(9, Bootstrap::readUserConfig()['scrollWheelLines'] ?? null);
    }

    public function testAForcedLookBySettingsFileWatchInvalidatesTheMemo(): void
    {
        $this->writeConfig(['scrollWheelLines' => 4]);
        SettingsFileWatch::seedAtLaunch();
        Bootstrap::readUserConfig();
        $derived = MergedConfigMemo::computations();

        // Nothing moved on disk: the memo's own key would hit. The forced look
        // (`/settings reload`) must still re-derive from the files.
        self::assertSame([], SettingsFileWatch::changedKeys(true));
        self::assertSame($derived + 1, MergedConfigMemo::computations(), 'a forced look is a genuine re-read');
    }

    public function testAnEditTheWatchSeesIsReportedAndReadAfresh(): void
    {
        $this->writeConfig(['scrollWheelLines' => 4]);
        SettingsFileWatch::seedAtLaunch();
        Bootstrap::readUserConfig();

        $this->writeConfig(['scrollWheelLines' => 5]);

        self::assertSame(['scrollWheelLines'], SettingsFileWatch::changedKeys());
        self::assertSame(5, Bootstrap::readUserConfig()['scrollWheelLines'] ?? null);
    }

    /** @param array<string, mixed> $data */
    private function writeConfig(array $data): void
    {
        file_put_contents(Bootstrap::userConfigPath(), json_encode($data === [] ? new \stdClass() : $data));
    }
}
