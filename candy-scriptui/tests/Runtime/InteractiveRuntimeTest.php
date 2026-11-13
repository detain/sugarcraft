<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use SugarCraft\ScriptUi\Runtime\InteractiveRuntime;

/**
 * Pins for the pipeline-defect core (review 2026-11-02 CC1 + CC2).
 *
 * CC1: interactive commands must never bind keystrokes to a drained/piped
 * STDIN (that shape both deadlocked `ls | filter` and let `printf 'yes\n' |
 * confirm` fabricate an answer). CC2: when stdout is captured, the TUI frame
 * must ride STDERR so `ANSWER=$(candy-scriptui choose a b c)` captures only
 * the answer bytes.
 *
 * The end-to-end arm runs the real bin under `setsid` so the child has NO
 * controlling terminal and STDIN is a byte-carrying pipe: the only honest
 * outcome is a refusal on stderr with empty stdout — never a hang and never a
 * piped-bytes-as-keystrokes answer.
 */
final class InteractiveRuntimeTest extends TestCase
{
    public function testProgramOptionsRoutesPaintToStderrWhenStdoutIsCaptured(): void
    {
        $stdoutIsTty = @stream_isatty(STDOUT);
        $options = InteractiveRuntime::programOptions(useAltScreen: true, hideCursor: true, loop: \React\EventLoop\Loop::get());

        if (!$stdoutIsTty) {
            // Two honest shapes under a captured stdout: paint-to-STDERR when
            // a terminal is reachable, outright refusal when it is not. What
            // is NEVER allowed is painting to the captured STDOUT or reading
            // keys from a data pipe.
            self::assertTrue(
                $options === null || $options->output === STDERR,
                'captured stdout must push the UI frame to stderr or refuse outright (CC2/CC1)',
            );
            if ($options !== null) {
                self::assertTrue(
                    $options->input === null || @stream_isatty($options->input),
                    'key input must be a terminal handle, not a data stream',
                );
            }
        } else {
            self::assertNotNull($options);
            self::assertTrue($options->openTty, 'a tty stdout must still negotiate /dev/tty keys for piped stdin (CC1)');
            self::assertNull($options->output, 'interactive stdout keeps painting to stdout');
        }
    }

    public function testEveryInteractiveCommandLaunchesThroughTheChokepoint(): void
    {
        // scriptui-53: the chokepoint is now proven by EXECUTION. Under
        // setsid (no controlling terminal) every interactive command must
        // answer with the runtime's own refusal sentence, on captured
        // STDERR, exit FAILURE, captured STDOUT empty — only a command that
        // actually consulted programOptions() can produce that shape. A
        // command that reverted to a raw-STDIN Program would hang or
        // fabricate an answer from the piped byte instead, and the
        // timeout(1) bound turns that regression into a loud 124/137.
        // SpinCommand cannot refuse (its honest headless shape is the
        // direct-run arm): a live child whose status and bytes survive
        // proves the isHeadless() consultation routed it there.
        $setsid = $this->locateSetsid();
        if ($setsid === null) {
            $this->markTestSkipped('util-linux setsid(1) not on PATH — cannot force the no-tty shape');
        }

        /** @var array<string, list<string>> $shapes */
        $shapes = [
            'choose' => ['choose', 'a', 'b'],
            'confirm' => ['confirm', 'Deploy?'],
            'file'    => ['file'],
            'filter'  => ['filter'],
            'input'   => ['input'],
            'pager'   => ['pager'],
            'write'   => ['write'],
        ];
        foreach ($shapes as $name => $args) {
            [$status, $stdout, $stderr] = $this->runHeadlessChild($setsid, $args, "alpha\n");
            $this->assertSame(1, $status, $name . ' must refuse the no-terminal shape with FAILURE');
            $this->assertSame('', $stdout, $name . ' must keep the data channel clean on refusal');
            $this->assertStringContainsString(
                $name . ' needs an interactive terminal',
                $stderr,
                $name . ' must refuse through the shared runtime (CC1/CC2)',
            );
        }

        [$status, $stdout, $stderr] = $this->runHeadlessChild($setsid, [
            'spin', '--', '/bin/sh', '-c', 'printf SPIN-53; exit 5',
        ]);
        $this->assertSame(5, $status, 'the direct-run arm must pass the child status through');
        $this->assertSame('SPIN-53', $stdout, 'the direct-run arm must pass the child bytes through');
        $this->assertStringNotContainsString("\x1b", $stdout, 'no DEC chrome may touch the data channel (CC2)');
    }

    /** ?string absolute path of util-linux setsid, or null. */
    private function locateSetsid(): ?string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return null;
        }
        $path = trim((string) @shell_exec('command -v setsid 2>/dev/null'));

        return $path !== '' ? $path : null;
    }

    /**
     * @param list<string> $args
     *
     * @return array{0:int,1:string,2:string} [exitCode, stdout, stderr]
     */
    private function runHeadlessChild(string $setsid, array $args, string $stdin = ''): array
    {
        $bin = realpath(__DIR__ . '/../../bin/candy-scriptui');
        self::assertIsString($bin);
        $proc = proc_open(
            ['timeout', '-s', 'KILL', '20', $setsid, '-w', PHP_BINARY, $bin, ...$args],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            \dirname($bin),
            ['PATH' => (string) getenv('PATH'), 'HOME' => (string) getenv('HOME')],
        );
        self::assertIsResource($proc, 'the setsid bin child must spawn');
        if ($stdin !== '') {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $stdout, $stderr];
    }

    /**
     * The fabricated-confirm shape from the review: `printf 'yes\n' | confirm`
     * with stdout captured and no controlling terminal. Refusal semantics:
     * non-zero exit, EMPTY stdout (no fabricated answer), diagnostic on
     * stderr. Bounded so a regression to the old deadlock reds the test fast
     * instead of hanging CI.
     */
    public function testHeadlessConfirmRefusesInsteadOfConsumingPipedBytes(): void
    {
        $setsid = trim((string) @shell_exec('command -v setsid 2>/dev/null'));
        $php = PHP_BINARY;
        if ($setsid === '' || !str_contains($php, 'php')) {
            $this->markTestSkipped('setsid/php binary unavailable for the no-tty child probe');
        }

        $bin = realpath(__DIR__ . '/../../bin/candy-scriptui');
        self::assertIsString($bin);
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            [$setsid, '-w', $php, $bin, 'confirm', 'Deploy?'],
            $descriptors,
            $pipes,
        );
        self::assertIsResource($process);
        fwrite($pipes[0], "yes\n");
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $code = proc_close($process);

        self::assertNotSame(0, $code, 'headless confirm must refuse, never fabricate a yes (CC1)');
        self::assertSame('', $stdout, 'refusal writes nothing onto the captured data channel');
        self::assertStringContainsString('terminal', strtolower($stderr), 'stderr carries the diagnostic');
    }
}
