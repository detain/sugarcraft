<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PHPUnit\Framework\TestCase;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Code\Attachments\MentionIndex;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Events\ToolStarted;
use SugarCraft\Code\Host\TurnRunner;
use SugarCraft\Code\Message;
use SugarCraft\Code\Workspace\GitRunner;

/**
 * C-WEB-1.c (plan_comparison): the `@`-mention index refreshes at EVERY turn
 * end, TUI and serve alike.
 *
 * {@see MentionIndex} memoizes one `git ls-files` per root per process, and
 * the memo's only releaser was `forget()` — a hook with test callers only.
 * The consequence was that a file the agent wrote never reached the popup (or
 * the web `@` list, which routes through the same memo) until the process
 * restarted. The fix lands on the one settle seam both hosts share:
 * {@see TurnRunner::complete()} forgets the memos as its first statement.
 *
 * These pins drive a REAL started turn (a hand-resolved backend promise, the
 * same shape the TUI's Cmd and the serve host's pump settle through) and
 * never call {@see MentionIndex::forget()} between the mid-turn assertion and
 * the after-turn one: the refresh must come from the turn closing, not from
 * the test clearing state. The fixtures are real `git init` roots with no
 * `git add` anywhere — the untracked `--others` listing is exactly the shape
 * a Write, ApplyPatch or Bash creates, so "present after the turn" pins the
 * fresh file's arrival by its untracked-ness.
 */
final class TurnRunnerMentionFreshnessTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        if (!GitRunner::available()) {
            self::markTestSkipped('git is not available');
        }
        MentionIndex::forget();
        $this->root = \sys_get_temp_dir() . '/crush-mention-fresh-' . \bin2hex(\random_bytes(6));
        \mkdir($this->root, 0o700, true);
        \exec('git init --quiet ' . \escapeshellarg($this->root) . ' 2>&1');
        \file_put_contents($this->root . '/early-note.md', 'there from the start');
    }

    protected function tearDown(): void
    {
        MentionIndex::forget();
        \exec('rm -rf ' . \escapeshellarg($this->root) . ' 2>&1');
    }

    /**
     * The regression itself: a file written while a started turn runs is
     * absent from the index before the turn closes and listed after it, with
     * no forget() call in between — closing the turn IS the refresh.
     */
    public function testAFileWrittenDuringATurnAppearsInTheIndexWhenTheTurnCompletes(): void
    {
        // Prime the memo the way the first `@` of a session does.
        self::assertContains('early-note.md', MentionIndex::files($this->root));

        $runner = TurnRunner::new();
        $cancellation = new CancellationToken();
        $backend = self::singleTurnBackend();
        $run = $runner->start(
            backend: $backend,
            history: [Message::user('write a note')],
            inbox: new \ArrayObject(),
            generation: 1,
            cancellation: $cancellation,
        );
        $run();

        // Mid-turn: the Write's file exists on disk, the memo cannot see it.
        \file_put_contents($this->root . '/fresh-note.md', 'written by the turn');
        self::assertNotContains('fresh-note.md', MentionIndex::files($this->root), 'the memo holds while the turn is open');
        self::assertNotContains('fresh-note.md', array_column(MentionIndex::rank('fresh', $this->root), 'path'));

        $backend->answer(Message::assistant('done'));

        self::assertContains('fresh-note.md', MentionIndex::files($this->root), 'the turn closing re-listed the root');
        self::assertContains('fresh-note.md', array_column(MentionIndex::rank('fresh', $this->root), 'path'), 'and the popup shows it untracked and all');
    }

    /**
     * A hard-cancelled turn counts too: its tools ran, so whatever they wrote
     * must be visible at the next `@`. The refresh also lands on the branch
     * that records only the turn's end and returns before any reply row.
     */
    public function testACancelledTurnStillRefreshesTheIndex(): void
    {
        self::assertContains('early-note.md', MentionIndex::files($this->root));

        $runner = TurnRunner::new();
        $cancellation = new CancellationToken();
        $backend = self::singleTurnBackend();
        $run = $runner->start(
            backend: $backend,
            history: [Message::user('cancel me')],
            inbox: new \ArrayObject(),
            generation: 1,
            cancellation: $cancellation,
        );
        $run();
        $cancellation->cancel();

        \file_put_contents($this->root . '/late-cancel.md', 'written before the cancel landed');
        self::assertNotContains('late-cancel.md', MentionIndex::files($this->root));

        $backend->answer(Message::assistant('too late'));

        self::assertContains('late-cancel.md', MentionIndex::files($this->root), 'the cancel branch never skips the first-statement refresh');
    }

    /**
     * The parked path (events still to fold when the reply settles): the
     * refresh belongs to the CLOSE, not to the settle — the listing stays
     * memoized while the caller is still applying the turn's events, and the
     * new file is visible once completeParked() reaches complete().
     */
    public function testAParkedTurnRefreshesOnlyWhenItActuallyCloses(): void
    {
        self::assertContains('early-note.md', MentionIndex::files($this->root));

        $runner = TurnRunner::new();
        $cancellation = new CancellationToken();
        $backend = self::singleTurnBackend();
        $inbox = new \ArrayObject();
        $run = $runner->start(
            backend: $backend,
            history: [Message::user('run a tool')],
            inbox: $inbox,
            generation: 1,
            cancellation: $cancellation,
        );
        $run();

        ($backend->emit)(new ToolStarted('c1', 'Bash', ['command' => 'touch a file']));
        \file_put_contents($this->root . '/parked-note.md', 'written by the tool');
        $backend->answer(Message::assistant('tool ran'));

        self::assertNotContains('parked-note.md', MentionIndex::files($this->root), 'settle parked behind the unfolded event; the turn has not closed');

        $runner->completeParked($cancellation);

        self::assertContains('parked-note.md', MentionIndex::files($this->root), 'and the parked close refreshes like any other');
    }

    /**
     * A backend whose one turn the test answers by hand; `emit` reports on
     * the running turn's event channel so a test can park its settle.
     */
    private static function singleTurnBackend(): Backend
    {
        return new class () implements Backend {
            public ?Deferred $deferred = null;

            public \Closure $emit;

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                return Message::assistant('unused');
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                $this->deferred = new Deferred();
                $this->emit = $onEvent === null ? static fn (object $event): ?object => $event : \Closure::fromCallable($onEvent);

                return $this->deferred->promise();
            }

            public function answer(Message $reply): void
            {
                $this->deferred?->resolve($reply);
            }
        };
    }
}
