<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\State;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\State\TreeState;
use SugarCraft\Top\State\TreeStateFile;

final class TreeStateFileTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/candy-top-treestate-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            @chmod($f->getPathname(), 0700);
            $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    public function testDefaultPathPrefersAbsoluteXdgStateHome(): void
    {
        $this->assertSame('/x/state/candy-top/tree-state.json', TreeStateFile::defaultPath(['XDG_STATE_HOME' => '/x/state/', 'HOME' => '/h']));
        $this->assertSame('/h/.local/state/candy-top/tree-state.json', TreeStateFile::defaultPath(['XDG_STATE_HOME' => 'rel', 'HOME' => '/h']), 'relative XDG is ignored');
        $this->assertSame('/h/.local/state/candy-top/tree-state.json', TreeStateFile::defaultPath(['HOME' => '/h']));
        $this->assertNull(TreeStateFile::defaultPath([]));
    }

    public function testMissingFileLoadsEmpty(): void
    {
        $this->assertSame(0, TreeStateFile::new($this->dir . '/none.json')->load(self::NOW)->count());
    }

    public function testRoundTripCreatesPrivateDirectoryAndFile(): void
    {
        $path = $this->dir . '/state/candy-top/tree-state.json';
        $file = TreeStateFile::new($path);
        $this->assertSame($path, $file->path());
        $file->write(TreeState::empty()->remember("systemd\x1fchromium", true), self::NOW);

        $this->assertFileExists($path);
        $this->assertSame(0600, fileperms($path) & 0777);
        $this->assertSame(0700, fileperms(\dirname($path)) & 0777);
        $json = json_decode((string) file_get_contents($path), true);
        $this->assertSame(['version' => 1, 'entries' => [['path' => ['systemd', 'chromium'], 'collapsed' => true, 'at' => self::NOW]]], $json);

        $loaded = $file->load(self::NOW + 10);
        $this->assertTrue($loaded->lookup("systemd\x1fchromium"));
    }

    /** @return iterable<string, array{string}> */
    public static function corrupt(): iterable
    {
        yield 'malformed json' => ['{"version":1,"entries":[{'];
        yield 'scalar' => ['42'];
        yield 'empty' => [''];
        yield 'wrong version' => ['{"version":9,"entries":[]}'];
        yield 'binary' => ["\x00\xff\xfe"];
    }

    /** @dataProvider corrupt */
    public function testCorruptFileLoadsEmpty(string $payload): void
    {
        $path = $this->dir . '/tree-state.json';
        file_put_contents($path, $payload);
        $this->assertSame(0, TreeStateFile::new($path)->load(self::NOW)->count());
    }

    public function testOversizedFileIsIgnored(): void
    {
        $path = $this->dir . '/tree-state.json';
        $entry = '{"path":["a"],"collapsed":true,"at":' . self::NOW . '}';
        file_put_contents($path, '{"version":1,"entries":[' . $entry . ']' . str_repeat(' ', TreeStateFile::MAX_BYTES) . '}');
        $this->assertSame(0, TreeStateFile::new($path)->load(self::NOW)->count());
    }

    public function testUnreadableFileLoadsEmpty(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root reads a 0000 file');
        }
        $path = $this->dir . '/tree-state.json';
        file_put_contents($path, '{"version":1,"entries":[]}');
        chmod($path, 0000);
        $this->assertSame(0, TreeStateFile::new($path)->load(self::NOW)->count());
    }

    public function testDirectoryInPlaceOfTheFileLoadsEmpty(): void
    {
        $path = $this->dir . '/tree-state.json';
        mkdir($path);
        $this->assertSame(0, TreeStateFile::new($path)->load(self::NOW)->count());
    }

    public function testWriteFailureThrowsAndKeepsNothingBehind(): void
    {
        $blocker = $this->dir . '/blocker';
        file_put_contents($blocker, 'x');
        $file = TreeStateFile::new($blocker . '/sub/tree-state.json');
        try {
            $file->write(TreeState::empty()->remember('a', true), self::NOW);
            $this->fail('expected a RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($blocker . '/sub/tree-state.json', $e->getMessage());
        }
        $this->assertSame('x', file_get_contents($blocker));
    }
}
