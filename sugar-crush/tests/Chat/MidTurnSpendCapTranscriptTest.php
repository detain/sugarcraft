<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\BackendToolEventsMsg;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Events\SpendCapBreached;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\ToolEventPumpMsg;
use SugarCraft\Crush\Usage;
use SugarCraft\Crush\Util\TokenTracker;

/**
 * E20's transcript half: what the user SEES when a turn is aborted mid-flight
 * by the spend cap. `EngineBackendSpendCapTest` proves the loop stops; this
 * file proves the stop lands in history as a system notice worded as an ABORT
 * — "aborted after provider call N, $X of the $Y cap spent" — and stays a
 * different promise from the pre-dispatch REFUSAL ("this turn was not sent").
 * E20's step called that distinction out on purpose: refusal means nothing
 * was billed and the draft is still in the box, abort means money is already
 * spent and the draft is gone; one line conflating the two would mislead
 * exactly when the user is deciding whether to raise the cap.
 *
 * Driven through the two REAL folds — the settle-time
 * {@see BackendToolEventsMsg} chain and the live {@see ToolEventPumpMsg} pump
 * — with breaches delivered exactly the way `EngineBackend` delivers them:
 * through the per-turn inbox. Nothing here calls the private notice appender;
 * if either fold ever drops the event class, these go red.
 */
final class MidTurnSpendCapTranscriptTest extends TestCase
{
    /** @param list<Message>|null $history */
    private function transcriptChat(
        ?array $history = null,
        ?\ArrayObject $inbox = null,
        ?TokenTracker $tracker = null,
    ): Chat {
        return new Chat(
            history: $history ?? [Message::user('hello'), Message::assistant('hi')],
            backend: new EchoBackend(),
            tokenTracker: $tracker,
            liveToolEvents: $inbox,
        );
    }

    /**
     * Drive one settle-time chain the way Program::runCmd() does: apply the
     * Msg, execute any re-sent Msg command, repeat — the breach arm of
     * applyBackendToolEvent re-sends the remaining events plus the settled
     * reply, so the whole turn lands in a few iterations.
     */
    private function driveChain(Chat $chat, BackendToolEventsMsg $msg): Chat
    {
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

        return $current;
    }

    /** @return list<string> */
    private function transcript(Chat $chat): array
    {
        return array_map(static fn (Message $m): string => $m->content, $chat->history);
    }

    private function indexOfLineMatching(Chat $chat, string $needle): int
    {
        foreach ($this->transcript($chat) as $index => $line) {
            if (str_contains($line, $needle)) {
                return $index;
            }
        }

        return -1;
    }

    /** Type + Enter, returning BOTH states — the settle chain tests need neither. */
    private function typeAndSubmit(Chat $chat, string $draft): array
    {
        foreach (mb_str_split($draft) as $char) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Char, $char));
        }

        return $chat->update(new KeyMsg(KeyType::Enter, ''));
    }

    // =====================================================================
    // The settle-time fold
    // =====================================================================

    public function testABreachReachingTheSettledTurnLeadsTheReplyWithAnAbortNotice(): void
    {
        $paid = Message::assistant('here is what I found')->withUsage(Usage::new(100, 0.5));
        $chat = $this->driveChain(
            $this->transcriptChat(),
            new BackendToolEventsMsg(
                [new SpendCapBreached(2, 0.5, 0.5)],
                $paid,
                generation: null,
            ),
        );

        $abortIndex = $this->indexOfLineMatching($chat, 'Spend cap reached mid-turn');
        $replyIndex = $this->indexOfLineMatching($chat, 'here is what I found');

        $this->assertGreaterThanOrEqual(0, $abortIndex, 'the abort notice must reach history — a silent cap hit is the budget surprise E20 exists to kill');
        $this->assertGreaterThanOrEqual(0, $replyIndex, 'the work that DID run still settles as the reply');
        $this->assertGreaterThan($abortIndex, $replyIndex, 'the notice must precede the settled reply — chronologically the loop stopped before the answer arrived');
        $this->assertMatchesRegularExpression(
            '/aborted after provider call 2 — \$0\.5000 of the \$0\.5000 cap spent\. No further calls were made this turn; \\/budget raises the cap\./',
            $chat->history[$abortIndex]->content,
            'the notice names the call count and both dollar figures the event carried — the transcript is the only place the user learns WHERE the turn stopped',
        );
    }

    public function testTheAbortLinePromisesSomethingDifferentThanTheRefusalLine(): void
    {
        // E20's own words: refused-to-start and aborted-mid-turn are "different
        // guarantees" — one bills nothing and keeps the draft, the other has
        // already spent and cannot. If those two strings ever merge, a user
        // reading "was not sent" under an aborted turn will re-send work the
        // session already paid for.
        $refusing = new TokenTracker();
        $refusing->addTotalUsage(100, 0.06);
        [$refusedChat] = $this->typeAndSubmit(
            new Chat(
                history: [Message::user('hello'), Message::assistant('hi')],
                backend: new EchoBackend(),
                tokenTracker: $refusing,
                maxCostUsd: 0.05,
            ),
            'work',
        );
        $refusalLine = $refusedChat->history[count($refusedChat->history) - 1]->content;

        $abortLine = $this->driveChain(
            $this->transcriptChat(),
            new BackendToolEventsMsg([new SpendCapBreached(1, 0.5, 0.5)], Message::assistant('x'), generation: null),
        )->history[2]->content;

        $this->assertStringContainsString('was not sent', $refusalLine, 'fixture: the refusal is the not-sent promise');
        $this->assertStringNotContainsString('mid-turn', $refusalLine);
        $this->assertStringContainsString('mid-turn', $abortLine, 'fixture: the abort is the already-spent promise');
        $this->assertStringNotContainsString('was not sent', $abortLine);
        $this->assertNotSame($refusalLine, $abortLine);
    }

    public function testTheBreachedTurnIsBilledAndTheNextTurnRefuses(): void
    {
        // The interlock: an aborted turn's executed steps land in the tracker
        // through the settled Message's usage (same accounting as any turn),
        // and once that total reaches the cap the START gate takes over —
        // 'aborted' is a per-turn stop, 'refused' is the session consequence.
        $tracker = new TokenTracker();
        $tracker->addTotalUsage(50, 0.4);
        $chat = $this->driveChain(
            new Chat(
                history: [Message::user('hello'), Message::assistant('hi')],
                backend: new EchoBackend(),
                tokenTracker: $tracker,
                maxCostUsd: 0.5,
            ),
            new BackendToolEventsMsg(
                [new SpendCapBreached(1, 0.8, 0.5)],
                Message::assistant('partial work')->withUsage(Usage::new(100, 0.4)),
                generation: null,
            ),
        );

        $this->assertEqualsWithDelta(0.8, $chat->spentUsd(), 0.000001, 'baseline 0.4 + billed 0.4 — the abort does not forgive the spend');

        [$after] = $this->typeAndSubmit($chat, 'again');
        $last = $after->history[count($after->history) - 1]->content;
        $this->assertStringContainsString('was not sent', $last, 'with the cap now met, the next turn must hit the START refusal, not dispatch');
        $this->assertNotSame(-1, $this->indexOfLineMatching($after, 'Spend cap reached mid-turn'), 'the earlier abort line stays visible');
    }

    public function testABreachStampedWithAnotherGenerationIsDropped(): void
    {
        $chat = $this->transcriptChat();
        $before = count($chat->history);

        [$after, $cmd] = $chat->update(new BackendToolEventsMsg(
            [new SpendCapBreached(2, 0.5, 0.5)],
            Message::assistant('late reply'),
            generation: 99,
        ));

        $this->assertNull($cmd, 'fixture: the staleness guard breaks the chain here, exactly as it does for tool events');
        $this->assertSame($before, count($after->history), 'a superseded turn must not scribble an abort notice into the new turn transcript');
    }

    // =====================================================================
    // The live pump fold
    // =====================================================================

    public function testTheLivePumpFoldsEachBreachInOrderAndStops(): void
    {
        $inbox = new \ArrayObject([
            [0, new SpendCapBreached(1, 0.3, 0.5)],
            [0, new SpendCapBreached(2, 0.6, 0.5)],
        ]);
        $chat = $this->transcriptChat(inbox: $inbox);

        [$chat, $cmd] = $chat->update(new ToolEventPumpMsg());
        $this->assertInstanceOf(\Closure::class, $cmd, 'the pump re-sends itself while events remain — the burst of two must not lose the second');
        $produced = $cmd();
        $this->assertInstanceOf(ToolEventPumpMsg::class, $produced);
        [$chat, $cmd] = $chat->update($produced);
        $this->assertNull($cmd, 'and it stops once the inbox is drained');

        $lines = $this->transcript($chat);
        $this->assertMatchesRegularExpression('/aborted after provider call 1/', $lines[2]);
        $this->assertMatchesRegularExpression('/aborted after provider call 2/', $lines[3]);
    }

    public function testTheLivePumpDiscardsABreachFromAnotherGeneration(): void
    {
        $inbox = new \ArrayObject([[99, new SpendCapBreached(1, 0.3, 0.5)]]);
        $chat = $this->transcriptChat(inbox: $inbox);

        [$after, $cmd] = $chat->update(new ToolEventPumpMsg());

        $this->assertNull($cmd);
        $this->assertCount(2, $after->history, 'same generation guard as deltas: an aborted turn from an older dispatch has nothing to say to this one');
        $this->assertCount(0, $inbox, 'the stale entry is still consumed — the pump must not spin on it');
    }

    // =====================================================================
    // Production wiring: the cap must actually be threaded per dispatch
    // =====================================================================

    public function testTheProductionLaunchFeedsTheCapAndTheSessionBaselineIntoTheBackend(): void
    {
        // scheduleBackendCompletion() clones the EngineBackend with
        // withSpendCap(cap, spent-so-far) per dispatch — and the fork boundary
        // means that clone, not Chat, is what enforces the cap inside the
        // child. Observed by reflection on the returned command's capture list
        // (the lane-ab TaskToolWiringTest precedent): the promise closure is
        // built around the CLONE, and the never-called provider double keeps
        // the test from performing real work.
        $tracker = new TokenTracker();
        $tracker->addTotalUsage(100, 0.2);
        $backend = EngineBackend::new($this->neverCalledProvider(), 'wired');
        $chat = new Chat(
            history: [Message::user('hello'), Message::assistant('hi')],
            backend: $backend,
            tokenTracker: $tracker,
            maxCostUsd: 0.5,
        );

        [, $cmd] = $this->typeAndSubmit($chat, 'go');
        $this->assertInstanceOf(\Closure::class, $cmd, 'fixture: a capped-but-under-cap session still dispatches');

        $launched = $this->digBackend($cmd);
        $this->assertInstanceOf(EngineBackend::class, $launched, 'the dispatched backend must be the capped clone, not the shared original');
        $this->assertNotSame($backend, $launched);
        $this->assertSame(0.5, $this->readProperty($launched, 'spendCapUsd'));
        $this->assertEqualsWithDelta(0.2, $this->readProperty($launched, 'sessionSpendAtStartUsd'), 0.000001, 'the turn inherits what the session already spent — the cap is a session budget, not a per-turn allowance');
        $this->assertNull($this->readProperty($backend, 'spendCapUsd'), 'the shared backend stays uncapped for every non-Chat caller');
    }

    public function testAnUncappedSessionLaunchesTheSharedBackendUntouched(): void
    {
        $backend = EngineBackend::new($this->neverCalledProvider(), 'wired');
        $chat = new Chat(
            history: [Message::user('hello'), Message::assistant('hi')],
            backend: $backend,
        );

        [, $cmd] = $this->typeAndSubmit($chat, 'go');

        $launched = $this->digBackend($cmd);
        $this->assertSame($backend, $launched, 'no cap configured means no clone — every session that never uses /budget keeps the exact dispatch path it had before E20');
    }

    // =====================================================================
    // helpers
    // =====================================================================

    private function neverCalledProvider(): ProviderInterface
    {
        return new class implements ProviderInterface {
            public function name(): string { return 'never'; }
            public function supportsStreaming(): bool { return false; }
            public function supportsFunctionCalling(): bool { return true; }
            public function supportsVision(): bool { return false; }
            public function supportsJsonSchema(): bool { return false; }
            public function contextWindow(): int { return 100_000; }
            public function costPer1kTokens(string $model, string $direction): float { return 0.0; }
            public function complete(CompleteRequest $request): CompleteResponse
            {
                throw new \LogicException('fixture: this provider must never run — the test observes the command closure, not a turn');
            }
            public function completeStream(CompleteRequest $request): \Generator
            {
                throw new \LogicException('fixture: never called');
            }
            public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
            {
                throw new \LogicException('fixture: never called');
            }
        };
    }

    /**
     * Follow the command-closure capture chain (Cmd::promise → its `factory`,
     * Cmd::batch → its `filtered` list) until a captured EngineBackend turns
     * up. Reflection-only: nothing is ever invoked, so no fork and no provider
     * call happen.
     */
    private function digBackend(\Closure $cmd): ?EngineBackend
    {
        $properties = (new \ReflectionFunction($cmd))->getStaticVariables();

        foreach ($properties as $key => $value) {
            if ($key === 'backend' && $value instanceof EngineBackend) {
                return $value;
            }
            if ($key === 'factory' && $value instanceof \Closure) {
                $found = $this->digBackend($value);
                if ($found !== null) {
                    return $found;
                }
            }
            if ($key === 'filtered' && is_array($value)) {
                foreach ($value as $inner) {
                    if ($inner instanceof \Closure) {
                        $found = $this->digBackend($inner);
                        if ($found !== null) {
                            return $found;
                        }
                    }
                }
            }
        }

        return null;
    }

    private function readProperty(object $subject, string $name): mixed
    {
        $property = new \ReflectionProperty($subject, $name);
        $property->setAccessible(true);

        return $property->getValue($subject);
    }
}
