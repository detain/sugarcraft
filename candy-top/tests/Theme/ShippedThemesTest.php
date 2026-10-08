<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Theme;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Theme\ThemeConfig;
use SugarCraft\Top\Theme\ThemeFile;
use SugarCraft\Top\Theme\ThemeRegistry;

/** Every bundled upstream theme parses cleanly and resolves every key. */
final class ShippedThemesTest extends TestCase
{
    public function testExactly42ThemesShipped(): void
    {
        self::assertCount(42, glob(ThemeRegistry::bundledDir() . '/*.theme') ?: []);
    }

    /** @return iterable<string, array{string}> */
    public static function themeFiles(): iterable
    {
        foreach (glob(dirname(__DIR__, 2) . '/themes/*.theme') ?: [] as $path) {
            yield basename($path) => [$path];
        }
    }

    #[DataProvider('themeFiles')]
    public function testThemeLoadsAndResolvesAllKeys(string $path): void
    {
        $raw = (string) file_get_contents($path);
        $parsed = ThemeFile::parse($raw);

        // Every live `theme[...]` line was consumed (no silent parse misses).
        preg_match_all('/^theme\[([a-z_]+)\]/m', $raw, $m);
        self::assertSame(array_values(array_unique($m[1])), array_keys($parsed));

        $t = ThemeConfig::fromFile($path);
        foreach ($t->colors() as $key => $color) {
            $value = $parsed[$key] ?? null;
            if ($value === '' && ($key === 'main_bg' || preg_match('/_(mid|end)$/', $key) === 1)) {
                self::assertNull($color, "$key empty → unset");
                continue;
            }
            if ($value === null && str_starts_with($key, 'process_') && !isset($parsed['process_start'])) {
                // Inherits the cpu_* stop, including an intentionally empty cpu_mid.
                self::assertEquals($t->color('cpu_' . substr($key, 8)), $color, "$key inherits cpu");
                continue;
            }
            self::assertNotNull($color, "$key resolved");
            if ($value !== null && $value !== '') {
                self::assertEquals(ThemeConfig::parseColor($value), $color, "$key matches file");
            }
        }
        foreach ($t->gradientNames() as $g) {
            self::assertCount(101, $t->gradient($g));
            self::assertNotNull($t->at($g, 0), "$g start");
            self::assertNotNull($t->at($g, 100), "$g end");
        }
        self::assertCount(11, $t->gradientNames());
    }
}
