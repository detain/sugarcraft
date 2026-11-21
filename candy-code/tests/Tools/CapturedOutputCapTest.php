<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tools\Concerns\CapturesProcessOutput;

/**
 * The output cap on the captured-process path. A caller that names no bound
 * (null, zero or negative) must still get a bounded capture: the old append
 * concatenated every chunk, so a runaway child grew the agent's memory without
 * limit. These tests pin the default cap, the per-stream drop accounting, and
 * that an explicit bound still wins.
 */
final class CapturedOutputCapTest extends TestCase
{
    private const MIB = 1024 * 1024;

    private static function host(): object
    {
        return new class {
            use CapturesProcessOutput;

            /** @return array<string,mixed> */
            public function capture(string $command, ?int $maxBytes, ?float $timeout = 30.0): array
            {
                return $this->runCaptured($command, null, $maxBytes, $timeout);
            }

            public function append(string $buffer, string $chunk, ?int $maxBytes, int &$discarded): string
            {
                $method = new \ReflectionMethod($this, 'appendBounded');

                return (string) $method->invokeArgs(null, [$buffer, $chunk, $maxBytes, &$discarded]);
            }
        };
    }

    /** The constant lives on the using class: a trait constant cannot be read through the trait name. */
    private static function defaultCap(): int
    {
        return (int) (new \ReflectionClass(self::host()))->getConstant('DEFAULT_RETAINED_BYTES');
    }

    public function testTheDefaultCapIsEightMebibytes(): void
    {
        self::assertSame(8 * self::MIB, self::defaultCap());
    }

    public function testTheClassDocblockNamesTheCap(): void
    {
        $doc = (string) (new \ReflectionClass(CapturesProcessOutput::class))->getDocComment();

        self::assertStringContainsString('DEFAULT_RETAINED_BYTES', $doc);
        self::assertStringContainsString('8 MiB', $doc);
    }

    public function testANullBoundTakesTheDefaultCapInsteadOfAppendingUnboundedly(): void
    {
        $discarded = 0;
        $buffer = '';
        // Three 4 MiB chunks: 12 MiB offered, 8 MiB may be retained.
        $chunk = str_repeat('a', 4 * self::MIB);
        for ($i = 0; $i < 3; $i++) {
            $buffer = self::host()->append($buffer, $chunk, null, $discarded);
        }

        self::assertSame(self::defaultCap(), strlen($buffer));
        self::assertSame(4 * self::MIB, $discarded);
    }

    public function testAZeroOrNegativeBoundTakesTheSameDefault(): void
    {
        foreach ([0, -1] as $bound) {
            $discarded = 0;
            $buffer = self::host()->append('', str_repeat('b', 9 * self::MIB), $bound, $discarded);

            self::assertSame(self::defaultCap(), strlen($buffer), "bound $bound");
            self::assertSame(self::MIB, $discarded, "bound $bound");
        }
    }

    public function testAnExplicitBoundIsStillHonoured(): void
    {
        $discarded = 0;
        $buffer = self::host()->append('', str_repeat('c', 100), 16, $discarded);

        self::assertSame(16, strlen($buffer));
        self::assertSame(84, $discarded);
    }

    public function testAChildWritingPastTheDefaultCapIsKeptAtTheCapAndCountedAsDropped(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('The pipe probe below needs a POSIX head(1).');
        }

        // 9 MiB of NUL bytes on stdout, no bound passed: the capture keeps the
        // default and reports the 1 MiB it refused, on the stdout side only.
        $run = self::host()->capture('head -c ' . (9 * self::MIB) . ' /dev/zero', null);

        self::assertSame(self::defaultCap(), strlen($run['stdout']));
        self::assertSame(self::MIB, $run['stdoutDropped']);
        self::assertSame(0, $run['stderrDropped']);
        self::assertSame(self::MIB, $run['truncatedBytes']);
        // NUL bytes carry no newline, so the cut lands mid-line and the caller is told.
        self::assertTrue($run['stdoutMidLine']);
    }
}
