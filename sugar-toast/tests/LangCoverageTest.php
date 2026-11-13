<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

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

    public function testAllToastTypeKeysPresent(): void
    {
        $langFile = __DIR__ . '/../lang/en.php';
        $translations = require $langFile;
        $this->assertArrayHasKey('type.info',    $translations);
        $this->assertArrayHasKey('type.warning', $translations);
        $this->assertArrayHasKey('type.error',   $translations);
        $this->assertArrayHasKey('type.success', $translations);
    }

    public function testDismissKeyPresent(): void
    {
        $langFile = __DIR__ . '/../lang/en.php';
        $translations = require $langFile;
        $this->assertArrayHasKey('dismiss', $translations);
    }

    public function testNotificationCountKeyPresent(): void
    {
        $langFile = __DIR__ . '/../lang/en.php';
        $translations = require $langFile;
        $this->assertArrayHasKey('count', $translations);
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

        // Find all 'Lang::t(' positions in the file
        $offset = 0;
        while (($pos = \strpos($content, 'Lang::t(', $offset)) !== false) {
            // Find the opening paren position
            $openPos = $pos + \strlen('Lang::t');

            // Scan forward to find the matching close paren, tracking nesting
            $depth = 0;
            $i = $openPos;
            $argEnd = null;
            while ($i < \strlen($content)) {
                $ch = $content[$i];
                if ($ch === '(' || $ch === '[' || $ch === '{') {
                    $depth++;
                } elseif ($ch === ')' || $ch === ']' || $ch === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $argEnd = $i;
                        break;
                    }
                }
                $i++;
            }

            if ($argEnd === null) {
                $offset = $openPos + 1;
                continue;
            }

            // Extract the argument portion
            $argsStr = \substr($content, $openPos + 1, $argEnd - $openPos - 1);

            // Split by comma, but respect nested brackets
            $args = self::splitArgsRespectNesting($argsStr);

            if (empty($args)) {
                $offset = $argEnd + 1;
                continue;
            }

            $firstArg = \trim($args[0]);

            // Case 1: Simple string literal Lang::t('key') or Lang::t("key")
            if (\preg_match('/^[\'"]([^\'"]+)[\'"]$/', $firstArg, $m)) {
                $patterns[] = $m[1];
                $offset = $argEnd + 1;
                continue;
            }

            // Case 2: Concatenation Lang::t('prefix' . $var)
            // Extract the leading string literal portion (e.g. 'day.' from 'day.' . $dow)
            if (\preg_match("/^[\']([^\']+)[\']\\s*\\.\\s*\\\$/", $firstArg, $m)) {
                if ($m[1] !== '') {
                    // For 'prefix' . $var, we verify that at least
                    // one key matching 'prefix*' exists in translations
                    $patterns[] = $m[1] . '*';
                }
            }

            $offset = $argEnd + 1;
        }

        return $patterns;
    }

    /**
     * Split arguments by comma, respecting bracket nesting.
     *
     * @return list<string>
     */
    private static function splitArgsRespectNesting(string $argsStr): array
    {
        $args = [];
        $depth = 0;
        $current = '';
        $len = \strlen($argsStr);

        for ($i = 0; $i < $len; $i++) {
            $ch = $argsStr[$i];

            if ($ch === '(' || $ch === '[' || $ch === '{') {
                $depth++;
                $current .= $ch;
            } elseif ($ch === ')' || $ch === ']' || $ch === '}') {
                $depth--;
                $current .= $ch;
            } elseif ($ch === ',' && $depth === 0) {
                $args[] = \trim($current);
                $current = '';
            } else {
                $current .= $ch;
            }
        }

        if ($current !== '') {
            $args[] = \trim($current);
        }

        return $args;
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

    /**
     * TST-PARITY: key-parity guard across every locale. A key landing in
     * en.php only (or drifting out in one locale) previously stayed green
     * and silently English-fallbacks for that locale's users. The manual
     * array_diff_key sweep (6 keys x 17 locales) says the tree is clean
     * today; this arm keeps it clean by construction (house LangParity
     * family — the candy-code exists for exactly this).
     */
    public function testEveryLocaleCarriesExactlyTheEnKeySet(): void
    {
        $enPath = __DIR__ . '/../lang/en.php';
        $enKeys = require $enPath;
        $this->assertIsArray($enKeys, 'lang/en.php must return an array');
        $enKeys = array_keys($enKeys);

        $locales = glob(__DIR__ . '/../lang/*.php');
        $this->assertGreaterThanOrEqual(17, count($locales), 'locale glob fell off the lang dir');

        $offenders = [];
        foreach ($locales as $path) {
            $code = basename($path, '.php');
            if ($code === 'en') {
                continue;
            }
            $keys = array_keys(require $path);
            $missing = array_diff($enKeys, $keys);
            $extra = array_diff($keys, $enKeys);
            foreach ($missing as $key) {
                $offenders[] = "{$code}: missing '{$key}' (silently English-fallbacks)";
            }
            foreach ($extra as $key) {
                $offenders[] = "{$code}: extra '{$key}' (no en source of truth)";
            }
        }

        // Fail-loud once with the full roster — one red lists every drift,
        // not just the first (audit-one-shy test-suite law).
        $this->assertSame([], $offenders, "locale key-parity drift:\n" . implode("\n", $offenders));
    }
}
