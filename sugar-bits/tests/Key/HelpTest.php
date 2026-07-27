<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Tests\Key;

use PHPUnit\Framework\TestCase;
use SugarCraft\Bits\Key\Help;

/**
 * Tests for {@see Help} value object.
 */
final class HelpTest extends TestCase
{
    public function testDefaultConstructor(): void
    {
        $h = new Help();
        $this->assertSame('', $h->key);
        $this->assertSame('', $h->desc);
    }

    public function testConstructorWithKeyAndDesc(): void
    {
        $h = new Help('q', 'quit');
        $this->assertSame('q', $h->key);
        $this->assertSame('quit', $h->desc);
    }

    public function testKeyIsReadonly(): void
    {
        $h = new Help('ctrl+c', 'interrupt');
        $this->assertSame('ctrl+c', $h->key);
    }

    public function testDescIsReadonly(): void
    {
        $h = new Help('q', 'quit application');
        $this->assertSame('quit application', $h->desc);
    }

    public function testImmutability(): void
    {
        $h1 = new Help();
        $h2 = new Help('q', 'quit');
        $this->assertNotSame($h1, $h2);
        $this->assertSame('', $h1->key);
        $this->assertSame('', $h1->desc);
    }
}
