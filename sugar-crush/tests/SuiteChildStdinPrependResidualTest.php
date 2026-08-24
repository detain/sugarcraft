<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;

/**
 * E296's PREPEND RESIDUAL IS CLOSED, AND THIS IS THE HARNESS THAT PROVED IT
 * OPEN, RUN IN THE OTHER DIRECTION.
 *
 * ## WHAT THIS FILE SAID, AND WHY IT IS NOT DELETED
 *
 * WHAT THIS SAID: "IF THIS TEST GOES RED, READ THIS BEFORE YOU FIX IT. A red
 * here most likely means someone CLOSED the prepend half — which is a good
 * thing and the outcome E296 wants. The correct response is to delete this test
 * and say in the commit what closed it."
 *
 * WHAT IS TRUE NOW: it did go red, for exactly that reason, and deleting it
 * would have thrown away the only executable apparatus anyone had for the
 * question. WHY THE FILE STILL EARNS ITS PLACE: the instruction was written to
 * forbid WIDENING the assertion until it passed again, and inverting is not
 * widening — the harness now asserts the opposite fact, which is the one that
 * has to stay true from here on. The residual was written down in prose twice
 * before it was ever executed, and prose does not go red; a closed residual
 * left in prose would re-open exactly the same way.
 *
 * ## WHAT CLOSED IT
 *
 * `tests/bootstrap.php` no longer flags descriptor 0 — it REPLACES it, with
 * `fclose(\STDIN)` and a `/dev/null` handle onto the freed fd, parked in
 * `$GLOBALS` so it outlives the method PHPUnit includes that file from.
 *
 * The flag repair (`stream_set_blocking(\STDIN, false)`, which SETS
 * `O_NONBLOCK` — the polarity is spelled out because two rounds of prose here
 * had it backwards) closed the BLOCKING half only. `O_NONBLOCK` changes when a
 * read RETURNS; it does not change what the read returns. Bytes already sitting
 * in the runner's pipe were available, so they were read, and
 * `NonInteractive::historyFrom()` prepended them to the prompt of every spawned
 * `-p` child. `/dev/null` reads empty, so replacing the descriptor closes both
 * halves at once.
 *
 * The price was priced wrong for two rounds at `9500 tests, 107 errors, rc 2`.
 * That run predated the candy-mosaic repairs: it was ONE defect — a fallback
 * naming a `Capability` case candy-palette spells differently — multiplied by
 * every test that reached it, plus the unguarded `?? STDIN` that dropped the
 * library into that fallback in the first place. Both are fixed. RE-MEASURED,
 * PHP 8.3.6, full suite, the descriptor replacement alone against a green
 * `9661 tests / 142165 assertions / 1 skipped / rc 0` baseline at the same
 * head: ONE error and TWO failures, all three in tests whose entire subject is
 * this repair, and not one candy-mosaic error of any kind.
 *
 * ## WHY THIS IS THREE ARMS AND NOT TWO
 *
 * The claim is now an ABSENCE — "the marker does not reach the child" — and an
 * absence is what a harness that never delivered anything also reports (rule
 * 15/E228). The old two-arm shape cannot tell those apart, because with the
 * repair in force BOTH of its arms are marker-free. So there is a third arm
 * whose answer is known: the same spawn, the same marker, from a script that
 * has NOT loaded `tests/bootstrap.php`. That one must come back WITH the
 * marker, and it is the only thing that makes the other two mean anything.
 *
 * ## The security shape, stated because it was never only untidiness
 *
 * The prepend was a data path from whatever handed this process its descriptor
 * 0 into a prompt sent to a model. In a suite that is a hermeticity bug; on a
 * CI runner that pipes a build log into `phpunit`, it is a build log going to a
 * provider, bounded only by `NonInteractive::MAX_STDIN_BYTES` (10MB). The third
 * arm still demonstrates that, one process outside the sandbox, which is the
 * honest way to hold it: piped stdin reaching the prompt is the documented
 * contract for a real `sugarcrush`, and it is only the SUITE that must not
 * inherit the runner's.
 */
final class SuiteChildStdinPrependResidualTest extends TestCase
{
    /**
     * Seconds the spawned binary gets. It answers in ~0.15s from the offline
     * echo provider; a bound this generous fails as a red rather than as a
     * PHPUnit "aborted after 60 seconds" RISKY with no name on it.
     */
    private const BOUND = 30;

    /**
     * The bytes put on the runner's stdin. Built by concatenation so the
     * literal never appears in this file as one string — a later sweep for
     * the marker must not be able to match the file that documents it (rule
     * 26), and the assertions below search the CHILD's output, not this
     * source.
     */
    private const MARKER_HEAD = 'R49B-PREPEND';

    private const MARKER_TAIL = 'RESIDUAL-MARKER';

    /** @var list<string> */
    private array $paths = [];

    private string $home = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->home = sys_get_temp_dir() . '/sc_prepend_home_' . getmypid() . '_' . uniqid((string) getmypid(), true);
        mkdir($this->home, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        $this->paths = [];
        self::removeTree($this->home);

        parent::tearDown();
    }

    /**
     * THREE ARMS THROUGH ONE HARNESS, DIFFERING IN THREE BITS: whether the
     * script loaded `tests/bootstrap.php`, whether the marker was written, and
     * whether the write end was CLOSED. {@see spawn()} takes exactly those
     * three parameters.
     *
     * The sentence here said "TWO BITS" and named the first two, which
     * undercounted the one bit that does the most work: `$closeWriter` is what
     * separates the known-positive from both guarded arms, and the paragraphs
     * below already turn on it twice — the known-positive needs the EOF to
     * terminate at all, and the treatment must NOT have it or the blocking
     * half is untested. Counting it out of the summary makes the harness look
     * like a two-by-two with one cell missing rather than the three arms it is.
     *
     * KNOWN-POSITIVE — no bootstrap, marker written, writer CLOSED. The child
     * must answer AND its output must carry the marker. This is the arm that
     * says the plumbing works: without it, "no marker" below is equally
     * satisfied by a harness that mis-spelled the binary, never started the
     * child, or wrote to the wrong pipe. The writer is closed here on purpose —
     * with no bootstrap there is nothing to keep `stream_get_contents()` from
     * parking forever, and the EOF is what lets the arm terminate. It is
     * therefore NOT evidence about the descriptor; it is evidence about the
     * harness.
     *
     * CONTROL — bootstrap, nothing written, writer left open. The child must
     * answer and carry no marker.
     *
     * TREATMENT — bootstrap, marker written, writer deliberately LEFT OPEN.
     * Leaving it open is load-bearing: an EOF would let even a blocking read
     * return, so a closed writer would prove nothing about the blocking half.
     * With the writer still open, the child returning AT ALL is the blocking
     * half closed, and the marker being ABSENT is the prepend half closed.
     */
    public function testBytesOnTheRunnersStdinDoNotReachASpawnedChildsPrompt(): void
    {
        $marker = self::MARKER_HEAD . '-' . self::MARKER_TAIL;

        $plumbing = $this->spawn($marker . "\n", withBootstrap: false, closeWriter: true);
        $this->assertSame(
            0,
            $plumbing['rc'],
            "the un-bootstrapped known-positive did not complete, so this harness cannot say anything "
                . "about the two arms below.\n" . $plumbing['raw'],
        );
        $this->assertStringContainsString(
            $marker,
            $plumbing['out'],
            'the harness could not deliver a prepend even with NOTHING defending against it, so the '
                . 'absences asserted below are the silence of a dead instrument rather than a repair. '
                . "Check that the child really ran and that the marker really reached its stdin.\n"
                . $plumbing['out'],
        );

        $control = $this->spawn(null, withBootstrap: true, closeWriter: false);
        $this->assertSame(
            0,
            $control['rc'],
            "the control spawn did not complete, so this harness cannot say anything about the treatment.\n"
                . $control['raw'],
        );
        $this->assertStringContainsString(
            'You said:',
            $control['out'],
            "the offline echo provider did not answer, so the child never reached the prompt at all.\n"
                . $control['raw'],
        );
        $this->assertStringNotContainsString(
            $marker,
            $control['out'],
            'the marker reached the child WITHOUT being written to stdin, so this harness cannot tell a '
                . 'prepend from its own plumbing',
        );

        $treatment = $this->spawn($marker . "\n", withBootstrap: true, closeWriter: false);
        $this->assertSame(
            0,
            $treatment['rc'],
            "the spawned binary did not complete with bytes waiting on the runner's stdin. If it was killed "
                . 'at the bound, tests/bootstrap.php has stopped replacing descriptor 0 - the repair is '
                . "fclose(\\STDIN) plus a /dev/null handle on the freed fd, and the child is parking in "
                . "stream_get_contents() on the runner's own pipe.\n" . $treatment['raw'],
        );
        $this->assertStringContainsString(
            'You said:',
            $treatment['out'],
            "the child did not reach the prompt with bytes on the runner's stdin, so its marker-free output "
                . "says nothing.\n" . $treatment['raw'],
        );
        $this->assertStringNotContainsString(
            $marker,
            $treatment['out'],
            "E296's PREPEND residual is OPEN AGAIN: bytes on the RUNNER's descriptor 0 were read by a "
                . 'spawned bin/sugarcrush and prepended to its prompt. The known-positive arm above has '
                . 'already shown this harness can see the difference, so this is the repair, not the test. '
                . 'tests/bootstrap.php must REPLACE descriptor 0 (fclose plus /dev/null); the O_NONBLOCK '
                . "flag that used to be there closes the blocking half only.\nChild output was:\n"
                . $treatment['out'],
        );
    }

    /** Remove a directory tree this test created, contents and all. */
    private static function removeTree(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }

        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                self::removeTree($path);

                continue;
            }
            @unlink($path);
        }

        @rmdir($dir);
    }

    /**
     * Run `bin/sugarcrush -p "hi"` from a script in a process whose descriptor
     * 0 is a pipe this test holds.
     *
     * $stdin is written to that pipe before the child is read from.
     * $withBootstrap is the difference between the known-positive arm and the
     * two guarded ones. $closeWriter exists only for the known-positive: with
     * no bootstrap there is nothing to stop the child parking in
     * `stream_get_contents()`, so that arm needs an EOF to terminate at all,
     * and the guarded arms must NOT have one or they would prove nothing.
     *
     * @return array{rc: int, out: string, raw: string}
     */
    private function spawn(?string $stdin, bool $withBootstrap, bool $closeWriter): array
    {
        $root = \dirname(__DIR__);

        // TMPDIR is forwarded explicitly: the bootstrap sets it for children,
        // and the child of a child needs it named rather than re-derived, or
        // the spawned binary's own startup sweep runs on the machine's real
        // temp directory.
        $command = sprintf(
            'cd %s && TMPDIR=%s HOME=%s timeout -s KILL %d %s %s -p %s 2>&1',
            escapeshellarg($this->home),
            escapeshellarg((string) (getenv('TMPDIR') ?: sys_get_temp_dir())),
            escapeshellarg($this->home),
            self::BOUND,
            escapeshellarg(\PHP_BINARY),
            escapeshellarg($root . '/bin/sugarcrush'),
            escapeshellarg('hi'),
        );

        // TMPDIR is forwarded explicitly on BOTH shapes: the bootstrap is
        // what normally sets it, and the un-bootstrapped arm is precisely
        // the run that has none - without this it would sweep the machine's
        // real temp directory on startup.
        $require = $withBootstrap
            ? var_export($root . '/tests/bootstrap.php', true)
            : var_export($root . '/vendor/autoload.php', true);

        $script = "<?php\ndeclare(strict_types=1);\n"
            . 'require ' . $require . ";\n"
            . '$out = [];' . "\n"
            . '$rc = 0;' . "\n"
            . 'exec(' . var_export($command, true) . ', $out, $rc);' . "\n"
            . 'echo "R49B-CHILD-RC=" . $rc . "\n";' . "\n"
            . 'echo implode("\n", $out), "\n";' . "\n";

        $file = tempnam(sys_get_temp_dir(), 'sc_prepend_probe_' . getmypid() . '_');
        self::assertIsString($file);
        $this->paths[] = $file;
        file_put_contents($file, $script);

        $process = proc_open(
            [\PHP_BINARY, $file],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);

        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
            fflush($pipes[0]);
        }
        if ($closeWriter) {
            // Known-positive arm only. Nothing is defending that child, so
            // without an EOF it parks in stream_get_contents() until the
            // `timeout -s KILL` bound - which is the ORIGINAL hazard, and not
            // what that arm is here to show.
            fclose($pipes[0]);
        }
        // Otherwise $pipes[0] is NOT closed: an EOF would let even a blocking
        // read return, and then the treatment would prove nothing about the
        // descriptor replacement.

        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);

        if (!$closeWriter) {
            fclose($pipes[0]);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $rc = preg_match('/^R49B-CHILD-RC=(-?\d+)$/m', $out, $m) === 1 ? (int) $m[1] : -1;

        return ['rc' => $rc, 'out' => $out, 'raw' => trim($out . "\n" . $err)];
    }
}
