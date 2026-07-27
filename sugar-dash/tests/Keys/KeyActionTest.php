<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Keys;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Keys\KeyAction;
use SugarCraft\Dash\Keys\Key;

final class KeyActionTest extends TestCase
{
    public function testConstruction(): void
    {
        $execute = fn(Key $key) => new class {
            public function render(): string { return 'rendered'; }
        };

        $action = new KeyAction('test-action', $execute);

        $this->assertSame('test-action', $action->name);
        $this->assertSame($execute, $action->execute);
    }

    public function testExecuteCallsClosure(): void
    {
        $executed = false;
        $execute = function(Key $key) use (&$executed) {
            $executed = true;
            return new class {
                public function render(): string { return 'result'; }
            };
        };

        $action = new KeyAction('test', $execute);
        $key = new Key('a');
        $result = $action->execute($key);

        $this->assertTrue($executed);
        $this->assertInstanceOf(\SugarCraft\Dash\Foundation\Item::class, $result);
    }

    public function testExecutePassesKeyToClosure(): void
    {
        $receivedKey = null;
        $execute = function(Key $key) use (&$receivedKey) {
            $receivedKey = $key;
            return new class {
                public function render(): string { return 'result'; }
            };
        };

        $action = new KeyAction('test', $execute);
        $key = new Key('x', ctrl: true, alt: true, shift: true);
        $action->execute($key);

        $this->assertNotNull($receivedKey);
        $this->assertSame('x', $receivedKey->key);
        $this->assertTrue($receivedKey->ctrl);
        $this->assertTrue($receivedKey->alt);
        $this->assertTrue($receivedKey->shift);
    }
}
