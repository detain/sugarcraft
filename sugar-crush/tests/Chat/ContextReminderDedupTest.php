<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\ReportsContextWindow;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;

/**
 * The context-usage reminder must stop ACCUMULATING in permanent history
 * (hardening backlog E33), without stopping reminding.
 *
 * The distinction these tests exist to draw: `Chat::dispatchTurn()` commits the
 * reminder into `history`, and `ContextCompactor::shouldSendReminder()` is pure
 * and stateless — a bare `$tokenCount >= $threshold` with no latch — so it
 * answered true on every turn once the estimate crossed the line and every
 * answer was appended, checkpointed and re-sent. Twenty turns past the
 * threshold meant twenty near-identical system messages.
 *
 * THESE TESTS ASSERT A QUANTITY, NOT A PRESENCE, and that is the whole point.
 * "A reminder is present in history" passes under the accumulation bug, under
 * the deduplication that fixes it, and under a fire-once latch that would leave
 * a stale figure on screen forever — it discriminates nothing. The count of
 * copies separates the first from the other two, and the FIGURE the survivor
 * carries separates the latch from the fix.
 */
final class ContextReminderDedupTest extends TestCase
{
    /**
     * The marker `Chat::contextReminderMessage()` is the sole producer of.
     *
     * Duplicated here rather than read off the private constant on purpose: a
     * test that derives its expectation from the implementation cannot notice
     * the implementation changing. Everything AFTER this point in the message
     * embeds the live token count, which is exactly why the production
     * predicate cannot use full-text equality.
     */
    private const REMINDER_MARKER = 'Heads up: this conversation has grown to ~';

    /**
     * ~280,000 chars ≈ 70,010 ESTIMATED tokens on `Chat::estimateTokenCount()`'s
     * chars/4 + 10-per-message formula.
     *
     * Sized to sit in the band this bundle is about, against the 100,000-token
     * window {@see ReminderWireRecorder} reports: over the 70% reminder tier
     * (70,000) and far under the 85% automatic-compaction tier (85,000), so the
     * reminder fires on every turn of the drive loop and no compaction tier ever
     * rewrites the history out from under the assertion. Each turn adds roughly
     * 23 estimated tokens (a short prompt plus a two-character reply), so the
     * six turns below move the figure by ~140 and never approach 85,000.
     */
    private const OVER_THRESHOLD_CHARS = 280_000;

    /** How many turns are driven past the threshold. */
    private const TURNS = 6;

    /**
     * The bug, as a quantity: N turns past the threshold must leave exactly ONE
     * reminder in history, and it must be the newest one.
     *
     * Under the accumulation bug this history ends with six reminders. Under a
     * fire-once latch it ends with one carrying turn 1's figure. Only the
     * deduplication satisfies both halves at once.
     */
    public function testSixTurnsPastTheThresholdLeaveExactlyOneReminderCarryingTheNewestFigure(): void
    {
        $backend = new ReminderWireRecorder(100_000);
        $chat = new Chat(
            history: [Message::user(str_repeat('x', self::OVER_THRESHOLD_CHARS))],
            backend: $backend,
        );

        $figures = [];
        for ($turn = 1; $turn <= self::TURNS; $turn++) {
            [$chat, $cmd] = $this->type($chat, "turn{$turn}");
            $this->assertInstanceOf(
                \Closure::class,
                $cmd,
                "fixture: turn {$turn} must actually dispatch, or nothing under test ran",
            );

            $inHistory = self::remindersIn($chat->history);
            $this->assertCount(
                1,
                $inHistory,
                "after turn {$turn} history must carry exactly one reminder, not "
                . count($inHistory) . ' - the pile-up is the bug',
            );
            $figures[] = self::figureOf($inHistory[0]);

            // Still reminding: the copy that survives is on the wire for the
            // turn it fires on, not merely in the local transcript. Asserted
            // after the Cmd is driven, because that is what calls the backend.
            $reply = $this->resolve($cmd);
            $this->assertCount(
                1,
                self::remindersIn($backend->lastHistory()),
                "turn {$turn}'s wire must carry the reminder exactly once",
            );

            [$chat] = $chat->update($reply);
        }

        $this->assertSame(
            self::TURNS,
            count($figures),
            'fixture: every turn must have produced a figure to compare',
        );
        $sorted = $figures;
        sort($sorted);
        $this->assertSame(
            $sorted,
            $figures,
            'each turn\'s reminder must quote a LARGER estimate than the last: a figure that '
            . 'stopped moving is a stale copy surviving instead of a fresh one replacing it',
        );
        $this->assertGreaterThan(
            $figures[0],
            $figures[self::TURNS - 1],
            'the surviving reminder must carry the newest figure, not turn 1\'s - which is what '
            . 'separates deduplication from a fire-once latch',
        );
        $this->assertGreaterThan(
            70_000,
            $figures[0],
            'fixture: the figure must be over the 70% tier of the 100,000-token window, or the '
            . 'reminder was never due and this test asserts nothing',
        );
    }

    /**
     * The predicate must never delete a user's own message.
     *
     * Quoting the reminder back into a prompt to ask what it meant is an
     * ordinary thing to do, and the quote is byte-identical to a real reminder,
     * so a role-blind stale-copy predicate silently eats it. `Role::User` is the
     * half of the predicate that prevents that, and this is the test that fails
     * when it is dropped.
     */
    public function testAUserMessageQuotingTheReminderIsNeverRemoved(): void
    {
        $quote = self::REMINDER_MARKER
            . '12345 estimated tokens, past the context-usage reminder threshold. '
            . 'Consider running /compact soon to keep the session responsive.';

        $backend = new ReminderWireRecorder(100_000);
        $chat = new Chat(
            history: [
                Message::user(str_repeat('x', self::OVER_THRESHOLD_CHARS)),
                Message::user($quote),
            ],
            backend: $backend,
        );

        [$next, $cmd] = $this->type($chat, 'what did that mean?');
        $this->assertInstanceOf(\Closure::class, $cmd, 'fixture: the turn must dispatch');

        $kept = array_values(array_filter(
            $next->history,
            static fn(Message $m): bool => $m->role === Role::User && $m->content === $quote,
        ));
        $this->assertCount(1, $kept, 'the user\'s own words must survive the deduplication');

        // And the app's own copy is still there exactly once beside it, so this
        // does not pass by the dedup having switched off altogether.
        $this->assertCount(
            1,
            self::remindersIn($next->history),
            'fixture: the tier must still have fired, or the negative proves nothing',
        );
    }

    /**
     * The reminder is not merely present after the first turn - it reaches the
     * provider on the turn it fires. Deduplication bounds the copies; it must
     * not suppress the reminding.
     */
    public function testTheReminderReachesTheWireOnTheTurnItFires(): void
    {
        $backend = new ReminderWireRecorder(100_000);
        $chat = new Chat(
            history: [Message::user(str_repeat('x', self::OVER_THRESHOLD_CHARS))],
            backend: $backend,
        );

        [$next, $cmd] = $this->type($chat, 'hello');
        $this->assertInstanceOf(\Closure::class, $cmd);
        $this->resolve($cmd);

        $this->assertSame(1, $backend->calls(), 'fixture: the backend must have been called once');
        $wire = $backend->lastHistory();
        $this->assertCount(1, self::remindersIn($wire), 'the reminder must be on the wire, once');
        $this->assertSame(
            Role::System,
            $wire[count($wire) - 1]->role,
            'and it rides AFTER the prompt as a system instruction, not before it',
        );
    }

    /**
     * A history under the threshold gets no reminder appended, and nothing else
     * about it moves.
     *
     * NOT because the strip is unreachable below the tier - it runs on every
     * dispatch, which is what finding 1 of E33's review round is about. It has
     * nothing to remove here, so the observable is only the negative: no
     * reminder appended, and a history that still holds exactly what was put in
     * it plus the prompt.
     * {@see testABelowThresholdDispatchRemovesAStalePreExistingReminder()} is
     * the positive half, and is the test that fails if the strip is scoped back
     * inside the tier arm - this one passes either way, because its history
     * carries no stale copy to strip.
     */
    public function testNothingIsAppendedOrRemovedUnderTheThreshold(): void
    {
        $backend = new ReminderWireRecorder(100_000);
        $chat = new Chat(history: [Message::user('hi')], backend: $backend);

        [$next, $cmd] = $this->type($chat, 'hello');
        $this->assertInstanceOf(\Closure::class, $cmd);

        $this->assertSame([], self::remindersIn($next->history));
        $this->assertCount(2, $next->history, 'the prompt, and nothing else');
    }

    /**
     * The strip is UNCONDITIONAL: a dispatch below the tier must remove a
     * reminder the history already carries, not leave it standing.
     *
     * This is finding 1 of E33's review round, and it is the case `/compact` is
     * for. With the strip scoped inside `if (shouldSendReminder(...))` - which
     * is what the bundle shipped - a session that compacts back under the line
     * keeps the last copy it was sent FOREVER: measured at 22% of the window,
     * the transcript still read "grown to ~70440 estimated tokens, past the
     * context-usage reminder threshold. Consider running /compact soon"
     * immediately after the user ran `/compact`, and it went back on the
     * provider wire on every turn after that. `Renderer` walks `Role::System`
     * entries, so it is on screen too.
     *
     * A fire-once latch has exactly this failure mode, which is why the
     * argument for deduplication over a latch only holds with the strip
     * ungated.
     */
    public function testABelowThresholdDispatchRemovesAStalePreExistingReminder(): void
    {
        $backend = new ReminderWireRecorder(100_000);
        $chat = new Chat(
            history: [Message::user('hi'), self::staleReminder(70_440)],
            backend: $backend,
        );

        [$next, $cmd] = $this->type($chat, 'hello');
        $this->assertInstanceOf(\Closure::class, $cmd, 'fixture: the turn must dispatch');

        $this->assertSame(
            [],
            self::remindersIn($next->history),
            'a stale reminder must be dropped even on a turn the tier does not fire on, or '
            . '/compact can never clear the warning it was run in answer to',
        );
        $this->assertSame(
            ['hi', 'hello'],
            array_map(static fn(Message $m): string => $m->content, $next->history),
            'and nothing but the reminder is dropped',
        );
    }

    /**
     * The quoted figure is counted BEFORE the strip, so the message can never
     * name a number below the threshold it says it is past.
     *
     * This is the contradiction the production comment calls load-bearing, and
     * until this test it was unpinned: swapping the count and the strip survived
     * both the targeted file and the full suite with byte-identical totals,
     * because every other fixture here sits far enough over the tier that
     * dropping 53 tokens from the figure still leaves it over.
     *
     * The fixture is sized to the boundary on purpose. 279,748 chars of filler
     * is 69,947 estimated tokens on `Chat::estimateTokenCount()`'s chars/4 + 10
     * formula, one stale reminder is 53, and 69,947 + 53 is exactly the 70,000
     * threshold of the 100,000-token window the backend reports. Counting after
     * the strip quotes 69,947 - under the line it claims to be past.
     */
    public function testTheQuotedFigureIsNeverBelowTheThresholdItSaysItIsPast(): void
    {
        $backend = new ReminderWireRecorder(100_000);
        $chat = new Chat(
            history: [
                Message::user(str_repeat('x', 279_748)),
                self::staleReminder(70_000),
            ],
            backend: $backend,
        );

        [$next, $cmd] = $this->type($chat, 'hello');
        $this->assertInstanceOf(\Closure::class, $cmd, 'fixture: the turn must dispatch');

        $survivors = self::remindersIn($next->history);
        $this->assertCount(
            1,
            $survivors,
            'fixture: the tier must have fired at exactly the threshold, or this asserts nothing',
        );
        $this->assertGreaterThanOrEqual(
            70_000,
            self::figureOf($survivors[0]),
            'the figure the message quotes must be >= the threshold it claims to be past: '
            . 'counted after the strip it is 69,947 against a 70,000 threshold, and the '
            . 'sentence contradicts itself',
        );
    }

    /**
     * A history carrying SEVERAL stale copies collapses to one in a SINGLE
     * dispatch - which is what every session and checkpoint written before this
     * bundle holds.
     *
     * Not an equivalent-mutant guard: a stripper that removed only the first
     * match would take one turn per accumulated copy, so a resumed 20-turn
     * session would still be sending 19 of them on its next request and would
     * need 19 more turns to drain. Removing only the first survived both the
     * targeted file and the full suite before this test existed, because every
     * other fixture here starts from a history with at most one copy.
     */
    public function testALegacyHistoryOfManyStaleCopiesCollapsesInOneDispatch(): void
    {
        $seeded = [70_001, 70_002, 70_003, 70_004, 70_005];
        $history = [Message::user(str_repeat('x', self::OVER_THRESHOLD_CHARS))];
        foreach ($seeded as $figure) {
            $history[] = self::staleReminder($figure);
        }

        $backend = new ReminderWireRecorder(100_000);
        $chat = new Chat(history: $history, backend: $backend);
        $this->assertCount(5, self::remindersIn($history), 'fixture: five copies to collapse');

        [$next, $cmd] = $this->type($chat, 'hello');
        $this->assertInstanceOf(\Closure::class, $cmd, 'fixture: the turn must dispatch');

        $survivors = self::remindersIn($next->history);
        $this->assertCount(
            1,
            $survivors,
            'five stale copies must collapse to one in ONE dispatch, not one copy per turn - '
            . 'got ' . count($survivors),
        );
        $this->assertNotContains(
            self::figureOf($survivors[0]),
            $seeded,
            'and the survivor must be the freshly built copy, not whichever seeded one the '
            . 'strip happened to leave behind',
        );
    }

    /**
     * A verbatim reminder as `Chat::contextReminderMessage()` would have written
     * it on an earlier turn, for fixtures that need a stale copy already in
     * history.
     *
     * Assembled from the same literal marker the assertions use rather than from
     * the production constant, for the reason given on
     * {@see self::REMINDER_MARKER}.
     */
    private static function staleReminder(int $figure): Message
    {
        return Message::system(
            self::REMINDER_MARKER
            . "{$figure} estimated tokens, past the context-usage reminder threshold. "
            . 'Consider running /compact soon to keep the session responsive.'
        );
    }

    /**
     * Every verbatim reminder in $history: `Role::System` AND carrying the
     * marker. Deliberately the same two-part shape as the production predicate,
     * because a test that matched on role alone would count the tier reports and
     * tool-running placeholders too.
     *
     * @param list<Message> $history
     * @return list<Message>
     */
    private static function remindersIn(array $history): array
    {
        return array_values(array_filter(
            $history,
            static fn(Message $m): bool => $m->role === Role::System
                && str_starts_with($m->content, self::REMINDER_MARKER),
        ));
    }

    /**
     * The estimated-token figure a reminder quotes. Returns -1 when the message
     * does not carry one, so a missing figure fails a comparison instead of
     * silently reading as zero.
     */
    private static function figureOf(Message $msg): int
    {
        return preg_match('/grown to ~(\d+) estimated tokens/', $msg->content, $m) === 1
            ? (int) $m[1]
            : -1;
    }

    /**
     * Type $draft one keystroke at a time and submit it. Typed rather than
     * injected because `Chat::withInputBuf()` is private and typing is the route
     * a user actually has.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function type(Chat $chat, string $draft): array
    {
        foreach (mb_str_split($draft) as $char) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Char, $char));
        }

        return $chat->update(new KeyMsg(KeyType::Enter, ''));
    }

    /** Drive a Cmd built by Cmd::promise() and hand back the Msg it resolves to. */
    private function resolve(\Closure $cmd): mixed
    {
        $asyncCmd = $cmd();
        $this->assertInstanceOf(AsyncCmd::class, $asyncCmd);
        $resolved = null;
        $asyncCmd->promise->then(function ($msg) use (&$resolved): void {
            $resolved = $msg;
        });

        return $resolved;
    }
}

/**
 * A backend that records the history of every turn it is handed and reports a
 * fixed context window, so the reminder tier has a real number to divide by.
 *
 * Its reply is two characters on purpose: `EchoBackend` echoes the last user
 * message back, and the fixture's last user message is 280,000 characters, so
 * echoing it would double the history every turn and walk the drive loop
 * straight through the 85% and 95% tiers.
 *
 * Named distinctly from `RecordingTurnBackend` in
 * {@see AutomaticCompactionModelSummaryTest} because both would live in this
 * namespace and the second declaration is a fatal error in a full-suite run.
 */
final class ReminderWireRecorder implements Backend, ReportsContextWindow
{
    /** @var list<list<Message>> */
    private array $seen = [];

    public function __construct(private readonly int $window) {}

    public function contextWindow(): int
    {
        return $this->window;
    }

    public function calls(): int
    {
        return count($this->seen);
    }

    /** @return list<Message> */
    public function lastHistory(): array
    {
        return $this->seen[count($this->seen) - 1] ?? [];
    }

    public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
    {
        $this->seen[] = $history;

        return Message::assistant('ok');
    }

    public function completeAsync(
        array $history,
        callable $onToken = null,
        ?CancellationToken $cancellation = null,
        ?callable $onEvent = null,
    ): PromiseInterface {
        $this->seen[] = $history;

        return \React\Promise\resolve(Message::assistant('ok'));
    }
}
