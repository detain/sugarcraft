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
use SugarCraft\Crush\Tests\Support\ReapsForkedChildrenTrait;

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
 * SOME ASSERTIONS HERE DO READ THE SINK. WHAT THIS SAID: "nothing asserts on
 * the sink's own state". WHAT IS TRUE NOW: that stopped being true when the
 * drop-when-unarmed gate landed, and it became less true again when
 * {@see testASecondBootstrapChatDoesNotInheritTheFirstsUndrainedInbox()}
 * arrived. WHY THE PARAGRAPH STILL EARNS ITS PLACE: every one of them is a
 * PRECONDITION, not a verdict — `assertFalse(RuntimeNoticeSink::isArmed())`,
 * `assertTrue(RuntimeNoticeSink::record(...))` and
 * `assertTrue(RuntimeNoticeSink::hasPending())` all exist so that an empty
 * transcript below cannot be an artefact of a sink that was never armed, a row
 * that was never accepted, or a row that was never pending in the first place.
 * The rule the original sentence was reaching for survives intact and is the
 * one to keep applying: no test here PASSES on the strength of a row being in
 * the queue. The one `assertFalse(RuntimeNoticeSink::hasPending())` that IS a
 * verdict is paired with a transcript assertion in the same test, and with a
 * known-positive after it.
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
    /**
     * ADOPTED AT MERGE, not by the lane that wrote this file. Round 47's lane
     * b widened ForkedChildReaperAdoptionTest::SCOPE while lane a was adding
     * this file, and neither lane could see the other; the merged tree went
     * red on a guard that was working exactly as designed. Both fork sites
     * below already reaped synchronously on the happy path — the reaper is
     * what covers the path where the parent is aborted at the time limit and
     * the explicit pcntl_waitpid() never runs, which is E142's mechanism and
     * the reason the trait exists.
     */
    use ReapsForkedChildrenTrait;

    /** The DSML markup token; fullwidth vertical lines, not ASCII pipes. */
    private const T = "\u{FF5C}DSML\u{FF5C}";

    /**
     * The provider environment this class has to neutralise, copied from
     * {@see \SugarCraft\Crush\Tests\Cli\BootstrapTest} rather than invented
     * here.
     *
     * TWO TESTS BELOW CALL `Bootstrap::chat()` FOR REAL, and `chat()` reaches
     * `backend()`, which reads all five of these. `SUGARCRUSH_BACKEND_CMD`
     * NAMES A COMMAND — so an ambient value does not merely change which
     * branch is exercised, it changes what this suite would run. Leaving them
     * to whatever an earlier test class or the operator's shell happens to
     * have set is how a green test stops describing the path it claims to.
     *
     * @var list<string>
     */
    private const VOLATILE_PROVIDER_ENV = [
        'SUGARCRUSH_PROVIDER',
        'SUGARCRUSH_BACKEND_CMD',
        'SUGARCRUSH_BACKEND_CMD_STREAM',
        'SUGARCRUSH_MODEL',
        'SUGARCRUSH_TITLE_MODEL',
    ];

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        RuntimeNoticeSink::reset();

        foreach (self::VOLATILE_PROVIDER_ENV as $name) {
            $this->savedEnv[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        $this->reapTrackedForkedChildren();

        RuntimeNoticeSink::reset();

        foreach ($this->savedEnv as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv("{$name}={$value}");
            }
        }
        $this->savedEnv = [];
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
     * THE POLL INTERVAL'S DOC-BLOCK MAKES A CHECKABLE CLAIM, so it is checked.
     *
     * `Chat::RUNTIME_NOTICE_POLL_SECONDS`' doc-block argues at length that it
     * is "SLOWER THAN {@see Chat::TOOL_EVENT_POLL_SECONDS} ON PURPOSE" and
     * gives the reason: a tool event is a two-state transition the user
     * watches, a notice is one static row of prose that reads identically half
     * a second later, and the slower tick halves the wake-ups on the one path
     * where the loop is already servicing the tool-event pump, the provider
     * socket and the spinner. Nothing held that. MEASURED, round 47: changing
     * `RUNTIME_NOTICE_POLL_SECONDS` from 0.5 to 30.0 was green.
     *
     * THE RELATION AND NOT THE LITERAL, deliberately. Both figures are
     * judgement calls that a later round may reasonably retune, and a test
     * that pins `0.5` would red on the retune while saying nothing about
     * whether the retune broke the argument. What must not silently invert is
     * the ORDER, and that the notice tick is a real interval rather than a
     * busy loop. Read off the live `Subscriptions` the runtime builds, not off
     * the constants, so a tick wired to the wrong constant also reds.
     *
     * WHAT THIS DELIBERATELY DOES NOT CATCH, said here so nobody reads the
     * green as an endorsement: there is no UPPER bound. MEASURED — 30.0 still
     * passes this test, because 30.0 is still slower than 0.1. A seam nobody
     * sees for thirty seconds is useless, and the doc-block's own reasoning
     * ("half a second later it reads identically") plainly does not stretch
     * that far — but every candidate ceiling is as much a judgement call as
     * the interval itself, and a ceiling picked to make this sentence true
     * would be the literal pin this test exists to avoid, wearing a
     * comparison operator. What IS caught: an inversion, a zero, and the tick
     * wired to the wrong constant (all three MEASURED by mutation).
     */
    public function testTheNoticePollIsSlowerThanTheToolEventPollAsItsDocBlockClaims(): void
    {
        // In flight, so BOTH ticks are declared by the same call.
        $subscriptions = self::ownerChat(inFlight: true)->subscriptions();
        self::assertNotNull($subscriptions);

        $seconds = [];
        foreach ($subscriptions->all() as $subscription) {
            if ($subscription->kind === Kind::Tick) {
                $seconds[$subscription->id] = $subscription->params['seconds'];
            }
        }

        self::assertArrayHasKey('crush.tool-event-poll', $seconds, 'the tool-event tick is gone; there is nothing to compare against');
        self::assertArrayHasKey('crush.runtime-notice-poll', $seconds);

        self::assertGreaterThan(
            0.0,
            $seconds['crush.runtime-notice-poll'],
            'the notice tick has a zero interval; that is a busy loop, not a poll',
        );
        self::assertGreaterThan(
            $seconds['crush.tool-event-poll'],
            $seconds['crush.runtime-notice-poll'],
            'the notice poll is no longer slower than the tool-event poll, which is the whole of '
                . 'RUNTIME_NOTICE_POLL_SECONDS\' doc-block',
        );
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

    /**
     * `Bootstrap::chat()` REALLY DOES APPOINT THE CHAT IT RETURNS.
     *
     * Every other test here appoints its own Chat, which pins what an appointed
     * Chat DOES and says nothing about whether anything appoints one. Flip
     * `drainsRuntimeNotices: true` to `false` in `Bootstrap::chat()` and the
     * entire mid-session seam goes dark with the whole of this file still
     * green — MEASURED, before this test existed. That is the one-line
     * regression E171 is about, so it gets a guard of its own that goes through
     * the real launch path.
     *
     * The sandbox this runs in — an isolated `HOME`, a discarded `error_log`,
     * and the neutralised provider environment — is
     * {@see withLaunchSandbox()} and {@see VOLATILE_PROVIDER_ENV}.
     */
    public function testBootstrapChatReturnsTheAppointedDrainOwner(): void
    {
        self::withLaunchSandbox(static function (string $home): void {
            $chat = \SugarCraft\Crush\Cli\Bootstrap::chat($home);

            // chat() is also the only caller of arm() in src/. Both halves of
            // the same decision, asserted together.
            self::assertTrue(RuntimeNoticeSink::isArmed(), 'Bootstrap::chat() did not open the inbox');
            self::assertTrue(RuntimeNoticeSink::record('raised on the turn after launch'));

            $subscriptions = $chat->subscriptions();
            self::assertNotNull($subscriptions, 'the Chat Bootstrap built does not poll the inbox it opened');
            self::assertTrue($subscriptions->has('crush.runtime-notice-poll'));

            [$next] = $chat->update(new RuntimeNoticePumpMsg());
            $rows = array_values(array_filter(
                $next->history,
                static fn ($m): bool => $m->role === Role::System
                    && $m->content === 'raised on the turn after launch',
            ));
            self::assertCount(1, $rows, 'the launched Chat did not drain the row into its transcript');
        });
    }

    /**
     * THE THIRD LEG OF `Bootstrap::chat()`'s SEAM DECISION, and until this test
     * existed it was the only unguarded one.
     *
     * `chat()` runs three statements for this seam: `reset()`, `arm()`, and
     * `drainsRuntimeNotices: true` on the Chat it returns. The arm has
     * {@see testBootstrapChatReturnsTheAppointedDrainOwner()}, the appointment
     * has the same test — and the reset had NOTHING. MEASURED, round 47:
     * deleting `RuntimeNoticeSink::reset();` from `chat()` and running the
     * WHOLE suite gave `Tests: 9425, Assertions: 131975, Skipped: 1`, rc 0.
     * Nine thousand tests and not one of them noticed.
     *
     * WHAT THE LINE IS FOR, which is why its absence matters rather than being
     * tidiness: `arm()` is idempotent — it early-returns the moment
     * `self::$armed` is true — so on a second `chat()` in one process the arm
     * is a no-op and the transport is the FIRST launch's, still holding
     * whatever the first Chat never drained. The new Chat then opens its
     * transcript with a row raised for a conversation it is not part of. That
     * is E171's own defect (a row reaching the wrong reader, or none), turned
     * around: here the row reaches a reader that should never have seen it.
     *
     * TWO CALLERS MAKE THIS REACHABLE and neither is hypothetical: `app()`'s
     * second-scan path builds a Chat through this method after one already
     * exists, and every test that launches twice in one PHPUnit process does
     * the same.
     *
     * THE KNOWN-POSITIVE IS IN THE SAME TEST (rule 15): an empty transcript
     * proves nothing if the second Chat is simply broken, so the same Chat is
     * then handed a row raised AFTER its launch and must deliver that one.
     */
    public function testASecondBootstrapChatDoesNotInheritTheFirstsUndrainedInbox(): void
    {
        self::withLaunchSandbox(static function (string $home): void {
            \SugarCraft\Crush\Cli\Bootstrap::chat($home);

            // Raised during the first session and deliberately never drained:
            // no update() is pumped on the first Chat.
            self::assertTrue(
                RuntimeNoticeSink::record('a row the first session never drained'),
                'the sink refused the row; the assertions below would be vacuous',
            );
            self::assertTrue(
                RuntimeNoticeSink::hasPending(),
                'the row is not pending, so a second launch inheriting it is not even possible',
            );

            $second = \SugarCraft\Crush\Cli\Bootstrap::chat($home);

            self::assertFalse(
                RuntimeNoticeSink::hasPending(),
                'the second launch inherited the first session\'s undrained inbox',
            );
            self::assertNull(
                $second->subscriptions(),
                'the second Chat armed the poll, so it believes it has a row to deliver',
            );

            [$afterLaunch] = $second->update(new RuntimeNoticePumpMsg());
            self::assertSame(
                [],
                $afterLaunch->history,
                'the second session opened with a row raised for the first',
            );

            // KNOWN-POSITIVE: the same Chat, a row raised after ITS launch.
            self::assertTrue(RuntimeNoticeSink::record('a row raised in the second session'));
            [$next] = $second->update(new RuntimeNoticePumpMsg());
            $rows = array_values(array_filter(
                $next->history,
                static fn ($m): bool => $m->role === Role::System,
            ));
            self::assertCount(1, $rows, 'the second Chat drains nothing at all; the empty transcript above proves nothing');
            self::assertSame('a row raised in the second session', $rows[0]->content);
        });
    }

    /**
     * An isolated `HOME` and a discarded `error_log` for the duration of a real
     * `Bootstrap::chat()` launch.
     *
     * ISOLATED HOME for the reason `BootstrapTest` gives: `chat()` walks the
     * skill and config trees, and a test must not read or write the operator's.
     * The provider environment is neutralised for the whole class — see
     * {@see VOLATILE_PROVIDER_ENV}.
     *
     * @param callable(string): void $body
     */
    private static function withLaunchSandbox(callable $body): void
    {
        $home = sys_get_temp_dir() . '/sc_lane_a_seam_home_' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($home, 0o700, true));
        $savedHome = getenv('HOME');

        $log = tempnam(sys_get_temp_dir(), 'sc_lane_a_seam_boot_');
        self::assertIsString($log);
        $previousLog = ini_set('error_log', $log);

        try {
            putenv("HOME={$home}");
            $body($home);
        } finally {
            if ($previousLog !== false) {
                ini_set('error_log', $previousLog);
            }
            @unlink($log);
            if ($savedHome === false) {
                putenv('HOME');
            } else {
                putenv("HOME={$savedHome}");
            }
            exec('rm -rf ' . escapeshellarg($home));
        }
    }

    public function testANoticeRaisedInAForkedChildReachesTheParentsTranscript(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            self::markTestSkipped('ext-pcntl is required to exercise the fork boundary');
        }

        self::assertTrue(RuntimeNoticeSink::arm());

        $log = tempnam(sys_get_temp_dir(), 'sc_lane_a_delivery_fork_');
        self::assertIsString($log);

        $pid = $this->forkTracked();
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

    /**
     * WHOLE, NOT ORDERED, AND THE NAME USED TO SAY "IN ORDER".
     *
     * WHAT THIS TEST ASSERTED: `$rows[$i] === "child {$i} says …"`, index by
     * index. WHAT IS TRUE NOW: nothing orders those three writes. The children
     * are forked in a loop and only reaped afterwards, so all three run
     * concurrently and the kernel delivers their datagrams in whatever order
     * the scheduler produced. MEASURED, PHP 8.3.6 / Linux 6.8, on a
     * byte-faithful replica of this fork/record/waitpid/drain sequence with a
     * lightweight parent: 19 reorderings in 2700 trials (0.70%), in three
     * separate takes of 900 (4, 8, 7 — every take non-zero), producing
     * `[0,2,1]`, `[1,0,2]` and `[1,2,0]`. It has not been seen inside PHPUnit,
     * where `fork()`'s copy-on-write cost in a ~280 MB process serialises the
     * children — but "a race the current memory footprint happens to hide" is
     * not a property, and the footprint is not one this file controls.
     *
     * WHY THE TEST STILL EARNS ITS PLACE, UNCHANGED: the property it exists for
     * was never order. A `SOCK_STREAM` transport would coalesce these three
     * writes into ONE `stream_socket_recvfrom()` read, and `assertCount(3, …)`
     * below is what catches someone "simplifying" the pair to `SOCK_STREAM` —
     * MEASURED by mutating `STREAM_SOCK_DGRAM` and confirming this test still
     * reddens. Datagram framing is about each write being one indivisible
     * message, and that is what is asserted: the three messages arrive, whole,
     * as a set.
     */
    public function testNoticesFromSeveralChildrenAllArriveWhole(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            self::markTestSkipped('ext-pcntl is required to exercise the fork boundary');
        }

        RuntimeNoticeSink::arm();

        $pids = [];
        for ($k = 0; $k < 3; $k++) {
            $pid = $this->forkTracked();
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

        // THE COUNT IS THE SOCK_STREAM GUARD and is asserted before the
        // contents: under a stream transport the three writes coalesce into a
        // single read and this is 1, whatever the payloads say.
        self::assertCount(3, $rows);

        $expected = [];
        for ($k = 0; $k < 3; $k++) {
            $expected[] = "child {$k} says " . str_repeat('.', 200);
        }
        $actual = array_map(static fn ($row): string => $row->content, $rows);

        // Compared as SETS. Each row must still be one child's message byte for
        // byte — a truncated or spliced datagram fails this exactly as an
        // index-wise comparison would — but which child got to the socket first
        // is the scheduler's business, not this seam's. See the doc-block.
        sort($expected);
        sort($actual);
        self::assertSame($expected, $actual);
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
