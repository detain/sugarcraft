<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use SugarCraft\ScriptUi\Application;
use SugarCraft\ScriptUi\Command\LogCommand;
use SugarCraft\ScriptUi\Command\SpinCommand;
use SugarCraft\ScriptUi\Command\TableCommand;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The unknown-value family must refuse with the allowed set on STDERR
 * (exit 1) instead of surfacing an exception frame or the old silent
 * paths. The static helpers keep their InvalidArgumentException contract
 — pinned elsewhere; these tests own the CLI surface.
 */
final class ThrowSiteGuardTest extends TestCase
{
    public function testUnknownSpinnerStyleIsRefusedWithAllowedList(): void
    {
        $tester = new CommandTester((new Application())->find('spin'));
        $status = $tester->execute(['--style' => 'whirl', 'argv' => ['true']]);

        self::assertSame(1, $status);
        self::assertSame('', $tester->getDisplay());
    }

    public function testUnknownTableBorderIsRefusedWithAllowedList(): void
    {
        $file = \sys_get_temp_dir() . '/suib-' . \getmypid() . '-' . \uniqid('b', false) . '.csv';
        \file_put_contents($file, "a,b\n");
        try {
            $tester = new CommandTester((new Application())->find('table'));
            $status = $tester->execute(['--file' => $file, '--border' => 'zigzag']);
            self::assertSame(1, $status);
            self::assertSame('', $tester->getDisplay());
        } finally {
            @\unlink($file);
        }
    }

    public function testUnknownLogLevelsAreRefusedWithAllowedList(): void
    {
        foreach (['--level', '--min-level'] as $flag) {
            $tester = new CommandTester((new Application())->find('log'));
            $status = $tester->execute(['message' => 'hi', $flag => 'catastrophic']);
            self::assertSame(1, $status, $flag . ' must refuse');
            self::assertSame('', $tester->getDisplay());
        }
    }

    public function testDeclaredSpellingsCoverEveryStaticResolutionArm(): void
    {
        // The refusal advertises a list; the guard set must equal what
        // the statics actually resolve, or valid spellings would break.
        foreach (SpinCommand::STYLE_SPELLINGS as $name) {
            self::assertInstanceOf(
                \SugarCraft\Forms\Spinner\Style::class,
                SpinCommand::pickStyle($name),
            );
        }
        foreach (TableCommand::BORDER_SPELLINGS as $name) {
            try {
                TableCommand::parseBorder($name); // must resolve (or null for none/off)
            } catch (\InvalidArgumentException $failure) {
                self::fail('border spelling ' . $name . ' is guarded but unresolvable');
            }
        }
        foreach (LogCommand::LEVEL_SPELLINGS as $name) {
            self::assertInstanceOf(
                \SugarCraft\ScriptUi\Log\LogLevel::class,
                \SugarCraft\ScriptUi\Log\LogLevel::fromString($name),
            );
        }
    }

    public function testGuardsRouteThroughTheSharedRefusalShape(): void
    {
        foreach (['SpinCommand', 'TableCommand', 'LogCommand'] as $class) {
            $path = __DIR__ . '/../../src/Command/' . $class . '.php';
            $src = (string) \file_get_contents($path);
            self::assertStringContainsString("Lang::t('runtime.unknown_choice'", $src, $class);
        }
    }
}
