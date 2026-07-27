<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Layout\Boxer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Layout\Boxer\NotFoundError;

final class NotFoundErrorTest extends TestCase
{
    public function testExtendsRuntimeException(): void
    {
        $error = new NotFoundError('address not found');

        $this->assertInstanceOf(\RuntimeException::class, $error);
    }

    public function testMessageIsPreserved(): void
    {
        $error = new NotFoundError('leaf with address: "foo" not found');

        $this->assertSame('leaf with address: "foo" not found', $error->getMessage());
    }
}
