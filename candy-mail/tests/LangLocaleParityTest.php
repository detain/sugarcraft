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
     * The docs-fix-1023 mechanical sweep shipped corrupted CJK substitutions
     * into the ja/ko catalogs (finding candy-mail-001): hanja look-alikes
     * dropped into kana/hangul words (丕-for-ホ, 宍-for-有, 亖-for-せ, 浩/有-for-호/유)
     * and simplified-Chinese bytes (认证) riding the Japanese catalog where
     * the Japanese 認証 belongs. Enumerated per-locale denylists so a
     * re-sweep can never silently re-poison a value — the sets are catalog
     * local on purpose: 有/効 are legitimate in zh-cn/ja, poison in ko.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function cjkCorruptionProvider(): array
    {
        return [
            'ja' => ['ja.php', '/[\x{4E15}\x{5B8D}\x{4E96}\x{8BA4}\x{8BC1}\x{4EAA}\x{535C}]/u'],
            'ko' => ['ko.php', '/[\x{6D69}\x{6709}\x{6548}]/u'],
        ];
    }

    /**
     * @param string $file    locale file name beside lang/
     * @param string $pattern denylist over the observed corrupt codepoints
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('cjkCorruptionProvider')]
    public function testCjkCatalogsCarryNoCorruptedCodepoints(string $file, string $pattern): void
    {
        $values = require \dirname(__DIR__) . '/lang/' . $file;

        foreach ($values as $key => $value) {
            $this->assertSame(
                0,
                \preg_match($pattern, $value),
                \sprintf('%s[%s] carries a corrupted codepoint from the docs-fix-1023 sweep (candy-mail-001)', $file, $key)
            );
        }
    }

    /**
     * The same mechanical sweep (docs-fix-1023) stripped diacritics and
     * hamzas from the Latin/Arabic catalogs (finding candy-mail-004):
     * gueltiger/valido/nao/neni/gecerli/prawidlowa/صالحا-style values with
     * their accents gone. Floor: each swept key must carry the corrected
     * characters — a re-stripping goes red here.
     *
     * @return array<string, array{0: string, 1: array<string, list<string>>}>
     */
    public static function orthographyFloorProvider(): array
    {
        return [
            'de'    => ['de.php', ['smtp.invalid_helo_host' => ['ü']]],
            'es'    => ['es.php', [
                'smtp.invalid_helo_host'   => ['á', 'í'],
                'transport.not_serializable' => ['ú'],
                'resend.curl_missing'      => ['ñ'],
            ]],
            'pt'    => ['pt.php', [
                'smtp.invalid_helo_host'   => ['ã', 'é', 'á', 'í'],
                'transport.not_serializable' => ['ã'],
            ]],
            'pt-br' => ['pt-br.php', [
                'smtp.invalid_helo_host'   => ['ã', 'é', 'á', 'í'],
                'transport.not_serializable' => ['ã'],
            ]],
            'cs'    => ['cs.php', [
                'smtp.invalid_helo_host'   => ['í', 'ý', 'á', 'č'],
                'transport.not_serializable' => ['ř', 'í', 'ú', 'á'],
            ]],
            'tr'    => ['tr.php', [
                'smtp.invalid_helo_host'   => ['ç', 'ı', 'ğ'],
                'transport.not_serializable' => ['ş', 'ı', 'ğ', 'ç'],
            ]],
            'pl'    => ['pl.php', [
                'smtp.invalid_helo_host'   => ['ł', 'ą', 'ś'],
                'transport.not_serializable' => ['ą', 'ż', 'ó', 'ć'],
            ]],
            'ar'    => ['ar.php', [
                'smtp.invalid_helo_host'   => ['ً', 'أ'],
                'transport.not_serializable' => ['أ', 'ً'],
            ]],
        ];
    }

    /**
     * @param array<string, list<string>> $floor key => required characters
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('orthographyFloorProvider')]
    public function testSweptKeysKeepTheirDiacritics(string $file, array $floor): void
    {
        $values = require \dirname(__DIR__) . '/lang/' . $file;

        foreach ($floor as $key => $chars) {
            foreach ($chars as $char) {
                $this->assertStringContainsString(
                    $char,
                    $values[$key],
                    \sprintf('%s[%s] lost the character %s (candy-mail-004)', $file, $key, $char)
                );
            }
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

    /**
     * CM-65: Traditional Chinese ships alongside zh-cn - the shelf is 17
     * codes, and the roster is pinned by name so a dropped catalog cannot
     * hide behind a replacement of the same count.
     */
    public function testTheShelfCarriesBothChineseCatalogsAndSeventeenCodes(): void
    {
        $codes = \array_map(
            static fn (string $f): string => \basename($f, '.php'),
            \glob(\dirname(__DIR__) . '/lang/*.php') ?: []
        );
        \sort($codes);
        $this->assertSame(
            ['ar', 'cs', 'de', 'en', 'es', 'fr', 'it', 'ja', 'ko', 'nl', 'pl', 'pt', 'pt-br', 'ru', 'tr', 'zh-cn', 'zh-tw'],
            $codes
        );
    }

    /**
     * CM-66: the "17 locales" figure in help() and the README is a claim
     * about the catalog shelf, not decoration. Adding or dropping a catalog
     * reddens the prose here until claim and shelf move together.
     */
    public function testTheLocaleFigureInTheProseMatchesTheCatalogShelf(): void
    {
        $count = \count(\glob(\dirname(__DIR__) . '/lang/*.php') ?: []);
        $this->assertSame(17, $count, 'the catalog shelf moved - update the prose claims in the same change');

        $patterns = [
            'bin/candy-mail' => '/\((\d+) locales\)/',
            'README.md'      => '/(\d+ catalogs ship|\((\d+)\n?locales ship\))/',
        ];
        foreach ($patterns as $rel => $regex) {
            $text = (string) \file_get_contents(\dirname(__DIR__) . '/' . $rel);
            $this->assertGreaterThanOrEqual(
                1,
                \preg_match_all($regex, $text, $m),
                $rel . ' must carry the locale-count claim this guard pins'
            );
            $figures = $m[1] !== [] ? $m[1] : [];
            if ($rel === 'README.md' && $figures === []) {
                $figures = $m[2];
            }
            foreach ($figures as $figure) {
                \preg_match('/(\d+)/', (string) $figure, $d);
                $this->assertSame(
                    (string) $count,
                    $d[1],
                    $rel . ' advertises ' . $d[1] . ' locales but the shelf ships ' . $count
                );
            }
        }
    }
}
