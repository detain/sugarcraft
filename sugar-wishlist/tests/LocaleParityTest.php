<?php

declare(strict_types=1);

namespace SugarCraft\Wishlist\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Locale-sync guard: `lang/en.php` is the source of truth and every one
 * of the 15 sibling locales must carry the full key set with the exact
 * same {placeholder} tokens. The audit fix wave found four keys
 * (config.field_not_scalar, endpoint.option_injection,
 * endpoint.port_invalid, cli.ssh_not_executable) shipped in en only;
 * this test makes that drift structurally impossible going forward.
 */
final class LocaleParityTest extends TestCase
{
    /** @return array<string,array{0:string}> */
    public static function localeProvider(): array
    {
        $cases = [];
        foreach (glob(__DIR__ . '/../lang/*.php') ?: [] as $file) {
            $cases[(string) basename($file, '.php')] = [(string) $file];
        }

        return $cases;
    }

    public function testEnglishSourceOfTruthExists(): void
    {
        $en = require __DIR__ . '/../lang/en.php';
        $this->assertIsArray($en);
        $this->assertNotEmpty($en);
    }

    /**
     * @param string $file Absolute path of a lang/<code>.php file.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('localeProvider')]
    public function testEveryLocaleCarriesTheFullEnglishKeySet(string $file): void
    {
        $en = require __DIR__ . '/../lang/en.php';
        $locale = require $file;
        $code = basename($file, '.php');

        $this->assertIsArray($locale, "{$code}: must return an array");
        $missing = array_diff(array_keys($en), array_keys($locale));
        $this->assertSame([], $missing, "{$code}: missing keys");
        $extra = array_diff(array_keys($locale), array_keys($en));
        $this->assertSame([], $extra, "{$code}: keys not present in en.php");
    }

    /**
     * @param string $file Absolute path of a lang/<code>.php file.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('localeProvider')]
    public function testPlaceholdersMatchEnglishVerbatim(string $file): void
    {
        $en = require __DIR__ . '/../lang/en.php';
        $locale = require $file;
        $code = basename($file, '.php');

        foreach ($en as $key => $template) {
            preg_match_all('/\{(\w+)\}/', $template, $expected);
            preg_match_all('/\{(\w+)\}/', $locale[$key], $actual);
            sort($expected[1]);
            sort($actual[1]);
            $this->assertSame(
                $expected[1],
                $actual[1],
                "{$code}: placeholder set drifted for [{$key}]",
            );
            $this->assertNotSame('', trim((string) $locale[$key]), "{$code}: empty value for [{$key}]");
        }
    }
}
