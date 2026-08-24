<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Cli\ArgvParser;
use SugarCraft\Crush\Cli\NonInteractive;
use SugarCraft\Crush\Message;

/**
 * `tests/bootstrap.php` KEEPS THE SUITE OFF THE RUNNER'S DESCRIPTOR 0, AND
 * THIS FILE IS WHY DELETING THAT LINE IS A RED RATHER THAN A HANG (E212).
 *
 * The defect it closes is a live hazard, not tidiness.
 * {@see NonInteractive::run()} is the `-p "<prompt>"` one-shot path and calls
 * {@see NonInteractive::readStdinIfPiped()} on every invocation; that method's
 * default used to be the `\STDIN` constant. So each of the direct `run()`
 * calls in `tests/Cli` read whatever descriptor 0 the process running PHPUnit
 * inherited, and the outcome was a property of the RUNNER rather than of the
 * code under test:
 *
 *  - a terminal — `stream_isatty()` true, null, harmless;
 *  - `/dev/null` or a closed pipe — `''`, null, harmless;
 *  - a pipe held OPEN and never written — `stream_get_contents()` blocks with
 *    no timeout, and the whole suite hangs with no failure and no verdict.
 *
 * The third is the ordinary shape when a CI job or a supervising process keeps
 * the child's stdin open, and a hang is worse than a failure: it consumes the
 * entire budget and reports nothing. A pipe that DOES carry bytes is the
 * quieter version — they are prepended to the prompt of every `-p` test.
 *
 * WHY AN ASSERTION AND NOT JUST THE FIX: the pin lives in `tests/bootstrap.php`,
 * one line among several, and its absence does not red anything. It changes a
 * green suite into a green suite on this machine and into a hang on a
 * different runner. That is precisely the class of guard that has to be
 * asserted rather than trusted.
 */
final class NonInteractiveStdinPinTest extends TestCase
{
    /** @var resource|null */
    private $pinnedByBootstrap;

    protected function setUp(): void
    {
        // Saved rather than reconstructed: two of these tests move the pin, and
        // putting back a NEW php://memory stream would leave every later test
        // in the process reading a different resource than the one
        // `tests/bootstrap.php` installed. Restoring the exact resource is what
        // keeps this file from being a global side effect.
        $this->pinnedByBootstrap = NonInteractive::stdinDefault();
    }

    protected function tearDown(): void
    {
        NonInteractive::pinStdinDefault($this->pinnedByBootstrap);
    }

    /**
     * THE PIN IS INSTALLED, and the assertion is against the STREAM'S IDENTITY
     * rather than against a null return.
     *
     * "readStdinIfPiped() returned null" is exactly what an unpinned suite
     * returns on this machine too (descriptor 0 here is not a tty and reads
     * empty), so it would be a green assertion over a dead pin — the shape
     * rule 15 exists for. What cannot be faked is WHICH stream answered:
     * `php://memory` on this process's own pin, versus `php://stdin` for the
     * constant.
     */
    public function testTheBootstrapHasPinnedTheDefaultStdinAwayFromTheRealOne(): void
    {
        $pinned = NonInteractive::stdinDefault();

        self::assertIsResource($pinned);
        self::assertNotSame(\STDIN, $pinned, 'tests/bootstrap.php no longer pins the stdin default');
        self::assertSame(
            'php://memory',
            stream_get_meta_data($pinned)['uri'] ?? null,
            'the pinned default is not the memory stream tests/bootstrap.php installs',
        );

        // KNOWN-POSITIVE THROUGH THE SAME PROBE (rule 15): the metadata read
        // above has to be able to tell the two apart, or the assertion proves
        // nothing about which stream is in place.
        //
        // A FRESH `php://stdin` HANDLE, NOT THE `\STDIN` CONSTANT.
        // WHAT THIS SAID: `stream_get_meta_data(\STDIN)`. WHAT IS TRUE NOW:
        // `tests/bootstrap.php` replaces descriptor 0 outright — `fclose(\STDIN)`
        // then `/dev/null` onto the freed fd — so the constant is a CLOSED
        // resource for the rest of the run and that call is a `TypeError`, not
        // an assertion. WHY THE CONTROL STILL EARNS ITS PLACE: what it checks is
        // that this probe distinguishes a `php://memory` stream from a stdin
        // one, and a handle opened by name does that without depending on the
        // constant's liveness. Measured, PHP 8.3.6: after the replacement,
        // `fopen('php://stdin')` succeeds and its `uri` is `php://stdin` while
        // `/proc/self/fd/0` reads `/dev/null` — the name is the wrapper's, not
        // the descriptor's, which is exactly what makes it usable here.
        $byName = fopen('php://stdin', 'r');
        self::assertIsResource($byName);
        self::assertSame('php://stdin', stream_get_meta_data($byName)['uri'] ?? null);
        fclose($byName);
    }

    /**
     * THE SEAM IS LIVE ON THE PRODUCTION CALL PATH, not merely present.
     *
     * `run()` calls `readStdinIfPiped()` with no argument, so the pin only
     * helps if that no-argument default is genuinely what the method reads.
     * Proved by moving the pin to a stream carrying known bytes and observing
     * them arrive in the history the backend is handed — the same route real
     * piped context takes ({@see NonInteractive::historyFrom()}).
     *
     * This is also the mutation-detector for the seam: revert
     * `readStdinIfPiped()`'s default to the `\STDIN` constant and this test
     * reds, because the pinned bytes never reach the prompt.
     */
    public function testRunReadsItsPipedContextThroughThePinnedDefault(): void
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, 'pinned stdin context');
        rewind($stream);
        NonInteractive::pinStdinDefault($stream);

        $backend = $this->recordingBackend($seen);

        ob_start();
        NonInteractive::run(ArgvParser::parse(['sugarcrush', '-p', 'explain this']), $backend);
        ob_get_clean();

        self::assertCount(1, $seen);
        self::assertSame("pinned stdin context\n\nexplain this", $seen[0]->content);
    }

    /**
     * AND THE PIN THE BOOTSTRAP INSTALLS CONTRIBUTES NOTHING TO THE PROMPT,
     * which is the property every other `-p` test in the suite depends on
     * without saying so.
     *
     * An empty `php://memory` stream is deliberately NOT a tty, so this goes
     * through the read rather than short-circuiting on `stream_isatty()` — the
     * same branch production takes for a pipe. If the bootstrap ever pinned a
     * stream with bytes left in it, every `-p` test's prompt would silently
     * grow a prefix and this is the test that would say so.
     */
    public function testTheBootstrapPinAddsNoStdinContextToAPrompt(): void
    {
        $backend = $this->recordingBackend($seen);

        ob_start();
        NonInteractive::run(ArgvParser::parse(['sugarcrush', '-p', 'just the prompt']), $backend);
        ob_get_clean();

        self::assertCount(1, $seen);
        self::assertSame('just the prompt', $seen[0]->content);
        self::assertNull(NonInteractive::readStdinIfPiped());
    }

    /**
     * A CALLER-SUPPLIED STREAM STILL OUTRANKS THE PIN — the property that keeps
     * production reading `\STDIN` and keeps the three existing
     * `readStdinIfPiped($stream)` tests meaningful.
     */
    public function testAnExplicitStreamStillWinsOverThePinnedDefault(): void
    {
        $explicit = fopen('php://memory', 'r+');
        fwrite($explicit, 'from the argument');
        rewind($explicit);

        $pin = fopen('php://memory', 'r+');
        fwrite($pin, 'from the pin');
        rewind($pin);
        NonInteractive::pinStdinDefault($pin);

        self::assertSame('from the argument', NonInteractive::readStdinIfPiped($explicit));
    }

    /**
     * Clearing the pin goes back to the `\STDIN` CONSTANT rather than to "no
     * stream at all" — and in THIS process that constant is a closed
     * descriptor, so the answer is `null`.
     *
     * Pinned dormancy: `src/` and `bin/` never call `pinStdinDefault()`, so
     * production always takes this arm, and it is the one arm no test would
     * otherwise reach.
     *
     * WHAT THIS TEST SAID: `assertSame(\STDIN, NonInteractive::stdinDefault())`.
     * WHAT IS TRUE NOW (E338): that assertion was passing on a CLOSED
     * resource. `tests/bootstrap.php` closes descriptor 0 on every non-tty
     * run, and PHPUnit named the old expectation "resource (closed)" the
     * moment the value stopped matching — so what this test was really
     * pinning was that a dead handle got handed to every consumer, one
     * `stream_isatty()` away from a `TypeError`. WHY THE TEST STILL EARNS ITS
     * PLACE: its CLAIM — "unpinning resolves the constant, it does not
     * disable the seam" — is exactly right and is the one arm production
     * takes. Only the observable changed, so the claim is now split across
     * two arms: this one for a dead descriptor, and
     * {@see testWithALiveDescriptorZeroClearingThePinHandsBackStdinItself()}
     * for a live one.
     *
     * `assertFalse(\is_resource(\STDIN))` is not decoration: it states the
     * PREMISE this arm's expectation rests on, so a bootstrap that stopped
     * closing descriptor 0 fails here with the reason on screen instead of
     * silently turning the assertion below into a different claim.
     */
    public function testClearingThePinFallsBackToTheStdinConstant(): void
    {
        NonInteractive::pinStdinDefault(null);

        self::assertFalse(
            \is_resource(\STDIN),
            'this suite is supposed to run with descriptor 0 closed; the expectation below assumes it',
        );
        self::assertNull(NonInteractive::stdinDefault());
    }

    /**
     * THE TEST E338 SAID THIS DEFECT WAS "ONE NEW TEST AWAY" FROM BECOMING.
     *
     * E338 recorded the fd-0 replacement as a LATENT `TypeError`: two callers
     * cleared the pin and neither then READ, "so the next test that unpins and
     * reads gets a `TypeError` where it used to get `null`". This is that
     * test, written on purpose so the hazard is exercised rather than
     * described, and it must answer `null`.
     *
     * IT COVERS BOTH READS, WHICH IS THE WHOLE POINT. Round 51 measured that a
     * guard placed only on the `stream_isatty()` call would RELOCATE the throw
     * to the `@\stream_get_contents()` two lines down, because `@` suppresses
     * diagnostics and not exceptions. A test that only reached the first call
     * would have greened that half-fix.
     */
    public function testReadingWithNoPinAndNoDescriptorZeroAnswersNullRatherThanThrowing(): void
    {
        NonInteractive::pinStdinDefault(null);

        self::assertFalse(\is_resource(\STDIN), 'the hazard needs a dead descriptor 0 to exist at all');
        self::assertNull(NonInteractive::readStdinIfPiped());
    }

    /**
     * The same guard, reached by the third route: a caller that passes its own
     * handle and has since closed it.
     *
     * Neither of the two call-site guards E338 weighed would have covered
     * this one — it is why the guard sits on the RESOLVED stream instead.
     */
    public function testReadingAnExplicitlyPassedClosedStreamAnswersNull(): void
    {
        $stream = \fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        \fwrite($stream, 'bytes that will never be read');
        \rewind($stream);
        \fclose($stream);

        self::assertNull(NonInteractive::readStdinIfPiped($stream));
    }

    /**
     * THE POSITIVE CONTROL for the arm above, and the half an in-process
     * assertion cannot make.
     *
     * With descriptor 0 closed, "resolves `\STDIN` and finds it dead" and
     * "always answers null" are the same observation, so the test above on
     * its own would be satisfied by a `stdinDefault()` mutated to
     * `return null;`. A child `php` handed a LIVE pipe on descriptor 0 tells
     * them apart: there the unpinned default must be the `\STDIN` constant
     * ITSELF, identity-compared inside the child.
     *
     * A child rather than a fork, because the property under test IS the
     * process's descriptor 0 and this process has none to lend.
     */
    public function testWithALiveDescriptorZeroClearingThePinHandsBackStdinItself(): void
    {
        $script = 'require ' . \var_export(\dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';'
            . '\SugarCraft\Crush\Cli\NonInteractive::pinStdinDefault(null);'
            . '$a = \SugarCraft\Crush\Cli\NonInteractive::stdinDefault();'
            . 'echo "live=", var_export(is_resource(STDIN), true),'
            . ' " same=", var_export($a === STDIN, true),'
            . ' " null=", var_export($a === null, true);';

        $process = \proc_open(
            [\PHP_BINARY, '-r', $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process, 'could not start the child php');

        \fwrite($pipes[0], "piped\n");
        \fclose($pipes[0]);
        $out = (string) \stream_get_contents($pipes[1]);
        $err = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        \proc_close($process);

        self::assertSame('live=true same=true null=false', $out, 'child stderr: ' . $err);
    }

    /**
     * A backend that records the history it was handed and answers.
     *
     * @param list<Message>|null $seen
     */
    private function recordingBackend(?array &$seen): Backend
    {
        $seen = [];

        return new class ($seen) implements Backend {
            /** @param list<Message>|null $seen */
            public function __construct(private ?array &$seen) {}

            public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
            {
                $this->seen = $history;

                return Message::assistant('answered');
            }

            public function completeAsync(
                array $history,
                callable $onToken = null,
                ?CancellationToken $cancellation = null,
                ?callable $onEvent = null,
            ): \React\Promise\PromiseInterface {
                return \React\Promise\resolve($this->complete($history, $onToken, $onEvent));
            }
        };
    }
}
