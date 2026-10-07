<?php

declare(strict_types=1);

namespace SugarCraft\Diff\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SugarCraft\Diff\Diff;
use SugarCraft\Diff\DiffOptions;
use SugarCraft\Diff\Hunk;
use PHPUnit\Framework\TestCase;

/**
 * GNU hunk-header parity (audit 2026-10-07): zero-length sides anchor at the
 * line the hunk sits AFTER (0 at file start), single-line ranges drop their
 * `,1` count, and `,0` is never dropped.
 *
 * The 14-case corpus in tests/fixtures/gnu-hunk-headers.php was captured from
 * `/usr/bin/diff -U<n>` (trailing-newline files only, so the `\ No newline at
 * end of file` shape is out of scope here) with the '--- '/'+++ ' rows stripped.
 * To regenerate after an intentional writer change:
 *
 *   for each case: write old/new files, run `diff -U<context> old new`,
 *   drop the two file-header rows, escape into the fixture array.
 *
 * The fixture replay needs no external binary; the oracle cross-check only
 * proves the fixture was not hand-edited and skips when diff is unavailable.
 */
#[CoversClass(Diff::class)]
#[CoversClass(Hunk::class)]
final class GnuHunkHeaderParityTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/fixtures/gnu-hunk-headers.php';

    /** @return array<string, array{old: string, new: string, context: int, hunks: string}> */
    public static function corpusRaw(): array
    {
        $cases = require self::FIXTURE;
        self::assertIsArray($cases);

        return $cases;
    }

    /** @return array<string, array{0: string, 1: string, 2: int, 3: string}> */
    public static function corpus(): array
    {
        $out = [];
        foreach (self::corpusRaw() as $name => $case) {
            $out[$name] = [$case['old'], $case['new'], $case['context'], $case['hunks']];
        }

        return $out;
    }

    #[Test]
    #[DataProvider('corpus')]
    public function testHunkTextMatchesTheCapturedGnuOutput(string $old, string $new, int $context, string $hunks): void
    {
        $diff = Diff::compute($old, $new, DiffOptions::new()->withContextLines($context));

        $this->assertSame($hunks, $diff->hunkText(), 'GNU parity broken (see data set name)');
    }

    #[Test]
    public function testCorpusIsNotEmptyAndNamesEveryShape(): void
    {
        $cases = self::corpusRaw();

        // The shapes the old ported rules got wrong must stay represented.
        foreach (['mid-insertion-u0', 'append-u0', 'head-insertion-u0', 'delete-all-u0', 'one-to-one-change-u0'] as $required) {
            $this->assertArrayHasKey($required, $cases);
        }
        $this->assertGreaterThanOrEqual(14, count($cases));
    }

    #[Test]
    public function testFixtureStillAgreesWithTheLiveOracle(): void
    {
        $diffBinary = trim((string) @shell_exec('command -v diff'));
        if ($diffBinary === '' || !is_executable($diffBinary)) {
            $this->markTestSkipped('GNU diff oracle not available on this host');
        }

        $dir = sys_get_temp_dir() . '/sugar-diff-oracle-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);

        try {
            foreach (self::corpusRaw() as $name => $case) {
                $oldPath = "$dir/$name.old";
                $newPath = "$dir/$name.new";
                file_put_contents($oldPath, $case['old']);
                file_put_contents($newPath, $case['new']);

                $process = proc_open(
                    [$diffBinary, '-U' . (string) $case['context'], $oldPath, $newPath],
                    [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
                    $pipes
                );
                $this->assertIsResource($process);
                $stdout = stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                $this->assertSame(1, proc_close($process), "diff exited unexpectedly for '$name'");

                $lines = explode("\n", rtrim((string) $stdout, "\n"));
                while ($lines !== [] && (str_starts_with($lines[0], '---') || str_starts_with($lines[0], '+++'))) {
                    array_shift($lines);
                }
                $expected = $lines === [''] ? '' : implode("\n", $lines) . "\n";

                $this->assertSame($case['hunks'], $expected, "fixture drifted from the live oracle for '$name'");
            }
        } finally {
            foreach (glob("$dir/*") ?: [] as $leftover) {
                @unlink($leftover);
            }
            @rmdir($dir);
        }
    }
}
