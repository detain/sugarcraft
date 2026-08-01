<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Testing\Concerns\TemporaryDirectoryTrait;

/**
 * Test TemporaryDirectoryTrait using the default getTempDirSuffix().
 *
 * This ensures the base implementation of getTempDirSuffix() (which returns
 * 'test') is covered by tests. All other test classes override this method,
 * so the base implementation would be uncovered otherwise.
 */
final class TemporaryDirectoryTraitDefaultSuffixTest extends TestCase
{
    use TemporaryDirectoryTrait;

    // Do NOT override getTempDirSuffix() - use the default from the trait.
    // This is intentional to test the base implementation.

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryDirectory();
    }

    public function testDefaultSuffixIsTest(): void
    {
        // The default getTempDirSuffix() returns 'test'.
        // Verify the temp directory was created with the correct suffix pattern.
        $this->assertStringContainsString('candy-testing-test-', $this->tmpDir);
    }

    public function testTempDirectoryExists(): void
    {
        $this->assertDirectoryExists($this->tmpDir);
    }

    public function testTempDirectoryIsWritable(): void
    {
        $this->assertTrue(is_writable($this->tmpDir));
    }
}
