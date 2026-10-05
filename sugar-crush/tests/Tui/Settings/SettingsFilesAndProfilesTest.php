<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui\Settings;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Commands\EditorCommand;
use SugarCraft\Crush\Config\LayeredSettings;
use SugarCraft\Crush\Config\Settings\ResolvedSetting;
use SugarCraft\Crush\Config\Settings\SettingSource;
use SugarCraft\Crush\Config\Settings\SettingsTier;
use SugarCraft\Crush\Config\Settings\SettingsWriter;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Theme;
use SugarCraft\Crush\Tui\Settings\SettingsEditor;
use SugarCraft\Crush\Tui\Settings\SettingsFileEditedMsg;
use SugarCraft\Crush\Tui\Settings\SettingsFileEditor;
use SugarCraft\Crush\Tui\Settings\SettingsProfile;
use SugarCraft\Crush\Tui\Settings\SettingsProfileMsg;
use SugarCraft\Crush\Tui\Settings\SettingsSources;

/**
 * N-P5's remainder in the settings view: a settings file opened in `$EDITOR`
 * (and re-read, and applied, when the editor exits), settings profiles
 * exported and imported, and the save preview's running-turn note passed in
 * by the shell, which is what knows a turn is running.
 */
final class SettingsFilesAndProfilesTest extends TestCase
{
    private string $dir = '';
    private string $configPath = '';
    private string $root = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush-settings-files-' . bin2hex(random_bytes(5));
        mkdir($this->dir . '/home/' . LayeredSettings::dir(), 0o700, true);
        mkdir($this->dir . '/project', 0o700, true);
        $this->configPath = $this->dir . '/home/' . LayeredSettings::dir() . '/config.json';
        $this->root = (string) realpath($this->dir . '/project');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir) . ' 2>&1');
    }

    // ── $EDITOR ─────────────────────────────────────────────────────────

    public function testEOpensTheTiersFileInTheEditorAsACmd(): void
    {
        [$app, $cmd] = $this->open()->update(new KeyMsg(KeyType::Char, 'e'));

        self::assertInstanceOf(\Closure::class, $cmd, 'the editor runs as a Cmd: the terminal is handed over');
        self::assertNull($app->settingsEditor?->status);
    }

    public function testTheSessionTierHasNoFileToOpen(): void
    {
        $app = $this->open();
        $app = $app->withSettingsEditor($app->settingsEditor?->withTier(SettingsTier::Session));
        [$after, $cmd] = $app->update(new KeyMsg(KeyType::Char, 'e'));

        self::assertNull($cmd);
        self::assertStringContainsString('has no file', (string) $after->settingsEditor?->status);
    }

    public function testAnUntrustedProjectTierSaysWhyItIsNotOpened(): void
    {
        $app = $this->open();
        $app = $app->withSettingsEditor($app->settingsEditor?->withTier(SettingsTier::ProjectShared));
        [$after, $cmd] = $app->update(new KeyMsg(KeyType::Char, 'e'));

        self::assertNull($cmd);
        self::assertStringStartsWith('Not opened:', (string) $after->settingsEditor?->status);
        self::assertStringContainsString('trust', (string) $after->settingsEditor?->status);
    }

    public function testTheEditorIsVisualThenEditorAndTheFileIsQuoted(): void
    {
        $editor = SettingsFileEditor::new(EditorCommand::new()->withEnvironment(['VISUAL' => 'code --wait', 'EDITOR' => 'vi']));
        self::assertSame('code --wait', $editor->editor());

        $failed = SettingsFileEditor::collect('/x/config.json', [], 'nano', 2, null);
        self::assertFalse($failed->ok());
        self::assertStringContainsString('exited with status 2', (string) $failed->error);
        self::assertTrue(SettingsFileEditor::collect('/x/config.json', [], 'nano', 0, null)->ok());
    }

    /** When the editor exits the view re-reads the layers, names what changed, and applies it. */
    public function testAnEditIsReReadAndItsChangedSettingsNamed(): void
    {
        file_put_contents($this->configPath, '{"maxToolSteps": 10, "notASetting": 1}');
        $app = $this->open()->withChat(new Chat());
        file_put_contents($this->configPath, '{"maxToolSteps": 12, "parallelToolCalls": false, "notASetting": 2}');

        [$after] = $app->update(new SettingsFileEditedMsg($this->configPath, ['maxToolSteps' => 10, 'notASetting' => 1], 'vi'));

        $status = (string) $after->settingsEditor?->status;
        self::assertStringContainsString('2 settings changed (maxToolSteps, parallelToolCalls)', $status);
        self::assertSame(12, $after->settingsEditor?->resolved['maxToolSteps']->value, 'the rows show what the file now says');
        self::assertSame(SettingSource::UserConfig, $after->settingsEditor?->resolved['maxToolSteps']->source);
    }

    public function testAnEditThatBreaksTheFileIsSaidSoAndNothingIsApplied(): void
    {
        file_put_contents($this->configPath, '{"maxToolSteps": 10');
        [$after, $cmd] = $this->open()->withChat(new Chat())
            ->update(new SettingsFileEditedMsg($this->configPath, ['maxToolSteps' => 10], 'vi'));

        self::assertNull($cmd);
        self::assertStringStartsWith('Not applied:', (string) $after->settingsEditor?->status);
    }

    public function testAnEditorThatFailedLeavesTheViewAsItWas(): void
    {
        [$after] = $this->open()->update(new SettingsFileEditedMsg($this->configPath, [], 'nope', 'nope could not be started (127)'));

        self::assertStringStartsWith('Not re-read: nope could not be started', (string) $after->settingsEditor?->status);
    }

    public function testOnTheFilesTabEOpensTheHighlightedFile(): void
    {
        $editor = $this->open()->settingsEditor;
        self::assertNotNull($editor);
        $files = \count($editor->tabLabels()) - 1;
        $editor = $editor->click(SettingsEditor::TAB_ZONE . $files);
        self::assertTrue($editor->onFilesTab());
        self::assertNotNull($editor->selectedFilePath(), 'a listed file is opened by its own path');
    }

    // ── profiles ────────────────────────────────────────────────────────

    public function testAnExportCarriesWhatYourFilesSetAndNoSecretOrTrustGrant(): void
    {
        $resolved = [
            'maxToolSteps' => ResolvedSetting::new('maxToolSteps', 12, SettingSource::UserConfig),
            'parallelToolCalls' => ResolvedSetting::new('parallelToolCalls', false, SettingSource::Session),
            'maxOutputTokens' => ResolvedSetting::new('maxOutputTokens', null, SettingSource::Default),
            'connectTimeoutSeconds' => ResolvedSetting::new('connectTimeoutSeconds', 7.0, SettingSource::Env),
            'trustedProjectSettings' => ResolvedSetting::new('trustedProjectSettings', ['/r'], SettingSource::UserConfig),
            'layout' => ResolvedSetting::new('layout', ['left' => 'files'], SettingSource::UserConfig),
        ];

        self::assertEquals(['maxToolSteps' => 12, 'parallelToolCalls' => false], SettingsProfile::values($resolved), 'schema order, any order here');
    }

    public function testAnImportStagesWhatTheTierTakesAndReportsTheRest(): void
    {
        $resolved = ['maxToolSteps' => ResolvedSetting::new('maxToolSteps', 12, SettingSource::UserConfig)];
        $staged = SettingsProfile::staged(
            ['maxToolSteps' => 12, 'maxOutputTokens' => 900, 'theme' => 'dracula', 'trustedProjectHooks' => ['/x'], 'nope' => 1],
            $resolved,
            SettingsTier::You,
        );

        self::assertSame(['maxOutputTokens' => 900], $staged['set']);
        self::assertSame(['maxToolSteps'], $staged['same'], 'a value already in force is not staged again');
        self::assertSame(['theme', 'trustedProjectHooks', 'nope'], array_keys($staged['skipped']));
    }

    public function testExportThenImportRoundTripsThroughTheView(): void
    {
        file_put_contents($this->configPath, '{"maxToolSteps": 33}');
        $path = $this->dir . '/profiles/mine.json';

        $app = $this->open();
        [$prompt] = $app->update(new KeyMsg(KeyType::Char, 'x'));
        self::assertSame(SettingsEditor::PROFILE_EXPORT, $prompt->settingsEditor?->profileAction());
        self::assertSame(SettingsProfile::defaultPath($this->configPath), $prompt->settingsEditor?->profilePath());
        self::assertStringContainsString('Export a settings profile to', Ansi::strip((string) $prompt->settingsEditor?->view(Theme::default(), 100, 30)));

        $prompt = $prompt->withSettingsEditor($prompt->settingsEditor?->withProfilePrompt(SettingsEditor::PROFILE_EXPORT, $path));
        [$exporting, $cmd] = $prompt->update(new KeyMsg(KeyType::Enter));
        self::assertNull($exporting->settingsEditor?->profileAction(), 'the prompt closes');
        self::assertInstanceOf(\Closure::class, $cmd);
        [$exported] = $exporting->update($cmd());
        self::assertStringStartsWith('Exported 1 setting to ' . $path, (string) $exported->settingsEditor?->status);
        self::assertSame(['maxToolSteps' => 33], json_decode((string) file_get_contents($path), true));
        self::assertSame(0o600, fileperms($path) & 0o777);

        // Change the profile, then import it: staged on the chosen tier, nothing written.
        file_put_contents($path, '{"maxToolSteps": 40, "theme": "dracula"}');
        $importing = $exported->withSettingsEditor($exported->settingsEditor?->withProfilePrompt(SettingsEditor::PROFILE_IMPORT, $path));
        [$reading, $cmd] = $importing->update(new KeyMsg(KeyType::Enter));
        [$imported] = $reading->update($cmd());
        self::assertSame(['maxToolSteps' => 40], $imported->settingsEditor?->set);
        self::assertStringContainsString('Staged 1 setting', (string) $imported->settingsEditor?->status);
        self::assertStringContainsString('1 skipped (theme is saved by /theme', (string) $imported->settingsEditor?->status);
        self::assertSame(['maxToolSteps' => 33], json_decode((string) file_get_contents($this->configPath), true));
    }

    public function testAProfileThatIsNotASettingsObjectIsNotImported(): void
    {
        $path = $this->dir . '/list.json';
        file_put_contents($path, '[1, 2]');
        $editor = $this->open()->settingsEditor?->withProfileResult(SettingsProfileMsg::failed(SettingsProfileMsg::IMPORT, $path, 'x'));
        self::assertStringStartsWith('Not imported: x', (string) $editor?->status);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('JSON list');
        SettingsProfile::read($path);
    }

    public function testNothingToExportIsRefusedRatherThanWrittenAsAList(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('nothing to export');
        SettingsProfile::write($this->dir . '/empty.json', []);
    }

    public function testAProfileIsNeverWrittenOverASettingsFile(): void
    {
        foreach (SettingsProfile::LAYER_FILE_NAMES as $name) {
            $caught = null;
            try {
                SettingsProfile::write($this->dir . '/home/' . LayeredSettings::dir() . '/' . $name, ['maxToolSteps' => 1]);
            } catch (\RuntimeException $e) {
                $caught = $e;
            }
            self::assertNotNull($caught, $name . ' was written as a profile');
            self::assertStringContainsString('settings file name', $caught->getMessage());
        }

        self::assertFileDoesNotExist($this->configPath);
    }

    public function testATildePathIsExpandedAgainstHome(): void
    {
        self::assertSame('/home/u/p.json', SettingsProfile::expand(' ~/p.json ', '/home/u'));
        self::assertSame('rel.json', SettingsProfile::expand('rel.json', '/home/u'));
    }

    // ── the running-turn note ───────────────────────────────────────────

    public function testThePreviewSaysWhenATurnIsRunning(): void
    {
        $app = $this->open();
        $app = $app->withSettingsEditor($app->settingsEditor?->stage('maxToolSteps', 40));

        $idle = $app->withChat(new Chat())->previewSettings()->settingsEditor;
        self::assertFalse($idle?->preview?->turnRunning);
        self::assertStringNotContainsString('A turn is running', Ansi::strip((string) $idle?->view(Theme::default(), 120, 40)));

        $busy = $app->withChat(new Chat(inFlight: true))->previewSettings()->settingsEditor;
        self::assertTrue($busy?->preview?->turnRunning);
        self::assertStringContainsString('A turn is running', Ansi::strip((string) $busy?->view(Theme::default(), 120, 40)));
    }

    private function open(): App
    {
        $sources = fn (): SettingsSources => SettingsSources::fromLaunch(
            $this->root,
            false,
            $this->dir . '/home/' . LayeredSettings::dir(),
            $this->configPath,
            [],
        );

        return App::new($this->createMock(ProviderInterface::class), 'test-model')
            ->withRoot($this->root)
            ->withSettingsSources($sources)
            ->withSettingsWriter(SettingsWriter::new($this->configPath, function (array $set, array $unset): void {
                $data = is_file($this->configPath) ? (array) json_decode((string) file_get_contents($this->configPath), true) : [];
                file_put_contents($this->configPath, (string) json_encode(SettingsWriter::patched($data, $set, $unset)));
            })->withProject($this->root, false))
            ->openSettings();
    }
}
