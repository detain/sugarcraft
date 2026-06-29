<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Keys;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Keys\Key;
use SugarCraft\Dash\Keys\KeyAction;
use SugarCraft\Dash\Keys\KeyMap;

/**
 * Tests for the KeyMap handler.
 */
final class KeyMapTest extends TestCase
{
    private function makeContent(): \SugarCraft\Dash\Foundation\Item
    {
        return new class implements \SugarCraft\Dash\Foundation\Item, \SugarCraft\Dash\Foundation\Sizer {
            public function render(): string { return 'content'; }
            public function getInnerSize(): array { return [10, 1]; }
            public function setSize(int $w, int $h): \SugarCraft\Dash\Foundation\Sizer { return $this; }
        };
    }

    public function testNewFactory(): void
    {
        $km = KeyMap::new($this->makeContent());
        $this->assertInstanceOf(KeyMap::class, $km);
    }

    public function testOnRegistersAction(): void
    {
        $km = KeyMap::new($this->makeContent());
        $newKm = $km->on('q', fn(Key $k) => $this->makeContent());

        $this->assertNotSame($km, $newKm);
        $this->assertTrue($newKm->has('q'));
        $this->assertFalse($km->has('q'));
    }

    public function testOnWithCtrl(): void
    {
        $km = KeyMap::new($this->makeContent());
        $newKm = $km->on('c', fn(Key $k) => $this->makeContent(), ctrl: true);

        $this->assertTrue($newKm->has('c', ctrl: true));
        $this->assertFalse($newKm->has('c', ctrl: false));
    }

    public function testOnAnyRegistersGlobalAction(): void
    {
        $km = KeyMap::new($this->makeContent());
        $newKm = $km->onAny(fn(Key $k) => $this->makeContent());

        $this->assertTrue($newKm->hasGlobalActions());
        $this->assertFalse($km->hasGlobalActions());
    }

    public function testOffRemovesAction(): void
    {
        $km = KeyMap::new($this->makeContent())->on('x', fn(Key $k) => $this->makeContent());
        $removedKm = $km->off('x');

        $this->assertNotSame($km, $removedKm);
        $this->assertFalse($removedKm->has('x'));
    }

    public function testOffWithModifiers(): void
    {
        $km = KeyMap::new($this->makeContent())->on('z', fn(Key $k) => $this->makeContent(), ctrl: true);
        $removedKm = $km->off('z', ctrl: true);

        $this->assertFalse($removedKm->has('z', ctrl: true));
    }

    public function testHandleReturnsTrueWhenActionFound(): void
    {
        $hit = false;
        $km = KeyMap::new($this->makeContent())->on('a', function (Key $k) use (&$hit) {
            $hit = true;
            return $this->makeContent();
        });

        $content = $km->handle(new Key('a'));
        $this->assertNotSame($km, $content[0]);
        $this->assertTrue($content[1]);
        $this->assertTrue($hit);
    }

    public function testHandleReturnsFalseWhenNoAction(): void
    {
        $km = KeyMap::new($this->makeContent());
        $content = $km->handle(new Key('z'));
        $this->assertSame($km, $content[0]);
        $this->assertFalse($content[1]);
    }

    public function testHandleChecksGlobalActions(): void
    {
        $hit = false;
        $km = KeyMap::new($this->makeContent())->onAny(function (Key $k) use (&$hit) {
            $hit = true;
            return $this->makeContent();
        });

        $content = $km->handle(new Key('anything'));
        $this->assertTrue($content[1]);
        $this->assertTrue($hit);
    }

    public function testHasReturnsTrueForRegistered(): void
    {
        $km = KeyMap::new($this->makeContent())->on('b', fn(Key $k) => $this->makeContent());
        $this->assertTrue($km->has('b'));
        $this->assertFalse($km->has('b', ctrl: true));
    }

    public function testGetRegisteredKeys(): void
    {
        $km = KeyMap::new($this->makeContent())
            ->on('a', fn(Key $k) => $this->makeContent())
            ->on('b', fn(Key $k) => $this->makeContent())
            ->on('c', fn(Key $k) => $this->makeContent(), ctrl: true);

        $keys = $km->getRegisteredKeys();
        // Key::toString() uppercases the key, so registered keys are uppercase
        $this->assertContains('A', $keys);
        $this->assertContains('B', $keys);
        $this->assertContains('Ctrl+C', $keys);
        $this->assertNotContains('C', $keys); // Ctrl+C registered, not plain C
    }

    public function testSetSizeReturnsSizer(): void
    {
        $km = KeyMap::new($this->makeContent());
        $sized = $km->setSize(80, 24);
        $this->assertInstanceOf(\SugarCraft\Dash\Foundation\Sizer::class, $sized);
    }

    public function testWithContentReturnsNewInstance(): void
    {
        $km = KeyMap::new($this->makeContent())->on('q', fn(Key $k) => $this->makeContent());
        $newContent = $this->makeContent();
        $newKm = $km->withContent($newContent);

        $this->assertNotSame($km, $newKm);
        $this->assertTrue($newKm->has('q'));
    }

    public function testRenderDelegatesToContent(): void
    {
        $km = KeyMap::new($this->makeContent());
        $this->assertSame('content', $km->render());
    }

    public function testGetInnerSizeFallsBackToContent(): void
    {
        $km = KeyMap::new($this->makeContent());
        $size = $km->getInnerSize();
        $this->assertSame([10, 1], $size);
    }
}
