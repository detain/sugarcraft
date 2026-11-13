<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Format;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Diff\DiffWriter;
use SugarCraft\Vcr\Format\CompressedJsonlFormat;
use SugarCraft\Vcr\Format\Format;
use SugarCraft\Vcr\Format\JsonlFormat;
use SugarCraft\Vcr\Format\RelativeFormat;
use SugarCraft\Vcr\Format\YamlFormat;

/**
 * Atomic placement contract for every cassette/diff writer (finding 018).
 *
 * Bytes land in an exclusively reserved temp in the destination directory,
 * restricted to 0600 BEFORE content, then rename() replaces the directory
 * ENTRY. The observable consequences pinned here: a planted symlink at the
 * destination is never written through; a failed write leaves the
 * destination untouched and no `.candyvcr-*` temp behind; and DiffWriter's
 * $context finally governs hunk size instead of being swallowed.
 */
final class AtomicWriteTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            @chmod($dir, 0700);
            foreach ((array) glob($dir . '/*') as $entry) {
                @unlink((string) $entry);
            }
            @rmdir($dir);
        }
        $this->dirs = [];
    }

    /**
     * Every format writer, plain and gzip: writing over a symlink planted
     * at the destination must replace the entry, not write through it.
     *
     * @return array<string, array{0: Format, 1: string}>
     */
    public static function formatProvider(): array
    {
        return [
            'jsonl' => [new JsonlFormat(), 'rec.cas'],
            'relative' => [new RelativeFormat(), 'rec.cas'],
            'yaml' => [new YamlFormat(), 'rec.yaml'],
            'compressed-plain' => [new CompressedJsonlFormat(), 'rec.cas'],
            'compressed-gz' => [new CompressedJsonlFormat(), 'rec.cas.gz'],
        ];
    }

    #[DataProvider('formatProvider')]
    public function testWriteNeverFollowsASymlinkPlantedAtTheDestination(Format $format, string $name): void
    {
        $dir = $this->scratch();
        $victim = $dir . '/victim.bin';
        $dest = $dir . '/' . $name;
        file_put_contents($victim, 'VICTIM-CONTENT');
        $this->assertTrue(symlink($victim, $dest));

        $format->write($this->stubCassette(), $dest);

        $this->assertSame(
            'VICTIM-CONTENT',
            (string) file_get_contents($victim),
            'rename-based placement must replace the entry, never write through a planted link',
        );
        $this->assertFalse(is_link($dest), 'destination must be a regular file after the write');
        $this->assertNoStrayTemps($dir);
        // The bytes really landed (read back re-encodes identically).
        $this->assertSame(
            $format->encode($this->stubCassette()),
            $format->encode($format->read($dest)),
        );
    }

    public function testFailedMoveLeavesDestinationUntouchedAndNoStrayTemp(): void
    {
        $dir = $this->scratch();
        // A DIRECTORY at the destination: temp reservation in the same dir
        // succeeds, the final rename cannot replace a directory with a file,
        // so this exercises the commit-failure path end to end.
        $slot = $dir . '/blocked.cas';
        $this->assertTrue(mkdir($slot));

        try {
            (new JsonlFormat())->write($this->stubCassette(), $slot);
            $this->fail('a rename onto a directory must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cannot write cassette to', $e->getMessage());
            $this->assertStringContainsString('Failed to move', $e->getMessage());
        }

        $this->assertTrue(is_dir($slot), 'the destination directory must survive the failed write');
        $this->assertSame([], iterator_to_array(new \GlobIterator($dir . '/.candyvcr-*')), 'failed writes must not leak temps');
    }

    public function testUnwritableGzipDestinationThrowsNamedAndLeaksNothing(): void
    {
        $dir = $this->scratch();
        $dest = $dir . '/rec.cas.gz';

        // Read-only directory: the temp reservation fails before any bytes move.
        $this->assertTrue(chmod($dir, 0500));
        try {
            (new CompressedJsonlFormat())->write($this->stubCassette(), $dest);
            $this->fail('a read-only destination directory must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cannot open gzip stream for', $e->getMessage());
        } finally {
            $this->assertTrue(chmod($dir, 0700));
        }

        $this->assertFalse(file_exists($dest));
        $this->assertNoStrayTemps($dir);
    }

    public function testLandedCassettesAreOwnerOnlyFromTheFirstByte(): void
    {
        $dir = $this->scratch();

        // Worst-case ambient umask: mode must still be 0600 because the
        // restriction lands BEFORE content, not after the write.
        $previous = umask(0);
        try {
            (new JsonlFormat())->write($this->stubCassette(), $dir . '/plain.cas');
            (new CompressedJsonlFormat())->write($this->stubCassette(), $dir . '/packed.cas.gz');
        } finally {
            umask($previous);
        }

        $this->assertSame(0600, fileperms($dir . '/plain.cas') & 0777);
        $this->assertSame(0600, fileperms($dir . '/packed.cas.gz') & 0777);
        $this->assertNoStrayTemps($dir);
    }

    public function testGzipWritePreservesEveryByteOfTheEncodedStream(): void
    {
        $dir = $this->scratch();
        $dest = $dir . '/rec.cas.gz';

        (new CompressedJsonlFormat())->write($this->stubCassette(), $dest);

        // The old streaming writer skipped empty lines, so a payload
        // containing one would have silently lost bytes. Whole-buffer
        // gzip must round-trip the exact encoded stream.
        $encoded = (new CompressedJsonlFormat())->encode($this->stubCassette());
        $expanded = '';
        $gz = gzopen($dest, 'rb');
        $this->assertNotFalse($gz);
        while (!gzeof($gz)) {
            $chunk = gzread($gz, 8192);
            if ($chunk === false) {
                break;
            }
            $expanded .= $chunk;
        }
        gzclose($gz);

        $this->assertSame($encoded, $expanded);
    }

    public function testDiffWriterAlsoPlacesAtomically(): void
    {
        $dir = $this->scratch();
        $victim = $dir . '/victim.txt';
        $dest = $dir . '/replay.diff';
        file_put_contents($victim, 'DO-NOT-TOUCH');
        $this->assertTrue(symlink($victim, $dest));

        $written = (new DiffWriter())->writeUnifiedDiff($dest, "a\nb\n", "a\nc\n");

        $this->assertIsInt($written);
        $this->assertSame('DO-NOT-TOUCH', (string) file_get_contents($victim));
        $this->assertFalse(is_link($dest));
        $this->assertNoStrayTemps($dir);
    }

    public function testContextParameterGovernsHunkSize(): void
    {
        $writer = new DiffWriter();

        $expected = [];
        $actual = [];
        for ($i = 1; $i <= 30; $i++) {
            $expected[] = "line-{$i}";
            $actual[] = $i === 5 || $i === 25 ? "CHANGED-{$i}" : "line-{$i}";
        }
        $exp = implode("\n", $expected) . "\n";
        $act = implode("\n", $actual) . "\n";

        $tight = $writer->buildUnifiedDiff($exp, $act, 1);
        $this->assertSame(
            4,
            substr_count($tight, '@@'),
            'context 1 keeps the two far-apart changes in separate hunks (two @@ per header)',
        );

        $wide = $writer->buildUnifiedDiff($exp, $act, 10);
        $this->assertSame(
            2,
            substr_count($wide, '@@'),
            'context 10 bridges the 20-line gap into a single hunk',
        );

        $default = $writer->buildUnifiedDiff($exp, $act);
        $explicitThree = $writer->buildUnifiedDiff($exp, $act, 3);
        $this->assertSame(
            $this->stripTimestamps($default),
            $this->stripTimestamps($explicitThree),
            'the documented default must stay 3',
        );
        // And a context 3 diff of far-apart changes must actually carry the
        // wider trailing surround: line 8 belongs only to the context-3 hunk.
        $this->assertStringContainsString(' line-8', $tight . $wide);
        $this->assertStringContainsString(' line-15', $wide, 'context 10 must reach 15 lines past the first change');
        $this->assertStringNotContainsString(' line-15', $tight, 'context 1 must not print that surround');
    }

    public function testWriteUnifiedDiffFileMatchesBuiltDiffAndHonoursContext(): void
    {
        $dir = $this->scratch();
        $writer = new DiffWriter();
        $dest = $dir . '/out.diff';

        $n = $writer->writeUnifiedDiff($dest, "a\nb\nc\n", "a\nX\nc\n");
        $this->assertIsInt($n);
        $this->assertSame($n, strlen((string) file_get_contents($dest)));

        $farExp = [];
        $farAct = [];
        for ($i = 1; $i <= 40; $i++) {
            $farExp[] = "line-{$i}";
            $farAct[] = $i === 5 || $i === 25 ? "CHANGED-{$i}" : "line-{$i}";
        }
        $far = $writer->writeUnifiedDiff(
            $dir . '/wide.diff',
            implode("\n", $farExp) . "\n",
            implode("\n", $farAct) . "\n",
            20,
        );
        $this->assertIsInt($far);
        $this->assertSame(
            2,
            substr_count((string) file_get_contents($dir . '/wide.diff'), '@@'),
            'the file on disk must carry the merged single hunk context 20 produces',
        );
    }

    private function stubCassette(): Cassette
    {
        return new Cassette(
            new CassetteHeader(1, '2026-01-01T00:00:00+00:00', 80, 24, 'sugarcraft/sugar-core@dev'),
            [],
        );
    }

    private function scratch(): string
    {
        $dir = sys_get_temp_dir() . '/candy-vcr-atomic-' . bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($dir));
        $this->dirs[] = $dir;

        return $dir;
    }

    private function assertNoStrayTemps(string $dir): void
    {
        $this->assertSame(
            [],
            iterator_to_array(new \GlobIterator($dir . '/.candyvcr-*')),
            'no .candyvcr-* temp may survive a completed or failed write',
        );
    }

    /** The date() header stamp changes per second; compare bodies without it. */
    private function stripTimestamps(string $diff): string
    {
        return (string) preg_replace('/\t\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', "\tNOW", $diff);
    }
}
