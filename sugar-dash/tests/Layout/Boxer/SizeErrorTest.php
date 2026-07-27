<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Layout\Boxer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Layout\Boxer\SizeError;

final class SizeErrorTest extends TestCase
{
    public function testExtendsRuntimeException(): void
    {
        $error = new SizeError('test message');

        $this->assertInstanceOf(\RuntimeException::class, $error);
    }

    public function testMessageIsPreserved(): void
    {
        $error = new SizeError('size problem: not enough space');

        $this->assertSame('size problem: not enough space', $error->getMessage());
    }
}
