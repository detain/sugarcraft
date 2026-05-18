<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\State;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\State\Persistence;

final class PersistenceTest extends TestCase
{
    private string $tmpDir;
    private Persistence $persistence;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sugar-dash-persistence-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0755, true);
        $this->persistence = new Persistence();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = array_diff(scandir($dir), ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    public function testSaveAndLoadRoundTrip(): void
    {
        $path = $this->tmpDir . '/state.json';
        $data = [
            'collapsedPanels' => ['0', '1'],
            'activeTab' => 'weather',
            'width' => 120,
        ];

        $this->persistence->save($path, $data);
        $loaded = $this->persistence->load($path);

        $this->assertNotNull($loaded);
        $this->assertSame($data, $loaded);
    }

    public function testLoadReturnsNullForNonexistentFile(): void
    {
        $path = $this->tmpDir . '/nonexistent.json';
        $loaded = $this->persistence->load($path);

        $this->assertNull($loaded);
    }

    public function testSaveCreatesIntermediateDirectories(): void
    {
        $path = $this->tmpDir . '/nested/deep/state.json';
        $this->persistence->save($path, ['key' => 'value']);

        $this->assertFileExists($path);
        $loaded = $this->persistence->load($path);
        $this->assertSame(['key' => 'value'], $loaded);
    }

    public function testSaveAtomicity(): void
    {
        $path = $this->tmpDir . '/atomic.json';
        $data = ['test' => 'value'];

        $this->persistence->save($path, $data);

        // Verify the file contains valid JSON with version wrapper
        $content = file_get_contents($path);
        $decoded = json_decode($content, true);

        $this->assertArrayHasKey('version', $decoded);
        $this->assertArrayHasKey('data', $decoded);
        $this->assertSame(1, $decoded['version']);
        $this->assertSame($data, $decoded['data']);
    }

    public function testSaveAndLoadWithEmptyArray(): void
    {
        $path = $this->tmpDir . '/empty.json';
        $this->persistence->save($path, []);
        $loaded = $this->persistence->load($path);

        $this->assertNotNull($loaded);
        $this->assertSame([], $loaded);
    }
}
