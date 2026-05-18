<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plugin;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Plugin\ExternalModule;

/**
 * Integration tests for ExternalModule with a real subprocess.
 *
 * Uses the echo-plugin.sh fixture to verify end-to-end plugin protocol
 * communication without any mocks.
 */
final class ExternalModuleRoundTripTest extends TestCase
{
    private string $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixturePath = __DIR__ . '/../fixtures/echo-plugin.sh';

        if (!file_exists($this->fixturePath)) {
            $this->markTestSkipped('echo-plugin.sh fixture not found');
        }

        if (!is_executable($this->fixturePath)) {
            $this->markTestSkipped('echo-plugin.sh is not executable');
        }
    }

    public function testInitSendsInitRequestAndReturnsResponse(): void
    {
        $module = new ExternalModule('test-plugin', $this->fixturePath);

        $result = $module->init();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('name', $result);
        $this->assertArrayHasKey('minSize', $result);
        $this->assertArrayHasKey('interval', $result);
        // ExternalModule uses configured name when fixture doesn't override
        $this->assertSame('test-plugin', $result['name']);
        $this->assertSame([30, 4], $result['minSize']);
        $this->assertSame(1, $result['interval']);
    }

    public function testUpdateSendsUpdateRequestAndReturnsState(): void
    {
        $module = new ExternalModule('test-plugin', $this->fixturePath);
        $module->init();

        $state = ['tick' => 5];
        $result = $module->update($state);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('tick', $result);
        // Echo plugin increments tick by 1 each update
        $this->assertSame(6, $result['tick']);
    }

    public function testViewSendsViewRequestAndReturnsContent(): void
    {
        $module = new ExternalModule('test-plugin', $this->fixturePath);
        $module->init();

        $content = $module->view(['tick' => 10], 80, 24);

        $this->assertIsString($content);
        $this->assertNotEmpty($content);
        $this->assertStringContainsString('tick:', $content);
    }

    public function testMultipleUpdatesIncrementTickEachTime(): void
    {
        $module = new ExternalModule('test-plugin', $this->fixturePath);
        $module->init();

        $state1 = $module->update(['tick' => 0]);
        $state2 = $module->update($state1);
        $state3 = $module->update($state2);

        $this->assertSame(1, $state1['tick']);
        $this->assertSame(2, $state2['tick']);
        $this->assertSame(3, $state3['tick']);
    }

    public function testViewResponseContainsTickFromState(): void
    {
        $module = new ExternalModule('test-plugin', $this->fixturePath);
        $module->init();

        // Update state first to set tick
        $module->update(['tick' => 7]);

        // View should reflect the current tick value
        $content = $module->view(['tick' => 7], 80, 24);

        $this->assertStringContainsString('7', $content);
    }

    public function testNameReturnsConfiguredName(): void
    {
        $module = new ExternalModule('my-custom-name', $this->fixturePath);

        $this->assertSame('my-custom-name', $module->name());
    }

    public function testMinSizeReturnsDefaultMinSize(): void
    {
        $module = new ExternalModule('test-plugin', $this->fixturePath);

        $minSize = $module->minSize();

        $this->assertSame([30, 4], $minSize);
    }

    public function testDestructorCleansUpProcessWithoutError(): void
    {
        $module = new ExternalModule('test-plugin', $this->fixturePath);
        $module->init();

        // Should not throw - destructor cleans up gracefully
        unset($module);

        // If we get here without errors, the test passes
        $this->assertTrue(true);
    }

    public function testInitFailureThrowsRuntimeException(): void
    {
        $module = new ExternalModule('test-plugin', '/nonexistent-binary');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to start process/');

        $module->init();
    }
}
