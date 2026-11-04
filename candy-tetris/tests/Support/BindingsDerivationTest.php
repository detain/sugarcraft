<?php

/**
 * candy-tetris/tests/Support/BindingsDerivationTest.php
 *
 * The load-bearing pin behind the verb-column migration (finding m27): the
 * on-screen help card and versus strip are DERIVED from the live binding
 * table, so with the shipped table they must reproduce — byte for byte, in
 * every one of the sixteen locales — the hand-authored cards that existed
 * before the registry. tests/fixtures/help-cards-pre-m27.json holds those
 * originals verbatim. If derivation drifts even one space, this goes red.
 */

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Tetris\Lang;
use SugarCraft\Tetris\Support\Bindings;

final class BindingsDerivationTest extends TestCase
{
    /** @var list<string> */
    private const LOCALES = [
        'ar', 'cs', 'de', 'en', 'es', 'fr', 'it', 'ja',
        'ko', 'nl', 'pl', 'pt', 'pt-br', 'ru', 'tr', 'zh-cn',
    ];

    protected function tearDown(): void
    {
        T::setLocale('en');
    }

    /**
     * @return \Generator<array{0:string,1:string}>
     */
    public static function helpCardFixtures(): \Generator
    {
        foreach (self::cards() as $locale => $cards) {
            yield $locale => [$locale, $cards['help_card']];
        }
    }

    /**
     * @return \Generator<array{0:string,1:string}>
     */
    public static function vsStripFixtures(): \Generator
    {
        foreach (self::cards() as $locale => $cards) {
            yield $locale => [$locale, $cards['vs_keys']];
        }
    }

    /**
     * @dataProvider helpCardFixtures
     */
    public function testTheDerivedHelpCardReproducesTheHandAuthoredOne(string $locale, string $card): void
    {
        T::setLocale($locale);

        self::assertSame($card, Bindings::helpCard(Bindings::table()));
    }

    /**
     * @dataProvider vsStripFixtures
     */
    public function testTheDerivedVersusStripReproducesTheHandAuthoredOne(string $locale, string $strip): void
    {
        T::setLocale($locale);

        self::assertSame($strip, Bindings::vsStrip(Bindings::table()));
    }

    public function testEveryLocaleCarriesTheVerbColumnsTheDerivationZipsAgainst(): void
    {
        // help_card is now a nine-verb column, vs_keys a five-verb column.
        // A row-count mismatch would silently blank a label's verb, so the
        // column shapes are pinned directly, in every locale.
        foreach (self::LOCALES as $locale) {
            T::setLocale($locale);

            self::assertCount(9, explode("\n", Lang::t('help_card')), $locale . ' help_card verbs');
            self::assertCount(5, explode("\n", Lang::t('vs_keys')), $locale . ' vs_keys verbs');
        }
    }

    /**
     * @return array<string, array{help_card: string, vs_keys: string}>
     */
    private static function cards(): array
    {
        $path = \dirname(__DIR__, 2) . '/tests/fixtures/help-cards-pre-m27.json';
        $raw = file_get_contents($path);
        self::assertIsString($raw, 'fixture ' . $path . ' must exist');

        $cards = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($cards);
        self::assertSame(self::LOCALES, array_keys($cards), 'fixture covers exactly the shipped locales');

        return $cards;
    }
}
