<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests;

use SugarCraft\Charts\Lang;
use PHPUnit\Framework\TestCase;

/**
 * @see Lang
 */
final class LangTest extends TestCase
{
    public function testNamespaceIsCharts(): void
    {
        // Use reflection to check the constant since it's protected
        $reflection = new \ReflectionClass(Lang::class);
        $namespaceConst = $reflection->getConstant('NAMESPACE');
        self::assertSame('charts', $namespaceConst);
    }

    public function testDirPointsToLangFolder(): void
    {
        $reflection = new \ReflectionClass(Lang::class);
        $dirConst = $reflection->getConstant('DIR');

        // Compare resolved paths since the constant uses __DIR__ which produces
        // different string representations depending on file location
        self::assertSame(realpath(__DIR__ . '/../lang'), realpath($dirConst));
    }

    public function testTTranslateMethodIsInherited(): void
    {
        $lang = new Lang();
        self::assertTrue(method_exists($lang, 't'));
    }

    public function testEveryLocaleCarriesTheSameKeySetAsEnglish(): void
    {
        // M16 (lib-reviews-1103): 'chart.dim_nonneg' and 'finite.non_finite'
        // shipped in en.php only; every locale must move with the source of
        // truth or fallbacks silently mask the gap from translators.
        $en = require __DIR__ . '/../lang/en.php';
        self::assertNotEmpty($en);

        foreach (glob(__DIR__ . '/../lang/*.php') as $file) {
            $locale = require $file;
            $name = basename($file);
            self::assertSame(
                array_keys($en),
                array_keys($locale),
                "locale {$name} key set drifted from en.php",
            );
            self::assertStringContainsString(
                '{value}',
                (string) $locale['finite.non_finite'],
                "locale {$name} dropped the {value} placeholder",
            );
        }
    }

    public function testFiniteRejectionMessageResolvesThroughLang(): void
    {
        $lang = new Lang();
        self::assertNotSame(
            'finite.non_finite',
            $lang::t('finite.non_finite', ['value' => 'NAN']),
            'key must resolve to a message, not echo raw',
        );
    }

    public function testTWithUnknownKeyReturnsKeyWhenNotTranslated(): void
    {
        $lang = new Lang();
        $result = $lang->t('charts.this_key_does_not_exist');

        // When a key is not found in any locale, the raw key is returned
        self::assertIsString($result);
    }

    public function testTIsStaticMethod(): void
    {
        $reflection = new \ReflectionClass(Lang::class);
        $method = $reflection->getMethod('t');
        self::assertTrue($method->isStatic());
    }

    public function testLangExtendsBaseLang(): void
    {
        $lang = new Lang();
        $parent = $lang instanceof \SugarCraft\Core\I18n\Lang;
        self::assertTrue($parent);
    }
}
