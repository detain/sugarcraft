<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Keys;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Keys\KeyIdentifier;

final class KeyIdentifierTest extends TestCase
{
    public function testConstruction(): void
    {
        $id = new KeyIdentifier('custom-id');

        $this->assertSame('custom-id', $id->value);
    }

    public function testOfFactory(): void
    {
        $id = KeyIdentifier::of('my-id');

        $this->assertInstanceOf(KeyIdentifier::class, $id);
        $this->assertSame('my-id', $id->value);
    }

    public function testQuitFactory(): void
    {
        $id = KeyIdentifier::quit();

        $this->assertSame('quit', $id->value);
    }

    public function testHelpFactory(): void
    {
        $id = KeyIdentifier::help();

        $this->assertSame('help', $id->value);
    }

    public function testRefreshFactory(): void
    {
        $id = KeyIdentifier::refresh();

        $this->assertSame('refresh', $id->value);
    }

    public function testFocusNextFactory(): void
    {
        $id = KeyIdentifier::focusNext();

        $this->assertSame('focus.next', $id->value);
    }

    public function testFocusPrevFactory(): void
    {
        $id = KeyIdentifier::focusPrev();

        $this->assertSame('focus.prev', $id->value);
    }
}
