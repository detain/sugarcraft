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
        if (mkdir($this->tmpDir, 0700, true) === false && is_dir($this->tmpDir) === false) {
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

    // ---- withPermissions ---------------------------------------------------

    public function testWithPermissionsPublishesRequestedModeRegardlessOfUmask(): void
    {
        $path = $this->tmpDir . '/token.json';

        $previous = umask(0000);
        try {
            AtomicJsonFile::new($path)->withPermissions(0600)->write(['token' => 'secret']);
        } finally {
            umask($previous);
        }

        // Under a permissive umask fopen('wb') would birth 0666; the explicit
        // mode wins because the temp inode is chmod'ed before it is published.
        $this->assertSame(0600, fileperms($path) & 0777);
    }

    public function testDefaultPermissionsLeaveTheUmaskInCharge(): void
    {
        // Byte-for-byte the pre-existing contract for every consumer that never
        // calls withPermissions(): the published bits are whatever the umask
        // allows, nothing more, nothing less.
        $permissive = $this->tmpDir . '/permissive.json';
        $private = $this->tmpDir . '/private.json';

        $previous = umask(0000);
        try {
            AtomicJsonFile::new($permissive)->write(['x' => 1]);
            $this->assertSame(0666, fileperms($permissive) & 0777);
        } finally {
            umask($previous);
        }

        $previous = umask(0077);
        try {
            AtomicJsonFile::new($private)->write(['x' => 1]);
            $this->assertSame(0600, fileperms($private) & 0777);
        } finally {
            umask($previous);
        }
    }

    public function testWithPermissionsTightensAPreviouslyLooseTarget(): void
    {
        $path = $this->tmpDir . '/loose.json';
        file_put_contents($path, '{"stale":true}');
        chmod($path, 0666);
        $this->assertSame(0666, fileperms($path) & 0777);

        AtomicJsonFile::new($path)->withPermissions(0600)->write(['token' => 'x']);

        // rename() publishes the TEMP's inode, so the old 0666 inode is unlinked
        // outright — tightening needs no chmod-after-publish (which would leave a
        // readable window on the bytes we are trying to protect).
        $this->assertSame(0600, fileperms($path) & 0777);
        $this->assertSame(['token' => 'x'], AtomicJsonFile::new($path)->read());
    }

    public function testWithPermissionsDerivesATraversableDirectoryMode(): void
    {
        $dir = $this->tmpDir . '/shared';

        // A hostile-to-group umask is the discriminating shape: mkdir()'s mode is
        // umask-filtered, so 0750 only survives here because write() re-asserts
        // the derived directory mode on the directory it just created.
        $previous = umask(0077);
        try {
            AtomicJsonFile::new($dir . '/state.json')->withPermissions(0640)->write(['x' => 1]);
        } finally {
            umask($previous);
        }

        $this->assertSame(0750, fileperms($dir) & 0777);
        $this->assertSame(0640, fileperms($dir . '/state.json') & 0777);
    }

    public function testDefaultDirectoryModeStaysPrivateWhenNoModeIsRequested(): void
    {
        $dir = $this->tmpDir . '/private-default';

        $previous = umask(0000);
        try {
            AtomicJsonFile::new($dir . '/state.json')->write(['x' => 1]);
        } finally {
            umask($previous);
        }

        // 0700 even under a null umask — the class's own legacy floor, unchanged.
        $this->assertSame(0700, fileperms($dir) & 0777);
    }

    public function testWithPermissionsNeverRePermissionsAnExistingDirectory(): void
    {
        $dir = $this->tmpDir . '/owned';
        mkdir($dir, 0700, true);

        AtomicJsonFile::new($dir . '/state.json')->withPermissions(0644)->write(['x' => 1]);

        $this->assertSame(0700, fileperms($dir) & 0777);
        $this->assertSame(0644, fileperms($dir . '/state.json') & 0777);
    }

    public function testWithPermissionsIsImmutableAndKeepsTheConfinedPath(): void
    {
        $base = $this->tmpDir . '/base';
        mkdir($base, 0700, true);

        $store = AtomicJsonFile::new($base . '/../base/state.json', $base);
        $private = $store->withPermissions(0600);

        $this->assertNotSame($store, $private);
        $this->assertNull($store->permissions());
        $this->assertSame(0600, $private->permissions());
        // Confinement resolved at new() survives the derive.
        $this->assertSame($store->path(), $private->path());
        // Compared through the SAME normalization confine() applies: it
        // re-anchors onto realpath()'d parent, and on macOS
        // sys_get_temp_dir() spells the base through the /var symlink
        // (/var/folders/...) while realpath collapses it to
        // /private/var/folders/.... realpath() is the identity on Linux, so
        // this pins the identical string equality there; the pin that the
        // `..` detour collapses onto base/state.json stays exact — an
        // uncollapsed or differently-anchored path() reddens it on any host.
        $realBase = realpath($base);
        $this->assertIsString($realBase, 'realpath() could not resolve ' . $base);
        $this->assertSame($realBase . '/state.json', $private->path());
    }

    public function testWithPermissionsRejectsValuesThatAreNotPermissionBits(): void
    {
        $store = AtomicJsonFile::new($this->tmpDir . '/state.json');

        foreach ([01000, 01777, 04000, -1, 512] as $nonsense) {
            try {
                $store->withPermissions($nonsense);
                $this->fail('mode ' . \decoct($nonsense) . ' must be rejected');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('permission bits', $e->getMessage());
            }
        }

        // The ceiling itself is fine — a caller asking for 0777 gets 0777.
        $this->assertSame(0777, $store->withPermissions(0777)->permissions());
    }

    /**
     * The atomicity law behind {@see AtomicJsonFile::withPermissions()}: the mode
     * is settled on the temp inode and never bolted on after the publish.
     *
     * Every mode assertion above pins the RESULT; none can see the ORDER, and a
     * refactor that moved the chmod after rename() would keep all of them green
     * while re-opening exactly the world-readable window the method exists to
     * close. So the order is pinned structurally, fail-closed.
     */
    public function testPermissionsAreSettledBeforeThePublishRenameAndNeverAfter(): void
    {
        $src = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Util/AtomicJsonFile.php');

        $write = self::methodBody($src, 'public function write');
        $settler = self::methodBody($src, 'private function applyPermissions');

        $rename = strpos($write, 'rename($tmp, $this->path)');
        $settle = strpos($write, '$this->applyPermissions($tmp)');
        $payload = strpos($write, 'fwrite($handle, $payload)');

        $this->assertIsInt($rename, 'write() no longer publishes with rename($tmp, $this->path)');
        $this->assertIsInt($settle, 'write() no longer settles permissions via applyPermissions($tmp)');
        $this->assertIsInt($payload);

        $this->assertLessThan($rename, $settle, 'permissions must be settled BEFORE the publish rename');
        $this->assertLessThan($payload, $settle, 'no payload byte may land before the mode is set');

        $this->assertStringContainsString('chmod($tmp, $this->fileMode)', $settler);
        // The banned shape: re-permissioning the PUBLISHED path (a chmod after
        // rename means the file was readable as someone else's for a moment).
        $this->assertStringNotContainsString('chmod($this->path', $src);
    }

    /**
     * Slice one method's source out of a file by its signature line, up to the
     * first brace at method indentation (PSR-12 puts every nested block deeper).
     */
    private static function methodBody(string $src, string $signature): string
    {
        $start = strpos($src, $signature);
        if ($start === false) {
            self::fail("AtomicJsonFile no longer declares {$signature}() — update this pin, do not delete it.");
        }

        $end = strpos($src, "\n    }\n", $start);
        if ($end === false) {
            self::fail("Could not find the end of {$signature}().");
        }

        return substr($src, $start, $end - $start);
    }
}
