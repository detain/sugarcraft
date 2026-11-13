<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Runtime;

use PHPUnit\Framework\TestCase;

/**
 * Wiring pins for the signal-exit, cursor-mode and timeout paths.
 *
 * scriptui-53 split, per row:
 * - The deadline PARSING order is a behaviour test now (bin children under
 *   garbage --timeout: the refusal wins over the UI and over the child
 *   spawn — captured at the fd boundary).
 * - What remains grepped is the documented core: legs that only become
 *   observable inside a live Program run behind a controlling terminal
 *   (signal consultation, guard arming, typed-Ctrl+C mapping, hideCursor
 *   plumbing). Those paths are unforgeable inside the suite (no TTY, no
 *   signal-to-self without racing the runner), so deleting a consult
 *   (mutation-checked) reddens exactly the row naming that command, and the
 *   observable halves have their own behaviour units (SignalExitTest,
 *   TimeoutGuardTest, CursorModeTest, the model tests).
 */
final class CommandSignalAndCursorWiringTest extends TestCase
{
    /** @return list<string> */
    private static function interactiveCommands(): array
    {
        return [
            'ChooseCommand',
            'ConfirmCommand',
            'FileCommand',
            'FilterCommand',
            'InputCommand',
            'PagerCommand',
            'WriteCommand',
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function commandSourceProvider(): array
    {
        $rows = [];
        foreach (self::interactiveCommands() as $name) {
            $rows[$name] = [$name];
        }
        return $rows;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function cursorModeCommandProvider(): array
    {
        return [
            'InputCommand'  => ['InputCommand'],
            'WriteCommand'  => ['WriteCommand'],
        ];
    }

    /**
     * @dataProvider commandSourceProvider
     */
    public function testInteractiveCommandConsultsTheStopSignal(string $name): void
    {
        // scriptui-53 KEEP (unforgeable-in-suite): only a real SIGINT/SIGTERM
        // at a controlling terminal makes this observable at runtime; the
        // 128+signum mapping itself has SignalExitTest.
        $src = (string) file_get_contents(__DIR__ . '/../../src/Command/' . $name . '.php');

        $this->assertStringContainsString(
            'SignalExit::code($program->stopSignal())',
            $src,
            $name . ' must translate a signal stop into 128+signum',
        );
        $this->assertStringContainsString(
            'use SugarCraft\ScriptUi\Runtime\SignalExit;',
            $src,
        );
    }

    /**
     * @dataProvider cursorModeCommandProvider
     */
    public function testCursorModeCommandsHonourTheFlag(string $name): void
    {
        // scriptui-53 KEEP (unforgeable-in-suite): hideCursor plumbing is
        // only visible in a live Program render; the isKnown refusal door is
        // behaviour-tested per command in Input/WriteCommandTest (fd capture).
        $src = (string) file_get_contents(__DIR__ . '/../../src/Command/' . $name . '.php');

        $this->assertStringContainsString('CursorMode::isKnown($cursorMode)', $src);
        $this->assertMatchesRegularExpression(
            '/hideCursor:\s+CursorMode::hidesCursor\(\$cursorMode\)/',
            $src,
            $name . ' must drive ProgramOptions::hideCursor from --cursor-mode',
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function guardCommandProvider(): array
    {
        return self::commandSourceProvider();
    }

    /**
     * The --timeout flag exists on every interactive command; before
     * docs-fix-1023 FileCommand defined it but never armed the guard, so
     * the promise was dead. Every armed command must clamp through the
     * shared TimeoutGuard (an unbounded user-provided wait is a hang
     * waiting to happen; FileCommand additionally caps at 86400s).
     *
     * @dataProvider guardCommandProvider
     */
    public function testInteractiveCommandArmsTheTimeoutGuard(string $name): void
    {
        // scriptui-53 KEEP (unforgeable-in-suite): arming and the 124 verdict
        // are only observable while a live Program waits; the guard itself
        // has TimeoutGuardTest (arm/fire/disarm, exit-124 constant).
        $src = (string) file_get_contents(__DIR__ . '/../../src/Command/' . $name . '.php');

        $this->assertStringContainsString('TimeoutGuard::arm(', $src,
            $name . ' accepts --timeout and must actually arm the guard');
        $this->assertStringContainsString('TimeoutGuard::EXIT_TIMEOUT', $src,
            $name . ' must return 124 when the armed guard fires');
    }

    public function testFileCommandClampsTheTimeoutCeiling(): void
    {
        // scriptui-53 KEEP (unforgeable-in-suite): the min() clamp is silent
        // by design and observable only through a live deadline; the pin
        // keeps the documented 24h ceiling paired with the clamp constant.
        $src = (string) file_get_contents(__DIR__ . '/../../src/Command/FileCommand.php');

        $this->assertStringContainsString('self::MAX_TIMEOUT_SECONDS', $src);
        $this->assertStringContainsString('MAX_TIMEOUT_SECONDS = 86400.0', $src,
            'the documented 24h ceiling must stay paired with the clamp');
    }

    /**
     * m-a (2026-11-03): every command owning a --timeout must parse it through
     * the strict TimeoutGuard::tryParseSeconds and refuse garbage BEFORE any UI
     * starts — the old (float) cast turned 'abc' into "no deadline".
     *
     * @return array<string, array{0: string}>
     */
    public static function timeoutParsingCommandProvider(): array
    {
        $rows = self::commandSourceProvider();
        $rows['SpinCommand'] = ['SpinCommand'];
        return $rows;
    }

    /**
     * @dataProvider timeoutParsingCommandProvider
     */
    public function testEveryTimeoutCommandParsesTheDeadlineStrictlyFirst(string $name): void
    {
        // scriptui-53: the ORDER is now proven by execution, not by strpos
        // arithmetic on source. Every timeout command run with a garbage
        // --timeout must answer with the bad-timeout refusal on captured
        // STDERR before its first point of no return: captured STDOUT stays
        // empty, the terminal-shaped refusal never appears (that would mean
        // the UI armed first), and for spin the child command must never
        // run. The old grep could be satisfied by reordered-to-match code.
        $lcName = strtolower(preg_replace('/Command$/', '', $name) ?? $name);

        $args = [$lcName, '--timeout', 'abc'];
        $stdin = '';
        if ($lcName === 'choose') {
            $args[] = 'a';
            $args[] = 'b';
        } elseif ($lcName === 'spin') {
            array_push($args, '--', '/bin/sh', '-c', 'printf SPAWNED-53');
        } elseif ($lcName === 'filter') {
            $stdin = "alpha\n"; // non-empty: the empty-input door must not pre-empt
        }

        [$status, $stdout, $stderr] = self::runBinChild($args, $stdin);
        $this->assertSame(1, $status, $name . ' must refuse a bad --timeout with FAILURE');
        $this->assertSame('', $stdout, $name . ' must refuse BEFORE painting UI or echoing child data');
        $this->assertStringContainsString("Invalid --timeout value 'abc'", $stderr,
            $name . ' must route --timeout through tryParseSeconds');
        $this->assertStringNotContainsString('needs an interactive terminal', $stderr,
            $name . ' must refuse a bad --timeout before arming the UI');
        $this->assertStringNotContainsString('SPAWNED-53', $stdout . $stderr,
            $name . ' must refuse a bad --timeout before reaching its point of no return');
    }

    /**
     * @dataProvider interruptedMappingCommandProvider
     */
    public function testAbortModelCommandsMapTypedCtrlCTo130(string $name): void
    {
        // scriptui-53 KEEP (unforgeable-in-suite): a typed Ctrl+C only exists
        // inside a raw-mode Program run; the model-level interruption flag
        // and the 130 constant have their own behaviour units
        // (the Model tests, SignalExitTest).
        $src = (string) file_get_contents(__DIR__ . '/../../src/Command/' . $name . '.php');

        self::assertStringContainsString('isInterrupted()', $src,
            $name . ' must consult the model interruption flag');
        self::assertStringContainsString('return SignalExit::EXIT_INTERRUPTED;', $src,
            $name . ' must exit 130 for a typed Ctrl+C');
    }

    /** @return array<string, array{0: string}> */
    public static function interruptedMappingCommandProvider(): array
    {
        return self::commandSourceProvider();
    }

    /** The spin path: signal deaths consult stopSignal (SIGTERM→143) and Esc maps to 130. */
    public function testSpinCommandConsultsSignalsAndMapsEscTo130(): void
    {
        // scriptui-53 KEEP (unforgeable-in-suite): delivery needs a real
        // signal against a live spin run; SpinHeadlessPurityTest holds the
        // behavioural child legs for the headless arm, SpinModel's abort
        // sentinel shape is pinned here because a '-1' return is invisible
        // to every headless probe (it only reaches a mapped exit inside a
        // Program the suite cannot run).
        $src = (string) file_get_contents(__DIR__ . '/../../src/Command/SpinCommand.php');
        self::assertStringContainsString('SignalExit::code($program->stopSignal())', $src,
            'a SIGTERM-killed spin run must exit 143, not 130');

        $model = (string) file_get_contents(__DIR__ . '/../../src/Model/SpinModel.php');
        self::assertStringContainsString('SignalExit::EXIT_INTERRUPTED', $model,
            'the spin abort sentinel must be the conventional 130');
        self::assertStringNotContainsString('-1,', $model,
            'the old negative sentinel must be gone');
    }

    /** @return array{0:int,1:string,2:string} [exitCode, stdout, stderr] */
    private static function runBinChild(array $args, string $stdin = ''): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('bounded child probes require a POSIX timeout(1)');
        }
        $bin = \dirname(__DIR__, 2) . '/bin/candy-scriptui';
        $proc = proc_open(
            ['timeout', '-s', 'KILL', '20', PHP_BINARY, $bin, ...$args],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            \dirname($bin),
            ['PATH' => (string) getenv('PATH'), 'HOME' => (string) getenv('HOME')],
        );
        self::assertIsResource($proc, 'the bin child must spawn');
        if ($stdin !== '') {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $stdout, $stderr];
    }
}
