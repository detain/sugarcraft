<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Share;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Share\JsonlTranscriptWriter;

/**
 * C-SES-8: the append-only JSONL transcript journal.
 *
 * Pins the four promises of the design block — append-only growth, an
 * idempotent re-save, a rewind written as a marker line instead of a rewrite,
 * and private modes (file 0600, dir 0700) — plus the `pathFor` naming seam
 * hooks consume (C-SES-8.g) and the `enabled()` process flag its wiring sets.
 *
 * Fixtures are plain array rows: {@see \SugarCraft\Code\Util\Exporter::toJsonl()}
 * passes an unmapped value straight through, so the journal framing is tested
 * without a Chat or provider behind it.
 */
final class JsonlTranscriptWriterTest extends TestCase
{
    private string $tempDir;
    private string $baseDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/cc-ses8f-' . getmypid() . '-' . uniqid('', true);
        $this->baseDir = $this->tempDir . '/journals';
        self::assertFalse(
            JsonlTranscriptWriter::enabled(),
            'the journal is opt-in; nothing may arm the flag before this test',
        );
    }

    protected function tearDown(): void
    {
        JsonlTranscriptWriter::setEnabled(false);
        JsonlTranscriptWriter::pinDefaultRoot(null);
        $this->removeFixtureTree($this->tempDir);
    }

    public function testReSaveAppendsOnlyTheRowsPastTheHighWaterMark(): void
    {
        $writer = new JsonlTranscriptWriter($this->baseDir);
        $path = JsonlTranscriptWriter::pathFor('sess-a', '/home/sites/two-projects', $this->baseDir);

        $first = self::rows(2);
        self::assertTrue($writer->save('sess-a', $first, '/home/sites/two-projects'));
        $before = (string) file_get_contents($path);
        self::assertSame(2, substr_count($before, "\n"));

        $grown = self::rows(4);
        self::assertTrue($writer->save('sess-a', $grown, '/home/sites/two-projects'));
        $after = (string) file_get_contents($path);

        // Append-only: every byte of the earlier journal is still the head.
        self::assertSame($before, substr($after, 0, strlen($before)));
        self::assertSame(4, substr_count($after, "\n"));
        $tail = substr($after, strlen($before));
        $tailLines = array_values(array_filter(explode("\n", $tail), static fn(string $l): bool => $l !== ''));
        self::assertCount(2, $tailLines, 'exactly the two new rows were appended');
        foreach ($tailLines as $line) {
            self::assertIsArray(json_decode($line, true));
        }
    }

    public function testIdempotentReSaveLeavesTheJournalByteUnchanged(): void
    {
        $writer = new JsonlTranscriptWriter($this->baseDir);
        $path = JsonlTranscriptWriter::pathFor('sess-a', null, $this->baseDir);

        $rows = self::rows(3);
        self::assertTrue($writer->save('sess-a', $rows));
        $once = (string) file_get_contents($path);

        self::assertTrue($writer->save('sess-a', $rows));
        self::assertSame($once, (string) file_get_contents($path));

        self::assertTrue($writer->save('sess-a', $rows));
        self::assertSame($once, (string) file_get_contents($path));
    }

    public function testRewindAppendsAMarkerLineInsteadOfRewritingTheFile(): void
    {
        $writer = new JsonlTranscriptWriter($this->baseDir);
        $path = JsonlTranscriptWriter::pathFor('sess-a', null, $this->baseDir);

        self::assertTrue($writer->save('sess-a', self::rows(3)));
        $three = (string) file_get_contents($path);

        self::assertTrue($writer->save('sess-a', self::rows(1)));
        $marked = (string) file_get_contents($path);

        // Nothing was rewritten; one marker line was appended.
        self::assertSame($three, substr($marked, 0, strlen($three)));
        self::assertSame($three . '{"type":"rewind","to":1}' . "\n", $marked);

        // The mark re-sets: growing from 1 appends only rows past message 1,
        // on top of the three original lines plus the marker.
        self::assertTrue($writer->save('sess-a', self::rows(2)));
        $grown = (string) file_get_contents($path);
        self::assertSame(5, substr_count($grown, "\n"));
        $lines = explode("\n", rtrim($grown, "\n"));
        self::assertSame('{"type":"rewind","to":1}', $lines[3]);
        self::assertSame('{"role":"user","content":"msg-2"}', $lines[4]);

        // Docblock truth (review-1): the prose must not claim the journal
        // replays as the export — after this rewind the file holds m1,m2,m3
        // plus the marker, while the live session is a shorter branch.
        $doc = (string) (new \ReflectionClass(JsonlTranscriptWriter::class))->getDocComment();
        self::assertStringNotContainsString('replays as the export', $doc);
        self::assertStringContainsString('including rewound branches', $doc);
    }

    public function testMessageLinesCarryRoleAndNeverTheMarkerFrame(): void
    {
        $writer = new JsonlTranscriptWriter($this->baseDir);
        $path = JsonlTranscriptWriter::pathFor('sess-a', null, $this->baseDir);
        self::assertTrue($writer->save('sess-a', self::rows(2)));

        foreach (explode("\n", rtrim((string) file_get_contents($path), "\n")) as $line) {
            $row = json_decode($line, true);
            self::assertIsArray($row, "journal line decodes: {$line}");
            // The scanHighWater marker door trusts `role` absence plus
            // `type=rewind`; a message row must satisfy neither half at once.
            self::assertArrayHasKey('role', $row);
            self::assertArrayNotHasKey('type', $row);
        }
    }

    public function testFreshJournalFileIsMode0600AndItsDirectory0700(): void
    {
        $writer = new JsonlTranscriptWriter($this->baseDir);
        $path = JsonlTranscriptWriter::pathFor('sess-a', '/home/sites/proj', $this->baseDir);

        self::assertTrue($writer->save('sess-a', self::rows(1), '/home/sites/proj'));

        self::assertSame(0o600, fileperms($path) & 0o777);
        self::assertSame(0o700, fileperms(dirname($path)) & 0o777);
        self::assertSame(0o700, fileperms($this->baseDir) & 0o777);
    }

    public function testSaveOfNothingCreatesNoJournalAndStillSucceeds(): void
    {
        $writer = new JsonlTranscriptWriter($this->baseDir);
        $path = JsonlTranscriptWriter::pathFor('sess-a', null, $this->baseDir);

        self::assertTrue($writer->save('sess-a', []));
        self::assertFileDoesNotExist($path);
        self::assertDirectoryDoesNotExist($this->baseDir);
    }

    public function testPathForNamesTheSessionUnderAProjectSlugWithoutCreating(): void
    {
        $path = JsonlTranscriptWriter::pathFor('sess-9', '/home/sites/sugarcraft', '/base/root');

        self::assertSame('/base/root/sugarcraft/sess-9.jsonl', $path);
        self::assertFileDoesNotExist($path);

        // The pin seam redirects the default root the no-argument pathFor and
        // save use, so hook payloads and journals cannot drift apart.
        JsonlTranscriptWriter::pinDefaultRoot('/pinned/root');
        self::assertSame('/pinned/root/default/y.jsonl', JsonlTranscriptWriter::pathFor('y'));
        self::assertSame('/pinned/root', JsonlTranscriptWriter::defaultRoot());
    }

    public function testPathForContainsIdAndRootShapedLikeClimbs(): void
    {
        $climb = JsonlTranscriptWriter::pathFor('../../etc/passwd', '/home/sites/../evil', '/base');
        self::assertStringStartsWith('/base/', $climb);
        self::assertStringNotContainsString('/../', $climb);

        // No root answers the deterministic `default` slug segment.
        self::assertSame('/base/default/x.jsonl', JsonlTranscriptWriter::pathFor('x', null, '/base'));
    }

    public function testTheEnabledFlagIsProcessDefaultOffAndRoundTrips(): void
    {
        JsonlTranscriptWriter::setEnabled(true);
        self::assertTrue(JsonlTranscriptWriter::enabled());
        JsonlTranscriptWriter::setEnabled(false);
        self::assertFalse(JsonlTranscriptWriter::enabled());
    }

    /**
     * @param positive-int $count
     * @return list<array{role: string, content: string}>
     */
    private static function rows(int $count): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = ['role' => 'user', 'content' => 'msg-' . $i];
        }

        return $rows;
    }

    private function removeFixtureTree(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
