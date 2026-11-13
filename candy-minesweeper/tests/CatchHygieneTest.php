<?php

declare(strict_types=1);

namespace SugarCraft\Minesweeper\Tests;

use PHPUnit\Framework\TestCase;

/**
 * msw-11 (lib-reviews C4 arm): every game-state catch names the fault family
 * it exists for. A blanket `catch (\Throwable)` over live code turns a
 * TypeError — a bug in our own lines — into a silent "save failed" toast, so
 * the narrowing is pinned where it can rot: the only blanket catch left in
 * the package is the autoload bootstrap in bin, which runs before classes
 * can even be trusted and legitimately has nothing narrower to name.
 */
final class CatchHygieneTest extends TestCase
{
    public function testGameAndVersionNameTheirFaultFamilies(): void
    {
        foreach (['src/Game.php', 'src/Support/Version.php', 'src/Support/Requirements.php'] as $relative) {
            $path = \dirname(__DIR__) . '/' . $relative;
            self::assertFileExists($path, 'sanity: ' . $relative . ' is on disk');
            self::assertStringNotContainsString(
                'catch (\Throwable',
                (string) file_get_contents($path),
                $relative . ' still swallows Throwable',
            );
        }
    }

    public function testBinaryKeepsNoBlanketCatch(): void
    {
        $bin = (string) file_get_contents(\dirname(__DIR__) . '/bin/candy-minesweeper');
        // The autoload bootstrap is an is_file()/require cascade, never a try;
        // the stats-seed echo is the only guard and it names its family.
        self::assertSame(
            0,
            substr_count($bin, 'catch (\Throwable'),
            'no blanket catch survives in the binary',
        );
        self::assertStringContainsString(
            'catch (\RuntimeException | \JsonException $e)',
            $bin,
            'the stats-seed refusal path must stay narrowed',
        );
    }
}
