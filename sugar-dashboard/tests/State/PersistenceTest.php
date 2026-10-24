<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\State;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\AtomicJsonFile;
use SugarCraft\Dashboard\State\Persistence;

final class PersistenceTest extends TestCase
{
    private string $tmpDir;
    private Persistence $persistence;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sugar-dashboard-persistence-test-' . bin2hex(random_bytes(4));
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

    /**
     * Regression: save() must persist the `{version, data}` wrapper through the
     * shared sugar-core atomic store, and load() must round-trip it back.
     */
    public function testSaveRoundTripsThroughAtomicJsonFile(): void
    {
        $path = $this->tmpDir . '/roundtrip.json';
        $data = ['panels' => ['a', 'b'], 'active' => 'weather'];

        $this->persistence->save($path, $data);

        $raw = AtomicJsonFile::new($path)->read();
        $this->assertSame(1, $raw['version']);
        $this->assertSame($data, $raw['data']);

        $this->assertSame($data, $this->persistence->load($path));
    }

    /**
     * Regression: the atomic tmp+rename write must leave no temp artifacts
     * behind, even across an overwrite.
     *
     * The contentless lock sidecar (`.<basename>.lock`) is deliberately NOT a
     * temp artifact: sugar-core AtomicJsonFile never deletes it (a delete-on-
     * release path lets a racing writer open the same inode and defeat the
     * lock), so this assertion carves the sidecar pattern out and still bans
     * every tmp residue (`.<basename>.tmp.<hex>`). The sidecar's own 0600 mode
     * is pinned below.
     */
    public function testSaveLeavesNoLeftoverTempFiles(): void
    {
        $path = $this->tmpDir . '/notemp.json';

        $this->persistence->save($path, ['k' => 'v1']);
        $this->persistence->save($path, ['k' => 'v2']);

        $all = array_values(array_diff(scandir($this->tmpDir), ['.', '..']));

        // Keep the sidecar carve to the exact sanctioned shape — a dot-prefixed
        // `*.lock` file; everything else (notably any `.tmp.*` artifact) must
        // still be absent.
        $artifacts = array_values(array_filter(
            $all,
            static fn (string $name): bool => !preg_match('/^\.[^\/]+\.lock$/', $name),
        ));
        $this->assertSame(['notemp.json'], $artifacts, 'atomic save must leave no temp artifacts');

        // The sidecar is present, contentless, and private (0600).
        $sidecar = $this->tmpDir . '/.notemp.json.lock';
        $this->assertFileExists($sidecar);
        $this->assertSame('', file_get_contents($sidecar));
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertSame(0600, fileperms($sidecar) & 0777);
        }
    }

    /**
     * PII pin: panel/tab state can name locations and layouts, so save()
     * publishes private bits — the payload at 0600 and any directory this
     * call creates at the derived 0700 (each read class earns that class's
     * execute bit), mirroring the weather cache's discipline.
     */
    public function testSavePublishesPrivateModesForFileAndCreatedDir(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX permission bits are not honoured on Windows');
        }

        $path = $this->tmpDir . '/privdir/state.json';
        $this->persistence->save($path, ['mode' => 'private']);

        $this->assertSame(0600, fileperms($path) & 0777);
        $this->assertSame(0600, fileperms($this->tmpDir . '/privdir/.state.json.lock') & 0777);
        $this->assertSame(0700, fileperms($this->tmpDir . '/privdir') & 0777);
    }

    /**
     * Regression: a present-but-malformed cache file must fail loudly per the
     * load() contract, not be silently treated as empty state.
     */
    public function testLoadThrowsOnMalformedJson(): void
    {
        $path = $this->tmpDir . '/corrupt.json';
        file_put_contents($path, '{not valid json');

        $this->expectException(\JsonException::class);
        $this->persistence->load($path);
    }

    /**
     * Regression: a valid-JSON but non-array top level (e.g. a bare string)
     * must throw rather than corrupt the loaded shape.
     */
    public function testLoadThrowsOnNonArrayTopLevel(): void
    {
        $path = $this->tmpDir . '/scalar.json';
        file_put_contents($path, '"just a string"');

        $this->expectException(\RuntimeException::class);
        $this->persistence->load($path);
    }
}
