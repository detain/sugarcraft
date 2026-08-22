<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Diagnostics;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Kind;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Crush\Providers\ToolCallParser\DsmlToolCallParser;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\RuntimeNoticePumpMsg;
use SugarCraft\Crush\Support\ForkedChild;

/**
 * THE WHOLE PATH, and the reason this file is separate from
 * {@see RuntimeNoticeSinkTest}.
 *
 * E171's defect is not "the queue has no rows in it", it is "the queue has
 * rows and nothing reads them". A test that records into the sink and then
 * asserts the sink holds a row reproduces the defect rather than catching it:
 * that is exactly what
 * {@see \SugarCraft\Crush\Cli\Bootstrap::warnPermissionConfigInTranscript()}
 * did correctly for four rounds while its rows went nowhere after the drain.
 *
 * So every test here starts at a real emitter and ends at a real
 * {@see Chat}, and every assertion about DELIVERY is made on the transcript
 * rather than on the queue.
 *
 * TWO ASSERTIONS HERE DO READ THE SINK, AND THE LINE THIS SAID — "nothing
 * asserts on the sink's own state" — stopped being true when the drop-when-
 * unarmed gate landed. Both are PRECONDITIONS, not verdicts:
 * `assertFalse(RuntimeNoticeSink::isArmed())` in
 * {@see testAOneShotThatNeverBuiltAChatKeepsTheNoticeOnStderrOnly()} and
 * `assertTrue(RuntimeNoticeSink::record(...))` in
 * {@see testAChatNobodyAppointedDoesNotPollTheInbox()} exist so that an empty
 * transcript below cannot be an artefact of a sink that was never armed or a
 * row that was never accepted. The rule the original sentence was reaching for
 * survives intact: no test here PASSES on the strength of a row being in the
 * queue.
 *
 * THE FORK CASE IS THE ONE THAT MATTERS, because it is the interactive path.
 * {@see \SugarCraft\Crush\Backend\EngineBackend::completeAsync()} runs the
 * provider, the tool-call parser and the whole tool loop inside a
 * `pcntl_fork()`ed child. A notice raised there is raised in a process that is
 * about to `exit()`, and an in-memory sink would have been a seam with a
 * cliff at the fork boundary. This file forks a child that runs the REAL
 * parser and asserts the row arrives in the parent's transcript.
 */
final class RuntimeNoticeSinkDeliveryTest extends TestCase
{
    /** The DSML markup token; fullwidth vertical lines, not ASCII pipes. */
    private const T = "\u{FF5C}DSML\u{FF5C}";

    protected function setUp(): void
    {
        RuntimeNoticeSink::reset();
    }

    protected function tearDown(): void
    {
        RuntimeNoticeSink::reset();
    }

    /**
     * Content whose only DSML envelope is inside a code fence, which is the
     * cheapest way to make a real parser raise a real notice: the prefilter
     * matches, {@see \SugarCraft\Crush\Providers\ToolCallParser\MarkupScanner}
     * judges no occurrence to be an action, and the parser reports that it
     * chose not to fire.
     */
    private static function quotedEnvelope(): string
    {
        return "here is the format:\n```\n<" . self::T . "tool_calls>\n</" . self::T . "tool_calls>\n```\n";
    }

    /**
     * A Chat appointed as the process's drain owner, which is what
     * {@see \SugarCraft\Crush\Cli\Bootstrap::chat()} returns.
     *
     * NOT A CONVENIENCE WRAPPER. `drainsRuntimeNotices` defaults to false, and
     * {@see testAChatNobodyAppointedDoesNotPollTheInbox()} is why: `drain()` is
     * destructive, so a Chat nobody appointed must not be able to take rows out
     * from under the real transcript. Every test here that expects a poll has
     * to say so explicitly, exactly as `Bootstrap` does.
     */
    private static function ownerChat(bool $inFlight = false): Chat
    {
        return new Chat(inFlight: $inFlight, drainsRuntimeNotices: true);
    }

    /** Silence `error_log()`'s half for the duration; only the seam is under test here. */
    private static function withErrorLogDiscarded(callable $body): string
    {
        $log = tempnam(sys_get_temp_dir(), 'sc_lane_a_delivery_');
        self::assertIsString($log);
        $previous = ini_set('error_log', $log);

        try {
            $body();
        } finally {
            if ($previous !== false) {
                ini_set('error_log', $previous);
            }
        }

        $contents = (string) file_get_contents($log);
        @unlink($log);

        return $contents;
    }

    public function testAParserNoticeRaisedInThisProcessReachesTheTranscript(): void
    {
        // arm(false) is the in-process backend on purpose: this is the path an
        // embedder driving a Chat in one process takes, and it is the one where
        // the notice and its reader are the same process.
        RuntimeNoticeSink::arm(false);

        $chat = self::ownerChat();
        self::assertNull($chat->subscriptions(), 'an idle Chat with an empty sink must arm no timer');

        self::withErrorLogDiscarded(static function (): void {
            DsmlToolCallParser::new()->parse(['content' => self::quotedEnvelope()]);
        });

        $subscriptions = $chat->subscriptions();
        self::assertNotNull($subscriptions, 'a pending notice did not arm the poll');
        self::assertTrue($subscriptions->has('crush.runtime-notice-poll'));

        $tick = null;
        foreach ($subscriptions->all() as $subscription) {
            if ($subscription->id === 'crush.runtime-notice-poll') {
                $tick = $subscription;
            }
        }
        self::assertNotNull($tick);
        self::assertSame(Kind::Tick, $tick->kind);

        // Through the Msg the RUNTIME would produce, not one this test builds:
        // a tick whose produce() returned the wrong Msg would otherwise pass.
        $msg = ($tick->produce)();
        self::assertInstanceOf(RuntimeNoticePumpMsg::class, $msg);

        [$next, $cmd] = $chat->update($msg);

        self::assertNull($cmd);
        self::assertNotSame($chat, $next);

        $rows = array_values(array_filter(
            $next->history,
            static fn ($m): bool => $m->role === Role::System,
        ));
        self::assertCount(1, $rows);
        self::assertStringContainsString('DsmlToolCallParser', $rows[0]->content);
        self::assertStringContainsString('no tool call recovered', strtolower($rows[0]->content));

        // AND IT IS ON THE SCREEN, not merely in the array. A row the renderer
        // drops is the same defect one layer up.
        self::assertStringContainsString('DsmlToolCallParser', $next->view());
    }

    public function testTheSecondPumpAddsNothingBecauseTheFirstConsumedTheInbox(): void
    {
        RuntimeNoticeSink::arm(false);

        self::withErrorLogDiscarded(static function (): void {
            DsmlToolCallParser::new()->parse(['content' => self::quotedEnvelope()]);
        });

        [$once] = self::ownerChat()->update(new RuntimeNoticePumpMsg());
        [$twice, $cmd] = $once->update(new RuntimeNoticePumpMsg());

        self::assertNull($cmd);
        self::assertSame($once, $twice, 'an empty pump must return $this rather than repaint');
        self::assertCount(1, $once->history);
    }

    /**
     * THE `-p` ONE-SHOT'S SHAPE, END TO END: a real parser raises a real notice
     * in a process that never built a `Chat`, and the transcript stays empty
     * while stderr gets the whole thing.
     *
     * This is the gate {@see RuntimeNoticeSink::record()} applies, asserted
     * where it matters rather than on the sink's own state.
     * {@see \SugarCraft\Crush\Cli\NonInteractive} never reaches
     * {@see \SugarCraft\Crush\Cli\Bootstrap::chat()}, which is the only
     * caller of `arm()` in `src/`, so nothing in that process would ever drain
     * a queued row — and a sink nothing drains is E171's own defect one level
     * down.
     */
    public function testAOneShotThatNeverBuiltAChatKeepsTheNoticeOnStderrOnly(): void
    {
        self::assertFalse(RuntimeNoticeSink::isArmed(), 'the fixture armed the sink; this test proves nothing');

        $log = self::withErrorLogDiscarded(static function (): void {
            DsmlToolCallParser::new()->parse(['content' => self::quotedEnvelope()]);
        });

        // The emitter really did fire — otherwise the empty transcript below
        // would be an artefact of a fixture that stopped triggering it.
        self::assertStringContainsString('DsmlToolCallParser', $log);

        $chat = self::ownerChat();
        self::assertNull($chat->subscriptions(), 'an unarmed sink armed the poll anyway');

        [$next] = $chat->update(new RuntimeNoticePumpMsg());
        self::assertSame([], $next->history);
    }

    public function testAnIdleChatWithNothingPendingArmsNoTimerAtAll(): void
    {
        // The objection Chat::subscriptions()' doc-block raises against an
        // unconditional tick: a timer waking the loop and repainting forever on
        // the overwhelmingly common launch where nothing ever warns. The Chat
        // is the appointed drain owner, so the null is about the empty inbox and
        // not about the appointment.
        self::assertNull(self::ownerChat()->subscriptions());
    }

    public function testATurnInFlightArmsThePollBeforeAnythingHasWarned(): void
    {
        // The load-bearing half of the ORed condition. Every mid-session
        // emitter raises its notice DURING a turn, and on the interactive path
        // from inside a forked child — so the poll has to be running already,
        // not waiting for a Msg that happens to arrive after the row lands.
        $subscriptions = self::ownerChat(inFlight: true)->subscriptions();

        self::assertNotNull($subscriptions);
        self::assertTrue($subscriptions->has('crush.runtime-notice-poll'));
    }

    /**
     * A CHAT NOBODY APPOINTED DOES NOT POLL, even with rows waiting.
     *
     * `RuntimeNoticeSink::drain()` is DESTRUCTIVE. Two Chats polling the one
     * process-wide inbox would not each get the row — the first tick to fire
     * would take it, and which transcript a mid-session warning landed in would
     * be a race. So the reader is appointed, once, by the method that opens the
     * inbox.
     *
     * THE POSITIVE CONTROL IS IN THE SAME TEST, because "nothing happened" is
     * the assertion round 44 proved is worth nothing on its own: the identical
     * sink state is handed to an appointed Chat and MUST produce the poll.
     */
    public function testAChatNobodyAppointedDoesNotPollTheInbox(): void
    {
        RuntimeNoticeSink::arm(false);
        self::assertTrue(RuntimeNoticeSink::record('a row with an owner to find it'));

        self::assertNull(
            (new Chat())->subscriptions(),
            'a Chat nobody appointed polled the inbox; it would steal the real transcript\'s rows',
        );
        // An in-flight Chat declares the tool-event poll regardless, so this
        // one asks about the runtime-notice tick specifically rather than about
        // the set being empty.
        $inFlight = (new Chat(inFlight: true))->subscriptions();
        self::assertNotNull($inFlight, 'the tool-event poll vanished; this assertion is now vacuous');
        self::assertFalse(
            $inFlight->has('crush.runtime-notice-poll'),
            'an in-flight turn on an unappointed Chat polled the inbox',
        );

        // KNOWN-POSITIVE: the same sink, the same row, an appointed reader.
        $subscriptions = self::ownerChat()->subscriptions();
        self::assertNotNull($subscriptions, 'the appointed Chat did not poll either; this test proves nothing');
        self::assertTrue($subscriptions->has('crush.runtime-notice-poll'));
    }

    /**
     * THE APPOINTMENT SURVIVES A KEYSTROKE, which is the `mutate()` half.
     *
     * A field missing from `Chat::mutate()`'s constructorProps map is silently
     * dropped on the next unrelated state change, so a drain owner that stopped
     * being one the moment the user typed would leave every mid-session notice
     * for the rest of the session with no reader — E171 exactly, reintroduced
     * by the omission of one array line.
     */
    public function testTheAppointmentSurvivesAnUnrelatedMutation(): void
    {
        RuntimeNoticeSink::arm(false);

        [$typed] = self::ownerChat()->update(new \SugarCraft\Core\Msg\KeyMsg(
            \SugarCraft\Core\KeyType::Char,
            'x',
        ));

        self::assertTrue(RuntimeNoticeSink::record('raised after the keystroke'));

        $subscriptions = $typed->subscriptions();
        self::assertNotNull($subscriptions, 'the drain owner lost its appointment on a keystroke');
        self::assertTrue($subscriptions->has('crush.runtime-notice-poll'));

        [$next] = $typed->update(new RuntimeNoticePumpMsg());
        $rows = array_values(array_filter(
            $next->history,
            static fn ($m): bool => $m->role === Role::System,
        ));
        self::assertCount(1, $rows);
        self::assertSame('raised after the keystroke', $rows[0]->content);
    }

    public function testANoticeRaisedInAForkedChildReachesTheParentsTranscript(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            self::markTestSkipped('ext-pcntl is required to exercise the fork boundary');
        }

        self::assertTrue(RuntimeNoticeSink::arm());

        $log = tempnam(sys_get_temp_dir(), 'sc_lane_a_delivery_fork_');
        self::assertIsString($log);

        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid, 'fork failed');

        if ($pid === 0) {
            // The child: exactly what EngineBackend::completeAsync() does with
            // the engine loop, reduced to the one call that warns.
            ini_set('error_log', $log);
            DsmlToolCallParser::new()->parse(['content' => self::quotedEnvelope()]);
            ForkedChild::exitNow(0);
        }

        $status = 0;
        pcntl_waitpid($pid, $status);
        self::assertSame(0, pcntl_wexitstatus($status));

        // The child wrote its stderr copy too — proof it really ran the emitter
        // rather than the parent having done it.
        self::assertStringContainsString('DsmlToolCallParser', (string) file_get_contents($log));
        @unlink($log);

        $chat = self::ownerChat();
        $subscriptions = $chat->subscriptions();
        self::assertNotNull($subscriptions, 'the child\'s notice did not cross the fork');
        self::assertTrue($subscriptions->has('crush.runtime-notice-poll'));

        [$next] = $chat->update(new RuntimeNoticePumpMsg());

        $rows = array_values(array_filter(
            $next->history,
            static fn ($m): bool => $m->role === Role::System,
        ));
        self::assertCount(1, $rows);
        self::assertStringContainsString('DsmlToolCallParser', $rows[0]->content);
        self::assertStringContainsString('DsmlToolCallParser', $next->view());
    }

    public function testNoticesFromSeveralChildrenAllArriveWholeAndInOrder(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            self::markTestSkipped('ext-pcntl is required to exercise the fork boundary');
        }

        // A SOCK_STREAM transport would interleave these into each other; the
        // datagram framing is what makes each write one indivisible message.
        // This is the assertion that would catch someone "simplifying" the pair
        // to SOCK_STREAM.
        RuntimeNoticeSink::arm();

        $pids = [];
        for ($k = 0; $k < 3; $k++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                RuntimeNoticeSink::record("child {$k} says " . str_repeat('.', 200));
                ForkedChild::exitNow(0);
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            $status = 0;
            pcntl_waitpid($pid, $status);
        }

        [$next] = self::ownerChat()->update(new RuntimeNoticePumpMsg());

        $rows = array_values(array_filter(
            $next->history,
            static fn ($m): bool => $m->role === Role::System,
        ));
        self::assertCount(3, $rows);
        foreach ($rows as $i => $row) {
            self::assertSame("child {$i} says " . str_repeat('.', 200), $row->content);
        }
    }

    public function testTheTranscriptRowCarriesNoStderrEnvelope(): void
    {
        // Bootstrap's launch rows deliberately carry neither the `sugarcrush: `
        // prefix nor the trailing full stop that STDERR_LINE_FORMAT adds, and
        // these rows are the same surface. A prefix leaking into the
        // conversation is a token cost and a lie about where the row came from.
        RuntimeNoticeSink::arm(false);

        self::withErrorLogDiscarded(static function (): void {
            RuntimeNoticeSink::warn('a bare sentence with no envelope');
        });

        [$next] = self::ownerChat()->update(new RuntimeNoticePumpMsg());

        $rows = array_values(array_filter(
            $next->history,
            static fn ($m): bool => $m->role === Role::System,
        ));
        self::assertSame('a bare sentence with no envelope', $rows[0]->content);
        self::assertStringNotContainsString('sugarcrush:', $rows[0]->content);
    }
}
