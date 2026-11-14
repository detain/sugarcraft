<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Copy-wave pins (candy-git-068, -069, -070, -071, -094, -102, -105).
 *
 * Each assertion locks one adjudicated finding fix against re-rotting: the
 * dash separator law, the German umlaut pass, the Arabic glossary
 * convergence (incl. the cherry-pick/stash success-message collision), the
 * Russian key-hint segment, the jargon-free history refusal, the removal of
 * the unreferenced rebase conflict key, and the routed git.bad_ref payload
 * (the envelope must never carry hardcoded English).
 */
final class LocaleCopyWaveTest extends TestCase
{
    private const LOCALES = ['en', 'ar', 'cs', 'de', 'es', 'fr', 'it', 'ja', 'ko', 'nl', 'pl', 'pt', 'pt-br', 'ru', 'tr', 'zh-cn'];

    /** @return array<string, array<string, string>> */
    private static function catalogs(): array
    {
        $out = [];
        foreach (self::LOCALES as $locale) {
            $data = require __DIR__ . '/../lang/' . $locale . '.php';
            \assert(\is_array($data));
            $out[$locale] = $data;
        }

        return $out;
    }

    public function testValuesUseThePlainDashSeparatorEverywhere(): void
    {
        foreach (self::catalogs() as $locale => $data) {
            foreach ($data as $key => $value) {
                $this->assertStringNotContainsString(' — ', (string) $value, "lang/{$locale}.php[{$key}] carries an em-dash separator");
                $this->assertStringNotContainsString(' -- ', (string) $value, "lang/{$locale}.php[{$key}] carries a double-hyphen separator");
            }
        }
    }

    public function testGermanCarriesRealUmlautsNotSurrogates(): void
    {
        $de = file_get_contents(__DIR__ . '/../lang/de.php');
        \assert($de !== false);
        foreach (['verfuegbar', 'benoetigt', 'druecken', 'loeschen'] as $surrogate) {
            $this->assertStringNotContainsString($surrogate, $de, "de.php still ships the ASCII surrogate {$surrogate}");
        }
    }

    public function testUnreferencedRebaseConflictKeyIsGoneAndBadRefIsRoutedEverywhere(): void
    {
        foreach (self::catalogs() as $locale => $data) {
            $this->assertArrayNotHasKey('rebase.conflict', $data, "lang/{$locale}.php keeps the dead rebase.conflict key");
            $this->assertArrayHasKey('git.bad_ref', $data, "lang/{$locale}.php lacks git.bad_ref");
            $this->assertStringContainsString('{ref}', $data['git.bad_ref'], "lang/{$locale}.php[git.bad_ref] lost its placeholder");
        }
    }

    public function testGuardRefSpeaksThroughLangNotHardcodedEnglish(): void
    {
        $src = file_get_contents(__DIR__ . '/../src/Git.php');
        \assert($src !== false);
        $this->assertStringNotContainsString("ref cannot start with", $src, 'the refusal must ride the git.bad_ref key (candy-git-071)');
        $this->assertStringContainsString("Lang::t('git.bad_ref'", $src);
    }

    public function testHistoryUnavailableSellsNoConstructorInternals(): void
    {
        foreach (self::catalogs() as $locale => $data) {
            $this->assertStringNotContainsString('HistoryManager', $data['history.unavailable'], "lang/{$locale}.php leaks the class name into a user refusal");
        }
    }

    public function testArabicGlossaryConvergesAndSuccessesStayDistinct(): void
    {
        $ar = self::catalogs()['ar'];
        $joined = implode("\n", $ar);
        $this->assertStringNotContainsString('الكرز', $joined, 'the literal fruit calque is off-glossary');
        $this->assertNotSame($ar['cherry_pick.success'], $ar['stash.applied'], 'cherry-pick and stash-apply must not share one status line');
        $this->assertStringContainsString('اختيار-التزام', $ar['cherry_pick.success']);
        $this->assertStringContainsString('التزام', $ar['history.op.commit'], 'one word for commit across the file');
        foreach ($ar as $key => $value) {
            $this->assertMatchesRegularExpression('/^git: |^candy-git: |^(?!git:)(?!candy-git:)/', (string) $value, "lang/ar.php[{$key}] crowds the git: prefix");
        }
    }

    public function testRussianKeyHintsStageSegmentIsRussian(): void
    {
        $ru = self::catalogs()['ru'];
        $this->assertStringContainsString('стейджить всё', $ru['help.keyhints']);
        $this->assertStringNotContainsString('stage всего', $ru['help.keyhints']);
    }
}
