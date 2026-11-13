<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Command;

use PHPUnit\Framework\TestCase;

/**
 * scriptui-06 (candy-scriptui-002/-005): a headless `spin` must never put
 * a key reader on STDIN and never paint DEC-private modes onto a
 * redirected stdout. The old default-program fallback did exactly both —
 * one 0x1B byte piped into `spin -- sleep 5` forged an ESC cancellation
 * (rc 130, live child killed), and a run that captured "nothing" still
 * sprayed ~38 raw mode bytes (hide cursor + keyboard-enhancement) across
 * the child's 10 data bytes.
 *
 * The arm is forced deterministically by running the shipped bin under
 * `setsid` (no controlling terminal) with pipes on both stdio ends — so
 * the child cannot see /dev/tty no matter what host runs this suite.
 * Hosts without util-linux setsid (stock macOS, Windows) self-skip.
 */
final class SpinHeadlessPurityTest extends TestCase
{
    private const BIN      = __DIR__ . '/../../bin/candy-scriptui';
    private const DEADLINE = 40.0;

    public function testEscapeByteFromPipedStdinNeverCancelsALiveChild(): void
    {
        [$rc, $stdout] = $this->runHeadlessBin("\033", [
            '--', '/bin/sh', '-c', 'printf done; sleep 0.4; exit 3',
        ]);

        self::assertSame(
            3,
            $rc,
            "the child's own status must survive; rc 130 here means a piped ESC was parsed as a key (candy-scriptui-002 repro)",
        );
        self::assertStringContainsString('done', $stdout, 'the child must have run to completion');
    }

    public function testHeadlessStdoutCarriesExactlyTheChildBytes(): void
    {
        [$rc, $stdout, $stderr] = $this->runHeadlessBin('', [
            '--', '/bin/sh', '-c', 'printf CHILD-PURE-9f3',
        ]);

        self::assertSame(0, $rc);
        self::assertSame('CHILD-PURE-9f3', $stdout, 'stdout must be EXACTLY the child data channel');
        self::assertStringNotContainsString("\x1b", $stdout, 'no escape sequence may touch the data channel (candy-scriptui-005)');
        self::assertStringNotContainsString('?25l', $stderr, 'DEC cursor modes must not be emitted headless at all');
    }

    public function testShowOutputFlushesPureChildBytes(): void
    {
        [$rc, $stdout] = $this->runHeadlessBin('', [
            '--show-output', '--', '/bin/sh', '-c', 'printf CAPTURED-OK',
        ]);

        self::assertSame(0, $rc);
        self::assertSame('CAPTURED-OK', $stdout, '--show-output must replay the capture verbatim, spinner chrome or mode bytes or nothing');
    }

    public function testHeadlessTimeoutStillKillsAndReports124(): void
    {
        [$rc, $stdout] = $this->runHeadlessBin('', [
            '--timeout', '0.3', '--', '/bin/sh', '-c', 'printf LINGER; sleep 30',
        ]);

        self::assertSame(124, $rc, 'the deadline arm must work without a Program');
        self::assertStringContainsString('LINGER', $stdout, 'bytes produced before the deadline stay visible');
    }

    /**
     * Cross-platform source pins: the unsafe fallback literal is gone and
     * the dispatch seam is wired (the subprocess legs above self-skip on
     * hosts without setsid, so the cure itself must stay greppable).
     */
    public function testFallbackLiteralIsGoneAndArmIsWired(): void
    {
        // scriptui-53 KEEP (cross-platform pin): the setsid subprocess legs
        // above self-skip on hosts without util-linux setsid(1), so the
        // fallback's absence and the dispatch wiring must stay greppable
        // everywhere the behaviour legs cannot run.
        $cmd = (string) file_get_contents(__DIR__ . '/../../src/Command/SpinCommand.php');
        self::assertStringNotContainsString('withoutRenderer', $cmd, 'the raw-STDIN key-seeding Program fallback must never return');
        self::assertStringContainsString('InteractiveRuntime::isHeadless()', $cmd);
        self::assertStringContainsString('private function runHeadless', $cmd);

        $rt = (string) file_get_contents(__DIR__ . '/../../src/Runtime/InteractiveRuntime.php');
        self::assertStringContainsString('public static function isHeadless(): bool', $rt);
        self::assertStringContainsString('programOptions(useAltScreen: false, hideCursor: false, loop: Loop::get())', $rt, 'isHeadless must ride the SAME decision the siblings use to refuse');
    }

    /**
     * Run the shipped bin headless-forced under setsid.
     *
     * @param list<string> $spinArgs
     * @return array{0:int,1:string,2:string} [exitCode, stdout, stderr]
     */
    private function runHeadlessBin(string $stdinBytes, array $spinArgs): array
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('POSIX-only: forces a controlling-terminal-free spawn via setsid(1)');
        }
        $setsid = $this->locateSetsid();
        if ($setsid === null) {
            self::markTestSkipped('util-linux setsid(1) not on PATH — cannot force the headless arm on this host');
        }

        $pipes = [];
        $proc  = @\proc_open(
            [$setsid, '-w', '--', PHP_BINARY, self::BIN, 'spin', ...$spinArgs],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            // No LANG/LC_*: keep message shape deterministic; no TERM:
            // dumb-terminal handling must never rescue or fake this probe.
            ['PATH' => (string) getenv('PATH'), 'HOME' => (string) getenv('HOME')],
        );
        self::assertIsResource($proc, 'setsid probe child must start');

        if ($stdinBytes !== '') {
            // The whole point: bytes land in the PIPE. A headless spin
            // that reads them as KEYS regressed; one that leaves them
            // unread is honest.
            fwrite($pipes[0], $stdinBytes);
        }
        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = $stderr = '';
        $end    = microtime(true) + self::DEADLINE;
        while (microtime(true) < $end) {
            $stdout .= (string) fread($pipes[1], 65536);
            $stderr .= (string) fread($pipes[2], 65536);
            if (feof($pipes[1]) && feof($pipes[2])) {
                break;
            }
            usleep(10_000);
        }
        if (!feof($pipes[1]) || !feof($pipes[2]) || proc_get_status($proc)['running']) {
            proc_terminate($proc, 9);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            self::fail("headless spin did not settle within " . self::DEADLINE . "s\nstdout: $stdout\nstderr: $stderr");
        }
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $stdout, $stderr];
    }

    /** @return ?string absolute path of util-linux setsid, or null */
    private function locateSetsid(): ?string
    {
        $path = (string) getenv('PATH');
        if ($path === '') {
            return null;
        }
        $suffix = DIRECTORY_SEPARATOR === '\\' ? '.exe' : '';
        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            $cand = rtrim($dir, '/') . '/setsid' . $suffix;
            if (is_executable($cand)) {
                return $cand;
            }
        }
        return null;
    }

}
