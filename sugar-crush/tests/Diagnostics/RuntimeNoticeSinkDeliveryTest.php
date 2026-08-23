<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Diagnostics;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Core\ProgramOptions;
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

    /**
     * ONE REAL `Program`, ONE IDLE SESSION, ONE OFF-LOOP WRITER (E193).
     *
     * WHAT THIS FILE COULD NOT SEE BEFORE. Every other delivery test here hands
     * a `RuntimeNoticePumpMsg` to `update()` by hand, or reads the tick off
     * `subscriptions()`. Both start one step past the defect: they assume
     * something asked. `Program` re-evaluates `Chat::subscriptions()` only when
     * it reconciles, and it reconciles after `init()` and after every
     * dispatched `Msg` — nowhere else. On an idle session there is no next
     * `Msg`, so a notice raised after the last reconcile arms nothing and goes
     * on arming nothing until the user presses a key. That is the exact shape
     * this file's own doc-block warns about one layer down: a row in the queue
     * with nothing that will ever come and read it.
     *
     * MEASURED BEFORE THE FIX, PHP 8.3.6 / `StreamSelectLoop` / Linux 6.8, on
     * the byte-equivalent of the harness below: zero `Role::System` rows after
     * two seconds of loop time, with `hasPending()` still true at the end. The
     * two controls below are what make that a measurement rather than a broken
     * harness — the same producer, the same loop, the same assertions, and
     * they delivered.
     *
     * WHY ONE SECOND. The producer fires at 0.15s and the wake is edge-driven,
     * so delivery is immediate; the remaining budget is slack for a loaded box.
     * It is not a poll interval and this test does not depend on one — with
     * `RUNTIME_NOTICE_POLL_SECONDS` at its current value the tick would not be
     * declared here at all, because nothing is in flight.
     */
    public function testANoticeRaisedWhileTheUiIsIdleReachesTheTranscriptWithNoKeystroke(): void
    {
        $model = $this->runIdleProgram(self::ownerChat(), static function (): void {
            DsmlToolCallParser::new()->parse(['content' => self::quotedEnvelope()]);
        });

        $rows = self::systemRows($model);
        self::assertCount(
            1,
            $rows,
            'a notice raised by an off-loop writer with the UI idle never reached the transcript. Nothing '
                . 'reconciles Chat::subscriptions() on an idle session, so the poll it declares can only be '
                . 'declared by a Msg that is not coming.',
        );
        self::assertStringContainsString('DsmlToolCallParser', $rows[0]->content);

        // AND IT IS ON THE SCREEN. A row the renderer drops is the same defect
        // one layer up, and the whole point of the seam is a surface the user
        // has while the alternate screen is up.
        self::assertStringContainsString('DsmlToolCallParser', $model->view());
    }

    /**
     * CONTROL ONE FOR THE HARNESS: the SAME producer, on a turn IN FLIGHT.
     *
     * This is the path that already worked, through the `$inFlight` clause of
     * {@see Chat::subscriptions()}' condition and its 0.5s tick. If this ever
     * reds, the idle test above is measuring a broken harness rather than a
     * missing wake-up — which is precisely how a confident false green gets
     * written down.
     */
    public function testTheSameProducerOnATurnInFlightWasAlreadyDelivered(): void
    {
        $model = $this->runIdleProgram(self::ownerChat(inFlight: true), static function (): void {
            DsmlToolCallParser::new()->parse(['content' => self::quotedEnvelope()]);
        });

        self::assertCount(
            1,
            self::systemRows($model),
            'the in-flight path stopped delivering, so the idle test above proves nothing about idleness',
        );
    }

    /**
     * CONTROL TWO: the same run with NOTHING raised must produce no rows.
     *
     * The counting side of the instrument. Both tests above pass by finding
     * exactly one `Role::System` row, and a harness that manufactured one —
     * from a launch notice, from a `WindowSizeMsg`-driven repaint, from
     * anything `Program` dispatches on startup — would make them green while
     * proving nothing at all.
     */
    public function testTheIdleHarnessProducesNoRowsWhenNothingWarns(): void
    {
        $model = $this->runIdleProgram(self::ownerChat(), static function (): void {
            // Deliberately empty: the child forks, waits and exits silently.
        });

        self::assertSame([], self::systemRows($model), 'the idle harness invents Role::System rows on its own');
    }

    /**
     * THE WAKE IS RE-ARMED, SO THE SECOND NOTICE ALSO ARRIVES.
     *
     * {@see RuntimeNoticeSink::notifyOnceWhenPending()} is one-shot on purpose,
     * which means a fix that installs it and never renews it delivers exactly
     * one notice per session and then goes as dark as it was before. That is a
     * fix whose own test would pass — the test above uses one notice — so the
     * renewal gets its own, with the two writes separated in time by more than
     * the round trip through `update()`.
     *
     * TWO CHILDREN AND NOT ONE WITH A SLEEP BETWEEN ITS WRITES, because a
     * single child's two datagrams can sit in the socket together and be taken
     * by ONE `drain()` — which would pass without the wake ever being renewed.
     * Separate processes at 0.15s and 0.55s make the second write land after
     * the first has certainly been consumed.
     */
    public function testTheIdleWakeIsRearmedSoASecondNoticeAlsoArrives(): void
    {
        $model = $this->runIdleProgram(
            self::ownerChat(),
            static function (): void {
                RuntimeNoticeSink::record('the first idle notice');
            },
            static function (): void {
                RuntimeNoticeSink::record('the second idle notice');
            },
        );

        $contents = array_map(static fn ($row): string => $row->content, self::systemRows($model));
        sort($contents);
        self::assertSame(
            ['the first idle notice', 'the second idle notice'],
            $contents,
            'only one idle notice arrived; the edge-driven wake is one-shot and something has to renew it '
                . 'after every pump, including a pump that found the inbox already empty',
        );
    }

    /**
     * A PUMP THAT FINDS NOTHING STILL RENEWS THE ONE-SHOT WAKE.
     *
     * THIS TEST EXISTS BECAUSE THE ONE ABOVE DID NOT CATCH IT. MEASURED by
     * mutation: replacing the empty path's `return [$this, $rearm]` with
     * `return [$this, null]` SURVIVED
     * `--filter RuntimeNoticeSink` — every pump in
     * {@see testTheIdleWakeIsRearmedSoASecondNoticeAlsoArrives()} finds a row,
     * so the empty arm is never taken there and the whole of the renewal
     * argument rested on an arm nothing exercised. The assertion's window was
     * wrong, not the mutation's relevance.
     *
     * THE ARM IS REACHED, AND NOT BY AN EXOTIC PATH. During a turn the
     * `$inFlight` tick and the watcher are BOTH live. A datagram lands, the
     * tick drains it, and the watcher's own `Msg` — already queued — then
     * arrives at an inbox that is empty. Under the mutation that pump returns
     * no Cmd, the one-shot wake is spent, and the session silently reverts to
     * E193's defect the moment the turn ends. That is a race, so it is pinned
     * here at the unit level rather than raced for in the `Program` harness:
     * the empty inbox is produced directly and the renewal is asserted by its
     * EFFECT, not by the Cmd being non-null.
     *
     * RUNNING THE Cmd IS THE LOAD-BEARING HALF. `Cmd::promise()` returns a
     * closure; the watcher is installed by the factory INSIDE it. A test that
     * stopped at `assertNotNull($cmd)` would pass for a Cmd that arms nothing.
     */
    public function testAPumpThatFindsNothingStillRenewsTheOneShotWake(): void
    {
        self::assertTrue(RuntimeNoticeSink::arm(), 'no cross-fork transport on this host');

        $chat = self::ownerChat();

        // The state the interleaving leaves behind: the wake has been spent and
        // the inbox is empty.
        RuntimeNoticeSink::cancelPendingNotification();
        self::assertFalse(RuntimeNoticeSink::isNotificationArmed());
        self::assertFalse(RuntimeNoticeSink::hasPending(), 'the inbox is not empty; this is not the empty arm');

        [$next, $cmd] = $chat->update(new RuntimeNoticePumpMsg());

        self::assertSame($chat, $next, 'an empty pump repainted; that is the other half of this arm');
        self::assertNotNull($cmd, 'an empty pump returned no Cmd, so the one-shot wake is spent for good');

        $cmd();
        self::assertTrue(
            RuntimeNoticeSink::isNotificationArmed(),
            'the empty pump returned a Cmd that arms no watcher when the runtime runs it',
        );

        // KNOWN-POSITIVE FOR THE OTHER ARM, in the same test: the non-empty
        // path must renew too, and by the same effect. Without this, a fix that
        // renewed ONLY on the empty path would pass everything above.
        RuntimeNoticeSink::cancelPendingNotification();
        self::assertTrue(RuntimeNoticeSink::record('a row for the non-empty arm'));

        [$after, $cmdAfter] = $chat->update(new RuntimeNoticePumpMsg());
        self::assertNotSame($chat, $after, 'the non-empty arm did not append; this control is vacuous');
        self::assertNotNull($cmdAfter);
        $cmdAfter();
        self::assertTrue(RuntimeNoticeSink::isNotificationArmed());
    }

    /**
     * THE WAKE Cmd IS NULL FOR EVERY Chat THAT MUST NOT LISTEN.
     *
     * Two gates, and they are different questions. A Chat nobody appointed must
     * not install a watcher, for {@see drain()}'s destructiveness — the same
     * reason {@see testAChatNobodyAppointedDoesNotPollTheInbox()} gives for the
     * tick. And a sink with no cross-fork transport has no fd to watch: the
     * in-process backend can only be written by this process, synchronously,
     * inside an `update()` that `Program` reconciles after anyway.
     *
     * THE KNOWN-POSITIVE IS THE LAST ASSERTION, because the three above are
     * `assertNull()` and an `init()` hard-wired to `return null` satisfies all
     * of them.
     */
    public function testOnlyAnAppointedChatWithATransportArmsTheWake(): void
    {
        self::assertNull((new Chat())->init(), 'an unarmed, unappointed Chat armed the wake');

        RuntimeNoticeSink::arm(false);
        self::assertFalse(RuntimeNoticeSink::hasTransport(), 'arm(false) built a transport; this test is moot');
        self::assertNull(self::ownerChat()->init(), 'the array backend has no fd, but a watcher was armed for it');
        self::assertNull((new Chat())->init());

        RuntimeNoticeSink::reset();
        self::assertTrue(RuntimeNoticeSink::arm(), 'no cross-fork transport on this host; the control below is moot');
        self::assertNull((new Chat())->init(), 'an unappointed Chat armed the wake on a real transport');
        self::assertNotNull(
            self::ownerChat()->init(),
            'the appointed Chat did not arm the wake either, so every null above is vacuous',
        );
    }

    /**
     * `reset()` TAKES THE WATCHER OFF THE LOOP BEFORE IT CLOSES THE STREAM.
     *
     * Not tidiness. `Loop::get()` is process-wide and this suite runs some nine
     * thousand tests through it; a watcher left registered against a closed
     * resource is one `stream_select()` will be handed on every iteration for
     * the rest of the run, in every later test's loop. The ordering inside
     * `reset()` is the fix and this is what pins it — a `reset()` that closed
     * first and cancelled second would leave this assertion green while doing
     * exactly the damage, so the assertion is on the watcher's own state and
     * the ordering is argued at the call site.
     */
    public function testResetTakesTheReadWatcherBackOffTheLoop(): void
    {
        self::assertTrue(RuntimeNoticeSink::arm(), 'no cross-fork transport on this host');
        self::assertFalse(RuntimeNoticeSink::isNotificationArmed());

        self::assertTrue(RuntimeNoticeSink::notifyOnceWhenPending(static function (): void {
        }));
        self::assertTrue(RuntimeNoticeSink::isNotificationArmed(), 'notifyOnceWhenPending() armed nothing');

        RuntimeNoticeSink::reset();
        self::assertFalse(
            RuntimeNoticeSink::isNotificationArmed(),
            'reset() closed the transport and left its read watcher on the shared loop',
        );

        // A second watcher REPLACES the first rather than stacking, which is
        // what lets update() re-arm on every pump without leaking one per turn.
        self::assertTrue(RuntimeNoticeSink::arm());
        self::assertTrue(RuntimeNoticeSink::notifyOnceWhenPending(static function (): void {
        }));
        self::assertTrue(RuntimeNoticeSink::notifyOnceWhenPending(static function (): void {
        }));
        RuntimeNoticeSink::cancelPendingNotification();
        self::assertFalse(
            RuntimeNoticeSink::isNotificationArmed(),
            'one cancel did not clear the watcher, so notifyOnceWhenPending() stacked a second one',
        );
    }

    /** @return list<\SugarCraft\Crush\Message> */
    private static function systemRows(Chat $chat): array
    {
        return array_values(array_filter(
            $chat->history,
            static fn ($m): bool => $m->role === Role::System,
        ));
    }

    /**
     * Run a REAL `Program` over `$chat` for a fixed slice of wall clock, with
     * each `$producer` fired from its own forked child part-way through.
     *
     * WHY A FORK AND NOT A LOOP TIMER. A timer callback runs on the loop and a
     * loop that is already awake is not the case under test; the whole point is
     * a writer that is not this process and cannot dispatch a `Msg`, which is
     * what {@see \SugarCraft\Crush\Backend\EngineBackend::completeAsync()}'s
     * child is. The children are forked AFTER {@see RuntimeNoticeSink::arm()}
     * so they inherit the write end, exactly as the real one does.
     *
     * `error_log()` IS DISCARDED IN THE CHILD, not in the parent: the parent is
     * about to take over the terminal, and a real emitter's stderr copy would
     * otherwise land in PHPUnit's output. The parent's `error_log` is left
     * alone so a genuine parent-side warning is still visible to whoever is
     * reading a failure.
     *
     * @param \Closure(): void ...$producers one child each, fired 0.4s apart
     */
    private function runIdleProgram(Chat $chat, \Closure ...$producers): Chat
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            self::markTestSkipped('ext-pcntl is required to exercise the off-loop writer');
        }

        self::assertTrue(RuntimeNoticeSink::arm(), 'no cross-fork transport on this host');

        $childLog = tempnam(sys_get_temp_dir(), 'sc_lane_a_idle_');
        self::assertIsString($childLog);

        $pids = [];
        foreach ($producers as $index => $producer) {
            $pid = $this->forkTracked();
            self::assertNotSame(-1, $pid, 'fork failed');

            if ($pid === 0) {
                ini_set('error_log', $childLog);
                usleep(150_000 + ($index * 400_000));
                $producer();
                ForkedChild::exitNow(0);
            }
            $pids[] = $pid;
        }

        $inputPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        self::assertIsArray($inputPair);
        $output = tmpfile();
        self::assertIsResource($output);

        $program = new \SugarCraft\Core\Program($chat, new ProgramOptions(
            useAltScreen: false,
            catchInterrupts: false,
            input: $inputPair[0],
            output: $output,
            windowSize: ['cols' => 80, 'rows' => 24],
        ));

        // The budget covers the last producer's 0.15s+0.4s*n offset plus slack
        // for a loaded box. Delivery itself is edge-driven and immediate.
        $budget = 0.85 + (0.4 * count($producers));
        Loop::get()->addTimer($budget, static function () use ($program): void {
            $program->quit();
        });

        $model = $program->run();

        foreach ($pids as $pid) {
            $status = 0;
            pcntl_waitpid($pid, $status);
        }

        fclose($inputPair[0]);
        fclose($inputPair[1]);
        fclose($output);
        @unlink($childLog);

        self::assertInstanceOf(Chat::class, $model);

        return $model;
    }

    /**
     * `Chat` CARRIES NO STACKED DOC-COMMENTS, which is a guard this round owes
     * the file rather than a general style rule.
     *
     * E193's re-arm reasoning landed as a SECOND doc-comment immediately
     * above {@see \SugarCraft\Crush\Chat::pumpRuntimeNotices()} instead of
     * being merged into the block already there. PHP attaches only the LAST
     * doc-comment in such a run, so the earlier one stops documenting anything:
     * `pumpRuntimeNotices()` lost its `@return array{0:Chat,1:?\Closure}` tag
     * (VERIFIED at the time by `ReflectionMethod::getDocComment()`, which
     * returned the re-arm block with no `@return` in it) and the batching and
     * `Role::System` arguments became prose no tool could resolve.
     *
     * IT WAS NOT THE ONLY ONE. Scanning for the shape found THREE pairs in this
     * file, and the other two were worse: `refuseInFlightCommand()` and
     * `dispatchCommand()` had each lost their doc-block to the method BELOW
     * them, so both read as undocumented while their prose sat above an
     * unrelated declaration — and `expandCustomCommand()`, which returns
     * `?string`, was preceded by a stranded `@return array{0: self, 1:
     * ?\Closure}|null`.
     *
     * SCOPED TO `Chat.php` DELIBERATELY. The same scan finds four more pairs
     * elsewhere in `src/`, in files this change does not own; widening the
     * guard would red on work in flight rather than on this defect. The finding
     * is recorded in the hardening backlog instead.
     */
    public function testChatCarriesNoStackedDocComments(): void
    {
        $chat = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Chat.php');

        self::assertSame(
            [],
            self::stackedDocCommentLines($chat),
            'src/Chat.php has doc-comments stacked immediately on top of each other at the lines '
                . 'listed. PHP attaches only the last of a run, so every earlier one documents '
                . 'nothing: its @return tag is off the method and its reasoning is orphaned. Merge '
                . 'them into one block, or move the stranded one down to the declaration it describes.',
        );

        // KNOWN-POSITIVE THROUGH THE SAME SCANNER IN THE SAME TEST (rule 15).
        // An assertion of [] is worth nothing if the instrument is dead, and
        // this one would stay green with the scanner mutated to never match.
        // Both halves: a stacked pair IS found, and an ordinary run of
        // separately-documented methods is NOT.
        self::assertSame([2], self::stackedDocCommentLines(<<<'PHP'
            <?php
            /** First, which PHP will drop on the floor. */
            /** Second, which wins. */
            function f(): void {}
            PHP));

        self::assertSame([], self::stackedDocCommentLines(<<<'PHP'
            <?php
            /** One block. */
            function f(): void {}
            /** Another block, with a declaration between them. */
            function g(): void {}
            PHP));
    }

    /**
     * The 1-indexed lines of every doc-comment immediately followed by another
     * doc-comment, with nothing significant between them.
     *
     * `T_COMMENT` is skipped but `T_DOC_COMMENT` is not, so a `//` line between
     * two blocks does NOT rescue the first — PHP does not attach it either.
     *
     * @return list<int>
     */
    private static function stackedDocCommentLines(string $source): array
    {
        $significant = [];
        foreach (token_get_all($source) as $token) {
            if (\is_array($token) && \in_array($token[0], [T_WHITESPACE, T_COMMENT], true)) {
                continue;
            }
            $significant[] = $token;
        }

        $lines = [];
        foreach ($significant as $i => $token) {
            if (!\is_array($token) || $token[0] !== T_DOC_COMMENT) {
                continue;
            }
            $next = $significant[$i + 1] ?? null;
            if (\is_array($next) && $next[0] === T_DOC_COMMENT) {
                $lines[] = $token[2];
            }
        }

        return $lines;
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
