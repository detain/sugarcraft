<?php

declare(strict_types=1);

namespace SugarCraft\SuperCandy\Tests;

use PHPUnit\Framework\TestCase;

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

    public function testAllStatusKeysPresent(): void
    {
        $translations = require __DIR__ . '/../lang/en.php';
        $this->assertArrayHasKey('status.nothing_to_delete', $translations);
        $this->assertArrayHasKey('status.cancelled', $translations);
        $this->assertArrayHasKey('status.deleted', $translations);
        $this->assertArrayHasKey('status.deleted_with_errors', $translations);
        $this->assertArrayHasKey('status.nothing_to_undo', $translations);
        $this->assertArrayHasKey('status.undone', $translations);
        $this->assertArrayHasKey('status.undo_with_errors', $translations);
        $this->assertArrayHasKey('status.cannot_close_last_tab', $translations);
    }

    public function testAllKeyHelpKeysPresent(): void
    {
        $translations = require __DIR__ . '/../lang/en.php';
        $this->assertArrayHasKey('keyhelp.default', $translations);
    }

    public function testAllSearchKeysPresent(): void
    {
        $translations = require __DIR__ . '/../lang/en.php';
        $this->assertArrayHasKey('search.no_match', $translations);
        $this->assertArrayHasKey('search.counter', $translations);
        $this->assertArrayHasKey('search.type_dir', $translations);
        $this->assertArrayHasKey('search.type_file', $translations);
    }

    public function testAllPaneKeysPresent(): void
    {
        $translations = require __DIR__ . '/../lang/en.php';
        $this->assertArrayHasKey('pane.hidden_suffix', $translations);
    }

    public function testAllSortKeysPresent(): void
    {
        $translations = require __DIR__ . '/../lang/en.php';
        $this->assertArrayHasKey('sort.name_asc', $translations);
        $this->assertArrayHasKey('sort.name_desc', $translations);
        $this->assertArrayHasKey('sort.mtime_asc', $translations);
        $this->assertArrayHasKey('sort.mtime_desc', $translations);
        $this->assertArrayHasKey('sort.size_asc', $translations);
        $this->assertArrayHasKey('sort.size_desc', $translations);
    }

    public function testAllEntryKeysPresent(): void
    {
        $translations = require __DIR__ . '/../lang/en.php';
        $this->assertArrayHasKey('entry.dir', $translations);
        $this->assertArrayHasKey('entry.link', $translations);
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
    {
        $srcDir = __DIR__ . '/../src';
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
}
