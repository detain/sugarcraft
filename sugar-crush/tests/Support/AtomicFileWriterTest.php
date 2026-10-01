<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Support\AtomicFileWriter;

/**
 * Pins for the raw-bytes sibling of candy-core AtomicJsonFile (audit M3).
 *
 * The class exists because the memory system persists markdown, not JSON —
 * AtomicJsonFile's array-in/JSON-out contract does not fit — so everything
 * that made the JSON writer trustworthy must hold here too: temp-then-rename
 * publishing, mode settled before the first payload byte, parents created
 * private, and failure loud with the target named.
 */
final class AtomicFileWriterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/afw_test_' . uniqid((string) getmypid(), true);
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($this->dir . '/' . $entry);
            }
        }
        @rmdir($this->dir);
    }

    public function testWritePublishesExactBytesAndLeavesNoTemp(): void
    {
        $target = $this->dir . '/note.md';
        $payload = "---\nid: x\n---\nbody ✓ with multibyte";

        AtomicFileWriter::write($target, $payload, 0600);

        $this->assertSame($payload, file_get_contents($target));
        clearstatcache();
        $this->assertSame(0o600, fileperms($target) & 0o777);
        $leftovers = array_values(array_filter(
            scandir($this->dir) ?: [],
            static fn(string $entry): bool => str_contains($entry, '.tmp.'),
        ));
        $this->assertSame([], $leftovers, 'the uniquely-named temp must be renamed away, not left beside the target');
    }

    public function testWriteCreatesMissingParentRecursivelyAtPrivateMode(): void
    {
        $target = $this->dir . '/scope/deep/index.md';

        AtomicFileWriter::write($target, 'content', 0600);

        $this->assertFileExists($target);
        clearstatcache();
        $this->assertSame(0o700, fileperms($this->dir . '/scope/deep') & 0o777);
    }

    public function testNullModeLeavesTheUmaskToDecideLikeTheDirectWriteItReplaced(): void
    {
        // Documented polarity of the $mode=null default (mirrors
        // AtomicJsonFile::withPermissions): no chmod is attempted, so the
        // ambient umask shapes the file exactly as file_put_contents did.
        $target = $this->dir . '/umask-shaped.md';
        $previous = umask(0o022);

        try {
            AtomicFileWriter::write($target, 'bytes');
        } finally {
            umask($previous);
        }

        clearstatcache();
        $this->assertSame(0o644, fileperms($target) & 0o777);
    }

    public function testExplicitModeLandsBeforeThePayloadIsWritten(): void
    {
        // The ordering law is structural, so it is pinned structurally (the
        // AtomicJsonFile precedent): the chmod call must appear between the
        // fopen of the temp and the fwrite of the payload, never after rename.
        $source = (string) file_get_contents(__DIR__ . '/../../src/Support/AtomicFileWriter.php');
        $body = substr($source, (int) strpos($source, 'public static function write'));

        $fopen = (int) strpos($body, 'fopen($tmp');
        $chmod = (int) strpos($body, 'chmod($tmp, $mode)');
        $fwrite = (int) strpos($body, 'fwrite($handle, $contents)');
        $rename = (int) strpos($body, 'rename($tmp, $path)');

        $this->assertGreaterThan(0, $fopen);
        $this->assertGreaterThan($fopen, $chmod, 'mode must settle on the temp inode first');
        $this->assertGreaterThan($chmod, $fwrite, 'payload may only land on an already-private temp');
        $this->assertGreaterThan($fwrite, $rename, 'publish is last');
        $this->assertFalse(strpos($body, 'chmod($path'), 'nothing may be chmod-ed after the publish');
    }

    public function testModeAbovePermissionBitsIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('above 0777');

        AtomicFileWriter::write($this->dir . '/x.md', 'x', 04600);
    }

    public function testUnwritableParentRaisesRuntimeExceptionNamingTheTargetAndCleansTheTemp(): void
    {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('root ignores directory permission bits');
        }

        $target = $this->dir . '/locked.md';
        // Block the PUBLISH the atomic way: the temp cannot even be opened,
        // because the directory carries no write bit.
        chmod($this->dir, 0500);

        // Capture-then-assert (SwallowingCatchCensus law): no assertion may
        // sit inside a try whose catch is wide enough to receive it — and
        // PHPUnit's AssertionFailedError extends RuntimeException, so a
        // fail() standing in this very try would be swallowed by it.
        $caught = null;
        try {
            AtomicFileWriter::write($target, 'payload', 0600);
        } catch (\RuntimeException $e) {
            $caught = $e;
        } finally {
            chmod($this->dir, 0700);
        }

        $this->assertNotNull($caught, 'an unwritable directory must raise, never silently skip');
        $this->assertStringContainsString($target, $caught->getMessage());

        $this->assertFileDoesNotExist($target);
        $leftovers = array_values(array_filter(
            scandir($this->dir) ?: [],
            static fn(string $entry): bool => str_contains($entry, '.tmp.'),
        ));
        $this->assertSame([], $leftovers, 'the failed temp must be unlinked before the throw');
    }

    public function testReplacementOfAnExistingTargetIsCompleteNotTorn(): void
    {
        $target = $this->dir . '/cycle.md';
        AtomicFileWriter::write($target, str_repeat('a', 4096), 0600);
        AtomicFileWriter::write($target, 'short', 0600);

        // A direct-write crash mode leaves prefix debris; rename swaps the
        // whole inode, so the only observable states are old-full/new-full.
        $this->assertSame('short', file_get_contents($target));
    }
}
