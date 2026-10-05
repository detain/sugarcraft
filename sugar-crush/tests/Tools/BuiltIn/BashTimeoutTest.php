<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Support\ProcessContainment;
use SugarCraft\Crush\Tools\BuiltIn\Bash;

/**
 * Item 0.4-a: Bash's `timeout` parameter.
 *
 * The kill mechanism already existed — {@see
 * \SugarCraft\Crush\Tools\Concerns\CapturesProcessOutput::runCaptured()}'s
 * deadline runs the 15→9 ladder over the setsid group (audit 15d-14) — but
 * Bash passed no deadline, so a hung command held its turn forever. These
 * rows pin the parameter end to end: advertised, coerced, honoured in both
 * spawn modes, and announced in the result the model reads.
 *
 * @see Bash
 */
final class BashTimeoutTest extends TestCase
{
    public function testSchemaAdvertisesAnOptionalBoundedIntegerTimeout(): void
    {
        $schema = (new Bash())->inputSchema();

        self::assertArrayHasKey('timeout', $schema['properties']);
        self::assertSame('integer', $schema['properties']['timeout']['type']);
        self::assertSame(Bash::MAX_TIMEOUT_SECONDS, $schema['properties']['timeout']['maximum']);
        self::assertNotContains('timeout', $schema['required']);
        self::assertStringContainsString('Default 120, maximum 600', $schema['properties']['timeout']['description']);
    }

    public function testDescriptionStatesTheDefaultAndTheCeiling(): void
    {
        $description = (new Bash())->description();

        self::assertStringContainsString('`timeout` seconds (default 120, max 600)', $description);
    }

    /**
     * @return iterable<string, array{mixed, int}>
     */
    public static function coercions(): iterable
    {
        yield 'absent' => [null, 120];
        yield 'integer' => [30, 30];
        yield 'fraction rounds up' => [2.1, 3];
        yield 'numeric string' => ['45', 45];
        yield 'over the ceiling clamps' => [900, 600];
        yield 'infinity clamps' => [INF, 600];
        yield 'zero is the default, never unbounded' => [0, 120];
        yield 'negative is the default' => [-5, 120];
        yield 'garbage is the default' => ['soon', 120];
        yield 'NaN is the default' => [NAN, 120];
        yield 'bool is the default' => [true, 120];
    }

    /** @dataProvider coercions */
    public function testTimeoutIsCoercedToABoundedPositiveNumberOfSeconds(mixed $raw, int $expected): void
    {
        $method = new \ReflectionMethod(Bash::class, 'timeoutSeconds');

        self::assertSame($expected, $method->invoke(new Bash(), $raw));
    }

    /** W9 integration: the default and the ceiling are the bashTimeoutSeconds / bashMaxTimeoutSeconds settings. */
    public function testTheBoundsAreSettingsAppliedPerTurn(): void
    {
        $method = new \ReflectionMethod(Bash::class, 'timeoutSeconds');
        $bash = \SugarCraft\Crush\Tools\ToolLimits::fromConfig([
            \SugarCraft\Crush\Tools\ToolLimits::BASH_TIMEOUT_KEY => 30,
            \SugarCraft\Crush\Tools\ToolLimits::BASH_MAX_TIMEOUT_KEY => 1800,
        ])->applyTo(new Bash());
        self::assertInstanceOf(Bash::class, $bash);

        self::assertSame(['default' => 30, 'max' => 1800], $bash->timeoutBounds());
        self::assertSame(30, $method->invoke($bash, null), 'no timeout asked: the configured default');
        self::assertSame(1200, $method->invoke($bash, 1200), 'under the raised ceiling: as asked');
        self::assertSame(1800, $method->invoke($bash, 5000), 'over it: clamped to the configured ceiling');
        self::assertStringContainsString('default 30, max 1800', $bash->description());
        self::assertSame(1800, $bash->inputSchema()['properties']['timeout']['maximum']);

        $lowered = (new Bash())->withTimeoutBounds(null, 60);
        self::assertSame(['default' => 60, 'max' => 60], $lowered->timeoutBounds(), 'a default above the ceiling is the ceiling');
        self::assertSame(['default' => 120, 'max' => 600], \SugarCraft\Crush\Tools\ToolLimits::fromConfig([])->applyTo(new Bash())->timeoutBounds());
    }

    public function testAFastCommandIsUntouchedByTheDefaultBound(): void
    {
        $result = (new Bash())->execute(['command' => 'echo hello', 'description' => 'Print hello']);

        self::assertFalse($result->isError());
        self::assertSame('hello', $result->content());
    }

    /**
     * The hang the parameter exists for: a command that never ends comes back
     * AT its bound, with the output it produced first and a line saying why
     * it stopped — exit 124 makes it an error result.
     */
    public function testAHungCommandIsKilledAtItsTimeoutAndSaysSo(): void
    {
        $started = microtime(true);
        $result = (new Bash())->execute([
            'command' => 'echo before-the-hang; sleep 30',
            'description' => 'Hang after printing',
            'timeout' => 1,
        ]);
        $elapsed = microtime(true) - $started;

        self::assertLessThan(6.0, $elapsed, 'the timeout must bound the call, not shadow a 30 s sleep');
        self::assertTrue($result->isError());
        self::assertStringContainsString('before-the-hang', $result->content());
        self::assertStringContainsString('[timed out after 1 s', $result->content());
    }

    /**
     * The kill reaches the whole process group, not just the `bash -c`: a
     * background grandchild that would otherwise outlive the call is gone.
     */
    public function testTheTimeoutKillsTheCommandsWholeProcessGroup(): void
    {
        if (ProcessContainment::detachedSpawnBinary() === '') {
            // No setsid on this host: the group is the agent's own, so the
            // ladder signals only the direct child. The single-process row
            // above still holds; assert the documented fallback instead.
            self::assertSame('', ProcessContainment::detachedSpawnBinary());

            return;
        }

        $pidFile = tempnam(sys_get_temp_dir(), 'bash-timeout-');
        self::assertIsString($pidFile);

        try {
            (new Bash())->execute([
                'command' => 'sleep 30 & echo $! > ' . escapeshellarg($pidFile) . '; wait',
                'description' => 'Background sleep then wait',
                'timeout' => 1,
            ]);

            $pid = (int) trim((string) file_get_contents($pidFile));
            self::assertGreaterThan(0, $pid);

            $deadline = microtime(true) + 3.0;
            while (microtime(true) < $deadline && self::alive($pid)) {
                usleep(20_000);
            }
            self::assertFalse(self::alive($pid), 'the background grandchild survived the timeout');
        } finally {
            @unlink($pidFile);
        }
    }

    /**
     * Interactive mode takes the same number as its WALL bound: a program that
     * keeps repainting never trips the idle ceiling, and before 0.4-a it ran
     * for as long as it liked.
     */
    public function testInteractiveModeHonoursTheTimeoutAsAWallBound(): void
    {
        $started = microtime(true);
        $result = (new Bash())->execute([
            'command' => 'while true; do echo tick; sleep 0.1; done',
            'description' => 'Paint forever',
            'interactive' => true,
            'timeout' => 2,
        ]);
        $elapsed = microtime(true) - $started;

        self::assertTrue($result->isError());
        self::assertLessThan(8.0, $elapsed, 'a never-silent interactive program must still end at its timeout');

        if (!ProcessContainment::interactiveAvailable()) {
            self::assertStringContainsString('interactive mode is unavailable', $result->content());

            return;
        }

        self::assertGreaterThanOrEqual(1.5, $elapsed, 'the wall bound fired early');
        self::assertStringContainsString('tick', $result->content());
        self::assertStringContainsString('still running after 2s', $result->content());
        self::assertStringContainsString('[timed out after 2 s', $result->content());
    }

    private static function alive(int $pid): bool
    {
        if (!is_dir('/proc/' . $pid)) {
            return false;
        }
        $stat = @file_get_contents('/proc/' . $pid . '/stat');
        if (!is_string($stat)) {
            return false;
        }
        $state = substr($stat, strrpos($stat, ')') + 2, 1);

        return $state !== 'Z' && $state !== 'X';
    }
}
