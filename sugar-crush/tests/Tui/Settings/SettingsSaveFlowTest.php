<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui\Settings;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Config\LayeredSettings;
use SugarCraft\Crush\Config\Settings\SettingSource;
use SugarCraft\Crush\Config\Settings\SettingsTier;
use SugarCraft\Crush\Config\Settings\SettingsWriter;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Theme;
use SugarCraft\Crush\Tui\Settings\SettingsEditor;
use SugarCraft\Crush\Tui\Settings\SettingsSavedMsg;
use SugarCraft\Crush\Tui\Settings\SettingsSavePreview;
use SugarCraft\Crush\Tui\Settings\SettingsSources;

/**
 * The settings editor's edit set, save preview and save (N-P2): staged values
 * show in the view, the preview diffs the target file, the write is a Cmd, and
 * its {@see SettingsSavedMsg} lands back in the open view.
 */
final class SettingsSaveFlowTest extends TestCase
{
    private string $dir = '';
    private string $configPath = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush-settings-save-' . bin2hex(random_bytes(5));
        mkdir($this->dir . '/home/' . LayeredSettings::dir(), 0o700, true);
        mkdir($this->dir . '/project/' . LayeredSettings::dir(), 0o700, true);
        $this->configPath = $this->dir . '/home/' . LayeredSettings::dir() . '/config.json';
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir) . ' 2>&1', $cleanup);
    }

    // ── the edit set ────────────────────────────────────────────────────

    public function testStagingAndResettingAreOneEditSet(): void
    {
        $editor = SettingsEditor::open($this->sources());
        self::assertFalse($editor->hasChanges());

        $editor = $editor->stage('maxToolSteps', 40)->stageReset('maxOutputTokens');
        self::assertSame(['maxToolSteps' => 40], $editor->set);
        self::assertSame(['maxOutputTokens'], $editor->unset);

        $editor = $editor->stage('maxOutputTokens', 100);
        self::assertSame([], $editor->unset, 'staging a value drops the staged reset of the same key');

        $editor = $editor->stageReset('maxToolSteps')->unstage('maxOutputTokens')->unstage('maxToolSteps');
        self::assertFalse($editor->hasChanges());
    }

    public function testTheViewShowsStagedValuesAndTheTier(): void
    {
        $editor = SettingsEditor::open($this->sources(), 'max tool steps')->stage('maxToolSteps', 40);
        $plain = Ansi::strip($editor->view(Theme::default(), 120, 30));

        self::assertStringContainsString('1 unsaved · You (all projects)', $plain);
        self::assertStringContainsString('• 40', $plain);

        $editor = $editor->withTier(SettingsTier::ProjectLocal);
        self::assertStringContainsString('This project (local)', Ansi::strip($editor->view(Theme::default(), 120, 30)));
        self::assertSame(['maxToolSteps' => 40], $editor->set, 'switching tier keeps the edit set');
    }

    // ── the preview ─────────────────────────────────────────────────────

    public function testThePreviewDiffsTheFileAndSaysWhenEachChangeApplies(): void
    {
        $preview = SettingsSavePreview::new(
            SettingsTier::You,
            '/x/config.json',
            ['theme' => 'nord', 'maxToolSteps' => 10],
            ['theme' => 'nord', 'maxToolSteps' => 40, 'parallelToolCalls' => false],
            ['maxToolSteps' => 40, 'parallelToolCalls' => false],
        );

        $plain = array_map(Ansi::strip(...), $preview->lines(Theme::default(), 60, turnRunning: true));

        self::assertTrue($preview->canSave());
        self::assertContains('-    "maxToolSteps": 10', $plain);
        self::assertContains('+    "maxToolSteps": 40,', $plain);
        self::assertContains('+    "parallelToolCalls": false', $plain);
        self::assertContains('Applies: 1 live · 1 next turn', $plain);
        self::assertContains('A turn is running — it keeps the settings it began with.', $plain);
        foreach ($preview->lines(Theme::default(), 20) as $line) {
            self::assertLessThanOrEqual(20, Width::string($line));
        }
    }

    public function testARefusedPreviewCannotSave(): void
    {
        $blocked = SettingsSavePreview::blocked(SettingsTier::ProjectLocal, null, 'not trusted');

        self::assertFalse($blocked->canSave());
        self::assertContains('✗ not trusted', array_map(Ansi::strip(...), $blocked->lines(Theme::default(), 60)));
    }

    // ── the shell's save ────────────────────────────────────────────────

    public function testASaveWritesThroughTheWriterAndTheViewReResolves(): void
    {
        $app = $this->app()->openSettings('max tool steps');
        $app = $app->withSettingsEditor($app->settingsEditor->stage('maxToolSteps', 40))->previewSettings();

        $preview = $app->settingsEditor->preview;
        self::assertNotNull($preview);
        self::assertTrue($preview->canSave());
        self::assertFileDoesNotExist($this->configPath, 'previewing writes nothing');

        [$app, $cmd] = $app->confirmSettingsSave();
        self::assertInstanceOf(\Closure::class, $cmd);
        self::assertFileDoesNotExist($this->configPath, 'the write is the Cmd, not update()');

        $msg = $cmd();
        self::assertInstanceOf(SettingsSavedMsg::class, $msg);
        self::assertTrue($msg->ok());
        self::assertSame(['maxToolSteps'], $msg->changed);
        self::assertSame(['maxToolSteps' => 40], json_decode((string) file_get_contents($this->configPath), true));

        [$app] = $app->update($msg);
        $editor = $app->settingsEditor;
        self::assertFalse($editor->hasChanges());
        self::assertNull($editor->preview);
        self::assertStringStartsWith('Saved 1 setting to ', (string) $editor->status);
        self::assertSame(40, $editor->resolved['maxToolSteps']->value);
        self::assertSame(SettingSource::UserConfig, $editor->resolved['maxToolSteps']->source);
    }

    public function testAPersistedModelIsSavedAsAProviderMap(): void
    {
        $app = $this->app()->openSettings();
        $app = $app->withSettingsEditor($app->settingsEditor->stage('models', ['openai' => 'gpt-5']))->previewSettings();

        [, $cmd] = $app->confirmSettingsSave();
        self::assertNotNull($cmd);
        self::assertTrue($cmd()->ok());
        self::assertSame(['models' => ['openai' => 'gpt-5']], json_decode((string) file_get_contents($this->configPath), true));
    }

    public function testThePreviewSaysWhatTheSaveOverridesAndWhatStillOutranksIt(): void
    {
        file_put_contents($this->dir . '/home/' . LayeredSettings::dir() . '/' . LayeredSettings::USER_FILE, (string) json_encode(['maxToolSteps' => 5]));
        $app = $this->app()->openSettings();
        $app = $app->withSettingsEditor($app->settingsEditor->stage('maxToolSteps', 40))->previewSettings();

        self::assertSame(['maxToolSteps: overrides the value in your settings.json'], $app->settingsEditor->preview->notes);

        $app = $app->withSettingsEditor($app->settingsEditor->unstage('maxToolSteps')->stage('parallelToolCalls', false)->withTier(SettingsTier::ProjectLocal))
            ->previewSettings();
        // No trusted project in this fixture, so the tier itself is refused —
        // and the user-tier value that would outrank it is named.
        self::assertFalse($app->settingsEditor->preview->canSave());
        self::assertArrayHasKey('*', $app->settingsEditor->preview->refusals);
    }

    public function testARefusedChangeIsShownAndNotWritten(): void
    {
        $app = $this->app()->openSettings();
        $app = $app->withSettingsEditor($app->settingsEditor->stage('provider', 'openai'))->previewSettings();

        self::assertFalse($app->settingsEditor->preview->canSave());
        self::assertArrayHasKey('provider', $app->settingsEditor->preview->refusals);
        [, $cmd] = $app->confirmSettingsSave();
        self::assertNull($cmd);
    }

    public function testAFailedSaveKeepsTheEditSetAndSaysWhy(): void
    {
        $app = $this->app()->openSettings();
        $app = $app->withSettingsEditor($app->settingsEditor->stage('maxToolSteps', 40));

        [$app] = $app->update(SettingsSavedMsg::failed(SettingsTier::You, ['maxToolSteps'], 'disk full'));

        self::assertSame(['maxToolSteps' => 40], $app->settingsEditor->set);
        self::assertSame('Not saved: disk full', $app->settingsEditor->status);
    }

    public function testWithoutAWriterThePreviewIsBlocked(): void
    {
        $app = $this->app()->withSettingsWriter(null)->openSettings();
        $app = $app->withSettingsEditor($app->settingsEditor->stage('maxToolSteps', 40))->previewSettings();

        self::assertFalse($app->settingsEditor->preview->canSave());
        [, $cmd] = $app->confirmSettingsSave();
        self::assertNull($cmd);
    }

    private function app(): App
    {
        $sources = fn (): SettingsSources => $this->sources();

        return App::new($this->createMock(ProviderInterface::class), 'test-model')
            ->withSettingsSources($sources)
            ->withSettingsWriter(SettingsWriter::new($this->configPath, function (array $set, array $unset): void {
                // A stand-in for Bootstrap::writeUserConfig() over this sandbox file.
                $data = is_file($this->configPath) ? (array) json_decode((string) file_get_contents($this->configPath), true) : [];
                file_put_contents($this->configPath, (string) json_encode(SettingsWriter::patched($data, $set, $unset)));
            }));
    }

    private function sources(): SettingsSources
    {
        return SettingsSources::fromLaunch(
            $this->dir . '/project',
            false,
            $this->dir . '/home/' . LayeredSettings::dir(),
            $this->configPath,
            [],
        );
    }
}
