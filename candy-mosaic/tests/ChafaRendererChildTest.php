<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Renderer\ChafaRenderer;

/**
 * E722 (round 82) child-e2e pins for ChafaRenderer — the lib's ONLY
 * external children (the AsyncRenderer interface's fork-style backend is
 * an unimplemented design note; see its docblock).
 *
 * The posture being pinned is child ACCOUNTING end-to-end against a fake
 * chafa planted on PATH:
 *
 *  - render() drains stdout to EOF, maps a non-zero exit to a typed
 *    failure, and leaves NO live or zombie child behind (proc_close reaps);
 *  - the child's stderr is INHERITED, never a reader-less pipe — a flood
 *    larger than the 64KB kernel buffer cannot wedge the render the way it
 *    would if the descriptor spec ever grew an unread `2 => pipe` entry
 *    (sugar-reel's F7 deadlock, structurally mirrored here);
 *  - available() probes without leaking a pipe or a process.
 *
 * The flood pin runs under a `timeout -s KILL` leash (r69 law: a wait-loop
 * that can only hang must be bounded by its supervisor) so the regression
 * it guards reads RED-FAST, never as a suite-wide hang.
 *
 * This file deliberately uses NO real chafa: the binary is absent on CI,
 * and the fake keeps every pin deterministic.
 */
final class ChafaRendererChildTest extends TestCase
{
    /** Bytes the fake floods to stderr — 4x the 64KB kernel pipe buffer. */
    private const FLOOD_BYTES = 256 * 1024;

    /** Wall leash for the flooded render harness. */
    private const HARNESS_TIMEOUT_SECONDS = 15;

    /** 1x1 red GIF, base64 — same fixture the sibling ChafaRendererTest uses. */
    private const GIF_B64 = 'R0lGODlhAQABAIAAAMLCwgAAACH5BAAAAAAALAAAAAABAAEAAAICRAEAOw==';

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
        if ($this->fakePidFile !== null && is_file($this->fakePidFile)) {
            // Last-resort hermetic teardown: a leaked fake child (mutation
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
                // Recursive: the fake dir holds the script and the pidfile.
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
     * @testdox render() drains the child, returns its stdout verbatim, and leaves no live or zombie child
     */
    public function testRenderDrainsStdoutAndReapsTheChild(): void
    {
        $this->plantFakeChafa();
        putenv('FAKE_CHAFA_MODE=ok');

        $out = (new ChafaRenderer())->render($this->source(), 10, 10);

        $this->assertSame('MOCK-ANSI-BYTES', $out, 'render() must return the child stdout verbatim');
        $this->assertChildReaped();
    }

    /**
     * @testdox render() maps a non-zero child exit to the command_failed RuntimeException
     */
    public function testRenderMapsNonZeroExitToRuntimeException(): void
    {
        $this->plantFakeChafa();
        putenv('FAKE_CHAFA_MODE=fail');

        try {
            (new ChafaRenderer())->render($this->source(), 10, 10);
            $this->fail('a failing child must surface as RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('exit code 3', $e->getMessage());
        }

        $this->assertChildReaped();
    }

    /**
     * @testdox available() probes the child, returns true on exit 0, and reaps it
     */
    public function testAvailableProbeReapsItsChild(): void
    {
        $this->plantFakeChafa();

        ChafaRenderer::reset();
        $this->assertTrue(ChafaRenderer::available(), 'a chafa on PATH must probe available');
        $this->assertChildReaped();
    }

    /**
     * @testdox the descriptor spec pipes ONLY stdout — stderr is never a reader-less pipe
     *
     * Structural half of the flood posture: the render() spec is a
     * single-entry array. Add `2 => ['pipe','w']` without a reader and
     * ffmpeg… chafa dies exactly like sugar-reel's F7 deadlock — this pin
     * reads red at the source, and the leashed flood test below reads red
     * at the behaviour.
     */
    public function testRenderDescriptorSpecIsStdoutPipeOnly(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../src/Renderer/ChafaRenderer.php');

        $this->assertStringContainsString(
            "\$descriptorSpec = [1 => ['pipe', 'w']];",
            $source,
            'render() must hold exactly one pipe — stdout',
        );
        $this->assertStringNotContainsString(
            '$pipes[2]',
            $source,
            'nothing may read (or ignore) a captured stderr pipe',
        );
    }

    /**
     * @testdox a 256KiB stderr flood cannot wedge a render — proven inside a leashed child
     *
     * Behavioural half of E722's mosaic posture. The real chafa is absent on
     * CI and rendering inside THIS process would let the flood hit PHPUnit's
     * own stderr, so the render runs in a `php -r` child whose stderr is a
     * FILE sink (unbounded — the flood can never block on it). The fake
     * floods 256KiB to stderr, then answers on stdout. Today that lands in
     * milliseconds. If the spec ever grows an unread stderr PIPE, the fake
     * blocks at ~64KiB, stdout never closes, the harness parks inside
     * render(), the leash fires, and the missing DONE marker reads RED —
     * bounded, never a hang.
     */
    public function testStderrFloodRenderCompletesInALeashedChild(): void
    {
        if (!$this->timeoutBinaryAvailable()) {
            $this->markTestSkipped('timeout(1) not present — cannot leash the flood harness');
        }

        $this->plantFakeChafa();
        putenv('FAKE_CHAFA_MODE=flood');

        $errLog = $this->touch('chafa-flood-err');
        // Generated code carries NO $-variables: the format string is
        // single-quoted, and `\$` there is a literal backslash that would
        // land in the child's source as a parse error.
        $code = sprintf(
            'require %s; echo (new \%s())->render(\%s::fromString(base64_decode(%s)), 10, 10) . "|HARNESS-DONE";',
            var_export(__DIR__ . '/../vendor/autoload.php', true),
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

        $this->assertSame(
            'FLOODED-ANSI-OUTPUT|HARNESS-DONE',
            $stdout,
            'the flooded child must still deliver its stdout and reach DONE inside the leash',
        );
        $this->assertSame(0, $exit, 'the leashed harness must exit cleanly');
        $this->assertGreaterThanOrEqual(
            self::FLOOD_BYTES,
            filesize($errLog),
            'the flood must have really been written — an empty sink would void the polarity',
        );
        $this->assertChildReaped();
    }

    // ---------------------------------------------------------------------

    /**
     * Put an executable fake `chafa` first on PATH, publishing its pid to a
     * file so every test can prove the parent actually reaped it.
     */
    private function plantFakeChafa(): void
    {
        $dir = sys_get_temp_dir() . '/q12-fake-chafa-' . getmypid() . '-' . uniqid();
        mkdir($dir, 0700);
        $this->cleanup[] = $dir;

        $script = <<<'SH'
#!/bin/sh
echo "$$" > "$FAKE_CHAFA_PIDFILE"
if [ "$1" = "--version" ]; then
    echo "chafa 99.9 (sugar-mosaic E722 test fake)"
    exit 0
fi
case "$FAKE_CHAFA_MODE" in
    fail)
        printf 'MOCK-ANSI-BYTES'
        exit 3
        ;;
    flood)
        # 256KiB > the 64KB kernel pipe buffer: any reader-less stderr pipe
        # would block the write here and deadlock the render above it.
        dd if=/dev/zero bs=1024 count=256 2>/dev/null | tr '\0' 'x' >&2
        printf 'FLOODED-ANSI-OUTPUT'
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
     * The fake publishes its own pid first thing; after any ChafaRenderer
     * call returns, that pid must be gone from the kernel table entirely —
     * not live, not zombie (a dropped-but-unreaped child keeps /proc/<pid>
     * with State Z).
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

    private function touch(string $prefix): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), $prefix);
        $this->cleanup[] = $path;

        return $path;
    }

    private function timeoutBinaryAvailable(): bool
    {
        $status = 127;
        exec('command -v timeout >/dev/null 2>&1', $out, $status);

        return $status === 0;
    }
}
