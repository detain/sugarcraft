<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui\Settings;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Config\LayeredSettings;
use SugarCraft\Crush\Config\Settings\SettingsTier;
use SugarCraft\Crush\Config\Settings\SettingsWriter;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Theme;
use SugarCraft\Crush\Tui\Settings\SettingsEditor;
use SugarCraft\Crush\Tui\Settings\SettingsSavedMsg;
use SugarCraft\Crush\Tui\Settings\SettingsSources;

/**
 * The settings editor's keys (carried from W4-g, R-KEYBIND): the whole edit,
 * preview, save and trust round trip driven by keystrokes through the shell,
 * which is the only route a user has.
 */
final class SettingsEditorKeysTest extends TestCase
{
    private string $dir = '';
    private string $configPath = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush-settings-keys-' . bin2hex(random_bytes(5));
        mkdir($this->dir . '/home/' . LayeredSettings::dir(), 0o700, true);
        mkdir($this->dir . '/project/' . LayeredSettings::dir(), 0o700, true);
        $this->configPath = $this->dir . '/home/' . LayeredSettings::dir() . '/config.json';
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir) . ' 2>&1');
    }

    public function testEnterEditsTypingChangesTheValueAndEnterStagesIt(): void
    {
        $app = $this->press($this->app('max tool steps'), new KeyMsg(KeyType::Enter));
        $this->assertNotNull($app->settingsEditor?->editing, 'Enter opens the field');

        $app = $this->press($app, ...array_fill(0, 6, new KeyMsg(KeyType::Backspace)));
        $app = $this->press($app, new KeyMsg(KeyType::Char, '4'), new KeyMsg(KeyType::Char, '0'), new KeyMsg(KeyType::Enter));

        $this->assertNull($app->settingsEditor?->editing);
        $this->assertSame(['maxToolSteps' => 40], $app->settingsEditor?->set);
    }

    public function testEscDropsTheFieldWithoutStaging(): void
    {
        $app = $this->press($this->app('max tool steps'), new KeyMsg(KeyType::Enter), new KeyMsg(KeyType::Escape));

        $this->assertNotNull($app->settingsEditor, 'the view stays open');
        $this->assertNull($app->settingsEditor->editing);
        $this->assertFalse($app->settingsEditor->hasChanges());
    }

    public function testRStagesAResetAndTSwitchesTheTier(): void
    {
        $app = $this->press($this->app('max tool steps'), new KeyMsg(KeyType::Char, 'r'), new KeyMsg(KeyType::Char, 't'));

        $this->assertSame(['maxToolSteps'], $app->settingsEditor?->unset);
        $this->assertSame(SettingsTier::ProjectLocal, $app->settingsEditor?->tier);
        $app = $this->press($app, new KeyMsg(KeyType::Char, 't'));
        $this->assertSame(SettingsTier::ProjectShared, $app->settingsEditor?->tier, 'then the committed project file (N-P5)');
        $app = $this->press($app, new KeyMsg(KeyType::Char, 't'));
        $this->assertSame(SettingsTier::Session, $app->settingsEditor?->tier, 'then the session tier (N-P3)');
        $this->assertSame(SettingsTier::You, $this->press($app, new KeyMsg(KeyType::Char, 't'))->settingsEditor?->tier, 'and back');
    }

    public function testSPreviewsYSavesAndTheViewReResolves(): void
    {
        $app = $this->staged();
        $app = $this->press($app, new KeyMsg(KeyType::Char, 's'));
        $this->assertNotNull($app->settingsEditor?->preview);
        $this->assertFileDoesNotExist($this->configPath);

        [$app, $cmd] = $app->update(new KeyMsg(KeyType::Char, 'y'));
        $this->assertInstanceOf(\Closure::class, $cmd, 'the write is a Cmd');
        [$app] = $app->update($cmd());

        $this->assertSame(['maxToolSteps' => 40], json_decode((string) file_get_contents($this->configPath), true));
        $this->assertFalse($app->settingsEditor?->hasChanges());
        $this->assertStringStartsWith('Saved 1 setting', (string) $app->settingsEditor?->status);
    }

    public function testNLeavesThePreviewAndKeepsTheEditSet(): void
    {
        $app = $this->press($this->staged(), new KeyMsg(KeyType::Char, 's'), new KeyMsg(KeyType::Char, 'n'));

        $this->assertNull($app->settingsEditor?->preview);
        $this->assertSame(['maxToolSteps' => 40], $app->settingsEditor?->set);
    }

    public function testSWithNothingStagedDoesNothing(): void
    {
        $app = $this->press($this->app('max tool steps'), new KeyMsg(KeyType::Char, 's'));

        $this->assertNull($app->settingsEditor?->preview);
    }

    public function testEscWithChangesAsksAndKKeepsEditing(): void
    {
        // The first Esc clears the search the view was opened with.
        $app = $this->press($this->staged(), new KeyMsg(KeyType::Escape), new KeyMsg(KeyType::Escape));
        $this->assertSame(SettingsEditor::CONFIRM_DISCARD, $app->settingsEditor?->confirm, 'it asks rather than closing');
        $this->assertStringContainsString('Close with 1 unsaved change?', Ansi::strip($app->settingsEditor->view(Theme::default(), 100, 30)));

        $kept = $this->press($app, new KeyMsg(KeyType::Char, 'k'));
        $this->assertNull($kept->settingsEditor?->confirm);
        $this->assertSame(['maxToolSteps' => 40], $kept->settingsEditor?->set);

        $discarded = $this->press($app, new KeyMsg(KeyType::Char, 'd'));
        $this->assertNull($discarded->settingsEditor, 'd throws the changes away and closes');

        $saving = $this->press($app, new KeyMsg(KeyType::Char, 's'));
        $this->assertNotNull($saving->settingsEditor?->preview, 's goes to the preview instead');
    }

    public function testEnterOnATrustListAsksAndYGrantsTheProject(): void
    {
        $app = $this->press($this->app('trustedProjectSettings'), new KeyMsg(KeyType::Enter));
        $this->assertSame(SettingsEditor::CONFIRM_TRUST, $app->settingsEditor?->confirm);
        $this->assertSame('trustedProjectSettings', $app->settingsEditor?->pendingTrustKey());
        $view = Ansi::strip($app->settingsEditor->view(Theme::default(), 100, 30));
        $this->assertStringContainsString('Trust this project: trustedProjectSettings', $view);
        foreach (explode("\n", $app->settingsEditor->view(Theme::default(), 40, 20)) as $line) {
            $this->assertLessThanOrEqual(40, Width::string(Ansi::strip($line)));
        }

        [$app, $cmd] = $app->update(new KeyMsg(KeyType::Char, 'y'));
        $this->assertInstanceOf(\Closure::class, $cmd);
        $msg = $cmd();
        $this->assertInstanceOf(SettingsSavedMsg::class, $msg);
        $this->assertTrue($msg->ok(), (string) $msg->error);
        [$app] = $app->update($msg);

        $config = json_decode((string) file_get_contents($this->configPath), true);
        $this->assertSame([realpath($this->dir . '/project')], $config['trustedProjectSettings'] ?? null);
        $this->assertNull($app->settingsEditor?->confirm);
    }

    public function testNCancelsTheTrustQuestionAndWritesNothing(): void
    {
        $app = $this->press($this->app('trustedProjectSettings'), new KeyMsg(KeyType::Enter), new KeyMsg(KeyType::Char, 'n'));

        $this->assertNull($app->settingsEditor?->confirm);
        $this->assertFileDoesNotExist($this->configPath);
    }

    public function testATrustListIsNeverOpenedAsAField(): void
    {
        $editor = SettingsEditor::open($this->sources(), 'trustedProjectSettings')->update(new KeyMsg(KeyType::Enter));

        $this->assertNull($editor?->editing);
        $this->assertFalse($editor?->hasChanges());
    }

    private function staged(): App
    {
        $app = $this->app('max tool steps');

        return $app->withSettingsEditor($app->settingsEditor?->stage('maxToolSteps', 40));
    }

    private function press(App $app, KeyMsg ...$keys): App
    {
        foreach ($keys as $key) {
            [$app] = $app->update($key);
        }

        return $app;
    }

    private function app(string $query): App
    {
        $sources = fn (): SettingsSources => $this->sources();

        return App::new($this->createMock(ProviderInterface::class), 'test-model')
            ->withRoot($this->dir . '/project')
            ->withSettingsSources($sources)
            ->withSettingsWriter(SettingsWriter::new($this->configPath, function (array $set, array $unset): void {
                $data = is_file($this->configPath) ? (array) json_decode((string) file_get_contents($this->configPath), true) : [];
                file_put_contents($this->configPath, (string) json_encode(SettingsWriter::patched($data, $set, $unset)));
            }))
            ->openSettings($query);
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
