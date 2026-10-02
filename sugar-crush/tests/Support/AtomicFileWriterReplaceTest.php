<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Support\AtomicFileWriter;

/**
 * {@see AtomicFileWriter::replace()} — the identity-keeping publish the
 * Edit/Write tools use (audit F-T7). The tool-level behaviour is pinned by
 * {@see \SugarCraft\Crush\Tests\Tools\BuiltIn\AtomicEditWriteTest}; this file
 * pins the routes only the writer itself can be asked about directly.
 */
final class AtomicFileWriterReplaceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = (string) realpath((string) sys_get_temp_dir()) . '/sugarcrush_afw_replace_' . uniqid((string) getmypid(), true);
        mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (array_diff((array) scandir($this->dir), ['.', '..']) as $name) {
            @unlink($this->dir . '/' . $name);
        }
        @rmdir($this->dir);
    }

    public function testReplacePublishesExactBytesOverAnExistingFileAndLeavesNoTemp(): void
    {
        $target = $this->dir . '/f.txt';
        file_put_contents($target, 'old');
        chmod($target, 0o751);

        AtomicFileWriter::replace($target, "new\0bytes");

        clearstatcache();
        self::assertSame("new\0bytes", file_get_contents($target));
        self::assertSame(0o751, fileperms($target) & 0o7777);
        self::assertSame(['f.txt'], $this->entries());
    }

    public function testADanglingSymlinkIsCreatedThroughAndSurvives(): void
    {
        $link = $this->dir . '/link';
        symlink($this->dir . '/made.txt', $link);

        AtomicFileWriter::replace($link, 'through');

        clearstatcache();
        self::assertTrue(is_link($link));
        self::assertSame('through', file_get_contents($this->dir . '/made.txt'));
    }

    /**
     * The hard-link fallback writes over the old bytes before it truncates, so
     * a failure part-way leaves the new prefix over the old tail. That is
     * damage — the reason it is only a fallback — but never an empty file,
     * which truncate-first file_put_contents() made of every interrupted write.
     */
    public function testAnInPlaceWriteThatFailsPartWayNeverLeavesTheFileEmpty(): void
    {
        $a = $this->dir . '/a.txt';
        file_put_contents($a, 'AAAAAAAAAA');
        link($a, $this->dir . '/b.txt');

        $caught = null;
        try {
            AtomicFileWriter::replace($a, 'BBBB', static function ($handle, string $contents): void {
                fwrite($handle, substr($contents, 0, 2));
                throw new \LogicException('injected');
            });
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught, 'a failed in-place write must raise');
        self::assertStringContainsString($a, $caught->getMessage());
        self::assertInstanceOf(\LogicException::class, $caught->getPrevious(), 'a non-runtime seam failure is wrapped, not lost');
        clearstatcache();
        self::assertSame('BBAAAAAAAA', file_get_contents($a));
        self::assertSame(2, stat($a)['nlink'], 'the link is never split');
    }

    public function testAMissingParentIsAnErrorNamingTheTargetNotAMkdir(): void
    {
        $target = $this->dir . '/nope/f.txt';

        $caught = null;
        try {
            AtomicFileWriter::replace($target, 'x');
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught);
        self::assertStringContainsString($target, $caught->getMessage());
        self::assertDirectoryDoesNotExist($this->dir . '/nope', 'replace() leaves directory creation to its caller, unlike write()');
    }

    /** @return list<string> */
    private function entries(): array
    {
        $names = array_values(array_diff((array) scandir($this->dir), ['.', '..']));
        sort($names);

        return $names;
    }
}
