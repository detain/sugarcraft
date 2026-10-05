<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui\Settings;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Theme;
use SugarCraft\Crush\Tui\Settings\SettingsEditor;
use SugarCraft\Crush\Tui\Settings\SettingsSources;

/**
 * The settings view on a small terminal (N-P5, Appendix N §4.7 "Layout"):
 * below 70 columns or 18 rows it is ONE column, the list fills the body, and
 * `i` swaps it for the highlighted key's details — which is the only way a
 * short terminal ever shows a key's help, source and lock.
 */
final class SettingsEditorNarrowTest extends TestCase
{
    private static function sources(): SettingsSources
    {
        return SettingsSources::fromLaunch('/nonexistent-root', null, null, null, ['SUGARCRUSH_PROVIDER' => 'dev-sglang']);
    }

    private static function plain(SettingsEditor $editor, int $cols = 120, int $rows = 30): string
    {
        return Ansi::strip($editor->view(Theme::default(), $cols, $rows));
    }

    /** @return list<array{0: int, 1: int}> sizes on both sides of each threshold */
    private static function singleColumnSizes(): array
    {
        return [[69, 30], [60, 24], [40, 12], [120, 17], [100, 10], [24, 9]];
    }

    public function testBelowEitherThresholdTheListHasTheBodyToItself(): void
    {
        $editor = SettingsEditor::open(self::sources());

        foreach (self::singleColumnSizes() as [$cols, $rows]) {
            $plain = self::plain($editor, $cols, $rows);

            self::assertStringNotContainsString('key      ', $plain, "{$cols}x{$rows}: no detail panel beside or below the list");
            if ($cols >= 40) {
                self::assertStringContainsString('i details', $plain, "{$cols}x{$rows}: the footer says how to see it");
            }
        }

        // At the thresholds the details come back beside or below the list.
        foreach ([[70, 30], [120, 18], [140, 30]] as [$cols, $rows]) {
            $plain = self::plain($editor, $cols, $rows);

            self::assertStringContainsString('key      ', $plain, "{$cols}x{$rows}");
            self::assertStringNotContainsString('i details', $plain, "{$cols}x{$rows}");
        }
    }

    public function testIShowsTheDetailsInTheListsPlaceAndBack(): void
    {
        $editor = SettingsEditor::open(self::sources());
        $first = $editor->rows()[0];
        $details = $editor->update(new KeyMsg(KeyType::Char, 'i'));

        self::assertNotNull($details);
        self::assertTrue($details->detail);
        $plain = self::plain($details, 60, 20);
        self::assertStringContainsString('key      ' . $first->key, $plain, 'the highlighted key, in full');
        self::assertStringContainsString('i list', $plain);

        $back = $details->update(new KeyMsg(KeyType::Char, 'i'));
        self::assertNotNull($back);
        self::assertFalse($back->detail);
        self::assertStringNotContainsString('key      ' . $first->key, self::plain($back, 60, 20));
    }

    public function testTheLockOfAKeyIsReachableOnAShortTerminal(): void
    {
        // `provider` is locked by the environment in these sources.
        $editor = SettingsEditor::open(self::sources(), 'provider');
        $plain = self::plain($editor->update(new KeyMsg(KeyType::Char, 'i')) ?? $editor, 50, 14);

        self::assertStringContainsString('locked   set by SUGARCRUSH_PROVIDER', $plain);
    }

    public function testEveryLineIsExactlyTheWidthInEveryStateAndSize(): void
    {
        $open = SettingsEditor::open(self::sources());
        $states = [
            'list' => $open,
            'details' => $open->update(new KeyMsg(KeyType::Char, 'i')),
            'staged' => $open->stage('maxToolSteps', 40)->stage('maxOutputTokens', 100),
            'project shared' => $open->stage('maxToolSteps', 40)->withTier(\SugarCraft\Crush\Config\Settings\SettingsTier::ProjectShared),
            'search' => SettingsEditor::open(self::sources(), 'zzzz-no-such-setting'),
            'files' => $open->click(SettingsEditor::TAB_ZONE . (string) (\count($open->tabLabels()) - 1)),
        ];

        foreach ($states as $name => $editor) {
            self::assertNotNull($editor, $name);
            foreach ([...self::singleColumnSizes(), [20, 8], [21, 8], [69, 17]] as [$cols, $rows]) {
                $lines = explode("\n", $editor->view(Theme::default(), $cols, $rows));

                self::assertCount($rows, $lines, "{$name} at {$cols}x{$rows}");
                foreach ($lines as $i => $line) {
                    self::assertSame($cols, Width::string($line), "{$name}: line {$i} at {$cols}x{$rows}");
                }
            }
        }
    }

    public function testTheTitleShortensTheTierRatherThanLosingTheCount(): void
    {
        $editor = SettingsEditor::open(self::sources())
            ->stage('parallelToolCalls', false)
            ->withTier(\SugarCraft\Crush\Config\Settings\SettingsTier::ProjectShared);

        self::assertStringContainsString('1 unsaved · This project (shared)', self::plain($editor, 120, 30));
        $narrow = explode("\n", self::plain($editor, 36, 12))[0];
        self::assertStringContainsString('1 unsaved · project shared', $narrow);
        self::assertStringEndsWith('╮', $narrow, 'the corner survives');
    }

    public function testDetailsShownMeanNoRowZones(): void
    {
        $editor = SettingsEditor::open(self::sources());
        $rowZones = static fn (SettingsEditor $e, int $cols, int $rows): array => array_filter(
            $e->zones(Theme::default(), $cols, $rows),
            static fn (array $z): bool => str_starts_with($z[3], SettingsEditor::ROW_ZONE),
        );

        self::assertNotSame([], $rowZones($editor, 60, 20), 'the list is clickable');
        $details = $editor->update(new KeyMsg(KeyType::Char, 'i'));
        self::assertNotNull($details);
        self::assertSame([], $rowZones($details, 60, 20), 'nothing to click where the details are painted');
        self::assertNotSame([], $rowZones($details, 140, 30), 'a wide view still shows the list beside them');
    }

    public function testTheFooterKeepsCloseWhenItCannotFitEveryHint(): void
    {
        $footer = static function (SettingsEditor $e, int $cols, int $rows): string {
            $lines = explode("\n", Ansi::strip($e->view(Theme::default(), $cols, $rows)));

            return trim($lines[$rows - 2], " │");
        };

        $narrow = $footer(SettingsEditor::open(self::sources()), 40, 12);
        self::assertStringEndsWith('Esc close', $narrow);
        self::assertStringStartsWith('i details', $narrow, 'the hint a single column needs most comes first');
    }
}
