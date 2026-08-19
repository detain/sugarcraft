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
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\AssistantMsg;
use SugarCraft\Crush\Context\CompactorConfig;
use SugarCraft\Crush\HistoryCompactedMsg;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Session\SessionStore;

/**
 * crush_code.md Phase 5 item 6, driven through the real `Chat::update()`.
 *
 * Two properties are load-bearing and both are asserted behaviourally rather
 * than described:
 *
 *  1. **Nothing blocks.** `/compact` returns a Cmd and rewrites nothing yet. A
 *     synchronous provider call at this point would freeze every keystroke for
 *     the length of a completion, and this codebase deliberately puts no
 *     total-request timeout on one.
 *  2. **No provider is not a failure.** With no summary backend — the offline
 *     default, and what every other test in this suite constructs — `/compact`
 *     is exactly as synchronous and as heuristic as it always was.
 */
final class CompactModelSummaryTest extends TestCase
{
    /**
     * A transcript long enough that stage 2 runs. Two pairs are preserved (see
     * {@see compactorConfig()}), so the earlier ones get summarised.
     *
     * Mind the arithmetic when reading the counts below: `/compact` appends a
     * user line and a notice, and those form a PAIR, so a 5-pair fixture is a
     * 6-pair history by the time the compaction is sized — 2 preserved, 4
     * condensed. That is deliberate (see `Chat::scheduleModelCompaction()`) and
     * it is why the fixtures here supply four summaries for five pairs.
     *
     * @return list<Message>
     */
    private function history(int $pairs = 5): array
    {
        $out = [];
        for ($i = 1; $i <= $pairs; $i++) {
            $out[] = Message::user("question {$i}");
            $out[] = Message::assistant("answer {$i} " . str_repeat('detail ', 60));
        }

        return $out;
    }

    private function compactorConfig(): CompactorConfig
    {
        return CompactorConfig::new()->withRecentPreserveCount(2);
    }

    private function chat(?Backend $summaryBackend, string $draft = '/compact', int $pairs = 5): Chat
    {
        return new Chat(
            history: $this->history($pairs),
            inputBuf: $draft,
            backend: new EchoBackend(),
            compactorConfig: $this->compactorConfig(),
            summaryBackend: $summaryBackend,
        );
    }

    /** A stand-in summarization backend answering with a fixed reply. */
    private function summarizer(string $reply, ?array &$seen = null): Backend
    {
        return new class ($reply, $seen) implements Backend {
            public function __construct(private readonly string $reply, private mixed &$seen) {}

            public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
            {
                $this->seen = $history;

                return Message::assistant($this->reply);
            }

            public function completeAsync(array $history, callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                $this->seen = $history;

                return \React\Promise\resolve(Message::assistant($this->reply));
            }
        };
    }

    /** A summarization backend whose call fails. */
    private function failingSummarizer(string $why): Backend
    {
        return new class ($why) implements Backend {
            public function __construct(private readonly string $why) {}

            public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
            {
                throw new \RuntimeException($this->why);
            }

            public function completeAsync(array $history, callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                return \React\Promise\reject(new \RuntimeException($this->why));
            }
        };
    }

    private function submit(Chat $chat): array
    {
        return $chat->update(new KeyMsg(KeyType::Enter, ''));
    }

    /**
     * Type $draft into $chat one keystroke at a time and submit it.
     *
     * The draft is typed rather than injected because `Chat::withInputBuf()` is
     * private — and typing is the route a user has anyway, so a test that reaches
     * past it could pass against an input path that no longer works.
     */
    private function type(Chat $chat, string $draft): array
    {
        foreach (mb_str_split($draft) as $char) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Char, $char));
        }

        return $this->submit($chat);
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

    // =====================================================================
    // Nothing blocks
    // =====================================================================

    /**
     * The first property: with a summarizer present, `/compact` hands back a Cmd
     * and the transcript is NOT yet compacted. If the provider call happened
     * inside `update()`, there would be no Cmd and the history would already be
     * rewritten — which is the shape this test exists to rule out.
     */
    public function testWithASummarizerCompactReturnsACmdAndRewritesNothingYet(): void
    {
        $chat = $this->chat($this->summarizer("1. first\n2. second\n3. third\n4. fourth"));
        $before = count($chat->history);

        [$next, $cmd] = $this->submit($chat);

        $this->assertNotNull($cmd, '/compact must hand the provider call to a Cmd, not run it in update()');
        $this->assertSame(
            $before + 2,
            count($next->history),
            'the transcript grows by the /compact line and a notice, and by nothing else - '
            . 'a compaction that had already happened would have SHRUNK it',
        );
        $this->assertStringContainsString('question 1', $next->view(), 'the earliest exchange is still verbatim');
        $this->assertStringContainsString(
            'Summarising 4 earlier exchanges',
            $next->history[count($next->history) - 1]->content,
        );
        $this->assertFalse($next->inFlight, 'and it does not pretend a turn is in flight');
    }

    /**
     * The compaction happens when the Msg lands, and it uses the model's lines.
     * The `[exchanged information]` placeholder — the thing item 6 exists to
     * remove — must be absent, and the model's own words present.
     */
    public function testTheCompactionHappensWhenTheSummariesLandAndUsesThem(): void
    {
        $chat = $this->chat($this->summarizer(
            "1. Asked about routing; chose config/routes.php.\n"
            . "2. Asked about caching; picked Redis.\n"
            . "3. Asked about tests; added a regression case.\n"
            . "4. Asked about deploys; settled on the CI job."
        ));

        [$pending, $cmd] = $this->submit($chat);
        $msg = $this->resolve($cmd);
        $this->assertInstanceOf(HistoryCompactedMsg::class, $msg);

        [$done] = $pending->update($msg);
        $text = implode("\n", array_map(static fn(Message $m): string => $m->content, $done->history));

        $this->assertStringContainsString('[summary] Asked about routing; chose config/routes.php.', $text);
        $this->assertStringContainsString('[summary] Asked about caching; picked Redis.', $text);
        $this->assertStringNotContainsString(
            '[exchanged information]',
            $text,
            'the placeholder must be gone from EVERY condensed exchange, not most of them: the offered set has to '
            . 'be the set the compaction actually condenses, and it is derived from the history this command '
            . 'leaves behind rather than the one it inherited for exactly that reason',
        );
        $this->assertStringContainsString('Context compacted:', $text);
        $this->assertLessThan(
            count($pending->history),
            count($done->history),
            'and it really did shrink the transcript',
        );
    }

    /**
     * Exactly ONE `/compact` line in the transcript for one command. The notice
     * is written when the request goes out and the result when it lands, so a
     * second echo of the draft on the second pass would be easy to ship and
     * invisible without this.
     */
    public function testOneCommandLeavesExactlyOneCompactLineInTheTranscript(): void
    {
        $chat = $this->chat($this->summarizer('1. a'));
        [$pending, $cmd] = $this->submit($chat);
        [$done] = $pending->update($this->resolve($cmd));

        $userLines = array_filter(
            $done->history,
            static fn(Message $m): bool => $m->role === Role::User && $m->content === '/compact',
        );
        $this->assertCount(1, $userLines);
    }

    /**
     * The request goes to the SUMMARY backend, not the conversation backend. This
     * is the trap the design exists to avoid: the main backend runs the whole
     * agentic loop, so a summarization routed through it can call `Bash` and put
     * a permission prompt on screen mid-compaction.
     *
     * Asserted by what the summarizer was HANDED — a system prompt about
     * compacting plus the numbered exchanges — and by the fact that the reply
     * used is the summarizer's, not the EchoBackend's echo.
     */
    public function testTheRequestGoesToTheToollessSummaryBackendAndNotTheConversationBackend(): void
    {
        $seen = null;
        $chat = $this->chat($this->summarizer('1. THE SUMMARIZER ANSWERED', $seen));

        [$pending, $cmd] = $this->submit($chat);
        [$done] = $pending->update($this->resolve($cmd));

        $this->assertIsArray($seen, 'the summary backend must be the one called');
        $this->assertCount(2, $seen, 'one system instruction and one user payload, and nothing else');
        $this->assertSame(Role::System, $seen[0]->role);
        $this->assertStringContainsString('compacting a coding-assistant conversation', $seen[0]->content);
        $this->assertStringContainsString('### Exchange 1', $seen[1]->content);
        $this->assertStringContainsString('question 1', $seen[1]->content);
        $this->assertStringContainsString(
            'THE SUMMARIZER ANSWERED',
            implode("\n", array_map(static fn(Message $m): string => $m->content, $done->history)),
        );
    }

    /**
     * The prompt carries the exchanges numbered from 1 in the order the compactor
     * will consume them, because the reply is mapped back POSITIONALLY. A prompt
     * numbered differently from the mapping would silently attach every summary
     * to the wrong exchange.
     */
    public function testThePromptNumbersTheExchangesInTheOrderTheReplyIsMappedBackIn(): void
    {
        $seen = null;
        $chat = $this->chat($this->summarizer('1. one', $seen));
        [$pending, $cmd] = $this->submit($chat);
        $pending->update($this->resolve($cmd));

        $payload = $seen[1]->content;
        $this->assertSame(
            ['### Exchange 1', '### Exchange 2', '### Exchange 3', '### Exchange 4'],
            array_values(array_filter(
                preg_split('/\R/', $payload) ?: [],
                static fn(string $line): bool => str_starts_with($line, '### Exchange'),
            )),
        );
        $this->assertLessThan(
            strpos($payload, 'question 2'),
            strpos($payload, 'question 1'),
            'and the earliest exchange is numbered first',
        );
    }

    // =====================================================================
    // Failure and staleness
    // =====================================================================

    /**
     * A failed summarization still compacts, on the heuristic, and SAYS SO. A
     * compaction is lossy and permanent, so "the fallback did this one and here
     * is why" is information the user needs at the moment it happens — unlike the
     * session-title call this rides beside, whose failure is a non-event.
     */
    public function testAFailedSummarizationCompactsOnTheHeuristicAndSaysWhy(): void
    {
        $chat = $this->chat($this->failingSummarizer('connection refused'));
        [$pending, $cmd] = $this->submit($chat);
        $msg = $this->resolve($cmd);

        $this->assertInstanceOf(HistoryCompactedMsg::class, $msg);
        $this->assertSame('connection refused', $msg->error);

        [$done] = $pending->update($msg);
        $last = $done->history[count($done->history) - 1]->content;

        $this->assertStringContainsString('Model summarisation failed (connection refused)', $last);
        $this->assertStringContainsString('Context compacted:', $last, 'and it still compacted');
        $this->assertStringContainsString(
            '[exchanged information]',
            implode("\n", array_map(static fn(Message $m): string => $m->content, $done->history)),
            'with the heuristic, which is the whole point of keeping it',
        );
    }

    /** A reply the model wrote as prose, with no numbered lines, is reported the same way. */
    public function testAReplyWithNoUsableLinesIsReportedRatherThanSilentlyIgnored(): void
    {
        $chat = $this->chat($this->summarizer('Sure! I have summarised your conversation for you.'));
        [$pending, $cmd] = $this->submit($chat);
        [$done] = $pending->update($this->resolve($cmd));

        $this->assertStringContainsString(
            'The model returned no usable summaries',
            $done->history[count($done->history) - 1]->content,
        );
    }

    /**
     * A superseded summarization is dropped. Two `/compact`s in flight would
     * otherwise both apply, the second compacting an already-compacted
     * transcript on stale summaries.
     */
    public function testASummarizationForASupersededCompactIsDropped(): void
    {
        $chat = $this->chat($this->summarizer('1. first attempt'));
        [$first, $firstCmd] = $this->submit($chat);
        $staleMsg = $this->resolve($firstCmd);

        // A second /compact latches a new id.
        [$second] = $this->type($first, '/compact');

        [$after, $cmd] = $second->update($staleMsg);

        $this->assertSame($second, $after, 'the stale message must change nothing at all');
        $this->assertNull($cmd);
    }

    /** And `/clear` abandons one, for the same reason. */
    public function testClearAbandonsAnOutstandingSummarization(): void
    {
        $chat = $this->chat($this->summarizer('1. first'));
        [$pending, $cmd] = $this->submit($chat);
        $msg = $this->resolve($cmd);

        [$cleared] = $this->type($pending, '/clear');
        $this->assertSame([], $cleared->history, 'fixture: /clear really emptied it');

        [$after] = $cleared->update($msg);
        $this->assertSame([], $after->history, 'the summaries must not resurrect the cleared exchanges');
    }

    // =====================================================================
    // Untrusted model text
    // =====================================================================

    /**
     * A summary line is model-authored text bound for the transcript AND for the
     * next prompt. An ESC could repaint the chrome around it and an embedded
     * newline would break the one-summary-per-message shape stage 3's grouping
     * relies on, so both are flattened out.
     */
    public function testControlBytesAndNewlinesAreStrippedFromASummaryLine(): void
    {
        $chat = $this->chat($this->summarizer("1. clean\x1b[31mred\x1b[0m and\nsplit"));
        [$pending, $cmd] = $this->submit($chat);
        [$done] = $pending->update($this->resolve($cmd));

        $summary = null;
        foreach ($done->history as $message) {
            if (str_starts_with($message->content, '[summary] clean')) {
                $summary = $message->content;
            }
        }

        $this->assertNotNull($summary, 'fixture: the model line must have been applied');
        $this->assertStringNotContainsString("\x1b", $summary);
        $this->assertStringNotContainsString("\n", $summary);
        $this->assertStringContainsString('clean', $summary, 'the visible text survives');
        $this->assertStringContainsString(
            '[31mred',
            $summary,
            'only the ESC byte goes - the rest of the sequence is inert literal text once its introducer is gone, '
            . 'and stripping it would be silently editing the model',
        );
        $this->assertStringNotContainsString(
            'split',
            $summary,
            'a newline ENDS the line at the parse step, so the tail after it is an unnumbered line and is not a '
            . 'summary at all - the sanitiser never sees it',
        );
    }

    /**
     * An unbounded summary would let a "compaction" be larger than what it
     * replaced. The ceiling is the same 200 characters
     * `COMPACT_SUMMARY_PROMPT` asks the model for, read off the constant so the
     * instruction and the enforcement cannot drift apart.
     */
    public function testAnOverlongSummaryLineIsBoundedToTheCeilingThePromptAsksFor(): void
    {
        $max = (new \ReflectionClass(Chat::class))->getConstant('SUMMARY_LINE_MAX_CHARS');
        $this->assertIsInt($max);
        $this->assertStringContainsString(
            "under {$max} characters",
            (string) (new \ReflectionClass(Chat::class))->getConstant('COMPACT_SUMMARY_PROMPT'),
            'the prompt must ask for the bound the code enforces',
        );

        $chat = $this->chat($this->summarizer('1. ' . str_repeat('z', $max * 3)));
        [$pending, $cmd] = $this->submit($chat);
        [$done] = $pending->update($this->resolve($cmd));

        $summary = null;
        foreach ($done->history as $message) {
            if (str_starts_with($message->content, '[summary] zzz')) {
                $summary = $message->content;
            }
        }
        $this->assertNotNull($summary);
        $this->assertSame($max, mb_strlen(substr($summary, strlen('[summary] '))));
    }

    /**
     * A number outside the range, and a duplicate, are both ignored rather than
     * mapped onto whatever is nearest — which is how a partially-obeyed
     * instruction degrades to the heuristic instead of mis-attributing.
     */
    public function testOutOfRangeAndDuplicateNumbersAreIgnored(): void
    {
        $chat = $this->chat($this->summarizer(
            "0. numbered from zero\n1. FIRST\n1. A SECOND LINE FOR ONE\n99. way out of range"
        ));
        [$pending, $cmd] = $this->submit($chat);
        $msg = $this->resolve($cmd);

        $this->assertSame(['FIRST'], array_values($msg->summaries));

        [$done] = $pending->update($msg);
        $text = implode("\n", array_map(static fn(Message $m): string => $m->content, $done->history));
        $this->assertStringNotContainsString('A SECOND LINE FOR ONE', $text);
        $this->assertStringNotContainsString('way out of range', $text);
        $this->assertStringNotContainsString('numbered from zero', $text);
    }

    // =====================================================================
    // The no-provider path stays exactly as it was
    // =====================================================================

    /**
     * The fallback is not an error path. With no summary backend — every unit
     * test, every offline run, every `$SUGARCRUSH_BACKEND_CMD` run — `/compact`
     * is synchronous, returns no Cmd, and compacts in the same update() call.
     */
    public function testWithNoSummaryBackendCompactIsSynchronousAndHeuristicExactlyAsBefore(): void
    {
        $chat = $this->chat(null);
        [$next, $cmd] = $this->submit($chat);

        $this->assertNull($cmd, 'nothing to ask means nothing to schedule');
        $this->assertLessThan(count($chat->history), count($next->history), 'it compacted here and now');
        $text = implode("\n", array_map(static fn(Message $m): string => $m->content, $next->history));
        $this->assertStringContainsString('[exchanged information]', $text);
        $this->assertStringContainsString('Context compacted:', $text);
    }

    /**
     * And a history with nothing a model could usefully summarise takes the
     * synchronous route too, even WITH a backend — there is no point paying for
     * a completion that would be applied to nothing.
     */
    public function testAHistoryWithNothingToSummariseTakesTheSynchronousRouteEvenWithABackend(): void
    {
        $seen = null;
        // ONE pair, which with the `/compact` pair makes two - exactly
        // recentPreserveCount, so nothing is condensed and nothing is worth asking.
        $chat = $this->chat($this->summarizer('1. never asked', $seen), '/compact', pairs: 1);

        [$next, $cmd] = $this->submit($chat);

        $this->assertNull($cmd);
        $this->assertNull($seen, 'the provider must not have been called at all');
        $this->assertStringContainsString(
            'Context compacted:',
            $next->history[count($next->history) - 1]->content,
        );
    }

    /**
     * OFFLINE BEATS CAPPED, and the order of the two early returns is what makes
     * it so.
     *
     * A capped session with a summary backend gets the cap notice — that is
     * `scheduleModelCompaction()`'s deliberate refusal to downgrade in silence.
     * A capped session with NO summary backend must get the ordinary
     * `Context compacted:` answer instead: with no provider at all there is
     * nothing the cap prevented, and telling the user to raise a ceiling that was
     * never in the way sends them to fix the wrong thing.
     *
     * Both halves are asserted, because the ordering only exists as the relative
     * position of two `if`s and either one alone would look correct.
     */
    public function testACappedSessionWithNoSummaryBackendReportsAPlainCompactionAndNotTheCap(): void
    {
        $tracker = new \SugarCraft\Crush\Util\TokenTracker();
        $tracker->addTotalUsage(1_000, 5.0);

        $offline = new Chat(
            history: $this->history(),
            inputBuf: '/compact',
            backend: new EchoBackend(),
            compactorConfig: $this->compactorConfig(),
            tokenTracker: $tracker,
            maxCostUsd: 1.0,
            summaryBackend: null,
        );
        [$next, $cmd] = $this->submit($offline);
        $answer = $next->history[count($next->history) - 1]->content;

        $this->assertNull($cmd);
        $this->assertStringStartsWith('Context compacted:', $answer);
        $this->assertStringNotContainsString(
            'Spend cap reached',
            $answer,
            'there was no provider for the cap to have stopped',
        );

        // The control: the same cap, WITH a provider, does say so.
        $seen = null;
        $capped = new Chat(
            history: $this->history(),
            inputBuf: '/compact',
            backend: new EchoBackend(),
            compactorConfig: $this->compactorConfig(),
            tokenTracker: $tracker,
            maxCostUsd: 1.0,
            summaryBackend: $this->summarizer('1. never asked', $seen),
        );
        [$cappedNext, $cappedCmd] = $this->submit($capped);

        $this->assertNull($cappedCmd, 'the compaction still runs, it just runs on the heuristic');
        $this->assertNull($seen, 'and the model is not asked');
        $this->assertStringContainsString(
            'Spend cap reached',
            $cappedNext->history[count($cappedNext->history) - 1]->content,
        );
    }

    /** An empty transcript answers as it always did, with no provider call. */
    public function testAnEmptyTranscriptStillAnswersWithoutAskingAnyone(): void
    {
        $seen = null;
        $chat = new Chat(
            history: [],
            inputBuf: '/compact',
            backend: new EchoBackend(),
            compactorConfig: $this->compactorConfig(),
            summaryBackend: $this->summarizer('1. never asked', $seen),
        );

        [$next, $cmd] = $this->submit($chat);

        $this->assertNull($cmd);
        $this->assertNull($seen);
        $this->assertStringContainsString(
            'Nothing to compact',
            $next->history[count($next->history) - 1]->content,
        );
    }
    // =====================================================================
    // A LANDING COMPACTION IS NOT A SUBMITTED COMMAND
    //
    // `Chat::compactNow()` and `Chat::applyModelCompaction()` share the
    // transcript rewrite and NOTHING else. Both properties below were false
    // while they shared `inputBuf`/`inFlight` too, and both are user-visible
    // data loss rather than polish.
    // =====================================================================

    /**
     * The draft survives. `HistoryCompactedMsg`'s contract is that the user can
     * keep typing while the summarization is out, so the compaction that lands
     * must not wipe what they typed — the synchronous `/compact` clears the box
     * because submitting it consumed the draft, which is not true here.
     */
    public function testALandingCompactionLeavesAnInProgressDraftAlone(): void
    {
        $chat = $this->chat($this->summarizer("1. a\n2. b\n3. c\n4. d"));
        [$pending, $cmd] = $this->submit($chat);

        // Keep typing, exactly as the Msg's docblock says a user may.
        $draft = 'a long half-typed prompt I am still writing';
        foreach (mb_str_split($draft) as $char) {
            [$pending] = $pending->update(new KeyMsg(KeyType::Char, $char));
        }
        $this->assertSame($draft, $pending->inputBuf, 'fixture: the draft is in the box');

        [$done] = $pending->update($this->resolve($cmd));

        $this->assertSame($draft, $done->inputBuf, 'the landing compaction destroyed the draft');
        $this->assertStringContainsString('half-typed', $done->view(), 'and the frame no longer shows it');
    }

    /**
     * The turn survives. Clearing `inFlight` under a running turn is not a
     * cosmetic bug: it lifts `update()`'s Enter-swallow, so a SECOND concurrent
     * turn is accepted, that bumps `$generation`, and the first turn's reply —
     * completed and paid for — is then dropped by the staleness guard.
     */
    public function testALandingCompactionLeavesARunningTurnInFlightAndItsReplyStillLands(): void
    {
        $chat = $this->chat($this->summarizer("1. a\n2. b\n3. c\n4. d"));
        [$pending, $summaryCmd] = $this->submit($chat);

        [$turned] = $this->type($pending, 'a real prompt');
        $this->assertTrue($turned->inFlight, 'fixture: a turn is running');
        $generation = $this->generationOf($turned);

        [$landed] = $turned->update($this->resolve($summaryCmd));

        $this->assertTrue($landed->inFlight, 'the compaction cleared inFlight out from under a running turn');
        $this->assertSame($generation, $this->generationOf($landed), 'and it must not move the generation counter');

        // The spinner and the cancel hint are what the user reads inFlight off.
        $this->assertStringContainsString('Esc Esc to cancel', $landed->view());

        // Enter is still swallowed, so no second turn can be started.
        [$afterEnter, $enterCmd] = $landed->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertNull($enterCmd, 'Enter must still be swallowed while the turn runs');
        $this->assertSame($generation, $this->generationOf($afterEnter));

        // And the turn's own reply, stamped with the generation it started
        // under, is still delivered rather than dropped as stale.
        [$replied] = $landed->update(new AssistantMsg(Message::assistant('the answer'), $generation));
        $this->assertFalse($replied->inFlight);
        $this->assertSame('the answer', $replied->history[count($replied->history) - 1]->content);
    }

    /**
     * `/rewind` releases the latch. Measured before it did: a summarization
     * landing after a rewind compacted the transcript the user had just
     * RECOVERED, and because the summaries were keyed to the content the rewind
     * discarded, none of them applied — five restored exchanges came back as
     * `[exchanged information]` placeholders. Automatic data loss on top of a
     * recovery command.
     */
    public function testRewindAbandonsAnOutstandingSummarization(): void
    {
        $dir = sys_get_temp_dir() . '/compact_rewind_' . uniqid('', true);
        mkdir($dir, 0755, true);

        try {
            $store = new EnhancedSessionStore($dir . '/s.db');
            $store->createSession('sess', 'p', 'm');
            $checkpoint = [];
            for ($i = 1; $i <= 6; $i++) {
                $checkpoint[] = ['role' => 'user', 'content' => "checkpointed question {$i}"];
                $checkpoint[] = [
                    'role' => 'assistant',
                    'content' => "checkpointed answer {$i} " . str_repeat('detail ', 60),
                ];
            }
            $store->saveCheckpoint('sess', ['messages' => $checkpoint]);

            $chat = new Chat(
                history: $this->history(),
                backend: new EchoBackend(),
                compactorConfig: $this->compactorConfig(),
                summaryBackend: $this->summarizer("1. a\n2. b\n3. c\n4. d"),
                sessionStore: $store,
                currentSessionId: 'sess',
            );

            [$pending, $cmd] = $this->type($chat, '/compact');
            $msg = $this->resolve($cmd);
            $this->assertInstanceOf(HistoryCompactedMsg::class, $msg, 'fixture: a summarization went out');

            [$rewound] = $this->type($pending, '/rewind');
            $restored = count($rewound->history);
            $this->assertStringContainsString(
                'checkpointed question 1',
                implode("\n", array_map(static fn(Message $m): string => $m->content, $rewound->history)),
                'fixture: the checkpoint really was restored',
            );

            [$after] = $rewound->update($msg);

            $this->assertSame($restored, count($after->history), 'the landing summary compacted a rewound transcript');
            $text = implode("\n", array_map(static fn(Message $m): string => $m->content, $after->history));
            $this->assertStringNotContainsString('[exchanged information]', $text);
            $this->assertStringNotContainsString('Context compacted:', $text);
        } finally {
            foreach (glob($dir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }

    /**
     * And so does the Ctrl+P palette's New session action — which is the ONLY
     * route to it. `/new` is `slashVisible: false` in the registry and has no
     * `dispatchCommand()` arm, so typing it sends the literal text to the model.
     */
    public function testThePalettesNewSessionActionAbandonsAnOutstandingSummarization(): void
    {
        $dir = sys_get_temp_dir() . '/compact_newsession_' . uniqid('', true);
        mkdir($dir, 0755, true);

        try {
            $store = new SessionStore($dir . '/s.db');
            $chat = new Chat(
                history: $this->history(),
                backend: new EchoBackend(),
                compactorConfig: $this->compactorConfig(),
                summaryBackend: $this->summarizer("1. a\n2. b\n3. c\n4. d"),
                sessionStore: $store,
                currentSessionId: 'sess',
            );

            [$pending, $cmd] = $this->type($chat, '/compact');
            $msg = $this->resolve($cmd);

            [$fresh] = $pending->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
            [$fresh] = $fresh->update(new KeyMsg(KeyType::Enter, ''));
            $this->assertStringContainsString(
                'New session created',
                $fresh->history[count($fresh->history) - 1]->content,
                'fixture: the palette root selects New session first',
            );

            $before = count($fresh->history);
            [$after] = $fresh->update($msg);

            $this->assertSame($before, count($after->history), 'the summary must not rewrite the new session');
        } finally {
            foreach (glob($dir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }

    /** `/new` is not a slash command at all — the palette action is the route. */
    public function testSlashNewIsNotACommandAndIsSentToTheModel(): void
    {
        $rows = array_filter(
            \SugarCraft\Crush\Commands\CommandRegistry::all(),
            static fn(object $spec): bool => $spec->name === 'new',
        );
        $this->assertCount(1, $rows, 'fixture: there is a registry row named new');
        $this->assertFalse(
            array_values($rows)[0]->slashVisible,
            'if /new ever becomes typeable it must clear the compaction latch too',
        );

        $chat = new Chat(
            history: [Message::user('hi'), Message::assistant('there')],
            backend: new EchoBackend(),
        );
        [$next, $cmd] = $this->type($chat, '/new');

        $this->assertNotNull($cmd, '/new falls through and is dispatched as a prompt');
        $this->assertTrue($next->inFlight);
        $this->assertSame('/new', $next->history[count($next->history) - 1]->content);
    }

    /** Read the private generation counter — there is no accessor for it. */
    private function generationOf(Chat $chat): int
    {
        return (new \ReflectionProperty(Chat::class, 'generation'))->getValue($chat);
    }
}
