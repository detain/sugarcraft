<?php

declare(strict_types=1);

namespace SugarCraft\Table\Tests;

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
}
