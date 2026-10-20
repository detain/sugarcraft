<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Renderer\ChafaRenderer;

/**
 * D-07: behavioural pins for the ChafaRenderer stderr sink, bounded stdout
 * drain, and the TERM->KILL reap ladder (survey D-07 / plan S1.6).
 *
 * Before D-07 the chafa child inherited this process's fd 2: its diagnostics
 * scribbled over the live TUI frame and the failure reason was thrown away
 * behind a reasonless "exit code N". The pins here prove the containment
 * end-to-end against a fake chafa planted on PATH (the binary is absent on
 * CI — the established harness of ChafaRendererChildTest, mirrored here):
 *
 *  - a child that spews to stderr and exits 1 -> the exception names the
 *    stderr sentence, and the CALLER's own stderr (captured to a file at
 *    the harness boundary) stays byte-clean;
 *  - the stderr tail is bounded to ChafaRenderer::STDERR_TAIL_BYTES — the
 *    head of a 100KiB spew is gone, the tail marker survives;
 *  - a flood-sized stdout beyond ChafaRenderer::MAX_STDOUT_BYTES is kept-
 *    then-drained-to-null: the child exits 0 (no EPIPE), the result is
 *    exactly cap bytes; the exact-cap boundary itself is pinned too (a
 *    stream of EXACTLY cap bytes returns whole — the clamp cuts nothing at
 *    size == cap);
 *  - a child that ignores SIGTERM after closing stdout is gone within the
 *    reap ladder — pinned inside a `timeout -s KILL` leash (r69 law: a
 *    wait-loop that can only hang must be bounded by its supervisor), so
 *    removing the KILL rung reads RED-fast, never as a suite-wide hang;
 *  - the sink file never outlives a render, on the success or the failure
 *    path (finally unlink).
 *
 * Survey D-07(d) prescribed an fd census; it is met in substance by the
 * sink-file residue scan plus the pid-reap census (the two censuses below),
 * which are exactly the leak shapes a `['file', $path, 'w']` spec can take
 * here — that spec hands the sink fd to the child at exec and holds no
 * parent-side descriptor to close.
 *
 * No real chafa, no pcntl requirement (signal numbers are literals), no
 * wall-clock assertions on the ladder — only its constants and markers.
 */
final class ChafaRendererStderrTest extends TestCase
{
    /** Wall leash for harnesses that could, under a mutation, only hang. */
    private const HARNESS_TIMEOUT_SECONDS = 30;

    /** 1x1 red GIF, base64 — the sibling ChafaRendererChildTest fixture. */
    private const GIF_B64 = 'R0lGODlhAQABAIAAAMLCwgAAACH5BAAAAAAALAAAAAABAAEAAAICRAEAOw==';

    /** Sentence the spew fake routes to stderr — exactly chafa's own wording. */
    private const SPOOL_SENTENCE = 'chafa: Source image dimensions 4096x4096 exceed maximum (256Mpx).';

    private ?string $pathBackup = null;

    /** @var list<string> temp dirs/files to remove in tearDown */
    private array $cleanup = [];

    private ?string $fakePidFile = null;

    protected function tearDown(): void
    {
        if ($this->pathBackup !== null) {
            putenv('PATH=' . $this->pathBackup);
            $this->pathBackup = null;
        }
        putenv('FAKE_CHAFA_MODE');
        putenv('FAKE_CHAFA_PIDFILE');
        if ($this->fakePidFile !== null && is_file($this->fakePidFile)) {
            // Last-resort hermetic teardown: a leaked stubborn fake (mutation
            // shape) must not outlive the test process.
            $pid = (int) file_get_contents($this->fakePidFile);
            if ($pid > 0 && function_exists('posix_kill')) {
                @posix_kill($pid, 9); // 9 literal — no ext-pcntl requirement
            }
        }
        ChafaRenderer::reset();
        ChafaRenderer::resetScratch();
        foreach ($this->cleanup as $path) {
            if (is_dir($path)) {
                foreach ((array) scandir($path) as $entry) {
                    if ($entry !== '.' && $entry !== '..') {
                        @unlink($path . '/' . $entry);
                    }
                }
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        $this->cleanup = [];
        $this->fakePidFile = null;
    }

    /**
     * @testdox D-07: a failing child's stderr sentence reaches the exception while the caller's fd 2 stays clean
     */
    public function testStderrReasonRidesTheExceptionAndNotTheCallersStderr(): void
    {
        $this->requireTimeoutBinary();
        $this->plantFakeChafa();
        putenv('FAKE_CHAFA_MODE=spew');

        // The render runs in a `php -r` child whose OWN stderr we capture to
        // a file the harness owns. Pre-D-07, the fake's sentence would land
        // in that file (inherited fd 2) and the message would be reasonless;
        // post-D-07 the sentence must appear exactly once — inside the
        // exception text printed on stdout — and never in the stderr file.
        $report = $this->runLeashedRender();

        $this->assertStringContainsString('MSG:Chafa command failed: exit code 1: ', $report['stdout']);
        $this->assertStringContainsString(self::SPOOL_SENTENCE, $report['stdout']);
        $this->assertStringNotContainsString(
            self::SPOOL_SENTENCE,
            $report['stderr'],
            'D-07: the child diagnostics belong to the renderer sink + exception tail — never the caller-visible fd 2',
        );
        $this->assertStringContainsString('HARNESS-EXIT-0', $report['stdout']);
    }

    /**
     * @testdox D-07: the exception carries the LAST STDERR_TAIL_BYTES of the spew, not its head
     */
    public function testStderrTailIsBoundedToTheTrailingWindow(): void
    {
        $this->plantFakeChafa();
        putenv('FAKE_CHAFA_MODE=megaspew');

        try {
            (new ChafaRenderer())->render($this->source(), 10, 10);
            $this->fail('a failing child must surface as RuntimeException');
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
        }

        $this->assertStringContainsString('exit code 7', $message);
        $this->assertStringContainsString('TAIL-MARKER-OK', $message, 'the newest diagnostics must survive the window');
        $this->assertStringNotContainsString('HEAD-MARKER-GONE', $message, 'a flood head must not ride into the message');
        $this->assertLessThanOrEqual(
            strlen('Chafa command failed: exit code 7: ') + ChafaRenderer::STDERR_TAIL_BYTES,
            strlen($message),
            'the message is capped by the declared tail window',
        );
    }

    /**
     * @testdox D-07: a quiet failing child keeps the bare reasonless message byte-identical (no trailing colon)
     */
    public function testEmptyStderrKeepsTheBareExitCodeMessage(): void
    {
        $this->plantFakeChafa();
        putenv('FAKE_CHAFA_MODE=fail');

        try {
            (new ChafaRenderer())->render($this->source(), 10, 10);
            $this->fail('a failing child must surface as RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('Chafa command failed: exit code 3', $e->getMessage());
        }
    }

    /**
     * @testdox D-07: stdout past MAX_STDOUT_BYTES is kept-then-drained-to-null; the child still exits 0
     */
    public function testStdoutBeyondTheCapIsDrainedNotBuffered(): void
    {
        $this->plantFakeChafa();
        putenv('FAKE_CHAFA_MODE=bigout');

        $out = (new ChafaRenderer())->render($this->source(), 10, 10);

        $this->assertSame(
            ChafaRenderer::MAX_STDOUT_BYTES,
            strlen($out),
            'the render keeps exactly the capped head of the stream',
        );
        $this->assertSame('y', $out[ChafaRenderer::MAX_STDOUT_BYTES - 1]);
        $this->assertStringNotContainsString('TAIL-AFTER-CAP', $out, 'past the cap the stream is discarded, not kept');
        // Reaching here at all means exit 0: had the drain closed the pipe
        // early, the writer child would have died of EPIPE and render()
        // would have thrown instead.
        $this->assertChildReaped();
    }

    /**
     * @testdox D-07 review pin: a stdout of EXACTLY MAX_STDOUT_BYTES is kept whole and succeeds
     *
     * The s1-6 m4 gap: bigout only ever exercised cap+1MiB, so an
     * off-by-one in the clamp boundary would have passed the suite. This is
     * the success-polarity half of the boundary — the exact-cap child must
     * come back byte-complete, not one byte short. The over-cap pin above
     * stays the discriminator for neutered caps (a removed clamp leaves
     * THIS pin green and reddens bigout, by design).
     */
    public function testStdoutOfExactlyTheCapIsKeptWhole(): void
    {
        $this->plantFakeChafa();
        putenv('FAKE_CHAFA_MODE=exactcap');

        $out = (new ChafaRenderer())->render($this->source(), 10, 10);

        $this->assertSame(
            ChafaRenderer::MAX_STDOUT_BYTES,
            strlen($out),
            'a stream of exactly cap bytes must not lose a byte to the clamp',
        );
        $this->assertSame(
            str_repeat('y', ChafaRenderer::MAX_STDOUT_BYTES),
            $out,
            'every byte of the exact-cap head must be kept, in order',
        );
        $this->assertChildReaped();
    }

    /**
     * @testdox D-07: a child that ignores SIGTERM after EOF is escalated to SIGKILL within the ladder
     */
    public function testStubbornChildIsKilledByTheReapLadder(): void
    {
        $this->requireTimeoutBinary();
        $this->plantFakeChafa();
        putenv('FAKE_CHAFA_MODE=stubborn');

        // The fake closes stdout (so the drain completes), then ignores TERM
        // and sleeps 120s. The ladder must finish in roughly REAP_WAIT_MS +
        // TERM_GRACE_MS — nowhere near the sleep. If the KILL rung is
        // removed, proc_close parks for 120s, the leash fires, DONE is
        // missing, and this reads RED bounded (r69 leash law).
        $report = $this->runLeashedRender();

        $this->assertStringContainsString('exit code 9', $report['stdout'], 'proc_close reports a signalled child as the raw signal number (SIGKILL=9), not shell-style 128+N');
        $this->assertStringContainsString('HARNESS-EXIT-0', $report['stdout'], 'the harness must finish on its own, inside the leash');
        $this->assertChildReaped();
    }

    /**
     * @testdox D-07: the stderr sink file never outlives a render — success or failure path
     */
    public function testSinkFileNeverOutlivesTheRender(): void
    {
        $this->plantFakeChafa();

        $before = $this->sinkFilesInTempDir();

        putenv('FAKE_CHAFA_MODE=ok');
        (new ChafaRenderer())->render($this->source(), 10, 10);
        $this->assertSame([], $this->newSinkFilesSince($before), 'a successful render must leave no sink file');

        putenv('FAKE_CHAFA_MODE=spew');
        try {
            (new ChafaRenderer())->render($this->source(), 10, 10);
            $this->fail('the spewing child must throw');
        } catch (\RuntimeException) {
            // expected — the finally leg still owes us the unlink.
        }
        $this->assertSame([], $this->newSinkFilesSince($before), 'a failing render must leave no sink file either');
    }

    /**
     * @testdox D-07: the containment constants are pinned (cap + tail window), the old unbounded slurp is gone
     */
    public function testContainmentBudgetsAreDeclaredValues(): void
    {
        $this->assertSame(32 * 1024 * 1024, ChafaRenderer::MAX_STDOUT_BYTES);
        $this->assertSame(8 * 1024, ChafaRenderer::STDERR_TAIL_BYTES);

        $source = (string) file_get_contents(__DIR__ . '/../../src/Renderer/ChafaRenderer.php');
        $this->assertSame(
            1,
            substr_count($source, '@unlink($errPath)'),
            'the single finally-leg unlink is the every-path guarantee',
        );
    }

    // ---------------------------------------------------------------------

    /**
     * Run one render() inside a `php -r` child under `timeout -s KILL`
     * leash; the child prints exception text and its exit verdict to
     * stdout, and both streams are handed back.
     *
     * @return array{stdout: string, stderr: string, exit: int}
     */
    private function runLeashedRender(): array
    {
        $errLog = $this->touch('chafa-stderr-harness');
        // The code reaches php -r through proc_open's argv — no shell is in
        // the path — so the child's own $e/$out are safe literals here.
        $code = sprintf(
            'require %s;'
            . ' try {'
            . ' $out = (new \%s())->render(\%s::fromString(base64_decode(%s)), 10, 10);'
            . ' echo "OUT:" . strlen($out) . "|HARNESS-EXIT-0|HARNESS-DONE";'
            . ' exit(0);'
            . ' } catch (\Throwable $e) { echo "MSG:" . $e->getMessage() . "|HARNESS-EXIT-0|HARNESS-DONE"; exit(0); }',
            var_export(__DIR__ . '/../../vendor/autoload.php', true),
            ChafaRenderer::class,
            ImageSource::class,
            var_export(self::GIF_B64, true),
        );
        $process = proc_open(
            ['timeout', '-s', 'KILL', (string) self::HARNESS_TIMEOUT_SECONDS, \PHP_BINARY, '-r', $code],
            [['pipe', 'r'], ['pipe', 'w'], ['file', $errLog, 'w']],
            $pipes,
        );
        $this->assertIsResource($process);

        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        if (is_resource($pipes[0])) {
            fclose($pipes[0]);
        }
        $exit = proc_close($process);

        $this->assertSame(0, $exit, 'the leashed harness must exit cleanly (leash fired = red-fast mutation)');

        return ['stdout' => $stdout, 'stderr' => (string) file_get_contents($errLog), 'exit' => $exit];
    }

    /**
     * Put an executable fake `chafa` first on PATH, publishing its pid to a
     * file so every test can prove the parent actually reaped it. Modes are
     * selected by FAKE_CHAFA_MODE, mirroring the sibling child harness.
     */
    private function plantFakeChafa(): void
    {
        $dir = sys_get_temp_dir() . '/d07-fake-chafa-' . getmypid() . '-' . uniqid();
        mkdir($dir, 0700);
        $this->cleanup[] = $dir;

        $script = <<<'SH'
#!/bin/sh
echo "$$" > "$FAKE_CHAFA_PIDFILE"
if [ "$1" = "--version" ]; then
    echo "chafa 99.9 (sugar-image D-07 test fake)"
    exit 0
fi
case "$FAKE_CHAFA_MODE" in
    spew)
        printf '%s\n' "chafa: Source image dimensions 4096x4096 exceed maximum (256Mpx)." >&2
        exit 1
        ;;
    megaspew)
        printf 'HEAD-MARKER-GONE\n' >&2
        dd if=/dev/zero bs=1024 count=100 2>/dev/null | tr '\0' 'z' >&2
        printf 'TAIL-MARKER-OK\n' >&2
        exit 7
        ;;
    fail)
        exit 3
        ;;
    stubborn)
        printf 'STUBBORN'
        exec 1>&-
        # Ignored dispositions survive exec(2): TERM can never touch this
        # child, only the KILL rung can end the render.
        trap '' 15
        exec sleep 120
        ;;
    bigout)
        # Cap + 1MiB of 'y', then a marker that must never be kept.
        dd if=/dev/zero bs=1048576 count=33 2>/dev/null | tr '\0' 'y'
        printf 'TAIL-AFTER-CAP'
        exit 0
        ;;
    exactcap)
        # EXACTLY the cap (32 x 1MiB) of 'y' — the boundary the clamp must
        # not cut; the over-cap half lives in bigout above.
        dd if=/dev/zero bs=1048576 count=32 2>/dev/null | tr '\0' 'y'
        exit 0
        ;;
    *)
        printf 'MOCK-ANSI-BYTES'
        exit 0
        ;;
esac
SH;

        $fake = $dir . '/chafa';
        file_put_contents($fake, $script);
        chmod($fake, 0700);

        $this->fakePidFile = $dir . '/child.pid';
        putenv('FAKE_CHAFA_PIDFILE=' . $this->fakePidFile);

        $this->pathBackup = (string) getenv('PATH');
        putenv('PATH=' . $dir . PATH_SEPARATOR . $this->pathBackup);
    }

    private function source(): ImageSource
    {
        return ImageSource::fromString((string) base64_decode(self::GIF_B64));
    }

    /**
     * The fake publishes its own pid first thing; after render() ends (or
     * throws) that pid must be gone from the kernel table entirely — not
     * live, not zombie.
     */
    private function assertChildReaped(): void
    {
        $this->assertNotNull($this->fakePidFile, 'a fake child pid file must have been armed');
        $this->assertFileExists($this->fakePidFile, 'the fake chafa must have started (pidfile written)');
        $pid = (int) file_get_contents($this->fakePidFile);
        $this->assertGreaterThan(0, $pid);

        $deadline = microtime(true) + 2.0;
        while (is_dir('/proc/' . $pid) && microtime(true) < $deadline) {
            usleep(10_000);
        }
        $this->assertDirectoryDoesNotExist(
            '/proc/' . $pid,
            'ChafaRenderer must reap its child (proc_close) — a live or zombie entry means an owner-reap leak',
        );
    }

    /** @return list<string> every sink file currently living in the temp dir */
    private function sinkFilesInTempDir(): array
    {
        // scandir + str_starts_with, never a glob() pattern.
        $found = [];
        foreach ((array) scandir(sys_get_temp_dir()) as $entry) {
            if (is_string($entry) && str_starts_with($entry, 'schafaerr')) {
                $found[] = $entry;
            }
        }

        return $found;
    }

    /**
     * @param list<string> $before
     *
     * @return list<string>
     */
    private function newSinkFilesSince(array $before): array
    {
        return array_values(array_diff($this->sinkFilesInTempDir(), $before));
    }

    private function touch(string $prefix): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), $prefix);
        $this->cleanup[] = $path;

        return $path;
    }

    private function requireTimeoutBinary(): void
    {
        $status = 127;
        exec('command -v timeout >/dev/null 2>&1', $out, $status);
        if ($status !== 0) {
            $this->markTestSkipped('timeout(1) not present — cannot leash the harness');
        }
    }
}
