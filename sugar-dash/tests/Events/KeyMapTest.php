<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Events;

use SugarCraft\Dash\Keys\Key;
use SugarCraft\Dash\Keys\KeyAction;
use SugarCraft\Dash\Keys\KeyMap;
use SugarCraft\Dash\Components\Card\Text;
use SugarCraft\Dash\Plot\Chart\Bar;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use PHPUnit\Framework\TestCase;

final class KeyMapTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Key class
    // ═══════════════════════════════════════════════════════════════

    public function testKeyCreation(): void
    {
        $key = new Key('a');
        $this->assertSame('a', $key->key);
        $this->assertFalse($key->ctrl);
        $this->assertFalse($key->alt);
        $this->assertFalse($key->shift);
    }

    public function testKeyWithCtrl(): void
    {
        $key = (new Key('c'))->withCtrl();
        $this->assertTrue($key->ctrl);
        $this->assertSame('c', $key->key);
    }

    public function testKeyWithAlt(): void
    {
        $key = (new Key('b'))->withAlt();
        $this->assertTrue($key->alt);
        $this->assertSame('b', $key->key);
    }

    public function testKeyWithShift(): void
    {
        $key = (new Key('x'))->withShift();
        $this->assertTrue($key->shift);
        $this->assertSame('x', $key->key);
    }

    public function testKeyToString(): void
    {
        $key = new Key('a');
        $this->assertSame('A', $key->toString());

        $ctrlC = (new Key('c'))->withCtrl();
        $this->assertSame('Ctrl+C', $ctrlC->toString());

        $ctrlAltDel = (new Key('DEL'))->withCtrl()->withAlt();
        $this->assertSame('Ctrl+Alt+DEL', $ctrlAltDel->toString());
    }

    public function testKeyMatches(): void
    {
        $key = new Key('a');
        $this->assertTrue($key->matches('a'));
        $this->assertFalse($key->matches('b'));

        $ctrlC = (new Key('c'))->withCtrl();
        $this->assertTrue($ctrlC->matches('c', ctrl: true));
        $this->assertFalse($ctrlC->matches('c', ctrl: false));
        $this->assertFalse($ctrlC->matches('c', ctrl: true, alt: true));
    }

    // ═══════════════════════════════════════════════════════════════
    // KeyAction class
    // ═══════════════════════════════════════════════════════════════

    public function testKeyActionExecute(): void
    {
        $executed = false;
        $action = new KeyAction('test', function (Key $key) use (&$executed) {
            $executed = true;
            return Text::new('Result: ' . $key->key);
        });

        $key = new Key('a');
        $result = $action->execute($key);

        $this->assertTrue($executed);
        $this->assertInstanceOf(Item::class, $result);
    }

    public function testKeyActionName(): void
    {
        $action = new KeyAction('my-action', fn() => Text::new('test'));
        $this->assertSame('my-action', $action->name);
    }

    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testKeyMapImplementsItem(): void
    {
        $keymap = KeyMap::new(Text::new('test'));
        $this->assertInstanceOf(Item::class, $keymap);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsContent(): void
    {
        $keymap = KeyMap::new(Text::new('Hello World'));
        $this->assertStringContainsString('Hello World', $keymap->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Action registration
    // ═══════════════════════════════════════════════════════════════

    public function testOnRegistersAction(): void
    {
        $keymap = KeyMap::new(Text::new('Original'));
        $newKeymap = $keymap->on('q', fn() => Text::new('Quit pressed'));

        $this->assertNotSame($keymap, $newKeymap);
    }

    public function testOnReturnsNewInstance(): void
    {
        $keymap = KeyMap::new(Text::new('test'));
        $modified = $keymap->on('a', fn() => Text::new('A pressed'));

        $this->assertNotSame($keymap, $modified);
    }

    public function testOnWithModifiers(): void
    {
        $keymap = KeyMap::new(Text::new('test'));
        $newKeymap = $keymap->on('c', fn() => Text::new('Ctrl+C'), ctrl: true);

        $this->assertNotSame($keymap, $newKeymap);
    }

    public function testOnAnyRegistersGlobalAction(): void
    {
        $keymap = KeyMap::new(Text::new('test'));
        $newKeymap = $keymap->onAny(fn() => Text::new('Any key'));

        $this->assertNotSame($keymap, $newKeymap);
        $this->assertTrue($newKeymap->hasGlobalActions());
    }

    public function testOffRemovesAction(): void
    {
        $keymap = KeyMap::new(Text::new('test'))
            ->on('a', fn() => Text::new('A'));

        $this->assertTrue($keymap->has('a'));

        $removed = $keymap->off('a');
        $this->assertFalse($removed->has('a'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Key handling
    // ═══════════════════════════════════════════════════════════════

    public function testHandleExecutesRegisteredAction(): void
    {
        $keymap = KeyMap::new(Text::new('Original'))
            ->on('a', fn() => Text::new('Handled A'));

        $key = new Key('a');
        [$content, $handled] = $keymap->handle($key);

        $this->assertTrue($handled);
        $this->assertStringContainsString('Handled A', $content->render());
    }

    public function testHandleReturnsFalseWhenNoAction(): void
    {
        $keymap = KeyMap::new(Text::new('Original'));

        $key = new Key('z');
        [$content, $handled] = $keymap->handle($key);

        $this->assertFalse($handled);
        $this->assertSame($keymap, $content);
    }

    public function testHandleWithModifiers(): void
    {
        $keymap = KeyMap::new(Text::new('Original'))
            ->on('c', fn() => Text::new('Ctrl+C'), ctrl: true);

        $key = new Key('c', ctrl: true);
        [$content, $handled] = $keymap->handle($key);

        $this->assertTrue($handled);
        $this->assertStringContainsString('Ctrl+C', $content->render());
    }

    public function testHandleGlobalAction(): void
    {
        $keymap = KeyMap::new(Text::new('Original'))
            ->onAny(fn() => Text::new('Global handled'));

        $key = new Key('x');
        [$content, $handled] = $keymap->handle($key);

        $this->assertTrue($handled);
        $this->assertStringContainsString('Global handled', $content->render());
    }

    public function testHandlePriorityToSpecificAction(): void
    {
        $specificCalled = false;
        $globalCalled = false;

        $keymap = KeyMap::new(Text::new('Original'))
            ->on('a', function () use (&$specificCalled) {
                $specificCalled = true;
                return Text::new('Specific');
            })
            ->onAny(function () use (&$globalCalled) {
                $globalCalled = true;
                return Text::new('Global');
            });

        $key = new Key('a');
        [$content, $handled] = $keymap->handle($key);

        // Specific action should take priority
        $this->assertTrue($specificCalled);
        $this->assertFalse($globalCalled);
        $this->assertStringContainsString('Specific', $content->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Query methods
    // ═══════════════════════════════════════════════════════════════

    public function testHasReturnsTrueForRegisteredKey(): void
    {
        $keymap = KeyMap::new(Text::new('test'))
            ->on('q', fn() => Text::new('Quit'));

        $this->assertTrue($keymap->has('q'));
        $this->assertFalse($keymap->has('x'));
    }

    public function testHasWithModifiers(): void
    {
        $keymap = KeyMap::new(Text::new('test'))
            ->on('c', fn() => Text::new('Ctrl+C'), ctrl: true);

        $this->assertTrue($keymap->has('c', ctrl: true));
        $this->assertFalse($keymap->has('c', ctrl: false));
    }

    public function testGetRegisteredKeys(): void
    {
        $keymap = KeyMap::new(Text::new('test'))
            ->on('a', fn() => Text::new('A'))
            ->on('b', fn() => Text::new('B'))
            ->on('c', fn() => Text::new('C'), ctrl: true);

        $keys = $keymap->getRegisteredKeys();

        $this->assertCount(3, $keys);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsSizerInstance(): void
    {
        $keymap = KeyMap::new(Text::new('test'));
        $result = $keymap->setSize(20, 5);

        $this->assertInstanceOf(Sizer::class, $result);
    }

    public function testSetSizePropagatesToContent(): void
    {
        $keymap = KeyMap::new(Text::new('test'));
        $resized = $keymap->setSize(20, 5);

        [$w, $h] = $resized->getInnerSize();
        $this->assertSame(20, $w);
        $this->assertSame(5, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers
    // ═══════════════════════════════════════════════════════════════

    public function testWithContentReturnsNewInstance(): void
    {
        $keymap = KeyMap::new(Text::new('Original'));
        $modified = $keymap->withContent(Text::new('Modified'));

        $this->assertNotSame($keymap, $modified);
        $this->assertStringContainsString('Modified', $modified->render());
    }

    public function testWithAction(): void
    {
        $keymap = KeyMap::new(Text::new('test'));
        $modified = $keymap->withAction('x', fn() => Text::new('X pressed'));

        $this->assertTrue($modified->has('x'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testMultipleActionsForSameKey(): void
    {
        $callOrder = [];

        $keymap = KeyMap::new(Text::new('test'))
            ->on('a', function () use (&$callOrder) {
                $callOrder[] = 'first';
                return Text::new('First');
            })
            ->on('a', function () use (&$callOrder) {
                $callOrder[] = 'second';
                return Text::new('Second');
            });

        $key = new Key('a');
        [$content, $handled] = $keymap->handle($key);

        // First registered action should execute
        $this->assertStringContainsString('First', $content->render());
    }

    public function testChainedOnCalls(): void
    {
        $keymap = KeyMap::new(Text::new('test'))
            ->on('a', fn() => Text::new('A'))
            ->on('b', fn() => Text::new('B'))
            ->on('c', fn() => Text::new('C'));

        $this->assertTrue($keymap->has('a'));
        $this->assertTrue($keymap->has('b'));
        $this->assertTrue($keymap->has('c'));
    }

    public function testKeyMapWithBarContent(): void
    {
        $keymap = KeyMap::new(Bar::new('Status'));
        $this->assertStringContainsString('Status', $keymap->render());
    }
}
