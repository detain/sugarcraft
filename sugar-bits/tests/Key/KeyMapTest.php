<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Tests\Key;

use PHPUnit\Framework\TestCase;
use SugarCraft\Bits\Key\Binding;
use SugarCraft\Bits\Key\Help;
use SugarCraft\Bits\Key\KeyMap;

/**
 * Tests for {@see KeyMap} interface — verifies contract via a concrete test double.
 */
final class KeyMapTest extends TestCase
{
    public function testShortHelpReturnsEmptyArrayByDefault(): void
    {
        $km = new class implements KeyMap {
            public function shortHelp(): array
            {
                return [];
            }
            public function fullHelp(): array
            {
                return [];
            }
        };

        $this->assertSame([], $km->shortHelp());
    }

    public function testFullHelpReturnsEmptyArrayByDefault(): void
    {
        $km = new class implements KeyMap {
            public function shortHelp(): array
            {
                return [];
            }
            public function fullHelp(): array
            {
                return [];
            }
        };

        $this->assertSame([], $km->fullHelp());
    }

    public function testShortHelpReturnsListOfBindings(): void
    {
        $bindings = [
            new Binding(['q'], new Help('q', 'quit')),
            new Binding(['ctrl+c'], new Help('ctrl+c', 'interrupt')),
        ];

        $km = new class($bindings) implements KeyMap {
            /** @param list<Binding> */
            public function __construct(private readonly array $bindings) {}
            public function shortHelp(): array
            {
                return $this->bindings;
            }
            public function fullHelp(): array
            {
                return [];
            }
        };

        $result = $km->shortHelp();
        $this->assertCount(2, $result);
        $this->assertSame('q', $result[0]->help->key);
        $this->assertSame('quit', $result[0]->help->desc);
    }

    public function testFullHelpReturnsListOfColumns(): void
    {
        $col1 = [new Binding(['q'], new Help('q', 'quit'))];
        $col2 = [new Binding(['ctrl+c'], new Help('ctrl+c', 'interrupt')), new Binding(['esc'], new Help('esc', 'cancel'))];

        $km = new class($col1, $col2) implements KeyMap {
            /** @param list<Binding> */
            public function __construct(
                private readonly array $col1,
                private readonly array $col2,
            ) {}
            public function shortHelp(): array
            {
                return [];
            }
            public function fullHelp(): array
            {
                return [$this->col1, $this->col2];
            }
        };

        $result = $km->fullHelp();
        $this->assertCount(2, $result);
        $this->assertCount(1, $result[0]);
        $this->assertCount(2, $result[1]);
    }

    public function testKeyMapInterfaceContract(): void
    {
        // Sanity-check that our test double satisfies the interface
        $km = new class implements KeyMap {
            public function shortHelp(): array
            {
                return [];
            }
            public function fullHelp(): array
            {
                return [];
            }
        };

        $this->assertInstanceOf(KeyMap::class, $km);
    }
}
