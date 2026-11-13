<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Async\Tests\Support\BoundedRun;

/**
 * The examples are documentation too — and until this file existed, nothing
 * executed them: the README fence guard walks only README.md, so an API
 * rename could rot examples/*.php silently, the exact class of defect the
 * fence guard exists for. Same mechanism, same bounded children: every
 * example must lint and exit 0 inside the bound.
 */
final class ExamplesTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function exampleProvider(): array
    {
        $cases = [];
        foreach (glob(dirname(__DIR__) . '/examples/*.php') ?: [] as $path) {
            $cases[basename($path)] = [$path];
        }
        self::assertNotEmpty($cases, 'examples/ must carry runnable demos');

        return $cases;
    }

    /**
     * @dataProvider exampleProvider
     */
    public function testEachExampleLintsAndRunsToCompletion(string $path): void
    {
        $lint = BoundedRun::run([PHP_BINARY, '-l', $path], 10.0);
        self::assertSame(0, $lint['code'], basename($path) . ' must lint: ' . $lint['output']);

        // Run from the lib root so relative paths inside an example resolve
        // the way a reader's `php examples/<name>.php` does.
        $run = BoundedRun::run([PHP_BINARY, $path], 15.0, dirname(__DIR__));
        self::assertFalse(
            $run['killed'],
            basename($path) . ' must finish inside the bound — a hang is an example bug',
        );
        self::assertSame(
            0,
            $run['code'],
            basename($path) . ' must exit 0 (code ' . $run['code'] . "):\n" . $run['output'],
        );
    }
}
