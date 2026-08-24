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
     * Clearing the pin goes back to `\STDIN` rather than to "no stream at all".
     *
     * Pinned dormancy: `src/` and `bin/` never call `pinStdinDefault()`, so
     * production always takes this arm, and it is the one arm no test would
     * otherwise reach.
     */
    public function testClearingThePinRestoresTheRealStdinAsTheDefault(): void
    {
        NonInteractive::pinStdinDefault(null);

        self::assertSame(\STDIN, NonInteractive::stdinDefault());
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
