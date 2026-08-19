<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\AssistantMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Usage;
use SugarCraft\Crush\Util\TokenTracker;

/**
 * crush_code.md Phase 5 item 7's user-facing half: `/budget`, and the spend cap
 * it sets.
 *
 * Everything is driven as a submitted draft through `Chat::update()`, and the
 * spend is put on the tracker the way production puts it there — by settling an
 * {@see AssistantMsg} whose {@see Message} carries a {@see Usage} — rather than
 * by poking the tracker directly. A test that seeded the tracker by hand could
 * not see the recording seam break.
 */
final class BudgetCommandTest extends TestCase
{
    private function chat(?float $cap = null, ?TokenTracker $tracker = null, array $history = []): Chat
    {
        return new Chat(
            history: $history === [] ? [Message::user('hello'), Message::assistant('hi')] : $history,
            backend: new EchoBackend(),
            tokenTracker: $tracker,
            maxCostUsd: $cap,
        );
    }

    /** Type $draft one keystroke at a time and submit it — the route a user has. */
    private function submitDraft(Chat $chat, string $draft): Chat
    {
        foreach (mb_str_split($draft) as $char) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Char, $char));
        }
        [$next] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        return $next;
    }

    private function lastLine(Chat $chat): string
    {
        return $chat->history[count($chat->history) - 1]->content;
    }

    /** Settle a turn that the provider billed, exactly as production does. */
    private function bill(Chat $chat, int $tokens, float $cost): Chat
    {
        [$next] = $chat->update(new AssistantMsg(
            Message::assistant('done')->withUsage(Usage::new($tokens, $cost)),
        ));

        return $next;
    }

    // =====================================================================
    // Recording
    // =====================================================================

    /**
     * The seam item 7 exists to close: a settled turn's provider-counted figures
     * reach the tracker. Before this, `TokenTracker` was constructed nowhere in
     * `src/` or `bin/` because nothing carried the numbers to it.
     */
    public function testASettledTurnsUsageReachesTheTracker(): void
    {
        $chat = $this->bill($this->chat(), 1500, 0.0300);

        $this->assertTrue($chat->hasReportedSpend());
        $this->assertEqualsWithDelta(0.0300, $chat->spentUsd(), 0.000001);
        $this->assertStringContainsString('1500 unsplit', $chat->usageSummary());
    }

    /** Successive turns accumulate rather than replacing one another. */
    public function testSuccessiveTurnsAccumulate(): void
    {
        $chat = $this->bill($this->bill($this->chat(), 100, 0.01), 250, 0.02);

        $this->assertEqualsWithDelta(0.03, $chat->spentUsd(), 0.000001);
        $this->assertStringContainsString('350 unsplit', $chat->usageSummary());
    }

    /**
     * The accumulator survives the clone chain. `Chat` is immutable and every
     * keystroke allocates a new one, so a tracker rebuilt per instance would zero
     * the session total on the next frame — which is why it is carried by object
     * identity through `mutate()` the way `$liveToolEvents` is.
     */
    public function testTheRunningTotalSurvivesTheKeystrokeCloneChain(): void
    {
        $chat = $this->bill($this->chat(), 100, 0.05);

        foreach (mb_str_split('typing away') as $char) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Char, $char));
        }

        $this->assertEqualsWithDelta(0.05, $chat->spentUsd(), 0.000001, 'the total must not reset on a keystroke');
    }

    /**
     * A turn whose provider reported nothing must not be recorded as a
     * zero-dollar call. `hasReportedSpend()` staying false is what keeps the
     * status bar silent on an offline run instead of printing `$0.0000`.
     */
    public function testATurnWithNoReportedUsageLeavesTheSessionUnreported(): void
    {
        [$chat] = $this->chat()->update(new AssistantMsg(Message::assistant('done')));

        $this->assertFalse($chat->hasReportedSpend());
        $this->assertSame(0.0, $chat->spentUsd());
    }

    /**
     * A genuinely free provider — real tokens, zero cost, which is what
     * SglangProvider and CustomProvider report — counts as REPORTED. The session
     * knows what it used; it just did not pay for it.
     */
    public function testAFreeProviderWithRealTokensCountsAsReported(): void
    {
        $chat = $this->bill($this->chat(), 900, 0.0);

        $this->assertTrue($chat->hasReportedSpend(), 'tokens were counted, so the session is measured');
        $this->assertSame(0.0, $chat->spentUsd());
    }

    /**
     * The spend belongs to the LAUNCH, not the transcript: `/clear` empties the
     * conversation and leaves the total alone, because money already spent does
     * not become unspent.
     */
    public function testClearDoesNotForgetWhatTheSessionSpent(): void
    {
        $chat = $this->submitDraft($this->bill($this->chat(), 100, 0.07), '/clear');

        $this->assertSame([], $chat->history, 'fixture: /clear really emptied the transcript');
        $this->assertEqualsWithDelta(0.07, $chat->spentUsd(), 0.000001);
    }

    // =====================================================================
    // /budget — showing
    // =====================================================================

    /**
     * The bare form is the only place the token breakdown is shown at all; the
     * status bar has room for the dollar figure and nothing else.
     */
    public function testBareBudgetReportsTheSpendAndTheTokenBreakdown(): void
    {
        $chat = $this->submitDraft($this->bill($this->chat(), 1500, 0.0225), '/budget');
        $line = $this->lastLine($chat);

        $this->assertStringContainsString('Spend so far: $0.0225', $line);
        $this->assertStringContainsString('no cap', $line);
        $this->assertStringContainsString('Tokens: 0 in / 0 out + 1500 unsplit', $line);
    }

    /**
     * With nothing reported it says so in words rather than printing `$0.0000`.
     * The two claims are different and the offline/streamed case is the common
     * one, so conflating them would be the default experience.
     */
    public function testBareBudgetSaysUnreportedRatherThanZeroWhenNothingHasArrived(): void
    {
        $line = $this->lastLine($this->submitDraft($this->chat(), '/budget'));

        $this->assertStringContainsString('not reported by this provider', $line);
        $this->assertStringNotContainsString('$0.0000', $line);
    }

    /** And it names the cap when there is one, even with nothing reported yet. */
    public function testBareBudgetNamesTheCapEvenWhenNothingHasBeenReported(): void
    {
        $line = $this->lastLine($this->submitDraft($this->chat(cap: 5.0), '/budget'));

        $this->assertStringContainsString('cap $5.0000', $line);
        $this->assertStringContainsString('not reported', $line);
    }

    // =====================================================================
    // /budget — setting and clearing
    // =====================================================================

    public function testBudgetWithAnAmountSetsTheCap(): void
    {
        $chat = $this->submitDraft($this->chat(), '/budget 5');

        $this->assertSame(5.0, $chat->maxCostUsd());
        $this->assertStringContainsString('Spend cap set to $5.0000', $this->lastLine($chat));
    }

    /** A leading `$` is what a human types, so it is accepted, not refused. */
    public function testALeadingDollarSignIsAccepted(): void
    {
        $this->assertSame(2.5, $this->submitDraft($this->chat(), '/budget $2.50')->maxCostUsd());
    }

    public function testBudgetOffClearsTheCap(): void
    {
        $chat = $this->submitDraft($this->chat(cap: 5.0), '/budget off');

        $this->assertNull($chat->maxCostUsd());
        $this->assertStringContainsString('Spend cap cleared', $this->lastLine($chat));
    }

    /** Saying `off` when there was no cap is answered, not silently accepted. */
    public function testBudgetOffWithNoCapSaysThereWasNone(): void
    {
        $this->assertStringContainsString(
            'No spend cap was set',
            $this->lastLine($this->submitDraft($this->chat(), '/budget none')),
        );
    }

    /**
     * `0` is refused rather than read as "no cap". A cap of zero and no cap are
     * opposite intentions and guessing would pick the looser one.
     *
     * @dataProvider refusedArguments
     */
    public function testARefusedArgumentLeavesTheCapAloneAndExplainsItself(string $draft, ?float $before): void
    {
        $chat = $this->submitDraft($this->chat(cap: $before), $draft);

        $this->assertSame($before, $chat->maxCostUsd(), 'a refused argument must not change the cap');
        $this->assertStringContainsString('Usage: /budget', $this->lastLine($chat));
    }

    /** @return iterable<string, array{string, ?float}> */
    public static function refusedArguments(): iterable
    {
        yield 'zero' => ['/budget 0', null];
        yield 'zero with a cap already set' => ['/budget 0', 3.0];
        yield 'negative' => ['/budget -5', null];
        yield 'not a number' => ['/budget lots', null];
        yield 'a number with prose' => ['/budget 5 dollars', null];
    }

    /** The cap is per-launch and must not be written to the persisted config. */
    public function testSettingACapDoesNotPersistAnything(): void
    {
        $written = [];
        $chat = new Chat(
            history: [Message::user('hello'), Message::assistant('hi')],
            backend: new EchoBackend(),
            onConfigChange: static function (string $key, string $value) use (&$written): void {
                $written[$key] = $value;
            },
        );

        $this->submitDraft($chat, '/budget 5');

        $this->assertSame([], $written, 'a cap is a per-launch decision, not a persisted setting');
    }

    // =====================================================================
    // The cap
    // =====================================================================

    /**
     * The refusal, and the side of the cap it lands on: a turn is refused when
     * the ALREADY-REPORTED spend has reached the cap. The prompt is not sent, the
     * draft is kept, and the message says both which side this is and how to get
     * out of it.
     */
    public function testATurnIsRefusedOnceTheReportedSpendHasReachedTheCap(): void
    {
        $chat = $this->bill($this->chat(cap: 0.05), 100, 0.06);
        $refused = $this->submitDraft($chat, 'please do some work');

        $this->assertFalse($refused->inFlight, 'the turn must not have gone out');
        $line = $this->lastLine($refused);
        $this->assertStringContainsString('Spend cap reached', $line);
        $this->assertStringContainsString('this turn was not sent', $line);
        $this->assertStringContainsString('$0.0600 of the $0.0500 cap', $line);
        $this->assertStringContainsString(
            'refuses the NEXT turn rather than aborting one in flight',
            $line,
            'the message has to say which of the two behaviours this is - they are different guarantees',
        );
        $this->assertStringContainsString('/budget off', $line, 'and how to get out of it');
        $this->assertSame(
            'please do some work',
            $refused->inputBuf,
            'the draft is kept: the prompt was never sent, and a user may well answer by raising the cap',
        );
    }

    /** Exactly at the cap counts as reached — the cap is a ceiling, not a target. */
    public function testSpendExactlyAtTheCapRefuses(): void
    {
        $refused = $this->submitDraft($this->bill($this->chat(cap: 0.05), 100, 0.05), 'more work');

        $this->assertStringContainsString('Spend cap reached', $this->lastLine($refused));
    }

    /** Below the cap the turn goes out as normal. */
    public function testBelowTheCapTheTurnGoesOut(): void
    {
        $chat = $this->bill($this->chat(cap: 1.0), 100, 0.01);
        $sent = $this->submitDraft($chat, 'carry on');

        $this->assertTrue($sent->inFlight, 'the turn must go out');
        $this->assertSame('', $sent->inputBuf, 'and the draft is consumed');
    }

    /**
     * A cap over an UNREPORTED session fails open. Deliberate: a streamed session
     * whose provider sends no usage would otherwise be refused from its first
     * turn on the strength of a figure nobody supplied. That makes the cap a
     * budget guard rather than a security control, which is what its docblock
     * says.
     *
     * ASSERTED AS THE PROPERTY, not as a clause. `spendCapRefusal()` used to hold
     * a separate `!hasReportedSpend()` guard beside the `$spent < $cap` test, and
     * this test — with `cap: 0.0001` — passed through the arithmetic whichever
     * way, so deleting that guard changed nothing and a mutation of it survived.
     * The guard could only ever have decided anything at `cap <= 0`, which is now
     * impossible by construction ({@see Chat::isUsableSpendCap()}, enforced in
     * the constructor and asserted below), so the two conditions were collapsed
     * into one and this is the property that remains: with a positive cap and
     * nothing reported, the spend is 0.0, which is below any cap there can be.
     * The smallest representable positive cap is used deliberately — the property
     * has to hold at the boundary or it is not a fail-open.
     */
    public function testACapOverAnUnreportedSessionFailsOpen(): void
    {
        $chat = $this->chat(cap: PHP_FLOAT_MIN);
        $this->assertFalse($chat->hasReportedSpend(), 'fixture: nothing has been reported');
        $this->assertSame(0.0, $chat->spentUsd());

        $sent = $this->submitDraft($chat, 'carry on');

        $this->assertTrue($sent->inFlight);
        $this->assertStringNotContainsString('Spend cap reached', $this->lastLine($sent));
    }

    /**
     * And the boundary on the other side: a cap that has been reached refuses,
     * where "reached" means `>=` rather than `>`. Exactly-at-the-cap is the case
     * that distinguishes the two and the one a user hits.
     */
    public function testACapIsReachedAtEqualityNotOnlyPastIt(): void
    {
        $exactly = $this->bill($this->chat(cap: 0.05), 100, 0.05);
        $this->assertStringContainsString('Spend cap reached', $this->lastLine($this->submitDraft($exactly, 'more')));

        $justUnder = $this->bill($this->chat(cap: 0.05), 100, 0.049999);
        $this->assertStringNotContainsString(
            'Spend cap reached',
            $this->lastLine($this->submitDraft($justUnder, 'more')),
        );
    }

    // =====================================================================
    // What counts as a cap at all
    // =====================================================================

    /**
     * The constructor refuses a cap that is not a positive finite number of
     * dollars, which is the third and last door into `$maxCostUsd` — `/budget`
     * and `$SUGARCRUSH_MAX_COST` both refuse at their own edge, and this one used
     * to accept anything.
     *
     * NOT A HYPOTHETICAL for two of the four: measured on the old code, a cap of
     * `0.0` or `-1.0` refused every turn the instant a single zero-cost usage
     * block arrived (a real, free, self-hosted completion), and `INF` — which
     * `/budget 1e309` produced from user input — installed a cap that rendered as
     * `$inf` on the status bar and, every comparison against it being false,
     * enforced nothing at all. `NAN` is the same failure in both directions.
     *
     * @dataProvider unusableCaps
     */
    public function testTheConstructorRefusesACapThatIsNotAPositiveFiniteNumber(float $cap): void
    {
        $this->assertFalse(Chat::isUsableSpendCap($cap));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/positive finite number/');
        new Chat(backend: new EchoBackend(), maxCostUsd: $cap);
    }

    /** @return iterable<string, array{float}> */
    public static function unusableCaps(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-1.0];
        yield 'nan' => [NAN];
        yield 'infinity' => [INF];
    }

    /** The invariant survives `mutate()`, because every clone goes back through the constructor. */
    public function testAUsableCapSurvivesTheCloneChain(): void
    {
        $chat = $this->chat(cap: 2.5);
        foreach (mb_str_split('typing') as $char) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Char, $char));
        }

        $this->assertSame(2.5, $chat->maxCostUsd());
    }

    /**
     * `/budget 1e309` is the input that reached the `INF` case from the keyboard:
     * `is_numeric('1e309')` is true and the cast is `INF`, which is `> 0.0`. It is
     * refused with the usage line, and the existing cap is left alone.
     */
    public function testBudgetRefusesAnAmountTooLargeToRepresent(): void
    {
        $chat = $this->chat(cap: 3.0);
        $answered = $this->submitDraft($chat, '/budget 1e309');

        $this->assertSame(3.0, $answered->maxCostUsd(), 'a refused amount must not change the cap');
        $this->assertStringContainsString('infinity', $this->lastLine($answered));
    }

    /**
     * `/budget` must still work while capped — which is the whole reason the cap
     * check sits AFTER command dispatch. Checking first would lock the user out of
     * the only control that unlocks it.
     */
    public function testBudgetStillWorksWhileCappedWhichIsWhyTheCheckSitsAfterDispatch(): void
    {
        $capped = $this->bill($this->chat(cap: 0.05), 100, 0.06);

        $raised = $this->submitDraft($capped, '/budget 10');
        $this->assertSame(10.0, $raised->maxCostUsd());
        $this->assertStringNotContainsString('Spend cap reached', $this->lastLine($raised));

        $sent = $this->submitDraft($raised, 'carry on');
        $this->assertTrue($sent->inFlight, 'and the raised cap really does let the next turn out');
    }

    /** Clearing the cap unblocks too. */
    public function testClearingTheCapUnblocksTheSession(): void
    {
        $capped = $this->bill($this->chat(cap: 0.05), 100, 0.06);
        $sent = $this->submitDraft($this->submitDraft($capped, '/budget off'), 'carry on');

        $this->assertTrue($sent->inFlight);
    }

    /**
     * Other slash commands survive the cap as well — the refusal is scoped to
     * prompts bound for the provider, not to the app.
     */
    public function testOtherCommandsStillDispatchWhileCapped(): void
    {
        $capped = $this->bill($this->chat(cap: 0.05), 100, 0.06);
        $cleared = $this->submitDraft($capped, '/clear');

        $this->assertSame([], $cleared->history);
    }

    /** An empty draft is still an empty draft, capped or not. */
    public function testAnEmptyDraftIsNotRefusedItIsIgnored(): void
    {
        $capped = $this->bill($this->chat(cap: 0.05), 100, 0.06);
        [$next] = $capped->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertSame(count($capped->history), count($next->history));
    }

    /** The refusal is an assistant line in the transcript, not a silent no-op. */
    public function testTheRefusalIsVisibleInTheTranscript(): void
    {
        $before = $this->bill($this->chat(cap: 0.05), 100, 0.06);
        $after = $this->submitDraft($before, 'work');

        $this->assertSame(count($before->history) + 1, count($after->history));
        $this->assertSame(Role::Assistant, $after->history[count($after->history) - 1]->role);
    }

    /**
     * An externally supplied tracker is the one used — the seam
     * `Bootstrap::chat()` would use to share one, and the seam that lets a test
     * observe the session's total from outside.
     */
    public function testAnInjectedTrackerIsTheOneFed(): void
    {
        $tracker = new TokenTracker();
        $this->bill($this->chat(tracker: $tracker), 42, 0.004);

        $this->assertSame(42, $tracker->totalTokens());
        $this->assertEqualsWithDelta(0.004, $tracker->totalCost(), 0.000001);
    }

    /**
     * A turn that came back with tool calls is billed too. That path returns early
     * through `beginToolCalls()`, so recording per-branch instead of before the
     * split is exactly how the most expensive turns would go unbilled.
     */
    public function testAToolCallingTurnIsBilledEvenThoughItReturnsEarly(): void
    {
        $chat = new Chat(
            history: [Message::user('hello')],
            backend: new EchoBackend(),
            tools: ['noop' => static fn(array $args): string => 'ok'],
        );

        [$next] = $chat->update(new AssistantMsg(
            Message::assistant('calling')
                ->withToolCalls([new \SugarCraft\Crush\ToolCall('noop', [], 'call_1')])
                ->withUsage(Usage::new(500, 0.02)),
        ));

        $this->assertEqualsWithDelta(0.02, $next->spentUsd(), 0.000001);
    }

    /**
     * A stale reply — one whose generation no longer matches — is dropped from the
     * transcript, and it is billed anyway. The provider charged for it whether or
     * not the user still wanted it, so pretending otherwise would under-report a
     * session in which turns were cancelled.
     */
    public function testAStaleReplyIsStillBilledBecauseTheProviderStillCharged(): void
    {
        $chat = $this->chat();
        [$next] = $chat->update(new AssistantMsg(
            Message::assistant('too late')->withUsage(Usage::new(100, 0.01)),
            generation: 99,
        ));

        $this->assertSame(count($chat->history), count($next->history), 'fixture: the reply really was dropped');
        $this->assertEqualsWithDelta(0.01, $next->spentUsd(), 0.000001);
    }

    /**
     * The same reply arriving down the TOOL-EVENT path is billed too, and this is
     * the half the direct-route test above cannot see.
     *
     * An agentic turn resolves as a `BackendToolEventsMsg`, whose staleness guard
     * returns `[$this, null]` and BREAKS the re-dispatch chain — so no
     * `AssistantMsg` is ever synthesised and `update()`'s accounting arm is never
     * reached. Measured before the fix: the direct route billed $1.50 and this one
     * billed $0.00 for the identical Message. Tool turns are "the expensive kind"
     * the accounting comment names, so this was the gap that mattered.
     */
    public function testASupersededToolEventTurnIsBilledOnBothRoutes(): void
    {
        $paid = Message::assistant('done')->withUsage(Usage::new(2000, 1.5));

        [$direct] = $this->chat()->update(new AssistantMsg($paid, generation: 99));
        [$viaEvents, $cmd] = $this->chat()->update(new \SugarCraft\Crush\BackendToolEventsMsg(
            [new \SugarCraft\Crush\Events\ToolStarted('Bash', 'id1')],
            $paid,
            generation: 99,
        ));

        $this->assertNull($cmd, 'fixture: the guard really does break the chain here');
        $this->assertEqualsWithDelta(1.5, $direct->spentUsd(), 0.000001, 'the direct route');
        $this->assertEqualsWithDelta(1.5, $viaEvents->spentUsd(), 0.000001, 'the tool-event route');
    }

    /**
     * Billed exactly once when the tool-event chain runs to completion, i.e. the
     * two accounting sites are mutually exclusive rather than additive. The chain
     * drains its queue and then re-sends the reply as an `AssistantMsg`, which is
     * where a matching-generation turn is accounted.
     */
    public function testAToolEventTurnThatRunsToCompletionIsBilledExactlyOnce(): void
    {
        $chat = new Chat(
            history: [Message::user('hello')],
            backend: new EchoBackend(),
            tokenTracker: new TokenTracker(),
        );
        $paid = Message::assistant('done')->withUsage(Usage::new(2000, 1.5));

        $msg = new \SugarCraft\Crush\BackendToolEventsMsg(
            [new \SugarCraft\Crush\Events\ToolStarted('Bash', 'id1')],
            $paid,
            generation: null,
        );

        // Drive the whole re-dispatch chain the way the Program's loop does.
        $current = $chat;
        $next = $msg;
        for ($i = 0; $i < 8 && $next !== null; $i++) {
            [$current, $cmd] = $current->update($next);
            $next = null;
            if ($cmd !== null) {
                $produced = $cmd();
                if ($produced instanceof \SugarCraft\Core\Msg) {
                    $next = $produced;
                }
            }
        }

        $this->assertEqualsWithDelta(1.5, $current->spentUsd(), 0.000001, 'billed once, not twice');
    }

    /**
     * `/compact`'s own summarization call is billed. It is a provider call on the
     * user's key — and the largest single prompt this app sends, since it hands
     * the model the whole earlier conversation — so a readout that omitted it was
     * under-reporting its own biggest call.
     */
    public function testTheCompactSummarizationCallIsBilled(): void
    {
        [$pending, $cmd] = $this->compactWith(Usage::new(1000, 0.25));
        $this->assertNotNull($cmd, 'fixture: a summarization went out');
        $this->assertSame(0.0, $pending->spentUsd(), 'nothing is billed until it lands');

        [$landed] = $pending->update($this->resolveMsg($cmd));

        $this->assertEqualsWithDelta(0.25, $landed->spentUsd(), 0.000001);
    }

    /**
     * And a summarization the user has since abandoned is billed anyway — the call
     * went out and was charged for whether or not its answer is still wanted.
     * Accounted BEFORE the latch check for the same reason a superseded turn is
     * accounted before the staleness guard.
     */
    public function testAnAbandonedCompactSummarizationIsStillBilled(): void
    {
        [$pending, $cmd] = $this->compactWith(Usage::new(1000, 0.25));
        $msg = $this->resolveMsg($cmd);

        $cleared = $this->submitDraft($pending, '/clear');
        $this->assertSame([], $cleared->history, 'fixture: /clear abandoned it');

        [$after] = $cleared->update($msg);

        $this->assertSame([], $after->history, 'the summaries stay dropped');
        $this->assertEqualsWithDelta(0.25, $after->spentUsd(), 0.000001, 'and the money stays counted');
    }

    /**
     * The spend cap GATES the summarization call, and the compaction still
     * happens. Measured before the gate: a session $5.00 into a $1.00 cap fired a
     * full-conversation completion on the provider's default model.
     *
     * Refusing the COMMAND was the alternative and was rejected: compaction is
     * what frees context, so refusing it could corner a user whose only other exit
     * is `/clear`. Nothing here refuses the command — the fallback is the same
     * local heuristic an offline run uses, so the gate costs summary quality and
     * nothing else, and the notice names the way back.
     */
    public function testTheSpendCapGatesTheSummarizationButNotTheCompaction(): void
    {
        [$capped, $cmd] = $this->compactWith(Usage::new(1000, 0.25), cap: 1.0, alreadySpent: 5.0);

        $this->assertNull($cmd, 'no provider call may go out over the cap');
        $this->assertSame(0, $this->summaryCalls, 'and the backend must not have been touched');
        $notice = $this->lastLine($capped);
        $this->assertStringContainsString('Spend cap reached', $notice);
        $this->assertStringContainsString('local heuristic', $notice, 'the notice must name what ran instead');
        $this->assertStringContainsString('/budget', $notice, 'and the way out');
        $this->assertStringContainsString('[exchanged information]', implode("\n", array_map(
            static fn(Message $m): string => $m->content,
            $capped->history,
        )), 'the heuristic compaction really did run');
    }

    /** Under the cap the call goes out as normal. */
    public function testUnderTheCapTheSummarizationCallStillGoesOut(): void
    {
        [, $cmd] = $this->compactWith(Usage::new(1000, 0.25), cap: 100.0, alreadySpent: 5.0);

        $this->assertNotNull($cmd);
    }

    /**
     * The session titler's call is billed as well, including when its answer is
     * unusable and when the store refuses the rename — the two paths that used to
     * dispatch nothing and so made the call free in the readout.
     *
     * @dataProvider titlerOutcomes
     */
    public function testTheSessionTitlerCallIsBilledWhateverBecomesOfTheTitle(string $title): void
    {
        $chat = $this->chat();
        [$next] = $chat->update(new \SugarCraft\Crush\SessionTitledMsg(
            'some-other-session',
            $title,
            Usage::new(40, 0.0004),
        ));

        $this->assertEqualsWithDelta(0.0004, $next->spentUsd(), 0.000001);
    }

    /** @return iterable<string, array{string}> */
    public static function titlerOutcomes(): iterable
    {
        yield 'a usable title for a session we have since left' => ['Refactor the parser'];
        yield 'an unusable (empty) title' => [''];
    }

    /** Calls made so far by the summarizer {@see compactWith()} builds. */
    private int $summaryCalls = 0;

    /**
     * A `/compact` submitted against a summarizer that reports $usage, returned
     * as `[$chat, $cmd]` straight out of `update()`.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function compactWith(?Usage $usage, ?float $cap = null, float $alreadySpent = 0.0): array
    {
        $this->summaryCalls = 0;
        $calls = &$this->summaryCalls;
        $summarizer = new class ($usage, $calls) implements \SugarCraft\Crush\Backend {
            public function __construct(private readonly ?Usage $usage, private mixed &$calls) {}

            public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
            {
                $this->calls++;

                return Message::assistant("1. a\n2. b\n3. c\n4. d")->withUsage($this->usage);
            }

            public function completeAsync(
                array $history,
                callable $onToken = null,
                ?\SugarCraft\Crush\Backend\CancellationToken $cancellation = null,
                ?callable $onEvent = null,
            ): \React\Promise\PromiseInterface {
                $this->calls++;

                return \React\Promise\resolve(
                    Message::assistant("1. a\n2. b\n3. c\n4. d")->withUsage($this->usage),
                );
            }
        };

        $history = [];
        for ($i = 1; $i <= 5; $i++) {
            $history[] = Message::user("question {$i}");
            $history[] = Message::assistant("answer {$i} " . str_repeat('detail ', 60));
        }

        $chat = new Chat(
            history: $history,
            backend: new EchoBackend(),
            compactorConfig: \SugarCraft\Crush\Context\CompactorConfig::new()->withRecentPreserveCount(2),
            tokenTracker: new TokenTracker(),
            maxCostUsd: $cap,
            summaryBackend: $summarizer,
        );

        if ($alreadySpent > 0.0) {
            $chat = $this->bill($chat, 500, $alreadySpent);
        }

        foreach (mb_str_split('/compact') as $char) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Char, $char));
        }

        return $chat->update(new KeyMsg(KeyType::Enter, ''));
    }

    /** Drive a `Cmd::promise()` and hand back the Msg it resolves to. */
    private function resolveMsg(\Closure $cmd): \SugarCraft\Core\Msg
    {
        $async = $cmd();
        $this->assertInstanceOf(\SugarCraft\Core\AsyncCmd::class, $async);
        $resolved = null;
        $async->promise->then(static function ($msg) use (&$resolved): void {
            $resolved = $msg;
        });
        $this->assertInstanceOf(\SugarCraft\Core\Msg::class, $resolved);

        return $resolved;
    }
}
