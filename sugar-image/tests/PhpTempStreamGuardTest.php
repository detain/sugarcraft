<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Every php://temp stream this library opens is a sink for a GD encode —
 * and an unguarded fopen() failure hands the next statement a false, where
 * rewind(false)/imagepng($img, false) mean either a TypeError cascade that
 * masks the real failure or, worse, a silent STDOUT dump (a file argument
 * of false is the same shape as no argument at all).
 *
 * SI-26 (finding 048): the discipline cannot be exercised in-process — an
 * unopenable php://temp needs resource exhaustion the test runner would not
 * survive pointedly — so it is pinned by census, house idiom (cf. the
 * child-lifetime roster and this lib's own SI-20/SI-25 shape guards).
 */
final class PhpTempStreamGuardTest extends TestCase
{
    public function testEveryPhpTempOpenIsImmediatelyGuarded(): void
    {
        $srcDir  = \dirname(__DIR__) . '/src';
        $files   = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS));
        $checked = 0;

        foreach ($files as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            $lines = explode("\n", (string) file_get_contents($file->getPathname()));
            foreach ($lines as $i => $line) {
                if (!str_contains($line, "fopen('php://temp'")) {
                    continue;
                }
                $checked++;

                $where = $file->getFilename() . ':' . ($i + 1);
                self::assertStringContainsString(
                    '@fopen(',
                    $line,
                    "{$where}: the open must suppress its warning — the guard throws, PHP does not spray"
                );

                $next = ltrim($lines[$i + 1] ?? '');
                self::assertSame(
                    'if ($tmp === false) {',
                    $next,
                    "{$where}: an unguarded php://temp open hands false to the encode — the typed refusal must be the very next statement"
                );
            }
        }

        self::assertGreaterThan(0, $checked, 'the census must actually see the two known sinks, not silently match nothing');
    }
}
