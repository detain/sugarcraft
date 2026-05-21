<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests\History;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\History\FileHistory;

final class FileHistoryTest extends TestCase
{
    private string $tmpDir;
    private string $historyFile;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sugar_readline_test_' . uniqid();
        mkdir($this->tmpDir);
        $this->historyFile = $this->tmpDir . '/history';
    }

    protected function tearDown(): void
    {
        $files = glob($this->tmpDir . '/*');
        foreach ($files as $file) {
            unlink($file);
        }
        rmdir($this->tmpDir);
    }

    public function testCreatesFileIfAbsent(): void
    {
        $this->assertFileDoesNotExist($this->historyFile);
        new FileHistory($this->historyFile);
        $this->assertFileExists($this->historyFile);
    }

    public function testPushPersistsToFile(): void
    {
        $h = new FileHistory($this->historyFile);
        $h->push('hello');
        $h->push('world');

        $contents = file_get_contents($this->historyFile);
        $this->assertSame("hello\nworld\n", $contents);
    }

    public function testLoadsExistingEntriesOnConstruction(): void
    {
        file_put_contents($this->historyFile, "existing\nentries\n");

        $h = new FileHistory($this->historyFile);

        $this->assertSame('entries', $h->getPrevious());
        $this->assertSame('existing', $h->getPrevious());
    }

    public function testPushIgnoresDuplicateOfLastEntryOnDisk(): void
    {
        file_put_contents($this->historyFile, "hello\n");

        $h = new FileHistory($this->historyFile);
        $h->push('hello'); // duplicate of on-disk last entry — ignored

        $h->getPrevious(); // should still go to 'hello'
        $this->assertNull($h->getNext()); // then back to live
    }

    public function testDuplicateInMemoryPushDoesNotRewriteFile(): void
    {
        $h = new FileHistory($this->historyFile);
        $h->push('first');
        $h->push('second');
        $h->push('first'); // duplicate of in-memory last entry — ignored, no file write

        $contents = file_get_contents($this->historyFile);
        $this->assertSame("first\nsecond\n", $contents);
    }

    public function testClearRemovesEntriesFromMemoryAndFile(): void
    {
        $h = new FileHistory($this->historyFile);
        $h->push('one');
        $h->push('two');
        $h->clear();

        $this->assertNull($h->getPrevious());
        $this->assertSame('', file_get_contents($this->historyFile));
    }

    public function testResetClearsNavigationWithoutClearingEntries(): void
    {
        $h = new FileHistory($this->historyFile);
        $h->push('a');
        $h->push('b');
        $h->getPrevious(); // navigate to 'b'

        $h->reset();

        // Entries still exist.
        $this->assertSame('b', $h->getPrevious());
        $this->assertSame('a', $h->getPrevious());
    }

    public function testEmptyLineNotPersisted(): void
    {
        $h = new FileHistory($this->historyFile);
        $h->push('');
        $h->push('valid');

        $contents = trim(file_get_contents($this->historyFile));
        $this->assertSame('valid', $contents);
    }

    public function testNewlineOnlyLineNotPersisted(): void
    {
        file_put_contents($this->historyFile, "hello\n\nworld\n");

        $h = new FileHistory($this->historyFile);

        // The empty line should be skipped during load.
        $this->assertSame('world', $h->getPrevious());
        $this->assertSame('hello', $h->getPrevious());
    }
}
