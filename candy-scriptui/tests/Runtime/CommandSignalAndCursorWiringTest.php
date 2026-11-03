<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Runtime;

use PHPUnit\Framework\TestCase;

/**
 * Structural pins for the signal-exit and cursor-mode wiring.
 *
 * These paths end the Program from a real SIGINT/SIGTERM arriving at a
 * controlling terminal — unforgeable inside the suite (no TTY, no
 * signal-to-self without racing the runner), so the pins assert the
 * consult call itself is present in every command that owns one.
 * Deleting a consult (mutation-checked) reddens exactly the row naming
 * that command.
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
     * @dataProvider commandSourceProvider
     */
    public function testInteractiveCommandConsultsTheStopSignal(string $name): void
    {
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
     * @dataProvider cursorModeCommandProvider
     */
    public function testCursorModeCommandsHonourTheFlag(string $name): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../src/Command/' . $name . '.php');

        $this->assertStringContainsString('CursorMode::isKnown($cursorMode)', $src);
        $this->assertStringContainsString(
            'hideCursor: CursorMode::hidesCursor($cursorMode)',
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
     */
    /**
     * @dataProvider guardCommandProvider
     */
    public function testInteractiveCommandArmsTheTimeoutGuard(string $name): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../src/Command/' . $name . '.php');

        $this->assertStringContainsString('TimeoutGuard::arm(', $src,
            $name . ' accepts --timeout and must actually arm the guard');
        $this->assertStringContainsString('TimeoutGuard::EXIT_TIMEOUT', $src,
            $name . ' must return 124 when the armed guard fires');
    }

    public function testFileCommandClampsTheTimeoutCeiling(): void
    {
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
        $src = (string) file_get_contents(__DIR__ . '/../../src/Command/' . $name . '.php');

        // SpinCommand builds its Program inside an injected factory closure, so
        // the first point of no return there is spawning the child; every other
        // command paints through InteractiveRuntime::programOptions inline.
        $anchor = $name === 'SpinCommand' ? '($this->processFactory)(' : 'InteractiveRuntime::programOptions';

        $parse = strpos($src, 'tryParseSeconds(');
        $ui = strpos($src, $anchor);
        self::assertNotFalse($parse, $name . ' must route --timeout through tryParseSeconds');
        self::assertNotFalse($ui, $name . ' must reach its UI/child through ' . $anchor);
        self::assertLessThan($ui, $parse,
            $name . ' must refuse a bad --timeout BEFORE arming the UI');
        self::assertStringContainsString('runtime.bad_timeout', $src);
    }

    /**
     * Cluster 4 (2026-11-03): the abort models must distinguish "Esc" (exit 1
     * /2 semantics) from "Ctrl+C" (130); every command owning one maps
     * isInterrupted() through SignalExit::EXIT_INTERRUPTED before its plain
     * abort path, so a typed cancellation can never masquerade as an answer.
     *
     * @return array<string, array{0: string}>
     */
    public static function interruptedMappingCommandProvider(): array
    {
        return self::commandSourceProvider();
    }

    /**
     * @dataProvider interruptedMappingCommandProvider
     */
    public function testAbortModelCommandsMapTypedCtrlCTo130(string $name): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../src/Command/' . $name . '.php');

        self::assertStringContainsString('isInterrupted()', $src,
            $name . ' must consult the model interruption flag');
        self::assertStringContainsString('return SignalExit::EXIT_INTERRUPTED;', $src,
            $name . ' must exit 130 for a typed Ctrl+C');
    }

    /** The spin path: signal deaths consult stopSignal (SIGTERM→143) and Esc maps to 130. */
    public function testSpinCommandConsultsSignalsAndMapsEscTo130(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../src/Command/SpinCommand.php');
        self::assertStringContainsString('SignalExit::code($program->stopSignal())', $src,
            'a SIGTERM-killed spin run must exit 143, not 130');

        $model = (string) file_get_contents(__DIR__ . '/../../src/Model/SpinModel.php');
        self::assertStringContainsString('SignalExit::EXIT_INTERRUPTED', $model,
            'the spin abort sentinel must be the conventional 130');
        self::assertStringNotContainsString('-1,', $model,
            'the old negative sentinel must be gone');
    }
}
