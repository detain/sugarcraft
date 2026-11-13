<?php

declare(strict_types=1);

namespace SugarCraft\MdView\Tests;

use PHPUnit\Framework\TestCase;

/**
 * KM7 + the house "all locales or none" law: every shipped locale must
 * carry exactly the key set of en.php, with the same placeholders per key,
 * or localized runs leak English on the most common error paths.
 *
 * pt-br.php rides along: it must not be a byte-copy of pt.php (a "locale"
 * that isn't one).
 */
final class LocaleParityTest extends TestCase
{
    private const EXPECTED_KEY_COUNT = 27;

    /** @return array<string, array<string, string>> */
    private function allLocales(): array
    {
        $files = glob(__DIR__ . '/../lang/*.php');
        $this->assertIsArray($files);
        $this->assertCount(16, $files, 'sixteen locale files ship with this app');

        $locales = [];
        foreach ($files as $file) {
            $code  = basename($file, '.php');
            $rows  = require $file;
            $this->assertIsArray($rows, 'lang/' . $code . '.php must return an array');
            $locales[$code] = $rows;
        }

        return $locales;
    }

    public function testEveryLocaleCarriesExactlyTheEnglishKeySet(): void
    {
        $locales = $this->allLocales();
        $english = array_keys($locales['en']);
        sort($english);

        foreach ($locales as $code => $rows) {
            $keys = array_keys($rows);
            sort($keys);
            $this->assertSame(
                $english,
                $keys,
                'lang/' . $code . '.php key set drifted from en.php (KM7)'
            );
            $this->assertCount(
                self::EXPECTED_KEY_COUNT,
                $rows,
                'lang/' . $code . '.php must carry every key'
            );
        }
    }

    public function testEveryLocaleRepeatsTheEnglishPlaceholders(): void
    {
        $locales = $this->allLocales();

        foreach ($locales['en'] as $key => $template) {
            preg_match_all('/\{\w+\}/', $template, $expected);
            sort($expected[0]);

            foreach ($locales as $code => $rows) {
                preg_match_all('/\{\w+\}/', $rows[$key], $actual);
                sort($actual[0]);
                $this->assertSame(
                    $expected[0],
                    $actual[0],
                    'lang/' . $code . '.php placeholder set for ' . $key . ' differs from en'
                );
            }
        }
    }

    public function testNoLocaleValueIsAnUntranslatedEnglishCopy(): void
    {
        // Only the en file may byte-match itself; a translated value that
        // equals English means the translation was skipped (keys with no
        // letters worth translating, like pure placeholders, do not exist here).
        $locales = $this->allLocales();

        foreach ($locales as $code => $rows) {
            if ($code === 'en') {
                continue;
            }
            $stale = [];
            foreach ($rows as $key => $value) {
                if ($value === $locales['en'][$key]) {
                    $stale[] = $key;
                }
            }
            $this->assertSame(
                [],
                $stale,
                'lang/' . $code . '.php carries untranslated keys: ' . implode(', ', $stale)
            );
        }
    }

    public function testBrazilianPortugueseIsItsOwnLocale(): void
    {
        $pt    = (string) file_get_contents(__DIR__ . '/../lang/pt.php');
        $ptBr  = (string) file_get_contents(__DIR__ . '/../lang/pt-br.php');

        $this->assertNotSame($pt, $ptBr, 'pt-br.php must not be a byte-copy of pt.php');
        // European pt says "ficheiro"; Brazilian pt-BR says "arquivo".
        $this->assertStringContainsString('ficheiro', $pt);
        $this->assertStringContainsString('arquivo', $ptBr);
        $this->assertStringNotContainsString('ficheiro', $ptBr);
    }
}
