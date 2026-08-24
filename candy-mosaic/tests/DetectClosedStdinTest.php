<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\Detect;

/**
 * A CLOSED DESCRIPTOR 0 MUST DEGRADE THIS PROBE, NOT THROW OUT OF IT (E318).
 *
 * `Detect` resolves the stream it probes as `self::$probeStdin ?? STDIN` and,
 * before the guard this file pins, handed the result straight to
 * `stream_select()`/`fread()`. A closed descriptor 0 is an ordinary state for a
 * process to be in — a daemon, a git hook, `sugarcrush 0<&-`, or a test harness
 * that replaced its own fd 0 to keep spawned children off the runner's stdin —
 * and `defined('STDIN')` stays TRUE after `fclose(STDIN)` while `is_resource()`
 * goes false, so nothing about the constant's presence says the handle is live.
 *
 * ## THE FAILURE IS NOT THE SUPPRESSED WARNING IT LOOKS LIKE
 *
 * `drainStdin()`'s call is written `@stream_select(...)`, which reads as
 * already-defended. It is not. MEASURED, PHP 8.3.6: handed an array whose only
 * member is a closed resource, `stream_select()` drops that member (the
 * suppressed part) and then throws `ValueError: No stream arrays were passed`
 * — a thrown error, which `@` does not touch. `fread()` on the same handle
 * throws `TypeError`. Both are pinned as fixtures below rather than described,
 * because this exact reading — "the `@` handles it" — is why the guard was
 * missing.
 *
 * ## WHY IT MATTERED SOMEWHERE ELSE
 *
 * `SugarCraft\Crush`'s suite wants to replace ITS descriptor 0 so a spawned
 * `bin/sugarcrush` cannot read the runner's stdin, and PHP has no `dup2`, so
 * that means `fclose(\STDIN)`. Two attempts died here: the throw above escaped
 * `Detect::probe()`, `Mosaic::auto()` caught it, and the fallback it landed in
 * named an enum case that did not exist — reported as 107 errors in a library
 * nobody had changed. The enum name was fixed separately; this is the trigger
 * underneath it, and "the throw is caught now" is not the same as "the library
 * is fine": before this guard, `Detect` left its normal path by exception on
 * EVERY call in such a process.
 *
 * ## WHAT IS DRIVEN, AND WHY THE INJECTED SEAM IS NOT THE WHOLE TEST
 *
 * `setProbeStdin()` reaches `stdinFd()` and `isInteractiveTty()` with a handle
 * of this test's choosing, which is how the guard is driven DIRECTLY rather
 * than through a thrown probe. But it cannot reach the `?? STDIN` arm — the one
 * that actually broke — because injecting anything at all takes the other
 * branch. That arm is therefore driven in a CHILD process that really does
 * `fclose(\STDIN)`, which is the only way to observe it.
 */
final class DetectClosedStdinTest extends TestCase
{
    /** @var list<resource> */
    private array $open = [];

    protected function tearDown(): void
    {
        Detect::reset();
        Detect::setProbeStdin(null);
        foreach ($this->open as $handle) {
            if (\is_resource($handle)) {
                fclose($handle);
            }
        }
        $this->open = [];

        parent::tearDown();
    }

    /**
     * THE TWO STDLIB FACTS THE GUARD EXISTS FOR, ASSERTED HERE SO THE PROSE
     * ABOVE IS A FIXTURE RATHER THAN A CLAIM.
     *
     * If a future PHP makes `stream_select()` tolerate a closed member, or
     * makes `fread()` return false instead of throwing, this row goes red and
     * whoever reads it learns the guard's premise moved before they conclude
     * the guard is unnecessary. It is also the known-positive control for
     * everything below (rule 15): a test that only ever asserts "nothing threw"
     * passes just as well in a tree where the calls were deleted.
     */
    public function testAClosedHandleIsStillHostileToTheTwoCallsTheGuardProtects(): void
    {
        $live = $this->pipe();
        $read = [$live];
        $write = $except = null;
        self::assertSame(
            0,
            @stream_select($read, $write, $except, 0, 1000),
            'a live, empty pipe should select as not-ready rather than error, or the closed case below '
                . 'is not being compared against anything',
        );
        self::assertSame('', @fread($live, 8), 'a live, empty non-blocking pipe should read empty');

        $dead = $this->pipe();
        fclose($dead);

        try {
            $read = [$dead];
            $write = $except = null;
            @stream_select($read, $write, $except, 0, 1000);
            self::fail('stream_select() accepted a closed resource, so the guard is protecting nothing');
        } catch (\ValueError $e) {
            self::assertStringContainsString('No stream arrays were passed', $e->getMessage());
        }

        $this->expectException(\TypeError::class);
        @fread($dead, 8);
    }

    /**
     * THE INJECTED SEAM: a probe stream that was closed after injection must
     * not be handed on.
     *
     * Control first, through the same call: with a LIVE injected stream
     * `probe()` completes, which is what makes the closed arm's completion mean
     * "the guard held" rather than "this call never touches stdin".
     */
    public function testAClosedInjectedProbeStreamDoesNotReachTheStreamCalls(): void
    {
        $live = $this->pipe();
        Detect::setProbeStdin($live);
        Detect::reset();
        self::assertNotNull(Detect::probe(), 'the control did not complete, so the treatment says nothing');

        $dead = $this->pipe();
        fclose($dead);
        Detect::setProbeStdin($dead);
        Detect::reset();

        // Without the guard this is `ValueError: No stream arrays were passed`
        // out of drainStdin(), which probe() does not catch.
        $cap = Detect::probe();
        self::assertTrue($cap->halfblock, 'halfblock is always available, so probe() answered rather than threw');
    }

    /**
     * AND THE `?? STDIN` ARM ITSELF, IN A CHILD THAT REALLY CLOSES FD 0.
     *
     * The injected seam above cannot reach this arm by construction. The child
     * runs the control first — `Detect::probe()` with fd 0 intact — so a child
     * that failed to autoload, or died before reaching the probe, reports as a
     * missing control line rather than as a passing treatment.
     */
    public function testProbeSurvivesTheRealStdinConstantBeingClosed(): void
    {
        $root = \dirname(__DIR__);
        $script = "<?php\n"
            . 'require ' . var_export($root . '/vendor/autoload.php', true) . ";\n"
            . 'use SugarCraft\Mosaic\Detect;' . "\n"
            . 'try { Detect::probe(); echo "CONTROL=ok\n"; }' . "\n"
            . 'catch (\Throwable $t) { echo "CONTROL=", get_class($t), "\n"; }' . "\n"
            . 'Detect::reset();' . "\n"
            . 'fclose(\STDIN);' . "\n"
            // Parked in a global: PHPUnit-style includes aside, a bare local
            // here would be freed and fd 0 would close again behind us.
            . '$GLOBALS["__hold"] = fopen("/dev/null", "r");' . "\n"
            . 'echo "LIVE=", var_export(is_resource(\STDIN), true), "\n";' . "\n"
            . 'try { Detect::probe(); echo "CLOSED=ok\n"; }' . "\n"
            . 'catch (\Throwable $t) { echo "CLOSED=", get_class($t), ": ", $t->getMessage(), "\n"; }' . "\n";

        $file = tempnam(sys_get_temp_dir(), 'mosaic_fd0_' . getmypid() . '_');
        self::assertIsString($file);

        try {
            file_put_contents($file, $script);

            $out = [];
            $rc = 0;
            exec(escapeshellarg(\PHP_BINARY) . ' ' . escapeshellarg($file) . ' < /dev/null 2>&1', $out, $rc);
            $text = implode("\n", $out);

            self::assertSame(0, $rc, "the child did not exit cleanly:\n" . $text);
            self::assertStringContainsString('CONTROL=ok', $text, "the child's control probe did not run:\n" . $text);
            self::assertStringContainsString(
                'LIVE=false',
                $text,
                "fclose(\\STDIN) did not leave the constant a dead resource, so the child never reached the "
                    . "state this test is about:\n" . $text,
            );
            self::assertStringContainsString(
                'CLOSED=ok',
                $text,
                "Detect::probe() threw with the \\STDIN constant closed. That is E318: stdinFd() resolved "
                    . "`self::\$probeStdin ?? STDIN` and handed a dead handle to stream_select().\n" . $text,
            );
        } finally {
            @unlink($file);
        }
    }

    /**
     * A closed injected stream is not "interactive".
     *
     * `isInteractiveTty()` short-circuits to true whenever a probe stream was
     * injected, on the reasoning that a test rig has no real tty. That reading
     * is right about a LIVE injected stream and wrong about a dead one, and it
     * is the branch that carries a dead handle into the two read paths rather
     * than the drain. Observable through `lastDa1Reply()`: the DA1 probe runs
     * only on the interactive arm, and it records its reply — `null` means the
     * arm was never entered.
     */
    public function testAClosedInjectedProbeStreamIsNotTreatedAsInteractive(): void
    {
        $live = $this->pipe();
        Detect::setProbeStdin($live);
        Detect::reset();
        Detect::probe();
        self::assertNotNull(
            Detect::lastDa1Reply(),
            'the control never entered the interactive arm, so its absence below proves nothing',
        );

        $dead = $this->pipe();
        fclose($dead);
        Detect::setProbeStdin($dead);
        Detect::reset();
        Detect::probe();
        self::assertNull(
            Detect::lastDa1Reply(),
            'a closed injected stream was treated as an interactive tty and the DA1 probe ran on it',
        );
    }

    /**
     * One end of a non-blocking socket pair, registered for teardown.
     *
     * A socket pair rather than `php://memory`: the guard's subject is
     * `stream_select()`, and a memory stream is not selectable at all, so it
     * could not tell a held guard from a call that never happened.
     *
     * @return resource
     */
    private function pipe()
    {
        $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, 0);
        self::assertIsArray($pair);
        stream_set_blocking($pair[0], false);
        $this->open[] = $pair[0];
        $this->open[] = $pair[1];

        return $pair[0];
    }
}
