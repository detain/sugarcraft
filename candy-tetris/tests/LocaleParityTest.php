<?php

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Locale parity guard. The catalogue started life as en.php alone, so every
 * other locale silently fell back to English; now that fifteen more ship, a
 * half-filled locale is the failure mode to fear — a missing key costs a
 * player one English line inside an otherwise translated frame, which no
 * runtime error ever announces. en.php is the source of truth: every locale
 * must carry exactly its key set, in its order, with its tokens.
 */
final class LocaleParityTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function localeFiles(): array
    {
        $cases = [];
        foreach (glob(__DIR__ . '/../lang/*.php') ?: [] as $path) {
            $code = basename($path, '.php');
            $cases[$code] = [$code, $path];
        }
        return $cases;
    }

    #[DataProvider('localeFiles')]
    public function testEveryLocaleCarriesExactlyTheEnglishKeySet(string $code, string $path): void
    {
        $en = require __DIR__ . '/../lang/en.php';
        $locale = require $path;
        $this->assertSame(
            array_keys($en),
            array_keys($locale),
            "locale '$code' key set/order must match en.php",
        );
    }

    #[DataProvider('localeFiles')]
    public function testEveryTranslationIsANonEmptyString(string $code, string $path): void
    {
        $locale = require $path;
        foreach ($locale as $key => $value) {
            $this->assertIsString($value, "$code: $key is not a string");
            $this->assertNotSame('', trim($value), "$code: $key is empty");
        }
    }

    /**
     * Substitution tokens ride the key, not the language: a translated
     * 'final score: {points}' that drops or renames the placeholder breaks
     * the named-argument replace at the call site with no warning. Compared
     * as multisets, so a locale is free to reorder the sentence.
     */
    #[DataProvider('localeFiles')]
    public function testEveryLocaleCarriesTheEnglishPlaceholderTokens(string $code, string $path): void
    {
        $en = require __DIR__ . '/../lang/en.php';
        $locale = require $path;
        foreach ($en as $key => $english) {
            $expected = self::tokens($english);
            sort($expected);
            $actual = self::tokens((string) $locale[$key]);
            sort($actual);
            $this->assertSame(
                $expected,
                $actual,
                "locale '$code' key '$key' must carry exactly the placeholders " . implode(',', $expected),
            );
        }
    }

    /**
     * The sprintf-shaped keys are consumed by sprintf(), so a lost or
     * gained %d is a fatal or a silent truncation — never a translation
     * choice. And since candy-tetris-030: MULTI-positional values are
     * forbidden outright — a three-%d frame key lets a locale reorder its
     * labels while the call site still feeds the args positionally,
     * printing the score under "lines" with every structural leg green.
     * Frame copy that takes more than one value uses named {tokens}; only
     * the single-value combo counter may stay sprintf-shaped.
     */
    #[DataProvider('localeFiles')]
    public function testEveryLocaleCarriesTheEnglishSprintfShape(string $code, string $path): void
    {
        $en = require __DIR__ . '/../lang/en.php';
        $locale = require $path;
        foreach ($en as $key => $english) {
            $this->assertSame(
                substr_count((string) $english, '%d'),
                substr_count((string) $locale[$key], '%d'),
                "locale '$code' key '$key' must keep the %d argument count",
            );
            $this->assertLessThanOrEqual(
                1,
                substr_count((string) $locale[$key], '%d'),
                "locale '$code' key '$key' uses more than one positional %d — the reorder trap (candy-tetris-030); migrate to named {tokens}",
            );
        }
    }

    /**
     * Punctuation/diacritics arms (candy-tetris-029/-088/-089): the shape
     * of these checks is a pin per documented defect class, not a
     * language model —
     * - Italian 'modalita' is a typo of 'modalità' in every seat;
     * - Russian clauses with чтобы carry a comma before the conjunction;
     * - the versus win lines are banner-register: any Latin letters a
     *   locale uses there must be capitals (every other locale's win line
     *   shouts; a lowercase one reads as body copy).
     */
    #[DataProvider('localeFiles')]
    public function testLocalePunctuationStaysInRegister(string $code, string $path): void
    {
        $locale = require $path;
        foreach ($locale as $key => $value) {
            $this->assertStringNotContainsString(
                'modalita',
                (string) $value,
                "locale '$code' key '$key': unaccented Italian 'modalita' (candy-tetris-029)",
            );
            if ($code === 'ru') {
                $this->assertSame(
                    0,
                    preg_match('/нажмите [^\s,]+ чтобы/u', (string) $value),
                    "locale '$code' key '$key': missing comma before чтобы (candy-tetris-088)",
                );
            }
            if (str_starts_with((string) $key, 'win.') && preg_match('/[a-zA-Z]/', (string) $value) === 1) {
                $this->assertSame(
                    mb_strtoupper((string) $value),
                    $value,
                    "locale '$code' key '$key': Latin win text must sit in the all-caps banner register (candy-tetris-089)",
                );
            }
        }
        if ($code === 'it') {
            $this->assertStringContainsString('modalità', (string) $locale['intro.vs'], "it.php intro.vs must keep the grave accent (candy-tetris-029)");
        }
    }

    /**
     * The usage screen translates its prose but never its flags: a refusal
     * that names '--puzzle' and a help line that advertises '—puzzle' would
     * leave the player guessing. Pinned per locale, derived flag list first.
     */
    #[DataProvider('localeFiles')]
    public function testEveryUsageScreenNamesTheAcceptedFlags(string $code, string $path): void
    {
        $locale = require $path;
        $help = (string) $locale['help_text'];
        foreach (['candy-tetris', '-v', '--vs', '-h', '--help', '--version'] as $flag) {
            $this->assertStringContainsString($flag, $help, "locale '$code' usage screen must name '$flag'");
        }
    }

    /**
     * A file that is a copy of en.php passes every structural leg while
     * shipping zero translation — the quietest way to look compliant.
     */
    #[DataProvider('localeFiles')]
    public function testNoLocaleIsAnUndisclosedCopyOfEnglish(string $code, string $path): void
    {
        if ($code === 'en') {
            $this->assertTrue(true, 'en.php is the source of truth, not a copy');
            return;
        }
        $en = require __DIR__ . '/../lang/en.php';
        $locale = require $path;
        $this->assertNotSame(
            array_values($en),
            array_values($locale),
            "locale '$code' carries English values — translate it or drop the file",
        );
    }

    /**
     * The over-banner fold (candy-tetris-005) re-splits the hint on the
     * house em-dash joiner; a locale that dropped or duplicated it would
     * silently opt out of the whole-word line discipline.
     */
    #[DataProvider('localeFiles')]
    public function testEveryRestartHintIsTheTwoClauseHousePair(string $code, string $path): void
    {
        $locale = require $path;
        $this->assertSame(
            2,
            count(preg_split('/\s+—\s+/', (string) $locale['over.restart_hint']) ?: []),
            "locale '$code' restart_hint must be two clauses joined by one spaced em-dash — the banner fold counts on it",
        );
    }

    public function testEnglishSourceOfTruthCarriesEveryRenderedKey(): void
    {
        $en = require __DIR__ . '/../lang/en.php';
        foreach ([
            'score_card', 'help_card', 'next_label', 'hold_label', 'paused',
            'over.title', 'over.final_score', 'over.restart_hint', 'over.reason',
            'over.reason.spawn_blocked', 'over.reason.top_out', 'over.reason.crushed',
            'hold_used', 'combo_line', 'b2b_line',
            'over.best', 'over.new_best', 'over.games',
            'label.player', 'label.computer', 'win.player', 'win.computer',
            'vs.garbage_rule', 'vs.record',
            'vs.strip.you', 'vs.strip.computer', 'vs.strip.score', 'vs.strip.lines', 'vs.strip.level',
            'intro.title', 'intro.goal', 'intro.press', 'intro.keys', 'intro.vs', 'key_or',
            'mode.unknown', 'help_text',
            // The key-education surfaces (candy-tetris-006/-095): the
            // compact sidebar pointer, the in-frame card, and --help.
            'keys.mini', 'help.keys', 'help.close', 'help.floor',
            // The on-frame moment slot (candy-tetris-025/-092): clears,
            // level-ups, incoming garbage and the save refusal ride the
            // banner machinery instead of raw stderr.
            'moment.lines.one', 'moment.lines.many', 'moment.level', 'moment.garbage',
            // The destructive-press confirm questions (candy-tetris-054/-083).
            'confirm.quit', 'confirm.restart',
            // Launch/CLI diagnostic frame (candy-tetris-028): the first
            // text a user reads is catalogue-owned, not inline English.
            'launch.extras', 'score.save.failed', 'keys.ignored', 'scores.ignored',
            'config.problem', 'cli.refusal', 'cli.too_small', 'cli.too_small_vs',
        ] as $key) {
            $this->assertArrayHasKey($key, $en, "en.php must own '$key' — visible strings never live inline");
        }
    }

    /**
     * Roster-count arm: the data provider can only compare files that exist,
     * so deleting a whole locale would silently shrink the shipped set and
     * every leg above would stay green. The count is pinned HERE.
     */
    public function testTheShippedLocaleRosterIsExactlySixteenFiles(): void
    {
        $codes = array_keys(self::localeFiles());
        sort($codes);
        $this->assertSame([
            'ar', 'cs', 'de', 'en', 'es', 'fr', 'it', 'ja',
            'ko', 'nl', 'pl', 'pt', 'pt-br', 'ru', 'tr', 'zh-cn',
        ], $codes, 'shipped locale roster changed — a count change must be a deliberate edit here');
    }

    /** @return list<string> */
    private static function tokens(string $value): array
    {
        return preg_match_all('/\{(\w+)\}/', $value, $m) === false ? [] : $m[1];
    }
}
