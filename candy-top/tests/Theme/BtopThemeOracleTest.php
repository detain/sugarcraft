<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Theme;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Top\Theme\ThemeConfig;
use SugarCraft\Top\Theme\TtyTheme;

/**
 * Byte-parity against btop itself: the fixture is produced by
 * prompt_kit/tools/btop-theme-oracle.cpp, which compiles btop's own
 * btop_theme.cpp resolution code verbatim (see that file's header to
 * regenerate). Covers Default, all 41 shipped themes, the edge themes under
 * tests/Theme/fixtures/edge/, and the builtin TTY theme.
 */
final class BtopThemeOracleTest extends TestCase
{
    /** @var ?array<string, mixed> */
    private static ?array $oracle = null;

    /** @return array<string, mixed> */
    private static function oracle(): array
    {
        return self::$oracle ??= json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/fixtures/btop-theme-oracle.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    private static function hex(?Color $c): ?string
    {
        return $c === null ? null : sprintf('%02x%02x%02x', $c->r, $c->g, $c->b);
    }

    /** @return iterable<string, array{string}> */
    public static function themeNames(): iterable
    {
        foreach (array_keys(self::oracle()['themes']) as $name) {
            yield $name => [$name];
        }
    }

    public function testOracleCoversDefaultEveryShippedThemeAndEdges(): void
    {
        $names = array_keys(self::oracle()['themes']);
        self::assertContains('Default', $names);
        foreach (glob(dirname(__DIR__, 2) . '/themes/*.theme') ?: [] as $path) {
            self::assertContains(pathinfo($path, PATHINFO_FILENAME), $names);
        }
        foreach (glob(__DIR__ . '/fixtures/edge/*.theme') ?: [] as $path) {
            self::assertContains(pathinfo($path, PATHINFO_FILENAME), $names);
        }
        self::assertCount(1 + 41 + count(glob(__DIR__ . '/fixtures/edge/*.theme') ?: []), $names);
    }

    #[DataProvider('themeNames')]
    public function testMatchesBtopExactly(string $name): void
    {
        $expected = self::oracle()['themes'][$name];
        $theme = match (true) {
            $name === 'Default' => ThemeConfig::new(),
            str_starts_with($name, 'edge-') => ThemeConfig::fromFile(__DIR__ . "/fixtures/edge/$name.theme"),
            default => ThemeConfig::fromFile(dirname(__DIR__, 2) . "/themes/$name.theme"),
        };

        $colors = array_map(self::hex(...), $theme->colors());
        ksort($colors, SORT_STRING);
        self::assertSame($expected['colors'], $colors);

        $gradients = [];
        foreach ($theme->gradientNames() as $g) {
            $gradients[$g] = implode('', array_map(
                static fn (?Color $c): string => self::hex($c) ?? '------',
                $theme->gradient($g),
            ));
        }
        ksort($gradients, SORT_STRING);
        self::assertSame($expected['gradients'], $gradients);
    }

    public function testTtyMatchesBtopExactly(): void
    {
        $expected = self::oracle()['tty'];
        $tty = TtyTheme::new();
        $params = static fn (string $sgr): string => strlen($sgr) >= 3 ? substr($sgr, 2, -1) : '';

        $colors = [];
        foreach (array_keys(TtyTheme::TTY_THEME) as $key) {
            $colors[$key] = $params($tty->sgr($key));
        }
        ksort($colors, SORT_STRING);
        self::assertSame($expected['colors'], $colors);

        $gradients = [];
        foreach ($tty->gradientNames() as $g) {
            $gradients[$g] = implode(',', array_map(
                static fn (?Color $c): string => $c === null ? '' : (string) ($c->ansiIndex < 8 ? 30 + $c->ansiIndex : 82 + $c->ansiIndex),
                $tty->gradient($g),
            ));
        }
        ksort($gradients, SORT_STRING);
        self::assertSame($expected['gradients'], $gradients);
    }
}
