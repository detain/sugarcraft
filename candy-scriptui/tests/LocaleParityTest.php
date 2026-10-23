<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Locale parity guard for docs-fix-1023 M6: every lang/*.php file must
 * carry EXACTLY the key set of lang/en.php in the same order with the
 * same {placeholder} set, non-empty values. A key added to en without a
 * translation used to fall back to English silently mid-output; this
 * makes that a CI failure (same genre as candy-minesweeper's).
 */
final class LocaleParityTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function localeProvider(): array
    {
        $rows = [];
        $files = glob(__DIR__ . '/../lang/*.php');
        sort($files);
        foreach ($files as $file) {
            $rows[basename($file, '.php')] = [$file];
        }
        self::assertGreaterThanOrEqual(16, count($rows), 'the shipped locale set shrank');
        return $rows;
    }

    /**
     * @param string $file absolute path of one lang/<locale>.php
     *
     * @dataProvider localeProvider
     */
    public function testLocaleMatchesEnglishShape(string $file): void
    {
        /** @var array<string, string> $en */
        $en = require __DIR__ . '/../lang/en.php';
        /** @var array<string, string> $locale */
        $locale = require $file;

        $this->assertSame(
            array_keys($en),
            array_keys($locale),
            basename($file) . ' key set/order drifted from en.php',
        );

        foreach ($locale as $key => $value) {
            $this->assertIsString($value);
            $this->assertNotSame('', trim($value), "$key is empty in " . basename($file));
            preg_match_all('/\{(\w+)\}/', $en[$key], $expected);
            preg_match_all('/\{(\w+)\}/', $value, $actual);
            $this->assertSame(
                $expected[0],
                $actual[0],
                "$key placeholders drifted in " . basename($file),
            );
        }
    }
}
