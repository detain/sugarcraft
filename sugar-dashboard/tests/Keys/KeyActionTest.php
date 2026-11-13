<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Keys;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Foundation\Item;
use SugarCraft\Dashboard\Keys\KeyAction;
use SugarCraft\Dashboard\Keys\Key;

/** @implements Item */
final class FakeItem implements Item
{
    public function __construct(private readonly string $content = 'rendered') {}
    public function render(): string { return $this->content; }
}

final class KeyActionTest extends TestCase
{
    public function testConstruction(): void
    {
        $execute = fn(Key $key) => new FakeItem('rendered');

        $action = new KeyAction('test-action', $execute);

        $this->assertSame('test-action', $action->name);
        $this->assertSame($execute, $action->execute);
    }

    public function testExecuteCallsClosure(): void
    {
        $executed = false;
        $execute = function(Key $key) use (&$executed): Item {
            $executed = true;
            return new FakeItem('result');
        };

        $action = new KeyAction('test', $execute);
        $key = new Key('a');
        $result = $action->execute($key);

        $this->assertTrue($executed);
        $this->assertInstanceOf(Item::class, $result);
    }

    public function testExecutePassesKeyToClosure(): void
    {
        $receivedKey = null;
        $execute = function(Key $key) use (&$receivedKey): Item {
            $receivedKey = $key;
            return new FakeItem('result');
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

    public function testNonCallableExecuteThrowsAtConstruction(): void
    {
        // D-139: the callable promise is enforced at construction, not with
        // a fatal at dispatch.
        foreach (['not-callable', 42, ['a', 'b']] as $bad) {
            try {
                new KeyAction('guarded', $bad);
                $this->fail('non-callable execute must throw at construction');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('guarded', $e->getMessage());
                $this->assertStringContainsString('callable', $e->getMessage());
            }
        }
    }

    public function testFirstClassCallableIsAccepted(): void
    {
        $action = new KeyAction('accepts', strrev(...));
        $this->assertTrue(is_callable($action->execute));
    }
}
