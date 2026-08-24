<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\HeadlessPermissionPrompt;
use SugarCraft\Crush\Cli\NonInteractive;
use SugarCraft\Crush\Permissions\PermissionMode;

/**
 * THE SECOND `?? \STDIN` IN THE `-p` CONSOLE FAMILY, AND THE PIN THAT CLOSES
 * IT (E243).
 *
 * E212 closed the first: {@see NonInteractive::readStdinIfPiped()} resolves
 * its default through {@see NonInteractive::stdinDefault()}, which
 * `tests/bootstrap.php` points at a `php://memory` stream once for the whole
 * process. {@see HeadlessPermissionPrompt::__construct()} had the identical
 * `$in = $in ?? \STDIN` and was not covered, and
 * {@see \SugarCraft\Crush\Cli\Bootstrap::withConsolePermissionPrompt()}
 * constructs it with no `$in` at all — so an approver attached on the `-p`
 * path or in the background-session daemon read whatever descriptor 0 the
 * runner inherited.
 *
 * THE HAZARD IS NARROWER THAN E212's AND THE FIX IS THE SAME SHAPE. `\fgets()`
 * in that class sits behind an `\stream_isatty()` probe, so a held-open pipe
 * takes the no-tty refusal arm instead of blocking; what remained was a real
 * block whenever the suite is run from a terminal. MEASURED at round 49: no
 * test in `tests/` currently invokes a `Bootstrap`-built approver without
 * first rebinding its streams by reflection, so the block was latent. Latent
 * is not closed — the defaulting is a property of the class and "no test
 * reaches it today" is a property of the suite.
 *
 * WHY THIS FILE AND NOT `HeadlessPermissionPromptTest`: every test in that
 * file passes both streams explicitly, which is exactly the discipline this
 * one exists to make unnecessary. Mixing the two would put the assertion that
 * the DEFAULT is safe in a file whose every other test names the stream.
 */
final class HeadlessPermissionPromptStdinDefaultTest extends TestCase
{
    /** @var resource|null */
    private $pinnedByBootstrap;

    protected function setUp(): void
    {
        // Captured, not assumed: this suite's pin is installed once in
        // tests/bootstrap.php, and every test below restores exactly what was
        // there rather than resetting to null - which would put the rest of
        // the run back on the runner's real descriptor 0.
        $this->pinnedByBootstrap = NonInteractive::stdinDefault();
    }

    protected function tearDown(): void
    {
        NonInteractive::pinStdinDefault($this->pinnedByBootstrap);
    }

    /**
     * THE CONSTRUCTOR'S DEFAULT IS THE PIN, NOT `\STDIN`.
     *
     * Asserted by IDENTITY against a stream this test made, so it cannot pass
     * by both sides happening to be a non-tty: `assertSame` on a resource
     * compares the handle.
     */
    public function testTheDefaultAnswerStreamIsTheProcessPinAndNotTheRealStdin(): void
    {
        $pin = fopen('php://memory', 'r+');
        self::assertIsResource($pin);
        NonInteractive::pinStdinDefault($pin);

        $prompt = new HeadlessPermissionPrompt(PermissionMode::Default);

        self::assertSame($pin, self::answerStreamOf($prompt));
        self::assertNotSame(\STDIN, self::answerStreamOf($prompt));
    }

    /**
     * AND AN EXPLICIT `$in` STILL WINS OVER THE PIN.
     *
     * The pin is a default, not an override. Without this, "route the default
     * through the seam" and "ignore the caller's stream" are the same green.
     */
    public function testAnExplicitAnswerStreamStillWinsOverThePin(): void
    {
        $pin = fopen('php://memory', 'r+');
        $named = fopen('php://memory', 'r+');
        self::assertIsResource($pin);
        self::assertIsResource($named);
        NonInteractive::pinStdinDefault($pin);

        $prompt = new HeadlessPermissionPrompt(PermissionMode::Default, $named);

        self::assertSame($named, self::answerStreamOf($prompt));
    }

    /**
     * PINNED DORMANCY: WITH NO PIN INSTALLED THE DEFAULT IS THE REAL `\STDIN`.
     *
     * This is the production claim, and it is the half a test suite normally
     * cannot make. `src/` and `bin/` never call `pinStdinDefault()` —
     * {@see NonInteractiveStdinPinTest} scans for that and is the reason this
     * test can read "no pin" as "a shipped run" — so a real
     * `sugarcrush -p "…" < file` gets exactly the descriptor it always did.
     * Without this arm the change could have swapped one wrong default for
     * another and stayed green.
     *
     * WHAT THIS TEST SAID: `assertSame(\STDIN, self::answerStreamOf($prompt))`.
     * WHAT IS TRUE NOW (E338): `tests/bootstrap.php` closes descriptor 0 on
     * every non-tty run, so that assertion was passing on a CLOSED resource —
     * PHPUnit names it "resource (closed)" — and what it pinned was that this
     * prompt got handed a dead handle. `NonInteractive::stdinDefault()` now
     * answers `null` for a dead descriptor rather than the corpse.
     * WHY THE TEST STILL EARNS ITS PLACE: its claim is about the RESOLUTION —
     * no pin means the process's own descriptor 0, not some other stream —
     * and that claim is unchanged. What it can observe changed, so it now
     * asserts the two things that are true of an unpinned prompt in a process
     * with no descriptor 0: the default resolved to nothing, and the prompt
     * consequently reports itself non-interactive. The second is why
     * {@see HeadlessPermissionPrompt} needed no change of its own for E338 —
     * `isInteractive()` already opens with `\is_resource($this->in)`, so the
     * `\fgets()` behind it is never reached.
     *
     * `assertFalse(\is_resource(\STDIN))` states the premise, so a bootstrap
     * that stopped closing descriptor 0 fails here with the reason visible
     * rather than turning the assertions below into a different claim.
     */
    public function testWithNoPinInstalledTheDefaultIsTheProcesssOwnDescriptorZero(): void
    {
        NonInteractive::pinStdinDefault(null);

        $prompt = new HeadlessPermissionPrompt(PermissionMode::Default);

        self::assertFalse(
            \is_resource(\STDIN),
            'this suite is supposed to run with descriptor 0 closed; the expectations below assume it',
        );
        self::assertNull(self::answerStreamOf($prompt));

        $isInteractive = new \ReflectionMethod(HeadlessPermissionPrompt::class, 'isInteractive');
        self::assertFalse($isInteractive->invoke($prompt));
    }

    /**
     * The prompt's `$in`, read straight off the object.
     *
     * Reflection rather than a getter: the property is private and adding an
     * accessor for a test would widen the class's surface for the benefit of
     * this file alone.
     *
     * @return resource
     */
    private static function answerStreamOf(HeadlessPermissionPrompt $prompt)
    {
        /** @var resource $stream */
        $stream = (new \ReflectionProperty(HeadlessPermissionPrompt::class, 'in'))->getValue($prompt);

        return $stream;
    }
}
