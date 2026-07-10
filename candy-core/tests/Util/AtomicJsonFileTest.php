<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\AtomicJsonFile;

final class AtomicJsonFileTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . \DIRECTORY_SEPARATOR
            . 'candy-core-atomicjson-' . bin2hex(random_bytes(8));
        if (!mkdir($this->tmpDir, 0700, true) && !is_dir($this->tmpDir)) {
            $this->fail("Could not create temp dir: {$this->tmpDir}");
        }
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpDir);
    }

    private function rmrf(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->rmrf($path . \DIRECTORY_SEPARATOR . $entry);
        }
        @rmdir($path);
    }

    public function testWriteThenReadRoundTrip(): void
    {
        $path = $this->tmpDir . '/state.json';
        $store = AtomicJsonFile::new($path);

        $data = ['scores' => [10, 20, 30], 'name' => 'ada', 'nested' => ['on' => true]];
        $store->write($data);

        $this->assertSame($data, $store->read());
    }

    public function testReadMissingFileReturnsEmptyArray(): void
    {
        $store = AtomicJsonFile::new($this->tmpDir . '/does-not-exist.json');

        $this->assertSame([], $store->read());
    }

    public function testWriteCreatesMissingParentDirectory(): void
    {
        $path = $this->tmpDir . '/nested/deeper/state.json';
        $store = AtomicJsonFile::new($path);

        $this->assertDirectoryDoesNotExist(\dirname($path));

        $store->write(['ok' => true]);

        $this->assertDirectoryExists(\dirname($path));
        $this->assertSame(['ok' => true], $store->read());
    }

    public function testWriteLeavesNoTempFilesBehind(): void
    {
        $path = $this->tmpDir . '/state.json';
        $store = AtomicJsonFile::new($path);

        $store->write(['a' => 1]);

        $entries = array_values(array_diff(scandir($this->tmpDir) ?: [], ['.', '..']));

        // After a successful atomic write the directory must contain ONLY the
        // target file — no orphaned `.state.json.tmp.*` sidecar.
        $this->assertSame(['state.json'], $entries);
    }

    public function testReadNonArrayTopLevelThrows(): void
    {
        $path = $this->tmpDir . '/scalar.json';
        file_put_contents($path, '42');
        $store = AtomicJsonFile::new($path);

        $this->expectException(\RuntimeException::class);

        $store->read();
    }

    public function testReadMalformedJsonThrows(): void
    {
        $path = $this->tmpDir . '/broken.json';
        file_put_contents($path, '{not: valid');
        $store = AtomicJsonFile::new($path);

        $this->expectException(\JsonException::class);

        $store->read();
    }

    public function testOverwriteReplacesContents(): void
    {
        $path = $this->tmpDir . '/state.json';
        $store = AtomicJsonFile::new($path);

        $store->write(['version' => 1, 'stale' => 'old']);
        $store->write(['version' => 2]);

        // The second write must fully replace, not merge, the first.
        $this->assertSame(['version' => 2], $store->read());
    }

    public function testWriteUsesPrettyPrintAndUnescapedSlashes(): void
    {
        $path = $this->tmpDir . '/state.json';
        $store = AtomicJsonFile::new($path);

        $store->write(['url' => 'https://example.com/a/b']);
        $raw = (string) file_get_contents($path);

        // Human-diffable: pretty-printed (newlines) and slashes not escaped.
        $this->assertStringContainsString("\n", $raw);
        $this->assertStringContainsString('https://example.com/a/b', $raw);
        $this->assertStringNotContainsString('\\/', $raw);
    }

    public function testPathAccessor(): void
    {
        $path = $this->tmpDir . '/state.json';
        $this->assertSame($path, AtomicJsonFile::new($path)->path());
    }

    public function testExistsAccessor(): void
    {
        $path = $this->tmpDir . '/state.json';
        $store = AtomicJsonFile::new($path);

        $this->assertFalse($store->exists());

        $store->write(['x' => 1]);

        $this->assertTrue($store->exists());
    }

    public function testBaseDirConfinementAllowsPathInside(): void
    {
        $path = $this->tmpDir . '/inside.json';
        $store = AtomicJsonFile::new($path, $this->tmpDir);

        $store->write(['ok' => true]);

        $this->assertSame(['ok' => true], $store->read());
    }

    public function testBaseDirConfinementAllowsNestedExistingSubdir(): void
    {
        $sub = $this->tmpDir . '/sub';
        mkdir($sub, 0700, true);

        $store = AtomicJsonFile::new($sub . '/state.json', $this->tmpDir);
        $store->write(['ok' => true]);

        $this->assertSame(['ok' => true], $store->read());
    }

    public function testBaseDirConfinementRejectsDotDotEscape(): void
    {
        $base = $this->tmpDir . '/base';
        mkdir($base, 0700, true);

        $this->expectException(\RuntimeException::class);

        // base/../secret.json resolves to $this->tmpDir/secret.json — outside base.
        AtomicJsonFile::new($base . '/../secret.json', $base);
    }

    public function testBaseDirConfinementRejectsSymlinkedTargetEscape(): void
    {
        if (!\function_exists('symlink')) {
            $this->markTestSkipped('symlink() unavailable on this platform');
        }

        $base = $this->tmpDir . '/base';
        $outside = $this->tmpDir . '/outside';
        mkdir($base, 0700, true);
        mkdir($outside, 0700, true);

        $secret = $outside . '/secret.json';
        file_put_contents($secret, '{"leak": true}');

        $link = $base . '/link.json';
        if (!@symlink($secret, $link)) {
            $this->markTestSkipped('symlink() not permitted on this platform');
        }

        $this->expectException(\RuntimeException::class);

        // Parent (base) is confined, but the final component is a symlink
        // pointing outside the base — must be rejected.
        AtomicJsonFile::new($link, $base);
    }

    public function testBaseDirConfinementAllowsSymlinkedTargetInside(): void
    {
        if (!\function_exists('symlink')) {
            $this->markTestSkipped('symlink() unavailable on this platform');
        }

        $base = $this->tmpDir . '/base';
        mkdir($base, 0700, true);

        $realTarget = $base . '/real.json';
        file_put_contents($realTarget, '{"ok": true}');

        $link = $base . '/link.json';
        if (!@symlink($realTarget, $link)) {
            $this->markTestSkipped('symlink() not permitted on this platform');
        }

        // A symlink whose target stays inside the base is accepted.
        $store = AtomicJsonFile::new($link, $base);
        $this->assertSame(['ok' => true], $store->read());
    }

    public function testBaseDirConfinementRejectsMissingBaseDir(): void
    {
        $this->expectException(\RuntimeException::class);

        AtomicJsonFile::new($this->tmpDir . '/x.json', $this->tmpDir . '/no-such-base');
    }
}
