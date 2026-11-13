<?php

declare(strict_types=1);

namespace SugarCraft\DatePicker\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\DatePicker\DatePicker;

/**
 * Verifies that every Lang::t() key referenced in src/ exists in lang/en.php.
 *
 * This ensures no translation key is silently missing when strings are
 * internationalized via Lang::t().
 */
final class LangCoverageTest extends TestCase
{
    private static array $translationKeys = [];

    public static function setUpBeforeClass(): void
    {
        $langFile = __DIR__ . '/../lang/en.php';
        $translations = require $langFile;
        \assert(\is_array($translations));
        self::$translationKeys = \array_keys($translations);
    }

    public function testLangFileExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../lang/en.php');
    }

    public function testLangFileReturnsArray(): void
    {
        $langFile = __DIR__ . '/../lang/en.php';
        $result = require $langFile;
        $this->assertIsArray($result);
    }

    public function testAllDayKeysPresent(): void
    {
        $langFile = __DIR__ . '/../lang/en.php';
        $translations = require $langFile;
        for ($i = 0; $i <= 6; $i++) {
            $this->assertArrayHasKey("day.{$i}", $translations);
        }
    }

    public function testAllMonthKeysPresent(): void
    {
        $langFile = __DIR__ . '/../lang/en.php';
        $translations = require $langFile;
        for ($i = 1; $i <= 12; $i++) {
            $this->assertArrayHasKey("month.{$i}", $translations);
        }
    }

    /**
     * Extracts translation key patterns from Lang::t() calls.
     *
     * For simple literal strings like Lang::t('foo'), returns ['foo'].
     * For concatenations like Lang::t('prefix' . $suffix), returns ['prefix']
     * and the pattern 'prefix*' is considered valid.
     *
     * @return list<string>
     */
    private static function extractKeyPatternsFromFile(string $path): array
    {
        $content = \file_get_contents($path);
        if ($content === false) {
            return [];
        }

        $patterns = [];

        // Find all Lang::t(...) calls - capture the entire argument section
        if (\preg_match_all('/Lang::t\(([^)]+)\)/', $content, $outerMatches)) {
            foreach ($outerMatches[1] as $arg) {
                $arg = \trim($arg);

                // Case 1: Simple string literal Lang::t('key') or Lang::t("key")
                if (\preg_match('/^[\'"]([^\'"]+)[\'"]$/', $arg, $m)) {
                    $patterns[] = $m[1];
                    continue;
                }

                // Case 2: Concatenation Lang::t('prefix' . $var)
                // Extract the leading string literal portion (e.g. 'day.' from 'day.' . $dow)
                // We only check the static prefix, not runtime-generated suffixes
                if (\preg_match_all("/'([^']+)'\\s*\\.\\s*\\\$/", $arg, $m)) {
                    foreach ($m[1] as $literalPart) {
                        if ($literalPart !== '') {
                            // For 'prefix' . $var, we verify that at least
                            // one key matching 'prefix*' exists in translations
                            $patterns[] = $literalPart . '*';
                        }
                    }
                }
            }
        }

        return $patterns;
    }

    public function testAllLangKeysUsedInSrcExistInEnPhp(): void
    {        $srcDir = __DIR__ . '/../src';
        $patterns = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $foundPatterns = self::extractKeyPatternsFromFile($file->getPathname());
            foreach ($foundPatterns as $pattern) {
                $patterns[$pattern] = true;
            }
        }

        $this->assertNotEmpty($patterns, 'No Lang::t() calls found in src/');

        foreach (\array_keys($patterns) as $pattern) {
            if (\str_ends_with($pattern, '*')) {
                // Wildcard pattern - check that at least one matching key exists
                $prefix = \substr($pattern, 0, -1);
                $matches = \array_filter(
                    self::$translationKeys,
                    fn(string $key): bool => \str_starts_with($key, $prefix)
                );
                $this->assertNotEmpty(
                    $matches,
                    "Lang::t('{$prefix}...' . \$var) is used in src/ but no key starting with '{$prefix}' found in lang/en.php"
                );
            } else {
                $this->assertContains(
                    $pattern,
                    self::$translationKeys,
                    "Lang::t('{$pattern}') is used in src/ but missing from lang/en.php"
                );
            }
        }
    }

    // ------------------------------------------------------------------
    // dp-14: locale contract validator (width budgets + positional order)
    // ------------------------------------------------------------------

    /**
     * Render budgets mirrored from src/DatePicker.php (CELL_CHARS=3, the
     * natural 21-wide header floor minus a 5-column year slot = 16).
     */
    private const DAY_BUDGET = 3;
    private const MONTH_BUDGET = 16;

    /**
     * @return array<string, list<string>> every lang/*.php file shipped
     */
    public static function localeFileProvider(): array
    {
        $cases = [];
        foreach (\glob(__DIR__ . '/../lang/*.php') ?: [] as $file) {
            $cases[\basename($file)] = [$file];
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('localeFileProvider')]
    public function testEveryShippedLocaleSatisfiesTheContract(string $file): void
    {
        $translations = require $file;
        \assert(\is_array($translations));

        $this->assertSame(
            [],
            self::contractViolations($translations),
            $file . ' violates the locale width/order contract',
        );
    }

    /**
     * The validator proper: missing keys, over-budget values and a shuffled
     * Sunday-first positional block are all reported (never thrown), so the
     * provider test prints the whole violation list at once.
     *
     * @param array<string, mixed> $t
     * @return list<string>
     */
    private static function contractViolations(array $t): array
    {
        $errors = [];

        $keys = \array_keys($t);
        for ($i = 0; $i <= 6; $i++) {
            $key = "day.{$i}";
            if (!isset($t[$key]) || !\is_string($t[$key])) {
                $errors[] = "missing string key {$key}";
                continue;
            }
            if (\SugarCraft\Core\Util\Width::of($t[$key]) > self::DAY_BUDGET) {
                $errors[] = "day.{$i} value '{$t[$key]}' exceeds the " . self::DAY_BUDGET . '-column cell budget';
            }
        }
        for ($i = 1; $i <= 12; $i++) {
            $key = "month.{$i}";
            if (!isset($t[$key]) || !\is_string($t[$key])) {
                $errors[] = "missing string key {$key}";
                continue;
            }
            if (\SugarCraft\Core\Util\Width::of($t[$key]) > self::MONTH_BUDGET) {
                $errors[] = "month.{$i} value '{$t[$key]}' exceeds the " . self::MONTH_BUDGET . '-column header budget';
            }
        }

        // dp-15: the header template is part of the key set, and its
        // placeholder tokens are protected — a locale may reorder them,
        // never drop them (a silent template loss would render "{year}").
        if (!isset($t['header']) || !is_string($t['header'])) {
            $errors[] = 'missing string key header';
        } else {
            foreach (['{month}', '{year}'] as $token) {
                if (!str_contains($t['header'], $token)) {
                    $errors[] = "header template '{$t['header']}' must keep the protected token {$token}";
                }
            }
        }

        // Positional Sunday-first shape: day.0..day.6 must be the leading
        // key block in index order (the renderer maps dow index straight to
        // column index — reordering keys would silently mislabel columns).
        $leading = \array_slice($keys, 0, 7);
        $expected = [];
        for ($i = 0; $i <= 6; $i++) {
            $expected[] = "day.{$i}";
        }
        if ($leading !== $expected) {
            $errors[] = 'day.0..day.6 must be the first seven keys, in index order (Sunday-first positional contract)';
        }

        return $errors;
    }

    public function testValidatorRejectsSyntheticFiveColumnDayName(): void
    {
        // Acceptance pin: the validator itself bites — "Montag" (6 columns)
        // in an otherwise-valid en copy reports exactly one budget error.
        $en = require __DIR__ . '/../lang/en.php';
        \assert(\is_array($en));

        $violations = self::contractViolations(\array_replace($en, ['day.1' => 'Montag']));
        $this->assertCount(1, $violations);
        $this->assertStringContainsString('day.1', $violations[0]);
        $this->assertStringContainsString('cell budget', $violations[0]);
    }

    public function testValidatorRejectsOverlongMonthName(): void
    {
        $en = require __DIR__ . '/../lang/en.php';
        \assert(\is_array($en));

        $violations = self::contractViolations(\array_replace($en, ['month.9' => 'Septemberember extremely long']));
        $this->assertCount(1, $violations);
        $this->assertStringContainsString('month.9', $violations[0]);
    }

    public function testOversizedLocaleDayNamesClampPerSlotAtRender(): void
    {
        // The render-side half of dp-14: with the longday fixture locale
        // active, "Montag"/"Freitag" clip to their own 3-column cells — the
        // 7-column header alignment survives exactly, nothing bleeds.
        $prevLocale = \SugarCraft\Core\I18n\T::locale();
        \SugarCraft\Core\I18n\T::overrideNamespace('datepicker', __DIR__ . '/fixtures/lang');
        \SugarCraft\Core\I18n\T::setLocale('longday');

        try {
            $picker = DatePicker::new(new \DateTimeImmutable('2026-05-01'));
            $lines = \explode("\n", $picker->view(21));
            $dayRow = \SugarCraft\Core\Util\Ansi::strip($lines[1]);

            // Clamped cells: Mo->Mon? 'Montag' truncated to 3 cols = 'Mon';
            // 'Freitag' = 'Fre'; all other en names untouched, still Sunday-
            // first on 3-column strides.
            // Slots start at exact 3-column strides: Su@0, 'Montag'->Mon@3
            // (fills its slot), Tu@6, ... 'Freitag'->Fre@15, Sa@18 — so the
            // names butt together with no separator inside full slots. The
            // unclamped bug would have read 'Su MontagTu We ...' bleeding
            // over the Tu/We columns.
            $this->assertSame('Su MonTu We Th FreSa', \rtrim($dayRow));

            foreach (\explode("\n", $picker->view(21)) as $line) {
                $this->assertLessThanOrEqual(
                    21,
                    \SugarCraft\Core\Util\Width::of(\SugarCraft\Core\Util\Ansi::strip($line)),
                    'no rendered line may exceed the requested grid width',
                );
            }
        } finally {
            \SugarCraft\Core\I18n\T::setLocale($prevLocale);
            \SugarCraft\Core\I18n\T::overrideNamespace('datepicker', __DIR__ . '/../lang');
        }
    }

    public function testHeaderBudgetClipsMonthNamesGracefully(): void
    {
        // month.* > budget: at the natural floor the header is truncated to
        // the calendar area instead of spilling (fixture month.9 is 22 cols).
        $prevLocale = \SugarCraft\Core\I18n\T::locale();
        \SugarCraft\Core\I18n\T::overrideNamespace('datepicker', __DIR__ . '/fixtures/lang');
        \SugarCraft\Core\I18n\T::setLocale('longmonth');

        try {
            $picker = DatePicker::new(new \DateTimeImmutable('2026-09-01'));
            $header = \SugarCraft\Core\Util\Ansi::strip(\explode("\n", $picker->view(21))[0]);
            $this->assertLessThanOrEqual(21, \SugarCraft\Core\Util\Width::of($header));
            $this->assertStringStartsWith('Septembe', \rtrim($header));
            $this->assertStringNotContainsString('2026', \rtrim($header),
                'the overlong month name must consume the header width, year clipped away — graceful, bounded, no structural bleed');
        } finally {
            \SugarCraft\Core\I18n\T::setLocale($prevLocale);
            \SugarCraft\Core\I18n\T::overrideNamespace('datepicker', __DIR__ . '/../lang');
        }
    }
}