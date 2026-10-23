<?php

declare(strict_types=1);

namespace SugarCraft\Mail\Tests;

use PHPUnit\Framework\TestCase;

/**
 * House i18n law: no silent locale gaps. Every key shipped in lang/en.php
 * must exist in every locale file, and placeholder sets must match so a
 * translated value can never drop (or invent) a substitution slot.
 *
 * docs-fix-1023 found 13 of 30 en keys missing from all 15 non-en locales;
 * this guard is the pin that keeps them in step.
 */
final class LangLocaleParityTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function localeProvider(): array
    {
        $files = \glob(\dirname(__DIR__) . '/lang/*.php') ?: [];
        $cases = [];
        foreach ($files as $file) {
            $code = \basename($file, '.php');
            if ($code === 'en') {
                continue;
            }
            $cases[$code] = [$file];
        }
        if ($cases === []) {
            throw new \RuntimeException('expected locale files beside en.php');
        }
        return $cases;    }

    /**
     * @param string $file
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('localeProvider')]
    public function testEveryEnglishKeyExistsInTheLocale(string $file): void
    {
        $en  = require \dirname(__DIR__) . '/lang/en.php';
        $loc = require $file;

        $missing = \array_diff(\array_keys($en), \array_keys($loc));
        $extra   = \array_diff(\array_keys($loc), \array_keys($en));

        $this->assertSame(
            [],
            $missing,
            \basename($file) . ' is missing ' . \count($missing) . ' key(s): ' . \implode(', ', $missing)
        );
        $this->assertSame(
            [],
            $extra,
            \basename($file) . ' carries ' . \count($extra) . ' key(s) absent from en.php: ' . \implode(', ', $extra)
        );
    }

    /**
     * @param string $file
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('localeProvider')]
    public function testPlaceholderSetsMatchEnglish(string $file): void
    {
        $en  = require \dirname(__DIR__) . '/lang/en.php';
        $loc = require $file;

        foreach ($en as $key => $english) {
            if (!isset($loc[$key])) {
                continue; // covered by the presence test
            }
            \preg_match_all('/\{([A-Za-z_]+)\}/', $english, $a);
            \preg_match_all('/\{([A-Za-z_]+)\}/', $loc[$key], $b);
            $this->assertSame(
                $a[1],
                $b[1],
                "placeholders for '{$key}' differ in " . \basename($file)
            );
        }
    }

    /**
     * @param string $file
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('localeProvider')]
    public function testValuesAreNotUntranslatedEnglishCopies(string $file): void
    {
        // A locale must actually translate, not shadow-copy en. A single
        // identical value is legitimate (Spanish "Error" == English "Error"),
        // a mass of them is a silent-gap relapse — threshold 3.
        $en  = require \dirname(__DIR__) . '/lang/en.php';
        $loc = require $file;

        $copied = [];
        foreach ($en as $key => $english) {
            if (isset($loc[$key]) && $loc[$key] === $english && \preg_match('/[A-Za-z]{4,}/', $english)) {
                $copied[] = $key;
            }
        }
        $this->assertLessThan(
            3,
            \count($copied),
            \basename($file) . ' copies English verbatim for ' . \count($copied) . ' keys: ' . \implode(', ', $copied)
        );
    }
}
