<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Commands\KeyBindingRegistry;

/**
 * #15: `/help` and the `/` popup read the shortcut of a command from the same
 * table `/keys` reads, so an override or an unbinding shows up in both at once.
 */
final class KeyBindingCommandShortcutTest extends TestCase
{
    protected function setUp(): void
    {
        KeyBindingRegistry::useOverrides([]);
    }

    protected function tearDown(): void
    {
        KeyBindingRegistry::useOverrides([]);
    }

    public function testACommandShowsTheChordOfTheRowThatRunsIt(): void
    {
        self::assertSame('Ctrl+A', KeyBindingRegistry::commandShortcut('agents'));
    }

    public function testACommandWithNoRowHasNoShortcut(): void
    {
        self::assertNull(KeyBindingRegistry::commandShortcut('no-such-command'));
    }

    public function testAnOverrideMovesTheShortcutWithIt(): void
    {
        KeyBindingRegistry::useOverrides(['chat.agents' => 'Ctrl+G']);

        self::assertSame('Ctrl+G', KeyBindingRegistry::commandShortcut('agents'));
    }

    public function testAnUnboundRowHasNoShortcut(): void
    {
        KeyBindingRegistry::useOverrides(['chat.agents' => null]);

        self::assertNull(KeyBindingRegistry::commandShortcut('agents'));
    }

    public function testTheChordIsRightAlignedToTheColumnBudget(): void
    {
        $row = (new \ReflectionMethod(Chat::class, 'withShortcut'))
            ->invoke(null, '  /agents' . str_repeat(' ', 4) . 'List the active agents', 'Ctrl+A', 40);

        self::assertSame(40, \SugarCraft\Core\Util\Width::string($row));
        self::assertStringEndsWith('Ctrl+A', $row);
    }

    public function testNoChordLeavesTheRowAsItWas(): void
    {
        $row = (new \ReflectionMethod(Chat::class, 'withShortcut'))
            ->invoke(null, '  /keys  Show the keyboard shortcut reference', null, 80);

        self::assertSame('  /keys  Show the keyboard shortcut reference', $row);
    }
}
