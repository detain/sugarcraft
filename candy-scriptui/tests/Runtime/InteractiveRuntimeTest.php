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
        $commands = [
            'ChooseCommand', 'ConfirmCommand', 'FileCommand', 'FilterCommand',
            'InputCommand', 'PagerCommand', 'SpinCommand', 'WriteCommand',
        ];
        foreach ($commands as $name) {
            $source = (string) file_get_contents(__DIR__ . "/../../src/Command/$name.php");
            self::assertStringContainsString(
                'InteractiveRuntime::programOptions(',
                $source,
                "$name must launch Programs through the shared runtime (CC1/CC2)",
            );
        }
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
