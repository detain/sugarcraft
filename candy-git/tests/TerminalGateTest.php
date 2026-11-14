<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Git\TerminalGate;

/**
 * The launcher's terminal gate as a pure truth table (candy-git-055,
 * candy-git-124): mintty-class named-pipe std handles must be rescued
 * through the probe, while a deliberate POSIX pipe must be REFUSED without
 * ever consulting /dev/tty - probing there would hijack `candy-git | cat`
 * onto an unrelated screen (and hang a piped test runner on a controlling
 * terminal). STDOUT/STDIN constants are consumed as identity sentinels by
 * the boot shape; the test passes the real streams as unopened doubles.
 *
 * @internal
 */
final class TerminalGateTest extends TestCase
{
    /** Never called on paths that must refuse blind. */
    private static function explodingProbe(): callable
    {
        return static function (): ?array {
            throw new \LogicException('the plain POSIX pipe must not touch /dev/tty');
        };
    }

    private static function nullProbe(): callable
    {
        return static fn(): ?array => null;
    }

    public function testBothStreamsTerminalsBootUntouched(): void
    {
        $gate = TerminalGate::decide(true, true, false, self::explodingProbe());
        self::assertTrue($gate['boot']);
        self::assertNull($gate['streams'], 'null means STDIN/STDOUT stay as-is');
        self::assertNull($gate['key']);
    }

    public function testPosixPipedStdoutRefusesNamedlyWithoutProbing(): void
    {
        $gate = TerminalGate::decide(false, true, false, self::explodingProbe());
        self::assertFalse($gate['boot']);
        self::assertSame('cli.piped_stdout', $gate['key']);
    }

    public function testWindowsNamedPipeStdoutRescuedByConsole(): void
    {
        $in = fopen('php://memory', 'rb');
        $out = fopen('php://memory', 'wb');
        self::assertNotFalse($in);
        self::assertNotFalse($out);
        $gate = TerminalGate::decide(false, false, true, static fn(): ?array => [$in, $out]);
        self::assertTrue($gate['boot']);
        self::assertSame([$in, $out], $gate['streams']);
    }

    public function testWindowsWithoutAnyConsoleKeepsClassicRefusal(): void
    {
        $gate = TerminalGate::decide(false, false, true, self::nullProbe());
        self::assertFalse($gate['boot']);
        self::assertSame('cli.needs_tty', $gate['key']);
    }

    public function testPosixRedirectedStdinRescuedWhenTtyReachable(): void
    {
        $ttyIn = fopen('php://memory', 'rb');
        $ttyOut = fopen('php://memory', 'wb');
        self::assertNotFalse($ttyIn);
        self::assertNotFalse($ttyOut);
        $gate = TerminalGate::decide(true, false, false, static fn(): ?array => [$ttyIn, $ttyOut]);
        self::assertTrue($gate['boot']);
        self::assertNotNull($gate['streams']);
        self::assertSame($ttyIn, $gate['streams'][0], 'the key channel is rescued');
        self::assertSame(STDOUT, $gate['streams'][1], 'a real tty stdout is kept, not swapped');
    }

    public function testPosixRedirectedStdinNamesTheMissingEndWhenNoTty(): void
    {
        $gate = TerminalGate::decide(true, false, false, self::nullProbe());
        self::assertFalse($gate['boot']);
        self::assertSame('cli.needs_tty_input', $gate['key'], 'the output end is fine - say which end is missing');
    }
}
