<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\AssistantMsg;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Backend\ObservesReasoning;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Events\ReasoningDelta;
use SugarCraft\Crush\Events\ToolStarted;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\ToolEventPumpMsg;
use SugarCraft\Crush\Tests\Backend\Support\BatchDouble;
use SugarCraft\Crush\Tools\ToolCall as EngineToolCall;
use SugarCraft\Crush\Tui\Components\ChatPane;

/**
 * **E494 — the last hop of E456: the thinking must reach the screen, and
 * nothing else.**
 *
 * Round 56 built the reasoning channel end to end — provider chunk →
 * {@see \SugarCraft\Crush\Runtime::run()}'s `$onProgress` →
 * {@see EngineBackend::completeAsync()}'s `reasoning` frame across the fork →
 * a fifth `$onReasoning` parameter — and then stopped one hop short.
 * {@see Chat} passed FOUR arguments, so every fragment crossed the socket into
 * the parent process and was dropped. A user daily-driving this app watched a
 * static "assistant is thinking…" for two minutes with the thinking already in
 * hand.
 *
 * ## Two assertions, never one
 *
 * A fix that paints the thought AND corrupts the conversation would pass a
 * paint-only test. So every wiring test here checks both halves: the thought
 * reaches the paint surface, and the history the model is re-sent is
 * byte-identical to what it would have been without it. The second half is not
 * hypothetical — {@see \SugarCraft\Crush\Runtime::runStreaming()} accumulates
 * the TOKEN channel's bytes into the `AssistantMessage` that is fed back and
 * checkpointed, which is the entire reason `ReasoningDelta` is not a
 * `TokenDelta` with a flag.
 *
 * ## Which renderer
 *
 * `src/Renderer.php` and `src/Tui/Renderer.php` both exist and neither name
 * says which paints a transcript. Established rather than assumed: the only
 * reader of `Chat::streamingText()` anywhere under `src/` is
 * `SugarCraft\Crush\Renderer::renderView()`, and `Tui\Renderer` imports that
 * same class as `LiveRenderer` and delegates its chat pane to it
 * ({@see \SugarCraft\Crush\Tui\Components\ChatPane}), rendering only the shell
 * itself. {@see testTheTuiShellPaintsTheThoughtThroughTheSameSurface()} holds
 * that delegation to it, so this file goes red rather than stale if the
 * transcript ever moves.
 */
final class ReasoningPaintTest extends TestCase
{
    // =====================================================================
    // the wiring: Chat -> backend -> inbox -> pump -> renderer
    // =====================================================================

    /**
     * The whole seam, driven as a real turn: the user presses Enter, `Chat`
     * schedules the backend, the backend calls the sink it was handed, the pump
     * folds it in, and the renderer paints it.
     */
    public function testARealTurnPaintsTheModelsThinking(): void
    {
        $backend = new ReasoningRecorderBackend(['weighing ', 'the options. '], 'Noon.');
        $chat = new Chat(backend: $backend, inputBuf: 'what time is it?');

        [$inFlight, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertNotNull($cmd, 'submitting produced no command');
        $cmd();

        $this->assertSame(
            5,
            $backend->asyncArgsSeen,
            'Chat called a reasoning-capable backend with four arguments - E494 is exactly this argument going missing',
        );
        $this->assertNotNull($backend->onReasoning, 'the fifth argument arrived null');

        $painted = $this->pumpToQuiescence($inFlight);

        $this->assertSame('weighing the options. ', $painted->reasoningText());
        $this->assertStringContainsString('weighing the options.', $this->render($painted));
    }

    /**
     * The other half, and the one a paint-only fix would sail through: the
     * conversation the model is re-sent must be untouched.
     */
    public function testAPaintedThoughtNeverEntersTheConversation(): void
    {
        $backend = new ReasoningRecorderBackend(['secretly ', 'plotting'], 'Noon.');
        $chat = new Chat(backend: $backend, inputBuf: 'what time is it?');

        [$inFlight, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $cmd();
        $painted = $this->pumpToQuiescence($inFlight);

        $this->assertNotSame('', $painted->reasoningText(), 'nothing was painted, so this proves nothing');

        foreach ($painted->history as $message) {
            $this->assertStringNotContainsString('secretly', $message->content, 'a thought reached the transcript');
            $this->assertStringNotContainsString('plotting', $message->content, 'a thought reached the transcript');
        }
        $this->assertSame('', $painted->streamingText(), 'a thought reached the assistant text accumulator');
        $this->assertSame(
            [Role::User],
            array_map(static fn (Message $m): Role => $m->role, $painted->history),
            'the history the backend will be re-sent is not the user turn alone',
        );
    }

    /**
     * The settled reply supersedes the live thought: leaving it up would show
     * the same thinking twice, since a settled {@see Message} carries its own
     * `reasoning` which the transcript renders from.
     */
    public function testTheSettledReplyClearsTheLiveThought(): void
    {
        $chat = new Chat(inFlight: true);
        $chat->enqueueReasoning('mid-thought');
        $thinking = $this->pumpToQuiescence($chat);
        $this->assertSame('mid-thought', $thinking->reasoningText());

        [$settled] = $thinking->update(new AssistantMsg(Message::assistant('Noon.')));

        $this->assertSame('', $settled->reasoningText());
    }

    /**
     * A tool call ends the step, and the thought that introduced it belongs to
     * the step that is over — the rule {@see Chat::$streamingText} already
     * follows, and for the same reason: {@see EngineBackend::complete()}
     * returns only the LAST step's content, so an accumulation spanning steps
     * would visibly shrink when the turn settled.
     */
    public function testAToolCallResetsTheThoughtLikeItResetsThePartial(): void
    {
        $chat = new Chat(inFlight: true);
        $chat->enqueueReasoning('I should check the clock. ');
        $chat->enqueueToken('Let me look. ');
        $chat->enqueueToolEvent(ToolStarted::fromCall(new EngineToolCall('c1', 'clock', [])));
        $chat->enqueueReasoning('now I know. ');

        $afterThought = $this->pumpOnce($chat);
        $this->assertSame('I should check the clock. ', $afterThought->reasoningText());

        $afterText = $this->pumpOnce($afterThought);
        $this->assertSame('Let me look. ', $afterText->streamingText());
        $this->assertSame('I should check the clock. ', $afterText->reasoningText(), 'text must not clear the thought');

        $afterTool = $this->pumpOnce($afterText);
        $this->assertSame('', $afterTool->reasoningText());
        $this->assertSame('', $afterTool->streamingText());

        $afterNext = $this->pumpOnce($afterTool);
        $this->assertSame('now I know. ', $afterNext->reasoningText(), 'the next step must think from a blank slate');
    }

    /**
     * The two accumulators must not merge, in either direction, however the two
     * kinds interleave on the shared inbox. The coalescing loop is the risk: it
     * folds a RUN of deltas into one append, and a run that crossed the class
     * boundary would append one channel's bytes to the other's field.
     */
    public function testAnInterleavedRunKeepsTheTwoChannelsApart(): void
    {
        $chat = new Chat(inFlight: true);
        foreach (['t1 ', 't2 '] as $t) {
            $chat->enqueueToken($t);
        }
        foreach (['r1 ', 'r2 '] as $r) {
            $chat->enqueueReasoning($r);
        }
        $chat->enqueueToken('t3');

        $done = $this->pumpToQuiescence($chat);

        $this->assertSame('t1 t2 t3', $done->streamingText());
        $this->assertSame('r1 r2 ', $done->reasoningText());
    }

    /** An aborted turn's thinking must not type itself in under the notice. */
    public function testAStaleThoughtIsDroppedRatherThanPainted(): void
    {
        $chat = new Chat(inFlight: true, generation: 7);
        $chat->enqueueReasoning('from the turn you cancelled', 3);
        $chat->enqueueReasoning('from this turn', 7);

        $done = $this->pumpToQuiescence($chat);

        $this->assertSame('from this turn', $done->reasoningText());
    }

    /**
     * The inbox is shared with the tool-lifecycle channel, and the accessor
     * that reports it is contracted to tool events only.
     */
    public function testAThoughtIsNotReportedAsAToolEvent(): void
    {
        $chat = new Chat(inFlight: true);
        $chat->enqueueReasoning('thinking');
        $chat->enqueueToolEvent(ToolStarted::fromCall(new EngineToolCall('c1', 'clock', [])));

        $this->assertCount(1, $chat->liveToolEvents(), 'liveToolEvents() must report tool lifecycle only');
        $this->assertInstanceOf(ToolStarted::class, $chat->liveToolEvents()[0]);
    }

    /**
     * The end-of-turn drain must discard an unpumped thought rather than carry
     * it out on the settled Msg.
     *
     * Driven through a REAL turn, and that is the whole point of the fixture
     * work below: {@see Chat::drainToolEventInbox()} is reachable ONLY from the
     * backend promise's settle handlers. An earlier version of this test
     * enqueued a thought and dispatched an {@see AssistantMsg} by hand, which
     * never reaches the drain at all — and a mutation deleting the
     * `ReasoningDelta` exclusion from it SURVIVED, entirely green. The window
     * was wrong, not the mutation.
     *
     * What the exclusion buys, concretely: a `ReasoningDelta` returned here
     * lands in a {@see \SugarCraft\Crush\BackendToolEventsMsg}, whose applier
     * dispatches on `instanceof ToolStarted` and sends everything else to the
     * ToolFinished branch. A thought would arrive there as a TypeError and take
     * the whole turn down.
     */
    public function testAnUnpumpedThoughtIsDiscardedByTheEndOfTurnDrain(): void
    {
        $backend = new ReasoningRecorderBackend(['unpumped thought'], 'Noon.');
        $chat = new Chat(backend: $backend, inputBuf: 'what time is it?');

        [$inFlight, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $async = $cmd();
        $this->assertInstanceOf(AsyncCmd::class, $async);

        // Deliberately NOT pumped: the thought is still sitting in the inbox
        // when the turn settles, which is the state the drain exists for.
        $resolved = null;
        $async->promise->then(static function ($msg) use (&$resolved): void { $resolved = $msg; });
        $backend->settle();

        $this->assertInstanceOf(
            AssistantMsg::class,
            $resolved,
            'the drain carried the unpumped thought out as a tool-lifecycle batch',
        );

        [$settled] = $inFlight->update($resolved);
        foreach ($settled->history as $message) {
            $this->assertStringNotContainsString('unpumped thought', $message->content);
        }
    }

    // =====================================================================
    // the capability seam, in BOTH polarities
    // =====================================================================

    /**
     * A backend that does NOT declare {@see ObservesReasoning} must be called
     * with four arguments. PHP drops a surplus positional argument to a
     * userland method in silence, so passing it unconditionally would look
     * identical here — and the silence is the defect, not the argument.
     */
    public function testABackendWithoutTheCapabilityIsCalledWithFourArguments(): void
    {
        $backend = new PlainArityBackend();
        $chat = new Chat(backend: $backend, inputBuf: 'hello');

        [, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $cmd();

        $this->assertFalse($backend instanceof ObservesReasoning, 'the fixture accidentally declares the capability');
        $this->assertSame(4, $backend->argsSeen, 'a backend that cannot report reasoning was handed a reasoning sink');
    }

    /** The shipped backend the user actually runs must declare the capability. */
    public function testTheEngineBackendDeclaresTheCapability(): void
    {
        $this->assertTrue(
            is_subclass_of(EngineBackend::class, ObservesReasoning::class),
            'EngineBackend is the only backend that can report thinking; if it stops declaring the capability, '
            . 'Chat silently stops asking for it and E494 is undone with the suite still green',
        );
    }

    /**
     * The three backends that cannot honestly report reasoning must NOT declare
     * it. This is the polarity a capability check invites you to skip, and it is
     * where the lie would live: declaring it makes `Chat` pass a sink that is
     * then never called, which paints nothing while looking wired.
     */
    public function testTheBackendsWithNoModelBehindThemDoNotClaimTheCapability(): void
    {
        foreach ([Backend\EchoBackend::class, Backend\CommandBackend::class, Backend\StreamingCommandBackend::class] as $class) {
            $this->assertTrue(is_subclass_of($class, Backend::class), "$class stopped being a Backend");
            $this->assertFalse(
                is_subclass_of($class, ObservesReasoning::class),
                "$class claims it can report the model's thinking; it has no model, or no way to tell a thought "
                . 'from an answer on its wire',
            );
        }
    }

    // =====================================================================
    // the paint surface
    // =====================================================================

    /**
     * Which of the two `Renderer` classes paints the transcript, asserted by
     * DRIVING the shell rather than by reading its source.
     *
     * WHAT THIS DID BEFORE: matched two literals in
     * `src/Tui/Components/ChatPane.php` — its `use ... as LiveRenderer` line
     * and `LiveRenderer::renderView(`. WHAT IS TRUE NOW: that proved an import
     * existed, not that the pane paints a thought, and it was keyed on the text
     * of a file this test's own package does not own: a reformat, or a switch
     * to a fully-qualified call, reddened this guard for no behavioural reason
     * (rule 40 — key an exemption, or an assertion, on structure). WHY THE
     * GUARD STILL EARNS ITS PLACE: unchanged and the reason is the important
     * half. If the shell ever stops delegating to the live renderer, every
     * paint assertion in this file is measuring a surface the user no longer
     * sees, and they would all stay green while the feature was gone.
     *
     * `ChatPane::renderView()` is public static and takes the shell's own
     * `App`, so the pane can be driven directly with a hosted `Chat` that
     * carries a thought — a structural fact PHP enforces, rather than a string.
     */
    public function testTheTuiShellPaintsTheThoughtThroughTheSameSurface(): void
    {
        $app = App::new(new BatchDouble(), 'm')
            ->withChat($this->pumpToQuiescence($this->withThought(new Chat(inFlight: true), 'PANEMARKER')));

        [$pane] = ChatPane::renderView($app, 100, 30);

        $this->assertStringContainsString(
            '💭',
            $pane,
            'the TUI chat pane no longer paints the live thought - the transcript may have moved',
        );
        $this->assertStringContainsString('PANEMARKER', $pane, 'the marker was painted but the thought itself was not');

        // Rule 15's known-negative through the SAME call: the pane must not
        // paint the marker for a chat that has no thought, or the assertions
        // above would pass against a pane that paints it unconditionally.
        [$silent] = ChatPane::renderView($app->withChat(new Chat(inFlight: true)), 100, 30);
        $this->assertStringNotContainsString('💭', $silent);
        $this->assertStringNotContainsString('PANEMARKER', $silent);
    }

    /**
     * **{@see Chat::enqueueReasoning()} and the live path must not drift.**
     *
     * `enqueueReasoning()` is production-DORMANT: measured, it has no caller
     * under `src/` or `bin/`. The live turn's `$onReasoning` sink in
     * `Chat::scheduleBackendCompletion()` is a `static` closure, so it has no
     * `$this` and appends to the shared inbox itself — which means the empty
     * drop and the `ReasoningDelta` construction exist TWICE, in two places
     * that no compiler keeps in step.
     *
     * The seam is kept (rule 6): it is how an embedder or a test puts a thought
     * in front of the renderer with no backend in play, and it is the shape
     * {@see Chat::enqueueToken()} already has. What is pinned here is that the
     * two agree on the two things that DO have observable consequences, both
     * mutation-checked on each path: the CHANNEL (a fragment routed onto
     * `TokenDelta` lands in `$streamingText`, and one layer down in the message
     * the model is re-sent) and the GENERATION (a thought stamped with a stale
     * one is discarded at drain time rather than painted under a cancellation
     * notice).
     *
     * **What this test does NOT pin, and what now does.** Both paths ALSO drop
     * the empty delta, and that half is invisible to every assertion in THIS
     * test — measured, removing either drop leaves the assertions below
     * entirely green, because the empty fragment is appended to a string
     * accumulator and `$s . ''` is the identity. It never reaches
     * {@see \SugarCraft\Crush\Renderer}, which tests `$liveThought !== ''`, so
     * no bare `💭` appears either.
     *
     * WHAT THIS SAID: "no assertion on painted text can see them go. Do not
     * read the green here as covering them." WHAT IS TRUE NOW: the first
     * sentence is still exactly right and the second was the wrong conclusion
     * to draw from it. The drops are a cost guard on INBOX CHURN, and the inbox
     * is not the painted text — `Chat`'s shared `\ArrayObject` can be handed in
     * at construction and counted directly, which sees a drop that no
     * accumulator ever will. WHY THE PARAGRAPH STILL EARNS ITS PLACE: the
     * measurement in it is correct and load-bearing, because it is the reason
     * the drops need a test of their own rather than a line in this one —
     * {@see testBothPathsDropTheEmptyDeltaBeforeItReachesTheInbox()} (E530).
     *
     * The double announces `''` deliberately, even though
     * {@see ObservesReasoning::completeAsync()} promises never to: it makes the
     * empty fragment travel the whole live path, so the two accumulators are
     * compared on the same input a contract-breaking third-party backend would
     * produce.
     */
    public function testTheDormantEntryPointAndTheLiveSinkAgree(): void
    {
        $viaSeam = new Chat(inFlight: true);
        $viaSeam->enqueueReasoning('');
        $viaSeam->enqueueReasoning('kept ');
        $viaSeam->enqueueReasoning('');
        $viaSeam->enqueueReasoning('and kept');
        $seamText = $this->pumpToQuiescence($viaSeam)->reasoningText();

        $backend = new ReasoningRecorderBackend(['', 'kept ', '', 'and kept'], 'done');
        $chat = new Chat(backend: $backend, inputBuf: 'go');
        [$inFlight, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertNotNull($cmd, 'submitting produced no command');
        $cmd();
        $liveText = $this->pumpToQuiescence($inFlight)->reasoningText();

        $this->assertSame('kept and kept', $seamText, 'the seam dropped or kept the wrong fragments');
        $this->assertSame(
            $seamText,
            $liveText,
            'the dormant entry point and the live sink have drifted - one of them changed and the other did not',
        );
    }

    /**
     * **E530 — the empty-delta drop itself, on BOTH copies of it.**
     *
     * {@see testTheDormantEntryPointAndTheLiveSinkAgree()} pins that the two
     * paths AGREE; until this test existed nothing pinned that either of them
     * DROPS. That gap was real and it was argued for: the paragraph above used
     * to end "no assertion on painted text can see them go", which is true and
     * was mistaken for "nothing can". The measurement it rested on — remove
     * either drop and the painted text is unchanged, because `$s . ''` is the
     * identity — is correct about the ACCUMULATOR and says nothing about the
     * INBOX, which is where the drop actually acts. Rule 2: the window was
     * wrong, not the mutation.
     *
     * The inbox is observable without reflection. `Chat`'s constructor takes
     * the shared `\ArrayObject` as an optional parameter (it defaults to a
     * fresh one), every `mutate()` clone is handed the SAME instance, and
     * {@see \SugarCraft\Crush\Chat::scheduleBackendCompletion()}'s `static`
     * `$onReasoning` closure captures that same object — so one array handed in
     * here sees every append both paths make, and counting it is exactly the
     * "cost guard on inbox churn" claim stated in the code.
     *
     * COUNTED BEFORE ANY PUMP, deliberately: {@see
     * \SugarCraft\Crush\Chat::pumpLiveToolEvents()} drains destructively and
     * coalesces a run of same-class same-generation deltas into one append, so
     * after quiescence an undropped `''` is invisible again for the same reason
     * the painted text is. The drop is observable in the window between the
     * append and the drain, and nowhere after it.
     *
     * BOTH POLARITIES (rule 33/25): a non-empty fragment must still ARRIVE. An
     * assertion that only counted zero would pass just as green against an
     * `enqueueReasoning()` mutated to return unconditionally, which is a
     * strictly worse defect than the one being pinned.
     */
    public function testBothPathsDropTheEmptyDeltaBeforeItReachesTheInbox(): void
    {
        // --- the dormant seam ---
        $seamInbox = new \ArrayObject();
        $seam = new Chat(inFlight: true, liveToolEvents: $seamInbox);

        $seam->enqueueReasoning('');
        $this->assertCount(
            0,
            $seamInbox,
            'Chat::enqueueReasoning() queued an empty ReasoningDelta - the drop is gone',
        );

        $seam->enqueueReasoning('kept');
        $this->assertCount(
            1,
            $seamInbox,
            'the positive half: a real fragment must still reach the inbox, or the zero above '
                . 'is what an unconditionally-returning enqueueReasoning() would also produce',
        );

        // --- the live sink, the copy that has no `$this` to call the seam ---
        $liveInbox = new \ArrayObject();
        $backend = new ReasoningRecorderBackend(['', 'kept ', '', 'and kept'], 'done');
        $chat = new Chat(backend: $backend, inputBuf: 'go', liveToolEvents: $liveInbox);

        [$inFlight, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertNotNull($cmd, 'submitting produced no command');
        $this->assertCount(0, $liveInbox, 'something reached the inbox before the turn ran');
        $cmd();

        // Four announced, two of them empty. The texts are asserted alongside
        // the count so that a live sink which dropped the WRONG two - or which
        // appended two entries of some other class - cannot satisfy this.
        $arrived = [];
        foreach ($liveInbox as [, $event]) {
            $this->assertInstanceOf(ReasoningDelta::class, $event);
            $arrived[] = $event->text;
        }

        $this->assertSame(
            ['kept ', 'and kept'],
            $arrived,
            'the live $onReasoning sink queued an empty ReasoningDelta, or dropped a real one. '
                . 'The backend announces four fragments and two of them are empty; the inbox '
                . 'must hold exactly the two non-empty ones, in order, BEFORE the pump runs.',
        );

        // And the drop must not have cost the turn its reply: the two copies of
        // this guard exist on a display-only channel, so a fix that dropped too
        // much would still paint nothing and still look green above.
        $this->assertSame(
            'kept and kept',
            $this->pumpToQuiescence($inFlight)->reasoningText(),
        );
    }

    /**
     * Rule 15's known-positive for the paint assertions: the SAME render call,
     * on a Chat whose only difference is an empty thought, must NOT contain the
     * marker. Without this, a renderer that painted the marker unconditionally
     * — or a `assertStringContainsString` looking at a frame that happens to
     * carry the text for some other reason — would read as a pass.
     */
    public function testTheMarkerIsAbsentWhenThereIsNothingToPaint(): void
    {
        $silent = new Chat(inFlight: true);
        $this->assertSame('', $silent->reasoningText());
        $this->assertStringNotContainsString('💭', $this->render($silent));

        $thinking = $this->pumpToQuiescence($this->withThought($silent, 'ruminating'));
        $frame = $this->render($thinking);
        $this->assertStringContainsString('💭', $frame);
        $this->assertStringContainsString('ruminating', $frame);
    }

    /**
     * A long trace must not evict the answer. The live thought goes through the
     * same collapse a settled Message's reasoning does, so a MiniMax-scale trace
     * costs one line rather than a screen.
     */
    public function testALongThoughtIsCollapsedRatherThanPaintedInFull(): void
    {
        $chat = $this->pumpToQuiescence($this->withThought(
            new Chat(inFlight: true),
            str_repeat('every thought in full ', 60),
        ));

        $frame = $this->render($chat);
        $lines = explode("\n", $frame);
        $first = null;
        foreach ($lines as $index => $line) {
            if (str_contains($line, '💭')) {
                $first = $index;
                break;
            }
        }
        $this->assertNotNull($first, 'the thought was not painted at all');

        // How many rows the thought actually occupies: from its marker to the
        // first line that no longer carries any of it. Counted rather than
        // assumed, because the collapsed single line is then WRAPPED to the
        // pane, so "collapsed" is a claim about a couple of rows and not about
        // exactly one.
        $rows = 0;
        for ($i = $first; $i < count($lines); $i++) {
            if (!str_contains($lines[$i], 'every thought in full') && !str_contains($lines[$i], '💭')) {
                break;
            }
            $rows++;
        }

        $this->assertLessThanOrEqual(
            3,
            $rows,
            'a trace that would fill a screen must be collapsed before it is painted',
        );
        $painted = implode("\n", array_slice($lines, $first, $rows));
        $this->assertStringContainsString('…', $painted, 'a trace longer than the cap must be elided');
        $this->assertLessThan(
            substr_count(str_repeat('every thought in full ', 60), 'every'),
            substr_count($painted, 'every'),
            'the whole trace reached the frame - nothing was collapsed',
        );
    }

    /**
     * **The live thought must keep ADVANCING once it passes the cap.**
     *
     * E494 painted the in-flight thought through the same head-anchored
     * elision the settled transcript uses. An in-flight thought is an
     * accumulation that only grows, so the moment it passed the cap every
     * later frame rendered the same leading characters: measured before the
     * fix, on this host at 100x30, the frame for a 340-character accumulation
     * and the frame for a 3790-character one were byte-identical. For the
     * MiniMax-scale trace the renderer's own doc-block names, that is the
     * first second of a two-minute turn followed by a static line under a
     * spinner still claiming work is happening - which is the exact symptom
     * E494 exists to remove.
     *
     * Nothing caught it: with the elision flipped end-for-end the ENTIRE
     * suite stayed green, assertion count unmoved. The neighbouring
     * {@see testALongThoughtIsCollapsedRatherThanPaintedInFull()} looks like
     * it covers this and does not - it checks the cap and the row budget, and
     * never which end of the accumulation survives. So this asserts the
     * property those two miss directly: two accumulations sharing a prefix
     * longer than the cap must not render the same bytes.
     *
     * Single-word markers rather than phrases, deliberately: the collapsed
     * line is word-wrapped to the pane afterwards, so a multi-word marker can
     * be split across rows and a `assertStringContainsString` on it would fail
     * for a reason that has nothing to do with the anchor.
     */
    public function testALiveThoughtKeepsAdvancingOnceItPassesTheCap(): void
    {
        $opening = 'OPENINGMARKER ' . str_repeat('and then a further consideration ', 8);
        $this->assertGreaterThan(120, mb_strlen($opening), 'the fixture must exceed the cap or it proves nothing');

        $early = $this->paintedThought($opening);
        $later = $this->paintedThought($opening . ' TAILMARKER');

        $this->assertNotSame(
            $early,
            $later,
            'the live thought froze: two accumulations differing only in their newest text painted identical frames',
        );
        $this->assertStringContainsString(
            'TAILMARKER',
            $later,
            'the newest thinking never reached the frame - the elision is keeping the wrong end',
        );
        $this->assertStringNotContainsString(
            'TAILMARKER',
            $early,
            'known-negative: the marker must be absent from the accumulation that does not carry it',
        );
        $this->assertStringNotContainsString(
            'OPENINGMARKER',
            $later,
            'the whole trace reached the frame - the cap is gone, which trades a frozen line for an evicted answer',
        );
        $this->assertStringContainsString('…', $later, 'an elided trace must still say it was elided');
    }

    /**
     * The other polarity, in the same test file, because the two anchors are
     * one decision: the cap is shared and the END that survives is not.
     *
     * A SETTLED turn's thought is a finished artefact being skimmed and its
     * opening is its summary, so the transcript keeps the head. A RUNNING
     * turn's thought is a progress indicator and only its newest bytes carry
     * information, so the live paint keeps the tail. Asserted together so that
     * "fixing" the freeze by flipping {@see \SugarCraft\Crush\Renderer}'s
     * elision wholesale - rather than at the live call site - goes red here
     * instead of silently rewriting how every past turn reads.
     */
    public function testTheSettledTranscriptKeepsTheOpeningTheLivePaintDrops(): void
    {
        $thought = 'OPENINGMARKER ' . str_repeat('and then a further consideration ', 8) . ' TAILMARKER';

        $settled = $this->render(new Chat(history: [
            Message::user('what time is it?'),
            Message::assistant('Noon.', null, $thought),
        ]));

        $this->assertStringContainsString(
            'OPENINGMARKER',
            $settled,
            'a settled turn must keep the opening of its thought - that is the half a reader skims',
        );
        $this->assertStringNotContainsString(
            'TAILMARKER',
            $settled,
            'the settled transcript stopped collapsing, or adopted the live paint\'s anchor',
        );

        $live = $this->paintedThought($thought);
        $this->assertStringContainsString('TAILMARKER', $live);
        $this->assertStringNotContainsString('OPENINGMARKER', $live);
    }

    /**
     * Reasoning is raw model output that never passes a Markdown renderer, so
     * it must not be able to smuggle a control sequence into the frame.
     */
    public function testAThoughtIsSanitizedBeforeItIsPainted(): void
    {
        $chat = $this->pumpToQuiescence($this->withThought(new Chat(inFlight: true), "esc\x1b[31mred\x07bell"));

        $frame = $this->render($chat);
        $this->assertStringNotContainsString("\x07", $frame, 'a BEL reached the frame');
        // The title of this test says "a control sequence" and BEL alone did
        // not earn it: an SGR the model wrote itself is the sequence that
        // actually repaints the user's transcript in the model's colours.
        $this->assertStringNotContainsString("\x1b[31m", $frame, 'a model-authored SGR reached the frame');
        $this->assertStringContainsString('red', $frame, 'sanitizing must not eat the text itself');
    }

    // =====================================================================
    // fixtures
    // =====================================================================

    /**
     * One in-flight frame carrying exactly `$thought` and nothing else, pumped
     * to quiescence the way a real turn's inbox is drained.
     */
    private function paintedThought(string $thought): string
    {
        return $this->render($this->pumpToQuiescence($this->withThought(new Chat(inFlight: true), $thought)));
    }

    private function withThought(Chat $chat, string $thought): Chat
    {
        $chat->enqueueReasoning($thought);

        return $chat;
    }

    private function render(Chat $chat): string
    {
        return Renderer::renderView($chat->withSize(100, 30))->body;
    }

    private function pumpOnce(Chat $chat): Chat
    {
        [$next] = $chat->update(new ToolEventPumpMsg());

        return $next;
    }

    /** Drain the inbox completely, with a bound so a pump regression fails rather than hangs. */
    private function pumpToQuiescence(Chat $chat): Chat
    {
        for ($i = 0; $i < 64; $i++) {
            [$next, $more] = $chat->update(new ToolEventPumpMsg());
            $chat = $next;
            if ($more === null) {
                return $chat;
            }
        }

        $this->fail('the live pump never went quiet');
    }
}

/**
 * A backend that DOES declare {@see ObservesReasoning} and records the sink it
 * was handed, then drives it.
 *
 * Named for what it is rather than `RecordingBackend`: this file's doubles live
 * in the shared `Tests\Backend` namespace beside every other test in the
 * directory, and a generic name there is a collision waiting for the next lane
 * (E497).
 */
final class ReasoningRecorderBackend implements ObservesReasoning
{
    public mixed $onReasoning = null;

    public int $asyncArgsSeen = 0;

    private ?Deferred $deferred = null;

    /** @param list<string> $thoughts */
    public function __construct(private array $thoughts, private string $reply) {}

    public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null, ?callable $onReasoning = null): Message
    {
        $this->onReasoning = $onReasoning;
        foreach ($this->thoughts as $thought) {
            if ($onReasoning !== null) {
                $onReasoning($thought);
            }
        }

        return Message::assistant($this->reply);
    }

    /**
     * Reports its thinking and then LEAVES THE TURN IN FLIGHT, rather than
     * resolving where it stands.
     *
     * Not a stylistic choice: a promise that resolves synchronously runs
     * {@see Chat::drainToolEventInbox()} in the same breath, which empties the
     * inbox by contract — an undrained delta belongs to a turn that is already
     * over. A double that resolved inline would therefore wipe its own thoughts
     * before the pump could ever see them, and this test would be measuring the
     * drain rather than the paint. A real backend spends loop ticks between the
     * first thought and the settle, and so does this one. Call
     * {@see settle()} when the test wants the turn to end.
     */
    public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null, ?callable $onReasoning = null): PromiseInterface
    {
        $this->asyncArgsSeen = func_num_args();
        $this->complete($history, $onToken, $onEvent, $onReasoning);
        $this->deferred = new Deferred();

        return $this->deferred->promise();
    }

    public function settle(): void
    {
        $this->deferred?->resolve(Message::assistant($this->reply));
    }
}

/**
 * A four-parameter backend — the shape of every third-party implementation and
 * of most doubles already in this package. It must still work, and it must NOT
 * be handed a fifth argument. (No count here on purpose: it changes whenever
 * anyone adds a double, and a stale number in a doc-block is worse than none.)
 */
final class PlainArityBackend implements Backend
{
    public int $argsSeen = 0;

    public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
    {
        return Message::assistant('plain');
    }

    public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
    {
        $this->argsSeen = func_num_args();

        return \React\Promise\resolve($this->complete($history, $onToken, $onEvent));
    }
}
