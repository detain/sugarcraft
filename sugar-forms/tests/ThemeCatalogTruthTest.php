<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Forms\Theme;

/**
 * The stock-theme inventory is a documentation promise, not just code:
 * README.md and docs/_data/sugar-forms.body.html each recite the theme
 * count in prose ("seven themes") and name every stock factory. Until
 * this guard, a factory added to (or dropped from) Theme::catalog()
 * — or a stale numeral creeping back into the prose — cost nothing:
 * the 2026-11 docs sweep found the README claiming six while catalog()
 * answered seven, and the site body naming a phantom "pink" theme.
 *
 * Theme::catalog() carries a manual-append law in its own docblock (a
 * stray public static method would silently join it); this test is the
 * external half of that contract — the exact ordered roster, and the
 * agreement between the roster, the README numeral + factory list, and
 * the site body.
 */
final class ThemeCatalogTruthTest extends TestCase
{
    /** The complete stock-theme roster, in catalog() order. */
    private const STOCK_THEMES = ['ansi', 'plain', 'charm', 'dracula', 'catppuccin', 'base16', 'base'];

    /** Cardinals for the prose numeral ↔ roster-length cross-check. */
    private const NUMBER_WORDS = [
        4 => 'four',
        5 => 'five',
        6 => 'six',
        7 => 'seven',
        8 => 'eight',
        9 => 'nine',
        10 => 'ten',
    ];

    public function testTheCatalogIsExactlyTheStockThemeRoster(): void
    {
        $catalog = Theme::catalog();

        $this->assertSame(
            self::STOCK_THEMES,
            $catalog,
            'Theme::catalog() is the single source of truth for the stock roster; changing it '
            . 'means updating README.md and docs/_data/sugar-forms.body.html in the same commit',
        );

        foreach ($catalog as $name) {
            $this->assertInstanceOf(
                Theme::class,
                Theme::{$name}(),
                "every catalog name must resolve to its static factory Theme::{$name}()",
            );
        }
    }

    public function testTheReadmeNumeralTracksTheCatalogLength(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__) . '/README.md');
        $count = count(self::STOCK_THEMES);
        $word = self::NUMBER_WORDS[$count] ?? (string) $count;

        $this->assertMatchesRegularExpression(
            '/\b' . $word . ' themes\b/',
            $readme,
            "README.md must recite the roster length as \"{$word} themes\" (catalog() has {$count} entries)",
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\b(?:' . implode('|', self::NUMBER_WORDS) . ') themes\b(?!\w)/',
            $this->onlyForeignNumerals($readme, $word),
            'no second, disagreeing "<number> themes" claim may survive in README.md',
        );
    }

    public function testTheReadmeThemesSectionNamesEveryStockFactory(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__) . '/README.md');

        foreach (self::STOCK_THEMES as $name) {
            $this->assertStringContainsString(
                '`' . $name . '()`',
                $readme,
                "the Themes section must name stock factory `{$name}()`",
            );
        }
    }

    public function testTheSiteBodyRecitesTheSameRosterAndNoPhantomTheme(): void
    {
        $path = dirname(__DIR__, 2) . '/docs/_data/sugar-forms.body.html';
        if (!is_file($path)) {
            $this->markTestSkipped('monorepo docs tree not present in this clone');
        }
        $body = (string) file_get_contents($path);
        $count = count(self::STOCK_THEMES);
        $word = self::NUMBER_WORDS[$count] ?? (string) $count;

        $this->assertStringContainsString(
            $word . ' themes',
            strtolower($body),
            "docs/_data/sugar-forms.body.html must agree with the roster length (\"{$word} themes\")",
        );
        foreach (self::STOCK_THEMES as $name) {
            $this->assertStringContainsString(
                $name,
                $body,
                "the site body must name stock theme {$name}",
            );
        }
        // The pre-2026-11 body shipped a "pink" theme that never existed.
        $this->assertStringNotContainsString(
            'pink',
            strtolower($body),
            'no theme named "pink" has ever existed in Theme::catalog()',
        );
    }

    /**
     * Blank out every occurrence of the agreed numeral so the foreign-
     * numeral regex can only match a DISAGREEING one.
     */
    private function onlyForeignNumerals(string $readme, string $word): string
    {
        $stripped = str_replace($word . ' themes', '', $readme);
        $stripped = str_replace(ucfirst($word) . ' themes', '', $stripped);

        return $stripped;
    }
}
