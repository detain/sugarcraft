<?php

declare(strict_types=1);

namespace SugarCraft\Video\Tests\Render;

use PHPUnit\Framework\TestCase;
use SugarCraft\Video\Render\AutoMode;
use ReflectionClass;

/**
 * Unit tests for AutoMode marker class.
 *
 * @covers \SugarCraft\Video\Render\AutoMode
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
     * @testdox AutoMode has no public constructor (instantiation rides the factory)
     */
    public function testConstructorIsPrivate(): void
    {
        $rc = new ReflectionClass(AutoMode::class);
        $constructor = $rc->getConstructor();
        $this->assertNotNull($constructor);
        $this->assertFalse($constructor->isPublic());
    }

    /**
     * @testdox AutoMode cannot be instantiated directly (SV-C1: the factory is the door)
     */
    public function testCannotInstantiate(): void
    {
        $this->expectException(\Error::class);
        new AutoMode();
    }

    /**
     * @testdox AutoMode exposes exactly one public method: the ::new() factory
     *
     * SV-C1: `new AutoMode()` from Reel::withAutoMode() was dead-on-arrival
     * against the private constructor. The marker keeps its private ctor and
     * gains the sanctioned factory — the surface is pinned to nothing else.
     */
    public function testFactoryIsTheOnlyPublicMethod(): void
    {
        $rc = new ReflectionClass(AutoMode::class);
        $publicMethods = $rc->getMethods(\ReflectionMethod::IS_PUBLIC);
        $this->assertSame(['new'], array_map(static fn (\ReflectionMethod $m): string => $m->getName(), $publicMethods));
    }

    /**
     * @testdox AutoMode::new() yields the marker
     */
    public function testFactoryReturnsTheMarker(): void
    {
        $this->assertInstanceOf(AutoMode::class, AutoMode::new());
    }
}
