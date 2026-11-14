<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util\Tty;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Posix\PosixPtySystem;

/**
 * End-to-end smokes for the sc-44 examples row (findings sugar-core-068,
 * -069, -070, -078).
 *
 * Every claim here is the shape the finding says was broken: a REAL `q`
 * keystroke driving the full input pipeline of a REAL child process until
 * it exits 0 — screen-stack from pushed depth, panes under the Panes Tab
 * filter, mouse through an SGR wire sequence — plus the shared terminal
 * guard in its three shapes. Before this row the first two hung and the
 * third rendered `Undefined property` warnings in place of coordinates
 * (a regression guard for those states is exactly what this file adds;
 * the mutation proofs were run at fix time by reverting the arms).
 *
 * The pty legs need the POSIX pty primitives (house gate below); the three
 * guard legs drive plain redirected pipes and run anywhere PHP runs.
 */
final class ExamplesPtyTest extends TestCase
{
    private const TIMEOUT_SECONDS = 8.0;

    private function examplePath(string $script): string
    {
        return \dirname(__DIR__, 3) . '/examples/' . $script . '.php';
    }

    /**
     * Gate for the pty legs: POSIX pty pair + posix_isatty, per the house
     * idiom in PosixBackendTest.
     */
    private function requirePty(): PosixPtySystem
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX PTY primitives are not available on Windows.');
        }
        if (!is_readable('/dev/ptmx') || !is_writable('/dev/ptmx')) {
            $this->markTestSkipped('/dev/ptmx is unreadable/unwritable on this host.');
        }
        if (!function_exists('posix_isatty')) {
            $this->markTestSkipped('posix_isatty is not available.');
        }

        return new PosixPtySystem();
    }

    /**
     * Drive an example inside a fresh PTY and return [exitCode, output, drivenAll].
     *
     * @param string $script example base name
     * @param string $marker byte string that must appear before the first step
     * @param list<array{wait:?string,send:string}> $steps staged keystrokes;
     *                         a step with wait=null fires immediately after
     *                         the previous one (coalesced single write)
     */
    private function drive(string $script, string $marker, array $steps): array
    {
        $system = $this->requirePty();
        $pair   = $system->open();
        $master = $pair->master();
        $slave  = fopen($pair->slave()->path(), 'r+');
        if ($slave === false) {
            $master->close();
            $this->markTestSkipped('Could not open PTY slave path: ' . $pair->slave()->path());
        }

        $process = proc_open(
            [PHP_BINARY, $this->examplePath($script)],
            [0 => $slave, 1 => $slave, 2 => $slave],
            $pipes,
            \dirname(__DIR__, 3),
            ['TERM' => 'xterm-256color', 'PATH' => getenv('PATH') ?: '/usr/bin:/bin'],
        );
        $this->assertIsResource($process, "could not start examples/$script.php");

        // slave fds in the PARENT are no longer needed; only the child holds
        // the session now, so master EOF means the example exited.
        fclose($slave);

        $output   = '';
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;
        $fired    = 0;

        while (microtime(true) < $deadline) {
            $chunk = $master->read(8192, 0.05);
            if ($chunk === '') {
                break; // confirmed EOF: every slave closed
            }
            if ($chunk !== null) {
                $output .= $chunk;
            }

            if ($fired < count($steps)) {
                if ($fired === 0) {
                    if (!str_contains($output, $marker)) {
                        continue;
                    }
                } elseif ($steps[$fired]['wait'] !== null && !str_contains($output, $steps[$fired]['wait'])) {
                    continue;
                }
                $master->write($steps[$fired]['send']);
                $fired++;
            }
        }

        $status = proc_get_status($process);
        if ($status['running']) {
            proc_terminate($process, 9);
        }
        $rc = proc_close($process);
        if ($status['running']) {
            $rc = -9; // killed: it never exited on its own — the hang shape
        }
        // Hermetic: a leaked master fd lands on the same device+inode as a
        // later fixture's pty and poisons uniqueness census (a full-suite
        // TtyDetectTest collision proved this line load-bearing).
        $master->close();

        return [$rc, $output, $fired === count($steps)];
    }

    /**
     * finding 068: `q` from a PUSHED screen used to reach only ScreenModel,
     * which handled no key — the shipped help text was a trap and once-QA
     * reproduced the hang under a PTY. Now q from depth exits the program
     * and the post-run echo proves a clean unwind.
     */
    public function testScreenStackQuitsFromPushedDepth(): void
    {
        // With a non-empty stack the program paints the ACTIVE SCREEN
        // ('Screen Level 2' — the pushes/pop ran before run()), which is
        // exactly the state whose q-arm was missing pre-fix.
        [$rc, $output, $driven] = $this->drive('screen-stack', 'Screen Level 2', [['wait' => null, 'send' => 'q']]);

        self::assertTrue($driven, 'the demo never painted the pushed screen');
        self::assertSame(0, $rc, 'q on a pushed screen must quit the program (screen-stack hung pre-sc-44)');
        self::assertStringContainsString('Screen Level 2', $output, 'the first frame is the pushed screen — with a non-empty stack Program paints the ACTIVE model, which is why the root help line never reached the user');
        self::assertStringContainsString('Final pushed/popped:', $output, 'run() must unwind to the example tail');
    }

    /**
     * finding 070: "Press any other key to quit" with zero quit arms. A real
     * keystroke on the focused pane must now quit. (Pre-fix this is the same
     * hang class the mutation proof below records.)
     */
    public function testPanesQuitsOnAnyOtherKey(): void
    {
        [$rc, $output, $driven] = $this->drive('panes', 'Counter: 0', [['wait' => null, 'send' => 'x']]);

        self::assertTrue($driven, 'the panes demo never painted its counter');
        self::assertSame(0, $rc, 'any other key must really quit the panes demo (0 arms pre-sc-44)');
        self::assertStringContainsString('Right Pane', $output);
    }

    /**
     * finding 069: the demo printed $msg->col/$msg->row on a MouseMsg whose
     * promoted properties are $x/$y — Undefined-property warnings and nulls
     * in a README-showcased example. Drive an SGR-1006 left-press at (10,5)
     * through the full decoder and assert the coordinates render.
     */
    public function testMouseDemoRendersRealCoordinates(): void
    {
        // Staged: the SGR press must PAINT its row before q rides the same
        // tick — coalescing the two bytes let the quit beat the repaint.
        [$rc, $output, $driven] = $this->drive('mouse', 'Mouse events', [
            ['wait' => null, 'send' => "\x1b[<0;10;5M"],
            ['wait' => '@ (10, 5)', 'send' => 'q'],
        ]);

        self::assertTrue($driven, 'the mouse demo never painted its frame');
        self::assertSame(0, $rc, 'q must quit the mouse demo');
        self::assertMatchesRegularExpression(
            '/@ \(10, 5\)/',
            $output,
            'coordinates must come from MouseMsg::$x/$::\$y — the example rendered col/row nulls pre-sc-44',
        );
        self::assertStringNotContainsString('Undefined property', $output);
        self::assertStringNotContainsString('@ (0, 0)', $output, 'a shredded decode must not masquerade as a hit');
    }

    // ─── finding 078: the shared terminal guard, three shapes ────────────────

    /**
     * TERM=dumb: one honest line on stdout, exit 0, and — the load-bearing
     * half — NOT ONE escape byte written (no raw/stty/altscreen armed).
     */
    public function testDumbTerminalGetsNoticeWithoutSgrNoise(): void
    {
        [$rc, $out, $err] = $this->runGuardProbe('counter', ['TERM' => 'dumb']);

        self::assertSame(0, $rc);
        self::assertStringContainsString('TERM=dumb', $out);
        self::assertStringNotContainsString("\x1b", $out, 'the dumb arm must engage no escape machinery');
        self::assertSame('', $err);
    }

    /**
     * No terminal + dead input (file /dev/null): the sc-05 silent-exit shape
     * becomes a named refusal — exit 1, message on stderr naming the script.
     */
    public function testNoTerminalDeadInputRefusesLoudly(): void
    {
        [$rc, $out, $err] = $this->runGuardProbe('counter', ['TERM' => 'xterm'], ['file', '/dev/null', 'r']);

        self::assertSame(1, $rc);
        self::assertStringContainsString('counter: this interactive demo needs a terminal', $err);
        self::assertSame('', $out, 'nothing may be painted into an unread stream');
    }

    /**
     * Scripted keystrokes on a pipe are LIVE input, not a missing terminal:
     * `printf 'q' | php examples/counter.php` still runs and quits cleanly.
     */
    public function testPipedKeystrokesPassTheGuardAndQuit(): void
    {
        $descriptors = [
            ['pipe', 'r'],
            ['pipe', 'w'],
            ['pipe', 'w'],
        ];
        $process = proc_open(
            [PHP_BINARY, $this->examplePath('counter')],
            $descriptors,
            $pipes,
            \dirname(__DIR__, 3),
            ['TERM' => 'xterm', 'PATH' => getenv('PATH') ?: '/usr/bin:/bin'],
        );
        $this->assertIsResource($process);

        $out = '';
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;
        while (microtime(true) < $deadline) {
            $chunk = fread($pipes[1], 8192);
            if ($chunk === false) {
                break;
            }
            $out .= $chunk;
            if (str_contains($out, 'Count:')) {
                break;
            }
            usleep(20000);
        }
        fwrite($pipes[0], 'q');
        fclose($pipes[0]);
        $out .= stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $rc = proc_close($process);

        self::assertSame(0, $rc, 'the piped q (or sc-05 EOF-quit) must end the run cleanly');
        self::assertStringContainsString('Count: 0', $out);
    }

    /**
     * @param array<string, string> $env
     * @param array{0:string,1:string,2:string}|null $stdinSpec
     * @return array{0:int,1:string,2:string}
     */
    private function runGuardProbe(string $script, array $env, ?array $stdinSpec = null): array
    {
        $base = getenv('PATH') ?: '/usr/bin:/bin';
        $descriptors = [
            $stdinSpec ?? ['pipe', 'r'],
            ['pipe', 'w'],
            ['pipe', 'w'],
        ];
        $process = proc_open(
            [PHP_BINARY, $this->examplePath($script)],
            $descriptors,
            $pipes,
            \dirname(__DIR__, 3),
            ['PATH' => $base] + $env,
        );
        $this->assertIsResource($process);

        if ($stdinSpec === null) {
            fclose($pipes[0]); // closed pipe: no keystrokes possible
        }
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $rc = proc_close($process);

        return [$rc, (string) $out, (string) $err];
    }
}
