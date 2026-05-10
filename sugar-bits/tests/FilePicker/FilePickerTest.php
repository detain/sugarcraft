<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Tests\FilePicker;

use SugarCraft\Bits\FilePicker\Entry;
use SugarCraft\Bits\FilePicker\FilePicker;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use PHPUnit\Framework\TestCase;

final class FilePickerTest extends TestCase
{
    /** @var string */ private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'candybits-fp-' . bin2hex(random_bytes(4));
        mkdir($this->root);
        mkdir($this->root . '/sub');
        file_put_contents($this->root . '/a.txt', 'a');
        file_put_contents($this->root . '/b.md',  'b');
        file_put_contents($this->root . '/.hidden', 'h');
        file_put_contents($this->root . '/sub/inner.txt', 'i');
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->root);
    }

    private function rmrf(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) as $name) {
                if ($name === '.' || $name === '..') continue;
                $this->rmrf($path . DIRECTORY_SEPARATOR . $name);
            }
            rmdir($path);
            return;
        }
        unlink($path);
    }

    private function focused(): FilePicker
    {
        $p = FilePicker::new($this->root);
        [$p, ] = $p->focus();
        return $p;
    }

    public function testListsCwdEntries(): void
    {
        $p = FilePicker::new($this->root);
        $names = array_map(static fn(Entry $e) => $e->name, $p->entries);
        // Directories first.
        $this->assertSame(['sub', 'a.txt', 'b.md'], $names);
    }

    public function testHiddenHiddenByDefault(): void
    {
        $p = FilePicker::new($this->root);
        $names = array_map(static fn(Entry $e) => $e->name, $p->entries);
        $this->assertNotContains('.hidden', $names);
    }

    public function testShowHiddenIncludesDotfiles(): void
    {
        $p = FilePicker::new($this->root)->withShowHidden(true);
        $names = array_map(static fn(Entry $e) => $e->name, $p->entries);
        $this->assertContains('.hidden', $names);
    }

    public function testAllowedExtensionsFilters(): void
    {
        $p = FilePicker::new($this->root)->withAllowedExtensions(['txt']);
        $names = array_map(static fn(Entry $e) => $e->name, $p->entries);
        $this->assertSame(['sub', 'a.txt'], $names);
    }

    public function testEnterDescendsIntoDir(): void
    {
        $p = $this->focused();
        // sub is index 0
        [$p, ] = $p->update(new KeyMsg(KeyType::Enter));
        $this->assertSame(rtrim($this->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sub', $p->cwd);
        $names = array_map(static fn(Entry $e) => $e->name, $p->entries);
        $this->assertSame(['inner.txt'], $names);
    }

    public function testBackspaceAscends(): void
    {
        $p = $this->focused();
        [$p, ] = $p->update(new KeyMsg(KeyType::Enter));   // descend into sub
        [$p, ] = $p->update(new KeyMsg(KeyType::Backspace)); // pop back
        $this->assertSame(rtrim($this->root, DIRECTORY_SEPARATOR), $p->cwd);
    }

    public function testEnterOnFileSelectsIt(): void
    {
        $p = $this->focused();
        // Move past the 'sub' dir to 'a.txt'.
        [$p, ] = $p->update(new KeyMsg(KeyType::Down));
        [$p, ] = $p->update(new KeyMsg(KeyType::Enter));
        $expected = rtrim($this->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'a.txt';
        $this->assertSame($expected, $p->selected());
    }

    public function testFileDisallowedSkipsSelection(): void
    {
        $p = $this->focused()->withFileAllowed(false);
        [$p, ] = $p->update(new KeyMsg(KeyType::Down));
        [$p, ] = $p->update(new KeyMsg(KeyType::Enter));
        $this->assertNull($p->selected());
    }

    public function testDirAllowedSelectsOnDescend(): void
    {
        $p = $this->focused()->withDirAllowed(true);
        [$p, ] = $p->update(new KeyMsg(KeyType::Enter));
        $this->assertNotNull($p->selected());
        $this->assertStringEndsWith('sub', $p->selected());
    }

    public function testRootSlashCwdIsPreserved(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            $this->markTestSkipped('POSIX-only path test');
        }
        // Whether or not the process can read /, setting cwd to it must
        // not collapse to '' (which would crash scandir on PHP 8+).
        $p = FilePicker::new('/');
        $this->assertSame('/', $p->cwd);
    }

    public function testSetCwdPreservesRoot(): void
    {
        $p = FilePicker::new($this->root)->setCwd(DIRECTORY_SEPARATOR);
        $this->assertSame(DIRECTORY_SEPARATOR, $p->cwd);
    }

    public function testCursorStaysInRange(): void
    {
        $p = $this->focused();
        for ($i = 0; $i < 50; $i++) {
            [$p, ] = $p->update(new KeyMsg(KeyType::Down));
        }
        $this->assertSame(count($p->entries) - 1, $p->cursor);
    }

    public function testHeightAccessor(): void
    {
        $p = FilePicker::new($this->root, 7);
        $this->assertSame(7, $p->height());
    }

    public function testHighlightedPathReturnsAbsolute(): void
    {
        $p = $this->focused();
        $entry = $p->highlightedEntry();
        $this->assertNotNull($entry);
        $expected = $entry->path($p->cwd);
        $this->assertSame($expected, $p->highlightedPath());
    }

    public function testHighlightedPathNullWhenEmpty(): void
    {
        $emptyDir = sys_get_temp_dir() . '/candy-fp-empty-' . bin2hex(random_bytes(4));
        mkdir($emptyDir);
        try {
            $p = FilePicker::new($emptyDir, 5);
            $this->assertNull($p->highlightedPath());
        } finally {
            @rmdir($emptyDir);
        }
    }

    public function testDirectoryFirstEnabledByDefault(): void
    {
        $p = FilePicker::new($this->root);
        $names = array_map(static fn(Entry $e) => $e->name, $p->entries);
        // 'sub' is a directory, should appear before files even though 'a' < 's' alphabetically
        $this->assertSame(['sub', 'a.txt', 'b.md'], $names);
    }

    public function testDirectoryFirstCanBeDisabled(): void
    {
        $p = FilePicker::new($this->root)->withDirectoryFirst(false);
        $names = array_map(static fn(Entry $e) => $e->name, $p->entries);
        // With directory-first disabled, alphabetical ordering applies
        $this->assertSame(['a.txt', 'b.md', 'sub'], $names);
    }

    public function testDirectoryFirstToggleRestoresOrder(): void
    {
        $p = FilePicker::new($this->root)->withDirectoryFirst(false);
        $names = array_map(static fn(Entry $e) => $e->name, $p->entries);
        $this->assertSame(['a.txt', 'b.md', 'sub'], $names);

        $p = $p->withDirectoryFirst(true);
        $names = array_map(static fn(Entry $e) => $e->name, $p->entries);
        $this->assertSame(['sub', 'a.txt', 'b.md'], $names);
    }
}
