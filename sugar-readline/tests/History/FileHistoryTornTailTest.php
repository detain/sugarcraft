<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests\History;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\History\FileHistory;

/**
 * S-43 (finding 064): durability + the torn-tail law. Every content-carrying
 * writer fsyncs before releasing its lock, and every decode of file content
 * (load, bounded merge, duplicate-guard tail reads) DROPS an unterminated
 * final line as a crash fragment instead of promoting it to an entry — or
 * letting it screen out the append of the very entry the crash was writing.
 */
final class FileHistoryTornTailTest extends TestCase
{
    private string $dir;
    private string $path;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/srl_torn_' . uniqid('', true);
        mkdir($this->dir, 0700);
        $this->path = $this->dir . '/history.txt';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    private function plant(string $content): void
    {
        file_put_contents($this->path, $content);
        clearstatcache();
    }

    private function diskBytes(): string
    {
        return file_exists($this->path) ? (string) file_get_contents($this->path) : '';
    }

    public function testLoadDropsTheUnterminatedTailFragment(): void
    {
        $this->plant("alpha\nbeta\npartial");
        $history = new FileHistory($this->path, 0);

        // Newest-first in memory; the crash fragment is not an entry.
        $this->assertSame(['beta', 'alpha'], $history->entries());
    }

    public function testRewriteArmDropsTheFragmentInsteadOfTerminatingIt(): void
    {
        // The old normalization appended "\n" to the fragment, re-permanentizing
        // crash garbage as an entry. The rewrite now truncates it away.
        $this->plant("alpha\npartial");
        $history = new FileHistory($this->path, 0);
        $history->push('beta'); // tail not newline-terminated -> rewrite fallback

        $this->assertSame("alpha\nbeta\n", $this->diskBytes());
    }

    public function testTornFragmentNeverScreensOutItsOwnAppend(): void
    {
        // The crash was mid-write of "partial": peek must answer with the last
        // TERMINATED line ("alpha"), so re-pushing "partial" still lands.
        $this->plant("alpha\npartial");
        $history = new FileHistory($this->path, 0);
        $history->push('partial');

        $this->assertSame(['partial', 'alpha'], $history->entries());
        $this->assertSame("alpha\npartial\n", $this->diskBytes());
    }

    public function testBoundedMergeKeepsTheFragmentOutOfTheRewrite(): void
    {
        // Deferred pushes flush through the cap-merge arm: the file is rewritten
        // from the decoded view, and the fragment must not ride in as an entry.
        $this->plant("alpha\nbeta\nfrag");
        $history = new FileHistory($this->path, 5, '', true);
        $history->push('gamma');
        $history->flush();

        $this->assertSame("#sugar-readline-history/v2\nalpha\nbeta\ngamma\n", $this->diskBytes());
        $this->assertStringNotContainsString('frag', $this->diskBytes());
    }

    public function testContentCarryingWritersSyncBeforeReleasingTheLock(): void
    {
        foreach (['appendLinesAtomically', 'rewriteLocked', 'writeAllLocked'] as $writer) {
            $this->assertStringContainsString(
                'self::syncHandle(',
                $this->methodBody($writer),
                "the $writer path must fsync the bytes it just wrote (finding 064)",
            );
        }

        $sync = $this->methodBody('syncHandle');
        $this->assertStringContainsString("function_exists('fsync')", $sync);
        $this->assertTrue(method_exists(FileHistory::class, 'lastNewlineOffset'));
    }

    public function testTailHelpersAnswerWithTheLastTerminatedLine(): void
    {
        $content = new \ReflectionMethod(FileHistory::class, 'tailLineOfContent');
        $content->setAccessible(true);
        $this->assertNull($content->invoke(null, 'frag'));
        $this->assertSame('b', $content->invoke(null, "a\nb\nfrag"));
        $this->assertSame('b', $content->invoke(null, "a\nb\n"));

        $stream = new \ReflectionMethod(FileHistory::class, 'tailLineOfStream');
        $stream->setAccessible(true);
        $fp = fopen('php://temp', 'w+');
        $this->assertNotFalse($fp);
        fwrite($fp, "x\ny\nfrag");
        $this->assertSame('y', $stream->invoke(null, $fp));
        fseek($fp, 0);
        ftruncate($fp, 0);
        fwrite($fp, 'only-frag');
        $this->assertNull($stream->invoke(null, $fp));
        fclose($fp);
    }

    private function methodBody(string $name): string
    {
        $method = new \ReflectionMethod(FileHistory::class, $name);
        $lines = file((string) $method->getFileName());
        $this->assertIsArray($lines);

        return implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    }
}
