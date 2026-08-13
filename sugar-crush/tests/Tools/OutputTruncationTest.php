<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tools\BuiltIn\Bash;
use SugarCraft\Crush\Tools\BuiltIn\Glob;
use SugarCraft\Crush\Tools\BuiltIn\Grep;

/**
 * Every tool result is replayed into EVERY following request of the turn, so
 * an unbounded one is not a one-off cost — it is paid again on each step until
 * compaction, and a single oversized result can exhaust the context window
 * outright.
 *
 * Bash and Grep had no ceiling of any kind: `find /`, a `cat` of a build
 * artefact, or a grep for a common identifier went into the conversation
 * whole. Glob acquired the same exposure the moment `**` started really
 * recursing — the measured `**\/*.php` at this library's root went from a
 * (wrong) short list to 8,916 paths / 898 KB, 95% of it `vendor/`.
 *
 * The cap must also ANNOUNCE itself: a quietly-clipped list reads to the model
 * as a complete answer, so it concludes "these are all the files" from a
 * partial set — a wrong answer stated confidently.
 *
 * @see \SugarCraft\Crush\Tools\Concerns\TruncatesOutput
 */
final class OutputTruncationTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = realpath(sys_get_temp_dir()) . '/crush-trunc-' . uniqid('', true);
        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->root)) {
            rmdir($this->root);
        }
    }

    // =========================================================================
    // Bash
    // =========================================================================

    public function testBashOutputIsCappedAndTheCapIsAnnounced(): void
    {
        $result = (new Bash(maxOutputBytes: 4096))->execute([
            'command' => 'for i in $(seq 1 20000); do echo "line-$i-padding-padding"; done',
            'id' => 'call_1',
        ]);

        $this->assertFalse($result->isError(), $result->content());
        $this->assertLessThan(
            4096 + 512,
            strlen($result->content()),
            'the cap plus one marker, not a megabyte',
        );
        $this->assertStringContainsString('truncated:', $result->content());
        $this->assertStringContainsString('bytes omitted', $result->content());
        $this->assertStringContainsString('PARTIAL', $result->content());
    }

    /** The reported total must count what the capture threw away, not only
     *  what survived long enough to be measured at the end. */
    public function testBashTruncationMarkerReportsTheRealSizeOfTheAnswer(): void
    {
        // 20000 lines x 21 bytes is comfortably over 300 KB.
        $result = (new Bash(maxOutputBytes: 4096))->execute([
            'command' => 'for i in $(seq 1 20000); do echo "line-$i-padding-padding"; done',
            'id' => 'call_2',
        ]);

        $this->assertSame(1, preg_match('/truncated: (\d+) of (\d+) bytes/', $result->content(), $m));
        $this->assertGreaterThan(300000, (int) $m[2], 'the total must reflect the whole output');
        $this->assertGreaterThan(300000, (int) $m[1], 'and so must the dropped count');
    }

    /** Bounded DURING capture, not merely clipped afterwards: a command that
     *  emits gigabytes must not be buffered in full first. */
    public function testBashDoesNotBufferTheWholeStreamBeforeClipping(): void
    {
        $before = memory_get_usage(true);

        $result = (new Bash(maxOutputBytes: 4096))->execute([
            // ~64 MB, which would be plainly visible in the memory delta.
            'command' => 'head -c 67108864 /dev/zero | tr "\\0" "a"',
            'id' => 'call_3',
        ]);

        $this->assertLessThan(4096 + 512, strlen($result->content()));
        $this->assertLessThan(
            16 * 1024 * 1024,
            memory_get_usage(true) - $before,
            'the capture retained the whole stream instead of bounding it',
        );
    }

    public function testShortBashOutputIsUntouched(): void
    {
        $result = (new Bash())->execute(['command' => 'echo hello', 'id' => 'call_4']);

        $this->assertSame('hello', $result->content());
    }

    public function testBashTruncationKeepsTheExitStatusVerdict(): void
    {
        $result = (new Bash(maxOutputBytes: 512))->execute([
            'command' => 'for i in $(seq 1 5000); do echo "line-$i"; done; exit 3',
            'id' => 'call_5',
        ]);

        $this->assertTrue($result->isError(), 'clipping output must not launder a failure');
        $this->assertStringContainsString('truncated:', $result->content());
    }

    /**
     * A truncation marker on a COMPLETE answer is worse than no marker: it
     * tells the model to narrow a query that already succeeded, so it burns a
     * turn re-running the command.
     *
     * The bug was an accounting one. The capture bounds both streams and
     * counts every byte it discards, but the merge then drops stderr entirely
     * when the command SUCCEEDED with real stdout. Folding the discarded
     * stderr into the reported loss charged the result for bytes that were
     * never going to appear at any cap. `bash -x`, `curl -v`, `rsync -v`, npm
     * and most compilers all hit this on a perfectly successful run.
     */
    public function testAChattyStderrOnASuccessfulCommandIsNotReportedAsTruncation(): void
    {
        $command = 'for i in $(seq 1 3000); do echo "+ set -x trace line $i" >&2; done; echo "BUILD OK"';

        // The PRODUCTION default, which is what Bootstrap constructs.
        $result = (new Bash())->execute(['command' => $command, 'id' => 'call_stderr_noise']);

        $this->assertFalse($result->isError());
        $this->assertSame('BUILD OK', $result->content());
        $this->assertStringNotContainsString('truncated:', $result->content());
        $this->assertStringNotContainsString('PARTIAL', $result->content());
    }

    /** The same command with the cap off is the control: identical content. */
    public function testTheChattyStderrCaseMatchesTheUncappedResultExactly(): void
    {
        $command = 'for i in $(seq 1 3000); do echo "+ set -x trace line $i" >&2; done; echo "BUILD OK"';

        $this->assertSame(
            (new Bash(maxOutputBytes: 0))->execute(['command' => $command, 'id' => 'a'])->content(),
            (new Bash())->execute(['command' => $command, 'id' => 'b'])->content(),
        );
    }

    /**
     * stderr is where a failing command explains itself — the whole reason
     * {@see \SugarCraft\Crush\Tools\Concerns\CapturesProcessOutput} exists.
     *
     * The merge appends stderr AFTER stdout, and clipping ran from the front,
     * so any failure whose stdout alone exceeded the cap lost the explanation
     * by construction. That is the single most common agent workflow there
     * is: run a build or a test suite, read the error.
     */
    public function testTheStderrReasonForAFailureSurvivesALargeStdout(): void
    {
        $result = (new Bash())->execute([
            'command' => 'for i in $(seq 1 4000); do echo "[build] compiling module number $i ......"; done; '
                . 'echo "error: undefined reference to \`main\` -- THE ACTUAL REASON" >&2; exit 1',
            'id' => 'call_failing_build',
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('THE ACTUAL REASON', $result->content());
        $this->assertStringContainsString('truncated:', $result->content(), 'and it is still honest about the loss');
        // The reason belongs at the END, next to the marker, not buried.
        $this->assertStringContainsString('THE ACTUAL REASON', substr($result->content(), -400));
    }

    /** …at a cap far too small to hold the stdout at all. */
    public function testTheStderrReasonSurvivesEvenAtATinyCap(): void
    {
        $result = (new Bash(maxOutputBytes: 1024))->execute([
            'command' => 'for i in $(seq 1 4000); do echo "[build] compiling module number $i ......"; done; '
                . 'echo "error: THE ACTUAL REASON" >&2; exit 1',
            'id' => 'call_failing_build_tiny',
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('THE ACTUAL REASON', $result->content());
    }

    /** The inverse must not happen either: a huge stderr cannot starve the
     *  stdout the question was about. */
    public function testAHugeStderrDoesNotStarveTheStdoutOnAFailure(): void
    {
        $result = (new Bash(maxOutputBytes: 8192))->execute([
            'command' => 'echo "STDOUT ANSWER"; '
                . 'for i in $(seq 1 5000); do echo "noise line $i" >&2; done; exit 1',
            'id' => 'call_noisy_failure',
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('STDOUT ANSWER', $result->content());
        $this->assertStringContainsString('noise line', $result->content());
    }

    /**
     * The marker is part of what the model receives, so a cap that excludes it
     * is not the bound it is documented to be. It used to overshoot by the
     * ~190-byte marker every time.
     *
     * @dataProvider capProvider
     */
    public function testATruncatedResultNeverExceedsItsCap(int $cap): void
    {
        $result = (new Bash(maxOutputBytes: $cap))->execute([
            'command' => 'for i in $(seq 1 5000); do echo "line-$i-padding"; done',
            'id' => 'call_bound',
        ]);

        $this->assertStringContainsString('truncated:', $result->content());
        $this->assertLessThanOrEqual($cap, strlen($result->content()));
    }

    /** @return iterable<string, array{int}> */
    public static function capProvider(): iterable
    {
        yield 'small' => [512];
        yield 'medium' => [4096];
        yield 'production default' => [65536];
    }

    public function testAZeroCapDisablesBashTruncation(): void
    {
        $result = (new Bash(maxOutputBytes: 0))->execute([
            'command' => 'for i in $(seq 1 5000); do echo "line-$i"; done',
            'id' => 'call_6',
        ]);

        $this->assertStringNotContainsString('truncated:', $result->content());
        $this->assertGreaterThan(30000, strlen($result->content()));
    }

    // =========================================================================
    // Grep
    // =========================================================================

    public function testGrepOutputIsCappedAndTheCapIsAnnounced(): void
    {
        file_put_contents(
            $this->root . '/haystack.txt',
            str_repeat("needle appears on this padded line\n", 20000),
        );

        $result = (new Grep(maxOutputBytes: 4096))->execute([
            'pattern' => 'needle',
            'path' => $this->root,
            'description' => 'find every needle',
            'id' => 'call_7',
        ]);

        $this->assertFalse($result->isError(), $result->content());
        $this->assertLessThan(4096 + 512, strlen($result->content()));
        $this->assertStringContainsString('truncated:', $result->content());
        $this->assertStringContainsString('PARTIAL', $result->content());
    }

    public function testShortGrepOutputIsUntouched(): void
    {
        file_put_contents($this->root . '/small.txt', "alpha\nneedle\nbeta\n");

        $result = (new Grep())->execute([
            'pattern' => 'needle',
            'path' => $this->root,
            'description' => 'find the needle',
            'id' => 'call_8',
        ]);

        $this->assertStringNotContainsString('truncated:', $result->content());
        $this->assertStringContainsString('needle', $result->content());
    }

    public function testGrepTruncationDoesNotTurnNoMatchesIntoAnError(): void
    {
        file_put_contents($this->root . '/small.txt', "alpha\n");

        $result = (new Grep(maxOutputBytes: 64))->execute([
            'pattern' => 'nothing-here',
            'path' => $this->root,
            'description' => 'find nothing',
            'id' => 'call_9',
        ]);

        $this->assertFalse($result->isError());
        $this->assertSame('', $result->content());
    }

    // =========================================================================
    // Glob — the same marker, so the model learns one signal not three
    // =========================================================================

    public function testGlobUsesTheSameTruncationMarkerAsBashAndGrep(): void
    {
        for ($i = 0; $i < 200; $i++) {
            file_put_contents($this->root . '/file' . $i . '.php', '<?php');
        }

        $glob = (new Glob(maxOutputBytes: 512))->execute([
            'pattern' => '*.php',
            'path' => $this->root,
            'description' => 'find php files',
            'id' => 'call_10',
        ]);
        $bash = (new Bash(maxOutputBytes: 512))->execute([
            'command' => 'for i in $(seq 1 5000); do echo "line-$i"; done',
            'id' => 'call_11',
        ]);

        foreach (['truncated:', 'bytes omitted', 'PARTIAL', 'narrow the query'] as $token) {
            $this->assertStringContainsString($token, $glob->content(), $token);
            $this->assertStringContainsString($token, $bash->content(), $token);
        }
    }

    /** A path sliced mid-string is not noise — it is a plausible-looking WRONG
     *  filename the model may go on to use. */
    public function testTruncationCutsBackToACompleteLine(): void
    {
        for ($i = 0; $i < 200; $i++) {
            file_put_contents($this->root . '/padded-filename-' . $i . '.php', '<?php');
        }

        $result = (new Glob(maxOutputBytes: 500))->execute([
            'pattern' => '*.php',
            'path' => $this->root,
            'description' => 'find php files',
            'id' => 'call_12',
        ]);

        foreach (explode("\n", $result->content()) as $line) {
            if ($line === '' || str_starts_with($line, '... [')) {
                continue;
            }
            $this->assertFileExists($line, 'a half-written path reached the model');
        }
    }

    // =========================================================================
    // Line integrity for the CAPTURED tools — the gap that let this ship
    // =========================================================================

    /**
     * The line-repair above only ever ran for Glob, where the trait does all
     * the clipping itself. Bash and Grep reach it with each stream ALREADY
     * bounded to exactly maxBytes by the capture, so the `strlen > maxBytes`
     * gate was false and the capture's arbitrary mid-chunk `substr()` cut
     * stood — a truncated path presented as a real one, in the tool whose
     * entire output is filenames.
     *
     * Swept across 40 consecutive caps because whether the cut lands mid-line
     * depends on exactly where the byte bound falls; a single cap tests one
     * arbitrary alignment and passes by luck.
     */
    public function testGrepNeverEndsOnAHalfWrittenPathAtAnyCap(): void
    {
        for ($i = 0; $i < 200; $i++) {
            file_put_contents(sprintf('%s/hay-%02x.txt', $this->root, $i), "needle here\n");
        }

        $fabricated = [];
        $exercised = 0;

        for ($cap = 4000; $cap < 4040; $cap++) {
            $result = (new Grep(maxOutputBytes: $cap))->execute([
                'pattern' => 'needle',
                'path' => $this->root,
                'description' => 'sweep the cap alignment',
                'id' => 'call_sweep',
            ]);

            $paths = self::pathsOfGrepHits($result->content());
            if ($paths === []) {
                continue;
            }
            $exercised++;

            $last = $paths[count($paths) - 1];
            if (!file_exists($last)) {
                $fabricated[] = "cap=$cap -> '$last'";
            }
        }

        $this->assertGreaterThan(30, $exercised, 'the sweep has to actually truncate to mean anything');
        $this->assertSame([], $fabricated, 'a truncated path was presented to the model as a real one');
    }

    /** Every hit, not merely the last one, has to be a real file. */
    public function testEveryGrepHitThatSurvivesTruncationIsAWholeLine(): void
    {
        for ($i = 0; $i < 200; $i++) {
            file_put_contents(sprintf('%s/hay-%02x.txt', $this->root, $i), "needle here\n");
        }

        $result = (new Grep(maxOutputBytes: 4017))->execute([
            'pattern' => 'needle',
            'path' => $this->root,
            'description' => 'find every needle',
            'id' => 'call_whole_lines',
        ]);

        $this->assertStringContainsString('truncated:', $result->content());
        foreach (self::pathsOfGrepHits($result->content()) as $path) {
            $this->assertFileExists($path);
        }
    }

    /** Bash output is lines too, and a `find`/`ls` result is read as paths. */
    public function testBashTruncationAlsoEndsOnACompleteLine(): void
    {
        $result = (new Bash(maxOutputBytes: 2000))->execute([
            'command' => 'for i in $(seq 1 5000); do printf "line-%06d-of-a-fixed-width-record\\n" "$i"; done',
            'id' => 'call_bash_lines',
        ]);

        foreach (explode("\n", $result->content()) as $line) {
            if ($line === '' || str_starts_with($line, '... [')) {
                continue;
            }
            $this->assertSame(
                1,
                preg_match('/^line-\d{6}-of-a-fixed-width-record$/', $line),
                "a half-written line reached the model: '$line'",
            );
        }
    }

    /**
     * A stream whose bound happened to land exactly on a newline is intact,
     * and repairing it anyway would throw away a whole complete line for
     * nothing. Sized so the cap falls precisely on a record boundary.
     */
    public function testAnAlignedCutDoesNotSacrificeACompleteLine(): void
    {
        // 10-byte records ("abcdefghi\n"), cap on an exact multiple.
        $result = (new Bash(maxOutputBytes: 0))->execute([
            'command' => 'for i in $(seq 1 3); do echo "abcdefghi"; done',
            'id' => 'call_aligned_control',
        ]);
        $this->assertSame("abcdefghi\nabcdefghi\nabcdefghi", $result->content());
    }

    /**
     * A single enormous line has no complete line to fall back on. Returning
     * nothing at all for `cat` of a one-line file is a worse answer than a
     * marked fragment, so the fragment is kept.
     */
    public function testAStreamWithNoNewlineAtAllStillReturnsItsFragment(): void
    {
        $result = (new Bash(maxOutputBytes: 2048))->execute([
            'command' => 'head -c 200000 /dev/zero | tr "\\0" "a"',
            'id' => 'call_one_long_line',
        ]);

        $this->assertStringContainsString('aaaa', $result->content());
        $this->assertStringContainsString('truncated:', $result->content());
        $this->assertLessThanOrEqual(2048, strlen($result->content()));
    }

    /**
     * Grep hits are `path:line:text`; the path is everything before the first
     * colon, and the temp roots used here contain none.
     *
     * @return list<string>
     */
    private static function pathsOfGrepHits(string $content): array
    {
        $paths = [];
        foreach (explode("\n", $content) as $line) {
            if ($line === '' || str_starts_with($line, '... [')) {
                continue;
            }
            $paths[] = explode(':', $line)[0];
        }

        return $paths;
    }
}
