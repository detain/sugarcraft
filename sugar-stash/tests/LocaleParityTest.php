<?php

declare(strict_types=1);

namespace SugarCraft\Stash\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Every locale under lang/ must ship the FULL key set of en.php with every
 * `{placeholder}` preserved.
 *
 * Before the audit fix the 15 non-English files carried only 3 keys each, so
 * 78/81 strings silently fell back to English for every non-en user (the
 * Lang lookup chain hides the gap — nothing failed, the UI was just wrong).
 * This guard makes the gap red: keys, non-empty values, and placeholder
 * multisets are compared against en.php bidirectionally.
 */
final class LocaleParityTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function localeProvider(): array
    {
        $files = scandir(__DIR__ . '/../lang');
        \assert($files !== false);
        $cases = [];
        foreach ($files as $file) {
            if (!str_ends_with($file, '.php') || $file === 'en.php') {
                continue;
            }
            $cases[basename($file, '.php')] = [substr($file, 0, -4)];
        }
        return $cases;
    }

    /** @return array<string, string> */
    private static function english(): array
    {
        $translations = require __DIR__ . '/../lang/en.php';
        \assert(\is_array($translations));
        return $translations;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('localeProvider')]
    public function testLocaleCarriesTheExactEnKeySet(string $locale): void
    {
        $translations = require __DIR__ . '/../lang/' . $locale . '.php';
        $this->assertIsArray($translations, "lang/{$locale}.php must return an array");

        $en = self::english();
        $missing = array_diff(array_keys($en), array_keys($translations));
        $extra = array_diff(array_keys($translations), array_keys($en));

        $this->assertSame([], $missing, "lang/{$locale}.php is missing keys: " . implode(', ', $missing));
        $this->assertSame([], $extra, "lang/{$locale}.php carries keys en.php does not define: " . implode(', ', $extra));
        $this->assertCount(count($en), $translations);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('localeProvider')]
    public function testLocaleValuesAreNonEmpty(string $locale): void
    {
        $translations = require __DIR__ . '/../lang/' . $locale . '.php';
        \assert(\is_array($translations));
        foreach ($translations as $key => $value) {
            $this->assertIsString($value, "lang/{$locale}.php[{$key}] must be a string");
            $this->assertNotSame('', trim($value), "lang/{$locale}.php[{$key}] is empty");
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('localeProvider')]
    public function testLocalePreservesPlaceholders(string $locale): void
    {
        $translations = require __DIR__ . '/../lang/' . $locale . '.php';
        \assert(\is_array($translations));
        foreach (self::english() as $key => $englishValue) {
            $expected = self::placeholders($englishValue);
            $actual = self::placeholders((string) ($translations[$key] ?? ''));
            $this->assertSame(
                $expected,
                $actual,
                "lang/{$locale}.php[{$key}] must keep exactly the en placeholders (" . implode(',', $expected) . ')',
            );
        }
    }

    /**
     * Sorted multiset of {placeholder} tokens in a message.
     *
     * @return list<string>
     */
    private static function placeholders(string $value): array
    {
        preg_match_all('/\{[a-z_]+\}/i', $value, $matches);
        $found = $matches[0];
        sort($found);
        return $found;
    }
}
