<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Tests\Render;

use PHPUnit\Framework\TestCase;
use SugarCraft\Reel\Render\AutoMode;
use ReflectionClass;

/**
 * Unit tests for AutoMode marker class.
 *
 * @covers \SugarCraft\Reel\Render\AutoMode
 */
final class AutoModeTest extends TestCase
{
    /**
     * @testdox AutoMode is a final class
     */
    public function testIsFinalClass(): void
    {
        $rc = new ReflectionClass(AutoMode::class);
        $this->assertTrue($rc->isFinal());
    }

    /**
     * @testdox AutoMode has no public constructor
     */
    public function testConstructorIsPrivate(): void
    {
        $rc = new ReflectionClass(AutoMode::class);
        $constructor = $rc->getConstructor();
        $this->assertNotNull($constructor);
        $this->assertFalse($constructor->isPublic());
    }

    /**
     * @testdox AutoMode cannot be instantiated directly
     */
    public function testCannotInstantiate(): void
    {
        $this->expectException(\Error::class);
        new AutoMode();
    }

    /**
     * @testdox AutoMode has no methods or properties
     */
    public function testHasNoPublicMethods(): void
    {
        $rc = new ReflectionClass(AutoMode::class);
        $publicMethods = $rc->getMethods(\ReflectionMethod::IS_PUBLIC);
        $this->assertCount(0, $publicMethods, 'AutoMode should have no public methods');
    }
}
