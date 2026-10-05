<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui\Settings;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Crush\Config\Settings\SettingDefinition;
use SugarCraft\Crush\Config\Settings\SettingsSchema;
use SugarCraft\Crush\Config\Settings\SettingsTier;
use SugarCraft\Crush\Theme;
use SugarCraft\Crush\Tui\Settings\SettingsEditor;
use SugarCraft\Crush\Tui\Settings\SettingsSavePreview;
use SugarCraft\Crush\Tui\Settings\SettingsSearch;
use SugarCraft\Crush\Tui\Settings\SettingsSources;

/**
 * The settings view's N-P5 polish: locked keys are read-only and say why, a
 * staged change the chosen tier would refuse is flagged before the preview,
 * the search reaches the variables and flags that override a key, and the
 * save preview lists each change and scrolls.
 */
final class SettingsEditorPolishTest extends TestCase
{
    private static function sources(?string $root = null, ?bool $trusted = null): SettingsSources
    {
        return SettingsSources::fromLaunch($root ?? '/nonexistent-root', $trusted, null, null, ['SUGARCRUSH_PROVIDER' => 'dev-sglang']);
    }

    private static function plain(SettingsEditor $editor, int $cols = 120, int $rows = 30): string
    {
        return Ansi::strip($editor->view(Theme::default(), $cols, $rows));
    }

    private static function key(string $key): SettingDefinition
    {
        return SettingsSchema::byKey($key) ?? throw new \LogicException($key);
    }

    // ── locked keys ─────────────────────────────────────────────────────

    public function testALockedKeyIsReadOnlyAndNamesWhatLocksIt(): void
    {
        $editor = SettingsEditor::open(self::sources(), 'provider');
        self::assertSame('provider', $editor->selected()?->key ?? null);

        $edit = $editor->update(new KeyMsg(KeyType::Enter));
        self::assertNotNull($edit);
        self::assertNull($edit->editing, 'no field opens for a key the environment sets');
        self::assertStringContainsString('SUGARCRUSH_PROVIDER', (string) $edit->status);

        $reset = $editor->update(new KeyMsg(KeyType::Char, 'r'));
        self::assertNotNull($reset);
        self::assertFalse($reset->hasChanges(), 'nor is a reset staged for it');
        self::assertStringContainsString('SUGARCRUSH_PROVIDER', (string) $reset->status);

        self::assertStringContainsString('locked (set by SUGARCRUSH_PROVIDER)', self::plain($editor), 'the footer says so before anything is pressed');
        self::assertStringNotContainsString('Enter edit', self::plain($editor));
    }

    public function testTheFooterOffersOnlyTheKeysThatActOnTheRow(): void
    {
        $steps = SettingsEditor::open(self::sources(), 'max tool steps');
        self::assertStringContainsString('Enter edit · r reset', self::plain($steps));
        self::assertStringNotContainsString('s save', self::plain($steps), 'nothing staged, nothing to save');
        self::assertStringContainsString('s save', self::plain($steps->stage('maxToolSteps', 4)));

        $files = SettingsEditor::open(self::sources());
        $files = $files->click(SettingsEditor::TAB_ZONE . (string) (\count($files->tabLabels()) - 1));
        self::assertTrue($files->onFilesTab());
        self::assertStringNotContainsString('Enter edit', self::plain($files));

        $trust = SettingsEditor::open(self::sources(), 'trusted project settings');
        self::assertStringContainsString('Enter trust this project', self::plain($trust));
    }

    public function testMovingRetiresAStatusLine(): void
    {
        $editor = SettingsEditor::open(self::sources(), 'provider')->beginEdit();
        self::assertNotNull($editor->status);

        $moved = $editor->update(new KeyMsg(KeyType::Down));
        self::assertNotNull($moved);
        self::assertNull($moved->status);
    }

    // ── the tier, before the preview ────────────────────────────────────

    public function testAStagedKeyTheTierRefusesIsFlaggedOnItsRowAndTheStatusLine(): void
    {
        $editor = SettingsEditor::open(self::sources(), 'max tool steps')->stage('maxToolSteps', 40);
        self::assertNull($editor->status, 'the You tier takes it');
        self::assertStringContainsString('• 40', self::plain($editor));

        foreach ([SettingsTier::ProjectLocal, SettingsTier::ProjectShared] as $tier) {
            $onProject = $editor->withTier($tier);
            self::assertStringStartsWith('! maxToolSteps may not be set by a project file', (string) $onProject->status, $tier->name);
            self::assertStringContainsString('t switches the tier', (string) $onProject->status);
            self::assertStringContainsString('✗ 40', self::plain($onProject), $tier->name);
        }
    }

    public function testAProjectTierWarnsWhenTheProjectIsNotTrustedOrAbsent(): void
    {
        $untrusted = SettingsEditor::open(self::sources('/some/root', false))->withTier(SettingsTier::ProjectShared);
        self::assertStringContainsString('not trusted', (string) $untrusted->status);

        $none = SettingsEditor::open(SettingsSources::fromLaunch(null, null, null, null, []))->withTier(SettingsTier::ProjectLocal);
        self::assertStringContainsString('No project is open', (string) $none->status);

        $trusted = SettingsEditor::open(self::sources('/some/root', true))->withTier(SettingsTier::ProjectLocal);
        self::assertNull($trusted->status, 'a trusted project and a project key: nothing to warn about');

        $unknown = SettingsEditor::open(self::sources('/some/root', null))->withTier(SettingsTier::ProjectLocal);
        self::assertNull($unknown->status, 'trust unknown: the writer answers at the preview');
    }

    // ── search ──────────────────────────────────────────────────────────

    public function testTheSearchFindsAKeyByTheVariableOrFlagThatOverridesIt(): void
    {
        $withEnv = array_values(array_filter(SettingsSchema::all(), static fn (SettingDefinition $d): bool => $d->envVar !== null));
        self::assertNotSame([], $withEnv);

        foreach ($withEnv as $definition) {
            $found = array_map(
                static fn (SettingDefinition $d): string => $d->key,
                SettingsSearch::matches((string) $definition->envVar, SettingsSchema::all()),
            );
            self::assertContains($definition->key, $found, (string) $definition->envVar);
        }

        $withFlag = array_values(array_filter(SettingsSchema::all(), static fn (SettingDefinition $d): bool => $d->cliFlag !== null));
        foreach ($withFlag as $definition) {
            $found = array_map(
                static fn (SettingDefinition $d): string => $d->key,
                SettingsSearch::matches((string) $definition->cliFlag, SettingsSchema::all()),
            );
            self::assertContains($definition->key, $found, (string) $definition->cliFlag);
        }
    }

    public function testSeveralWordsMatchAKeyHoldingEveryOne(): void
    {
        $keys = static fn (string $q): array => array_map(
            static fn (SettingDefinition $d): string => $d->key,
            SettingsSearch::matches($q, SettingsSchema::all()),
        );

        $both = $keys('web timeout');
        self::assertContains('webFetchTimeoutSeconds', $both);
        self::assertContains('webSearchTimeoutSeconds', $both);
        self::assertNotContains('bashTimeoutSeconds', $both, 'timeout alone is not enough');
        self::assertNotContains('webSearchMaxResults', $both, 'web alone is not enough');

        self::assertSame($keys('timeout web'), $both, 'in any order');
    }

    public function testAnEmptySearchSaysHowToGetOut(): void
    {
        $plain = self::plain(SettingsEditor::open(self::sources(), 'zzzz-no-such-setting'), 160, 30);

        self::assertStringContainsString('No setting matches "zzzz-no-such-setting"', $plain);
        self::assertStringContainsString('Esc clears it', $plain);
        self::assertStringContainsString('Esc clear search', $plain, 'and so does the footer');
        self::assertStringNotContainsString('←→ category', $plain, 'the arrows do not switch category under a search');

        // A narrow list wraps the sentence rather than cutting it.
        $narrow = self::plain(SettingsEditor::open(self::sources(), 'zzzz-no-such-setting'), 50, 20);
        self::assertStringContainsString('Backspace edits the', $narrow);
    }

    // ── the save preview ────────────────────────────────────────────────

    public function testThePreviewListsEachChangeWithWhatTheFileHadAndWhenItApplies(): void
    {
        $preview = SettingsSavePreview::new(
            SettingsTier::You,
            '/home/you/.sugar-crush/config.json',
            ['maxToolSteps' => 1000, 'theme' => 'nord'],
            ['maxToolSteps' => 40, 'parallelToolCalls' => false, 'theme' => 'nord'],
            ['maxToolSteps' => 40, 'parallelToolCalls' => false],
            ['maxOutputTokens'],
        );

        $changes = $preview->changeLines();
        self::assertCount(3, $changes);
        self::assertStringStartsWith('maxToolSteps: 1000 → 40  [', $changes[0]);
        self::assertSame('parallelToolCalls: (not in this file) → off  [' . self::key('parallelToolCalls')->applyMode->badge() . ']', $changes[1]);
        self::assertStringStartsWith('maxOutputTokens: (not in this file) → (removed', $changes[2]);

        $plain = implode("\n", array_map(Ansi::strip(...), $preview->lines(Theme::default(), 100)));
        self::assertStringContainsString('maxToolSteps: 1000 → 40', $plain);
        self::assertStringNotContainsString('committed file', $plain);
    }

    public function testASaveToTheCommittedProjectFileSaysWhoElseGetsIt(): void
    {
        $preview = SettingsSavePreview::new(SettingsTier::ProjectShared, '/repo/.sugar-crush/settings.json', [], ['parallelToolCalls' => false], ['parallelToolCalls' => false]);
        $plain = implode("\n", array_map(Ansi::strip(...), $preview->lines(Theme::default(), 120)));

        self::assertStringContainsString('Save to This project (shared)', $plain);
        self::assertStringContainsString('everyone who clones this repository', $plain);
    }

    public function testAPreviewTallerThanTheBodyScrolls(): void
    {
        $set = [];
        foreach (['maxToolSteps' => 40, 'maxOutputTokens' => 100, 'parallelToolCalls' => false, 'parallelToolDeadlineSeconds' => 30, 'readMaxBytes' => 1000] as $key => $value) {
            $set[$key] = $value;
        }

        $preview = SettingsSavePreview::new(SettingsTier::You, '/home/you/.sugar-crush/config.json', [], $set, $set);
        $editor = SettingsEditor::open(self::sources())->withPreview($preview);

        $top = self::plain($editor, 80, 14);
        self::assertStringContainsString('Save to You', $top);
        self::assertMatchesRegularExpression('/lines 1–\d+ of \d+ · ↑↓ scroll/', $top);

        $down = $editor->update(new KeyMsg(KeyType::Down));
        self::assertNotNull($down);
        self::assertSame(1, $down->previewOffset);
        self::assertNotNull($down->preview, 'scrolling keeps the preview open');
        self::assertStringNotContainsString('Save to You', self::plain($down, 80, 14));
        self::assertMatchesRegularExpression('/lines 2–\d+ of \d+/', self::plain($down, 80, 14));

        $up = $down->update(new KeyMsg(KeyType::Char, 'k'))?->update(new KeyMsg(KeyType::Up));
        self::assertSame(0, $up?->previewOffset, 'never above the first line');
    }
}
