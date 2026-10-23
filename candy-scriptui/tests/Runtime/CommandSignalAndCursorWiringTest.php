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
            'hideCursor:      CursorMode::hidesCursor($cursorMode),',
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
}
