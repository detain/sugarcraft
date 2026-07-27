<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sprinkles\VAlign;

final class VAlignTest extends TestCase
{
    public function testEnumCases(): void
    {
        $cases = VAlign::cases();
        $this->assertCount(3, $cases);
    }

    public function testTopCase(): void
    {
        $this->assertSame('top', VAlign::Top->value);
    }

    public function testMiddleCase(): void
    {
        $this->assertSame('middle', VAlign::Middle->value);
    }

    public function testBottomCase(): void
    {
        $this->assertSame('bottom', VAlign::Bottom->value);
    }

    public function testFromValue(): void
    {
        $this->assertSame(VAlign::Top, VAlign::from('top'));
        $this->assertSame(VAlign::Middle, VAlign::from('middle'));
        $this->assertSame(VAlign::Bottom, VAlign::from('bottom'));
    }

    public function testTryFromInvalidValue(): void
    {
        $this->assertNull(VAlign::tryFrom('invalid'));
    }
}
