<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;

/**
 * E296's RESIDUAL, PINNED AS A FACT RATHER THAN LEFT AS A SENTENCE.
 *
 * `tests/bootstrap.php` repairs the suite's descriptor 0 by SETTING
 * `O_NONBLOCK` on it — `stream_set_blocking(\STDIN, false)` sets that flag,
 * making the descriptor NON-BLOCKING. (MEASURED, PHP 8.3.6, three takes, from
 * `/proc/self/fdinfo/0` with fd 0 a pipe: the flag is clear at startup, set
 * after `stream_set_blocking($s, false)`, clear again after
 * `stream_set_blocking($s, true)`. The polarity is written out because this
 * doc-block previously had it backwards, and every reader downstream of a
 * backwards mechanism sentence inherits it.)
 *
 * That set flag closes the BLOCKING half of E212/E290 — a spawned
 * `bin/sugarcrush -p` can no longer park forever in `stream_get_contents()` on
 * a runner stdin nobody will ever write to or close — and it does NOT close
 * the PREPEND half. `O_NONBLOCK` changes when a read RETURNS; it does not
 * change what the read returns. Bytes already sitting in the runner's pipe are
 * available, so they are read, and `NonInteractive::historyFrom()` prepends
 * them to the prompt of every spawned `-p` child as stdin context.
 *
 * ## Why this is a test and not a paragraph
 *
 * The residual was already written down, twice, in prose. Prose is not a
 * measurement and does not go red. Round 49 shipped that repair after FOUR
 * attempts, and three of the four were killed by a claim that had been
 * reasoned rather than run. So this file executes the residual: it puts a
 * marker on the runner's stdin, spawns the real binary the way the suite
 * spawns it, and asserts the marker comes back out of the child's prompt.
 *
 * ## IF THIS TEST GOES RED, READ THIS BEFORE YOU FIX IT
 *
 * A red here most likely means someone CLOSED the prepend half — which is a
 * good thing and the outcome E296 wants. The correct response is to delete
 * this test and say in the commit what closed it; it is not to widen the
 * assertion until it passes again. The only repair that closes it is replacing
 * descriptor 0 rather than flagging it, and E290's attempts 1 and 3 are what
 * that costs: PHP has no `dup2`, so replacing fd 0 means closing the `\STDIN`
 * constant, and `SugarCraft\Mosaic\Detect` reads that constant unguarded from
 * a sibling library. MEASURED there, full suite, PHP 8.3.6: `9500 tests, 107
 * errors, rc 2`. {@see \SugarCraft\Crush\Tests\StdinConstantReaderCensusTest}
 * derives which libraries would have to be looked at first.
 *
 * ## The security shape, stated because it is not only untidiness
 *
 * The prepend is a data path from whatever handed this process its descriptor
 * 0 into a prompt sent to a model. In a suite that is a hermeticity bug. On a
 * CI runner that pipes a build log into `phpunit`, it is a build log going to
 * a provider. Recorded on the backlog rather than fixed here, because the fix
 * is the descriptor replacement above and that is one decision, not two.
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
     * THE CONTROL AND THE TREATMENT SHARE ONE HARNESS AND DIFFER IN ONE BYTE
     * STRING, which is what makes either of them mean anything (rule 15).
     *
     * CONTROL — the runner's stdin is an open pipe that is never written. The
     * child must answer (that is the blocking half, closed) and its output
     * must NOT contain the marker. Without this arm, "the marker is in the
     * child's output" would also be satisfied by a harness that echoed its own
     * script back, or by a marker that leaked through the environment.
     *
     * TREATMENT — identical, except the marker is written onto that pipe
     * first and the write end is deliberately LEFT OPEN. Leaving it open is
     * the point: an EOF would make even a blocking read return, so a closed
     * writer would prove nothing about the flag. With the writer still open,
     * the child returning AT ALL is the flag working, and the marker coming
     * back is the residual the flag does not cover.
     */
    public function testBytesOnTheRunnersStdinAreStillPrependedToASpawnedChildsPrompt(): void
    {
        $marker = self::MARKER_HEAD . '-' . self::MARKER_TAIL;

        $control = $this->spawn(null);
        $this->assertSame(
            0,
            $control['rc'],
            "the control spawn did not complete, so this harness cannot say anything about the treatment.\n"
                . $control['raw'],
        );
        $this->assertStringNotContainsString(
            $marker,
            $control['out'],
            'the marker reached the child WITHOUT being written to stdin, so this harness cannot tell a '
                . 'prepend from its own plumbing',
        );
        // The control must also prove the child really ran and really answered
        // - an empty stdout would satisfy the assertion above for free.
        $this->assertStringContainsString(
            'You said:',
            $control['out'],
            "the offline echo provider did not answer, so the child never reached the prompt at all.\n"
                . $control['raw'],
        );

        $treatment = $this->spawn($marker . "\n");
        $this->assertSame(
            0,
            $treatment['rc'],
            "the spawned binary did not complete with bytes waiting on the runner's stdin. If it was killed "
                . "at the bound, tests/bootstrap.php has stopped SETTING O_NONBLOCK on descriptor 0 - the "
                . "repair is the flag being set (stream_set_blocking(\STDIN, false)), not cleared.\n"
                . $treatment['raw'],
        );
        $this->assertStringContainsString(
            $marker,
            $treatment['out'],
            "E296's PREPEND residual is CLOSED. That is the outcome the backlog entry wants, and this test "
                . 'is now wrong: delete it and record what closed it, rather than widening this assertion. '
                . "Child output was:\n" . $treatment['out'],
        );
        // WHERE the marker landed is the claim, not merely that it is present:
        // stdin context is prepended to the prompt, so it must appear BEFORE
        // the prompt text in the echoed turn.
        $this->assertLessThan(
            (int) strrpos($treatment['out'], 'hi'),
            (int) strpos($treatment['out'], $marker),
            'the marker reached the child but not as prepended prompt context, so this test is no longer '
                . 'pinning the shape it was written for',
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
     * Run `bin/sugarcrush -p "hi"` from a script that loaded the bootstrap,
     * in a process whose descriptor 0 is a pipe this test holds.
     *
     * $stdin is written to that pipe before the child is read from, and the
     * write end stays open either way.
     *
     * @return array{rc: int, out: string, raw: string}
     */
    private function spawn(?string $stdin): array
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

        $script = "<?php\ndeclare(strict_types=1);\n"
            . 'require ' . var_export($root . '/tests/bootstrap.php', true) . ";\n"
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
        // $pipes[0] is NOT closed: an EOF would let even a blocking read
        // return, and then the treatment would prove nothing about the flag.

        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);

        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $rc = preg_match('/^R49B-CHILD-RC=(-?\d+)$/m', $out, $m) === 1 ? (int) $m[1] : -1;

        return ['rc' => $rc, 'out' => $out, 'raw' => trim($out . "\n" . $err)];
    }
}
