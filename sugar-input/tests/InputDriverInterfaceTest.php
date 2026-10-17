<?php

declare(strict_types=1);

namespace SugarCraft\Input\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Input\InputDriver;

/**
 * Tests for the InputDriver interface.
 */
final class InputDriverInterfaceTest extends TestCase
{
    public function testInputDriverIsAnInterface(): void
    {
        $this->assertTrue(interface_exists(InputDriver::class));
    }

    public function testInputDriverDeclaresReadMethod(): void
    {
        $methods = get_class_methods(InputDriver::class);

        $this->assertContains('read', $methods);
    }

    public function testReadMethodHasNoParameters(): void
    {
        $reflection = new \ReflectionMethod(InputDriver::class, 'read');
        $params = $reflection->getParameters();

        $this->assertCount(0, $params);
    }

    public function testReadMethodReturnTypeIsEventOrNull(): void
    {
        $reflection = new \ReflectionMethod(InputDriver::class, 'read');
        $returnType = $reflection->getReturnType();

        $this->assertNotNull($returnType);
        // Nullable return type: getName() returns the base type, allowsNull() confirms nullable
        $this->assertSame('SugarCraft\Input\Event', $returnType->getName());
        $this->assertTrue($returnType->allowsNull());
    }
}
