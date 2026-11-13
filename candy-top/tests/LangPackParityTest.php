<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Top\Lang;

/**
 * S-N11: every locale pack carries exactly en.php's keys, with the same
 * `{placeholder}` tokens (Lang::t is a plain strtr, so a renamed or dropped
 * token would print raw braces), and loads through the translator.
 */
final class LangPackParityTest extends TestCase
{
    private const DIR = __DIR__ . '/../lang';

    protected function setUp(): void
    {
        T::reset();
    }

    protected function tearDown(): void
    {
        T::reset();
        putenv('LANG');
    }

    /** @return array<string, string> */
    private static function pack(string $code): array
    {
        /** @var array<string, string> $a */
        $a = require self::DIR . "/{$code}.php";

        return $a;
    }

    /** @return list<string> */
    private static function tokens(string $text): array
    {
        preg_match_all('/\{[a-z_0-9]+\}/i', $text, $m);
        sort($m[0]);

        return $m[0];
    }

    /** @return iterable<string, array{string}> */
    public static function packs(): iterable
    {
        foreach (glob(self::DIR . '/*.php') ?: [] as $file) {
            $code = basename($file, '.php');
            if ($code !== 'en') {
                yield $code => [$code];
            }
        }
    }

    #[DataProvider('packs')]
    public function testSameKeysAsEnglish(string $code): void
    {
        $en = self::pack('en');
        $pack = self::pack($code);
        $this->assertSame([], array_values(array_diff(array_keys($en), array_keys($pack))), "{$code} misses keys");
        $this->assertSame([], array_values(array_diff(array_keys($pack), array_keys($en))), "{$code} has stray keys");
    }

    #[DataProvider('packs')]
    public function testPlaceholdersSurviveTranslation(string $code): void
    {
        $en = self::pack('en');
        foreach (self::pack($code) as $key => $text) {
            $this->assertIsString($text, "{$code}:{$key}");
            $this->assertTrue(trim($text) !== '' || ($en[$key] ?? '') === '', "{$code}:{$key} is blank");
            $this->assertSame(self::tokens($en[$key] ?? ''), self::tokens($text), "{$code}:{$key} placeholder set differs");
        }
    }

    public function testEveryRecommendedCoreLocaleShips(): void
    {
        foreach (['de', 'es', 'fr', 'it', 'pt', 'pt-br', 'nl', 'ja', 'ko', 'zh-cn', 'zh-tw', 'ru'] as $code) {
            $this->assertFileExists(self::DIR . "/{$code}.php", "{$code} pack");
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function smokeLocales(): iterable
    {
        yield 'de' => ['de_DE.UTF-8', 'de'];
        yield 'fr' => ['fr_FR.UTF-8', 'fr'];
        yield 'ja' => ['ja_JP.UTF-8', 'ja'];
    }

    #[DataProvider('smokeLocales')]
    public function testALocaleEnvironmentSelectsItsPack(string $lang, string $code): void
    {
        if (!is_file(self::DIR . "/{$code}.php")) {
            $this->markTestSkipped("no {$code} pack yet");
        }
        putenv("LANG={$lang}");
        T::reset();
        $translated = self::pack($code)['menu.options.category.proc'];
        $this->assertSame($translated, Lang::t('menu.options.category.proc'));
    }
}
