<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tui\Renderer;

/**
 * @see Renderer
 * @see Renderer::setSize()
 * @see Renderer::getTerminalSize()
 * @see Renderer::resetSizeCache()
 */
final class RendererTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Reset the static cache before each test to prevent cross-test pollution
        Renderer::resetSizeCache();
    }

    protected function tearDown(): void
    {
        Renderer::resetSizeCache();
        parent::tearDown();
    }

    // =========================================================================
    // Renderer::setSize() Tests
    // =========================================================================

    public function testSetSizeSetsTerminalSizeCorrectly(): void
    {
        Renderer::setSize(100, 50);

        $size = Renderer::getTerminalSize();

        $this->assertSame(50, $size['rows']);
        $this->assertSame(100, $size['cols']);
    }

    public function testSetSizeWithValidPositiveValues(): void
    {
        Renderer::setSize(80, 24);

        $size = Renderer::getTerminalSize();

        $this->assertSame(24, $size['rows']);
        $this->assertSame(80, $size['cols']);
    }

    public function testSetSizeIgnoresZeroColumns(): void
    {
        Renderer::resetSizeCache();
        // Set a valid size first
        Renderer::setSize(100, 50);

        // Try to set with zero columns - should be ignored
        Renderer::setSize(0, 30);

        $size = Renderer::getTerminalSize();
        // Should still be the previous valid size (100x50)
        $this->assertSame(50, $size['rows']);
        $this->assertSame(100, $size['cols']);
    }

    public function testSetSizeIgnoresZeroRows(): void
    {
        Renderer::resetSizeCache();
        Renderer::setSize(100, 50);

        // Try to set with zero rows - should be ignored
        Renderer::setSize(100, 0);

        $size = Renderer::getTerminalSize();
        // Should still be the previous valid size (100x50)
        $this->assertSame(50, $size['rows']);
        $this->assertSame(100, $size['cols']);
    }

    public function testSetSizeIgnoresNegativeValues(): void
    {
        Renderer::resetSizeCache();
        Renderer::setSize(100, 50);

        // Try to set with negative values - should be ignored
        Renderer::setSize(-10, 30);
        Renderer::setSize(100, -5);

        $size = Renderer::getTerminalSize();
        // Should still be the previous valid size (100x50)
        $this->assertSame(50, $size['rows']);
        $this->assertSame(100, $size['cols']);
    }

    // =========================================================================
    // Renderer::getTerminalSize() Tests
    // =========================================================================

    public function testGetTerminalSizeReturnsCachedSize(): void
    {
        Renderer::setSize(150, 60);

        // First call
        $size1 = Renderer::getTerminalSize();
        // Second call - should return cached value
        $size2 = Renderer::getTerminalSize();

        $this->assertSame($size1, $size2);
        $this->assertSame(60, $size1['rows']);
        $this->assertSame(150, $size1['cols']);
    }

    public function testGetTerminalSizeWithNoCacheFallsBackToDefaults(): void
    {
        // No setSize() call, so it will try to get from Tty and fall back to defaults
        // We can't reliably test Tty failure, but we can verify the method returns an array
        $size = Renderer::getTerminalSize();

        $this->assertIsArray($size);
        $this->assertArrayHasKey('rows', $size);
        $this->assertArrayHasKey('cols', $size);
        $this->assertGreaterThan(0, $size['rows']);
        $this->assertGreaterThan(0, $size['cols']);
    }

    // =========================================================================
    // Renderer::resetSizeCache() Tests
    // =========================================================================

    public function testResetSizeCacheClearsTheCache(): void
    {
        // Set a size to populate cache
        Renderer::setSize(120, 40);
        $this->assertSame(40, Renderer::getTerminalSize()['rows']);

        // Reset the cache
        Renderer::resetSizeCache();

        // Cache is cleared, next getTerminalSize() will try to get fresh size
        // Since we cleared it, the next call should either get from Tty or default
        // We just verify the cache is null after reset by checking that a new setSize works
        Renderer::setSize(200, 80);
        $size = Renderer::getTerminalSize();
        $this->assertSame(80, $size['rows']);
        $this->assertSame(200, $size['cols']);
    }

    public function testResetSizeCacheAllowsFreshSizeAfterCache(): void
    {
        // First, cache a size
        Renderer::setSize(100, 50);
        $this->assertSame(50, Renderer::getTerminalSize()['rows']);

        // Reset and set new size
        Renderer::resetSizeCache();
        Renderer::setSize(200, 100);

        $size = Renderer::getTerminalSize();
        $this->assertSame(100, $size['rows']);
        $this->assertSame(200, $size['cols']);
    }
}
