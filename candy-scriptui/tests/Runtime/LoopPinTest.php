<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;

/**
 * scriptui-48 (candy-scriptui-134): the suite arms real timer bounds on the
 * shared loop (spin drain, exit watcher, TimeoutGuard legs), and an ext-uv
 * loop computes every deadline against a clock refreshed once per iteration
 * — a timer armed after synchronous idle is already overdue at arm time.
 * tests/bootstrap.php pins the stable clock as the FIRST touch of the loop
 * singleton; these pins keep that wiring from rotting back silently.
 */
final class LoopPinTest extends TestCase
{
    public function testPhpunitBootsTheTestBootstrap(): void
    {
        $xml = (string) file_get_contents(__DIR__ . '/../../phpunit.xml');

        self::assertStringContainsString('bootstrap="tests/bootstrap.php"', $xml);
    }

    public function testBootstrapPinsTheStableClockBeforeAnythingElseRuns(): void
    {
        $boot = (string) file_get_contents(__DIR__ . '/../../tests/bootstrap.php');

        self::assertStringContainsString('LoopPin::pinStableClock();', $boot);
        self::assertLessThan(
            (int) strpos($boot, 'pinStableClock'),
            (int) strpos($boot, 'autoload.php'),
            'the autoloader must load before the pin, never the other way',
        );    }

    public function testTheRunningSuiteActuallyHoldsAPinnedLoop(): void
    {
        // Bootstrap has long since run; the class name here proves the pin
        // decided this process's shared loop (uv would mean every under-test
        // deadline was armed against a stale clock).
        self::assertSame(
            \React\EventLoop\StreamSelectLoop::class,
            Loop::get()::class,
            'the shared loop must be the pinned stable clock, not an ext-uv loop',
        );
    }

    public function testSugarTestingIsADeclaredDevDependencyWithoutAPathRepo(): void
    {
        $json = json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true);

        self::assertIsArray($json);
        self::assertArrayHasKey('sugarcraft/sugar-testing', $json['require-dev'] ?? []);
        // path-repo closure law: the committed manifest never carries
        // sibling repositories — CI injects them.
        self::assertArrayNotHasKey('repositories', $json);
    }
}
