<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Tests\I18n;

use CandyCode\Bundles\Discord\Lang;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;

/**
 * Pins the Lang facade's DIR wiring to this bundle's own catalog. The
 * LangParityTest and the bundle suites compare the facade with itself, so a
 * DIR pointed at another plugin's lang/ (which also ships an en.php) would
 * pass them while every Discord user saw raw keys. Reading en.php directly and
 * comparing it with Lang::t() catches that mis-wiring.
 */
final class LangFacadeWiringTest extends TestCase
{
    private const EN_CATALOG = __DIR__ . '/../../lang/en.php';

    private string $previousLocale = 'en';

    /** @return array<string, array{string}> */
    public static function englishKeyProvider(): array
    {
        $cases = [];
        foreach (\array_keys(require self::EN_CATALOG) as $key) {
            $cases[$key] = [(string) $key];
        }

        return $cases;
    }

    protected function setUp(): void
    {
        // The facade resolves through the process-wide locale; pin English so the
        // catalog text is the expected value regardless of the host environment.
        $this->previousLocale = T::locale();
        T::setLocale('en');
    }

    protected function tearDown(): void
    {
        T::setLocale($this->previousLocale);
    }

    #[DataProvider('englishKeyProvider')]
    public function testFacadeReturnsTheBundleEnglishText(string $key): void
    {
        $en = require self::EN_CATALOG;

        // No params: interpolate() leaves any {placeholder} untouched, so the
        // facade output must equal the catalog template byte for byte.
        self::assertSame($en[$key], Lang::t($key), "Lang::t('{$key}') must resolve to lang/en.php, not a raw key or another catalog");
    }

    public function testFacadeNeverEchoesTheRawKey(): void
    {
        self::assertStringNotContainsString('discord.channels.discord.', Lang::t('channels.discord.ack.default'));
        self::assertSame('Working…', Lang::t('channels.discord.ack.default'));
    }
}
