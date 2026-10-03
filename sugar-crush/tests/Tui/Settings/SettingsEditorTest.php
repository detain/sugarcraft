<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui\Settings;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Config\LayeredSettings;
use SugarCraft\Crush\Config\Settings\SettingCategory;
use SugarCraft\Crush\Config\Settings\SettingDefinition;
use SugarCraft\Crush\Config\Settings\SettingsSchema;
use SugarCraft\Crush\Config\Settings\SettingSource;
use SugarCraft\Crush\Config\Settings\UiEditability;
use SugarCraft\Crush\Tui\Settings\SettingsEditor;
use SugarCraft\Crush\Tui\Settings\SettingsFile;
use SugarCraft\Crush\Tui\Settings\SettingsSources;

/**
 * The settings view's state (roadmap N-P1): what it lists, where each value
 * came from, and how it answers keys. Read-only — nothing here writes.
 */
final class SettingsEditorTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush-settings-' . bin2hex(random_bytes(5));
        mkdir($this->dir . '/home/' . LayeredSettings::dir(), 0o700, true);
        mkdir($this->dir . '/project/' . LayeredSettings::dir(), 0o700, true);
    }

    protected function tearDown(): void
    {
        // `2>&1` into exec()'s output array: a bare exec() inherits fd 2 onto
        // the suite's stderr (ChildStderrCaptureTest).
        exec('rm -rf ' . escapeshellarg($this->dir) . ' 2>&1', $cleanup);
    }

    private function sources(?bool $trusted = null, array $env = []): SettingsSources
    {
        return SettingsSources::fromLaunch(
            $this->dir . '/project',
            $trusted,
            $this->dir . '/home/' . LayeredSettings::dir(),
            $this->dir . '/home/' . LayeredSettings::dir() . '/config.json',
            $env,
        );
    }

    private function write(string $relative, array $data): void
    {
        file_put_contents($this->dir . '/' . $relative, json_encode($data));
    }

    private static function key(string $rune): KeyMsg
    {
        return new KeyMsg(KeyType::Char, $rune);
    }

    public function testEveryListedKeyIsOnExactlyOneCategoryTabAndFilesIsLast(): void
    {
        $editor = SettingsEditor::open($this->sources());
        $labels = $editor->tabLabels();

        self::assertSame(SettingsEditor::FILES_TAB, end($labels));
        self::assertCount(\count($editor->categories()) + 1, $labels);

        $seen = [];
        foreach ($editor->categories() as $i => $category) {
            $tab = $editor;
            for ($n = 0; $n < $i; $n++) {
                $tab = $tab->update(new KeyMsg(KeyType::Right));
            }

            self::assertSame($i, $tab->tab);
            foreach ($tab->rows() as $row) {
                self::assertInstanceOf(SettingDefinition::class, $row);
                self::assertSame($category, $row->category);
                $seen[] = $row->key;
            }
        }

        $listed = array_map(
            static fn (SettingDefinition $d): string => $d->key,
            array_values(array_filter(SettingsSchema::all(), static fn (SettingDefinition $d): bool => $d->ui !== UiEditability::Hidden)),
        );
        sort($seen);
        sort($listed);
        self::assertSame($listed, $seen, 'every schema key the schema does not hide is listed once');
    }

    public function testAValueShowsTheSourceThatWonAndWhatItShadowed(): void
    {
        $this->write('home/' . LayeredSettings::dir() . '/config.json', ['maxToolSteps' => 400]);
        $this->write('home/' . LayeredSettings::dir() . '/' . LayeredSettings::USER_FILE, ['maxToolSteps' => 300]);

        $editor = SettingsEditor::open($this->sources());
        $resolved = $editor->resolvedFor(SettingsSchema::byKey('maxToolSteps'));

        self::assertSame(400, $resolved->value);
        self::assertSame(SettingSource::UserConfig, $resolved->source);
        self::assertSame([SettingSource::UserSettings], $resolved->shadowed);
        self::assertFalse($resolved->locked);
    }

    public function testAnEnvironmentVariableLocksTheKeyItOutranks(): void
    {
        $provider = SettingsSchema::byKey('provider');
        self::assertNotNull($provider?->envVar, 'fixture: provider has an env override');

        $editor = SettingsEditor::open($this->sources(env: [(string) $provider->envVar => 'dev-sglang']));
        $resolved = $editor->resolvedFor($provider);

        self::assertSame('dev-sglang', $resolved->value);
        self::assertSame(SettingSource::Env, $resolved->source);
        self::assertTrue($resolved->locked);
        self::assertStringContainsString((string) $provider->envVar, (string) $resolved->lockReason);
    }

    public function testATrustedProjectContributesAndAnUnknownTrustContributesNothing(): void
    {
        $this->write('project/' . LayeredSettings::SHARED_PATH, ['theme' => 'dracula']);
        $theme = SettingsSchema::byKey('theme');

        $trusted = SettingsEditor::open($this->sources(trusted: true))->resolvedFor($theme);
        self::assertSame('dracula', $trusted->value);
        self::assertSame(SettingSource::ProjectShared, $trusted->source);

        foreach ([null, false] as $answer) {
            self::assertSame(
                SettingSource::Default,
                SettingsEditor::open($this->sources(trusted: $answer))->resolvedFor($theme)->source,
                'a project the launch has not trusted (or this view was not told about) sets nothing',
            );
        }
    }

    public function testTheFilesTabSaysWhichFilesAreReadAndListsTheProjectConfigAsNotALayer(): void
    {
        $this->write('home/' . LayeredSettings::dir() . '/config.json', []);
        $this->write('project/' . LayeredSettings::SHARED_PATH, ['theme' => 'dracula']);
        $this->write('project/' . LayeredSettings::dir() . '/config.json', ['trustedProjectMcp' => ['/x']]);

        $status = static function (SettingsSources $sources): array {
            $out = [];
            foreach ($sources->files as $file) {
                $out[$file->role] = $file->status;
            }

            return $out;
        };

        self::assertSame(
            ['your config' => 'read', 'your settings' => 'absent', 'project local' => 'absent', 'project shared' => 'not shown', 'project config' => 'not a layer'],
            $status($this->sources()),
        );
        self::assertSame('ignored', $status($this->sources(trusted: false))['project shared']);
        self::assertSame('read', $status($this->sources(trusted: true))['project shared']);

        $editor = SettingsEditor::open($this->sources())->update(new KeyMsg(KeyType::Left));
        self::assertTrue($editor->onFilesTab());
        self::assertContainsOnlyInstancesOf(SettingsFile::class, $editor->rows());
        $notALayer = array_values(array_filter($editor->rows(), static fn (SettingsFile $f): bool => $f->status === 'not a layer'));
        self::assertCount(1, $notALayer);
        self::assertStringContainsString('Not a settings layer', $notALayer[0]->note);
    }

    public function testSearchingTypesFiltersAcrossCategoriesAndEscClearsBeforeClosing(): void
    {
        $editor = SettingsEditor::open($this->sources())->update(self::key('/'));
        self::assertTrue($editor->searching);

        foreach (mb_str_split('trusted') as $char) {
            $editor = $editor->update(self::key($char));
        }

        self::assertSame('trusted', $editor->query);
        $keys = array_map(static fn (SettingDefinition $d): string => $d->key, $editor->rows());
        self::assertContains('trustedProjectSettings', $keys);
        self::assertContains('trustedProjectHooks', $keys);
        $categories = array_unique(array_map(static fn (SettingDefinition $d): string => $d->category->value, $editor->rows()));
        self::assertSame([SettingCategory::Permissions->value], array_values($categories));

        // Letters type while searching; arrows still move.
        $moved = $editor->update(new KeyMsg(KeyType::Down));
        self::assertSame(1, $moved->cursor);

        $kept = $editor->update(new KeyMsg(KeyType::Enter));
        self::assertFalse($kept->searching);
        self::assertSame('trusted', $kept->query);
        self::assertSame($kept, $kept->update(new KeyMsg(KeyType::Right)), 'tabs do not switch under a search');

        $cleared = $kept->update(new KeyMsg(KeyType::Escape));
        self::assertNotNull($cleared);
        self::assertSame('', $cleared->query);
        self::assertNull($cleared->update(new KeyMsg(KeyType::Escape)), 'the second Esc closes');
    }

    public function testASearchSpaceIsKeptAndControlBytesAreNot(): void
    {
        $editor = SettingsEditor::open($this->sources())->update(self::key('/'));
        $editor = $editor->update(self::key('a'))->update(new KeyMsg(KeyType::Space))->update(self::key("\x1b"))->update(self::key('b'));

        self::assertSame('a b', $editor->query);
        self::assertSame('a ', $editor->update(new KeyMsg(KeyType::Backspace))->query);
    }

    public function testAnOpeningQueryShowsItsMatches(): void
    {
        $editor = SettingsEditor::open($this->sources(), 'parallel');

        self::assertSame('parallel', $editor->query);
        self::assertFalse($editor->searching);
        self::assertContains('parallelToolCalls', array_map(static fn (SettingDefinition $d): string => $d->key, $editor->rows()));
    }

    public function testNoMatchIsAnEmptyListNotAnError(): void
    {
        $editor = SettingsEditor::open($this->sources(), 'zzqqxx');

        self::assertSame([], $editor->rows());
        self::assertNull($editor->selected());
        self::assertSame($editor, $editor->update(new KeyMsg(KeyType::Down)));
    }

    public function testClicksSelectTabsAndRowsAndTheWheelMovesWithoutWrapping(): void
    {
        $editor = SettingsEditor::open($this->sources());
        $files = \count($editor->tabLabels()) - 1;

        self::assertTrue($editor->click(SettingsEditor::TAB_ZONE . $files)->onFilesTab());
        self::assertSame(2, $editor->click(SettingsEditor::ROW_ZONE . '2')->cursor);
        self::assertSame($editor, $editor->click(SettingsEditor::ROW_ZONE . '999'));
        self::assertSame($editor, $editor->click('pane:files'));

        $last = \count($editor->rows()) - 1;
        self::assertSame(min(3, $last), $editor->wheel(1)->cursor);
        self::assertSame(0, $editor->wheel(-1)->cursor, 'the wheel clamps at the top rather than wrapping');
    }

    public function testASecretShapedValueIsNeverShown(): void
    {
        $this->write('home/' . LayeredSettings::dir() . '/config.json', ['claudeMcpEnv' => ['TOKEN' => 'sk-very-secret']]);

        $editor = SettingsEditor::open($this->sources(), 'environment');
        $frame = $editor->view(\SugarCraft\Crush\Theme::default(), 140, 40);

        self::assertStringNotContainsString('sk-very-secret', $frame);
        self::assertStringContainsString('1 variable, hidden', \SugarCraft\Core\Util\Ansi::strip($frame));
    }
}
