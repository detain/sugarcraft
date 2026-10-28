<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Backend;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\CacheReusingSummaryBackend;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Backend\SummarisesWithCache;
use SugarCraft\Code\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Code\Host\CompactionService;
use SugarCraft\Code\Message;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Providers\ContextOverflow;
use SugarCraft\Code\Tests\Support\ScriptedProvider;
use SugarCraft\Code\ToolResult;

/**
 * The two one-shot retries the summary request earns before it folds
 * heuristically (C-CTX-5.a): a compaction request that overflows the window
 * itself is retried once with older tool outputs clipped, and a configured
 * `summaryModel` that fails for a non-transient reason is retried once on the
 * session's own model. Everything else rethrows unchanged — the caller's
 * heuristic fold stays the single failure path — and every retry is capped at
 * one extra billed call. No timeout is added anywhere (E646).
 */
final class CacheReusingSummaryBackendTest extends TestCase
{
    private string $sandbox = '';
    private string|false $originalHome = false;

    protected function setUp(): void
    {
        // The notice-cap pin arms the process-global sink; both ends reset so
        // this file can never leak it armed (the idiom of the Diagnostics
        // suite's own setUp/tearDown pair).
        RuntimeNoticeSink::reset();
        $this->sandbox = sys_get_temp_dir() . '/crush-summretry-' . bin2hex(random_bytes(6));
        @mkdir($this->sandbox . '/repo', 0700, true);
        $this->originalHome = getenv('HOME');
        putenv('HOME=' . $this->sandbox);
    }

    protected function tearDown(): void
    {
        RuntimeNoticeSink::reset();
        if ($this->originalHome !== false) {
            putenv('HOME=' . $this->originalHome);
        }
        if (is_dir($this->sandbox)) {
            exec('rm -rf ' . escapeshellarg($this->sandbox));
        }
    }

    public function testAnOverflowRejectionIsRetriedOnceWithClippedOlderToolOutputs(): void
    {
        $seen = new \ArrayObject();
        $engine = new RejectingEngine($seen, self::overflowError(), thenResolve: 'the clipped summary');
        $pair = CacheReusingSummaryBackend::new(new ToollessStub(), $engine);

        $history = self::fatToolHistory(rows: 12);
        $message = $this->settle($pair->summariseAsync($history, 'summarise the session'));

        self::assertSame('the clipped summary', $message->content);
        self::assertSame(2, $seen['calls'], 'the overflow rides exactly one retry');

        $second = $seen['histories'][1];
        self::assertSame('summarise the session', $seen['instructions'][1]);
        // The oldest fat tool row comes back clipped on the retry…
        self::assertNotSame($history[1], $second[1]);
        self::assertStringContainsString('older tool output clipped', $second[1]->content);
        self::assertLessThan(mb_strlen($history[1]->content), mb_strlen($second[1]->content));
        // …the newest rows ride verbatim…
        foreach ([10, 11] as $index) {
            self::assertSame($history[$index], $second[$index]);
        }
        // …and the clipped row keeps every field the original carried.
        self::assertSame('row-0', $second[1]->id);
        self::assertSame($history[1]->toolResults, $second[1]->toolResults);
    }

    public function testTheOverflowRetryIsCappedAtOneExtraCall(): void
    {
        $seen = new \ArrayObject();
        $error = self::overflowError();
        $engine = new RejectingEngine($seen, $error, thenResolve: null);
        $pair = CacheReusingSummaryBackend::new(new ToollessStub(), $engine);

        $failure = $this->settleFailure($pair->summariseAsync(self::fatToolHistory(rows: 12), 'summarise'));

        self::assertSame(2, $seen['calls'], 'the handler sees only the FIRST rejection');
        self::assertSame($error, $failure, 'the retry failure reaches the fold unchanged');
    }

    /**
     * C-CTX-5.a notice-cap leg (r1 MINOR, VERIFIED BY PIN). The ask was that
     * a folded summary failure must not push a turn past
     * {@see RuntimeNoticeSink::TURN_NOTICE_LIMIT}. Reading the fold path, it
     * CANNOT over-emit, and both halves of that claim are pinned here.
     * STRUCTURALLY: no code from the pair's `summariseAsync` entry downward
     * names the notice sink at all — a failed summary leaves the backend as
     * exactly ONE Throwable (identity pinned by the cap test above) that the
     * caller turns into one HistoryCompactedMsg; it is never a batch of
     * notice rows. BEHAVIOURALLY: with TURN_NOTICE_LIMIT + 5 refusals pending
     * in a budgeted turn, a fold that fails mid-turn changes nothing the
     * drain sees — the kept refusals plus exactly one capped aggregate row.
     * Any future wiring of the fold through the sink breaks the structure
     * census, the row shape, or both.
     */
    public function testTheFoldCannotOverrunTheTurnNoticeCap(): void
    {
        // Comments stripped from the entry point down: the prose legitimately
        // NAMES the failure path; the code must not CALL a sink.
        $source = (string) file_get_contents(
            (new \ReflectionClass(CacheReusingSummaryBackend::class))->getFileName(),
        );
        $entry = strpos($source, 'public function summariseAsync');
        self::assertIsInt($entry, 'the summariseAsync entry point moved or renamed; re-derive this pin');
        $body = (string) preg_replace(
            ['~/\\*.*?\\*/~s', '~[ \\t]//.*~'],
            '',
            substr($source, $entry),
        );
        foreach (['Sink', 'record(', 'warn('] as $forbidden) {
            self::assertSame(
                0,
                substr_count($body, $forbidden),
                'the fold path grew a notice-emission call (' . $forbidden . '); '
                    . 'over-emission is no longer structurally impossible — re-derive this pin',
            );
        }

        RuntimeNoticeSink::arm(false);
        RuntimeNoticeSink::beginTurn();
        $pending = RuntimeNoticeSink::TURN_NOTICE_LIMIT + 5;
        for ($i = 0; $i < $pending; $i++) {
            $accepted = RuntimeNoticeSink::record('overflow refusal ' . $i);
            self::assertSame(
                $i < RuntimeNoticeSink::NOTICE_LIMIT,
                $accepted,
                'refusal ' . $i . ' took the queue by the wrong end of the cap',
            );
        }

        $seen = new \ArrayObject();
        $error = self::overflowError();
        $engine = new RejectingEngine($seen, $error, thenResolve: null);
        $pair = CacheReusingSummaryBackend::new(new ToollessStub(), $engine);

        $failure = $this->settleFailure($pair->summariseAsync(self::fatToolHistory(rows: 12), 'summarise'));

        self::assertSame($error, $failure, 'the fold is ONE rejection, not a row per refusal');
        self::assertSame(2, $seen['calls'], 'the two billed calls still entered nothing into the sink');

        $rows = RuntimeNoticeSink::drain();
        $kept = RuntimeNoticeSink::NOTICE_LIMIT;
        self::assertLessThanOrEqual(
            RuntimeNoticeSink::TURN_NOTICE_LIMIT + 1,
            count($rows),
            'the turn surfaced more than its budget plus the single aggregate row',
        );
        self::assertCount($kept + 1, $rows, 'the fold added a row the refusals alone do not account for');
        for ($i = 0; $i < $kept; $i++) {
            self::assertSame('overflow refusal ' . $i, $rows[$i], 'row ' . $i . ' shifted; the fold inserted something');
        }
        self::assertSame(
            RuntimeNoticeSink::overflowNotice($pending - $kept),
            $rows[$kept],
            'the over-cap refusals did not collapse into exactly one capped aggregate row',
        );
        self::assertFalse(RuntimeNoticeSink::hasPending(), 'the capped drain deferred rows instead of discarding them');
    }

    public function testNothingIsRetriedOnceTheTurnIsCancelled(): void
    {
        $seen = new \ArrayObject();
        $error = self::overflowError();
        $engine = new RejectingEngine($seen, $error, thenResolve: 'should never run');
        $pair = CacheReusingSummaryBackend::new(new ToollessStub(), $engine);
        $token = new CancellationToken();
        $token->cancel();

        $failure = $this->settleFailure($pair->summariseAsync(self::fatToolHistory(rows: 12), 'summarise', $token));

        self::assertSame(1, $seen['calls'], 'a dead turn dispatches nothing');
        self::assertSame($error, $failure);
    }

    public function testATransientFailureIsNotRetriedByEitherLeg(): void
    {
        // Scope: the double is not an EngineBackend, so the instanceof leg
        // blocks the retry here whatever the transient clause does — this pins
        // the fold contract for non-engine hosts only. The transient clause
        // against a real engine is pinned in
        // testATransientFailureOnAnOverriddenSummaryModelDoesNotFallBack.
        $seen = new \ArrayObject();
        $error = self::transientError();
        $engine = new RejectingEngine($seen, $error, thenResolve: 'should never run');
        $pair = CacheReusingSummaryBackend::new(new ToollessStub(), $engine);

        $failure = $this->settleFailure($pair->summariseAsync(self::fatToolHistory(rows: 12), 'summarise'));

        self::assertSame(1, $seen['calls'], "the engine's own retry policy owns transient failures");
        self::assertSame($error, $failure);
    }

    public function testAFailureWithoutAnOverriddenSummaryModelIsNotRetried(): void
    {
        $seen = new \ArrayObject();
        // A plain failure against an engine that is NOT an EngineBackend: no
        // override to drop, so the fold path is the answer, not a retry.
        // Where instanceof passes and only the missing override can stop the
        // retry, the real-engine twin is
        // testAFailureWithoutAnOverriddenSummaryModelCostsExactlyOneProviderCall.
        $error = new \RuntimeException('a plain summary refusal');
        $engine = new RejectingEngine($seen, $error, thenResolve: 'x');
        $pair = CacheReusingSummaryBackend::new(new ToollessStub(), $engine);

        $failure = $this->settleFailure($pair->summariseAsync([Message::user('q')], 'summarise'));

        self::assertSame(1, $seen['calls']);
        self::assertSame($error, $failure);
    }

    public function testANonTransientFailureOfAnOverriddenSummaryModelFallsBackToTheSessionModel(): void
    {
        // The provider answers every request made with the session model and
        // refuses the unknown override, so the ONE successful reply can only
        // arrive through the fallback leg. Deterministic on both shapes: the
        // no-fork path rejects the original throwable and the fork path
        // flattens the same message to text — wording classifies either way.
        $provider = new ScriptedProvider([
            static function (CompleteRequest $request): CompleteResponse {
                if ($request->model === 'small') {
                    throw new \RuntimeException('The model small does not exist');
                }

                return new CompleteResponse('answered on ' . $request->model);
            },
        ]);
        $engine = EngineBackend::new($provider, 'm')->withoutHooks()->withRoot($this->sandbox . '/repo');
        $pair = CacheReusingSummaryBackend::new(new ToollessStub(), $engine->withSummaryModel('small'));

        $message = $this->settle($pair->summariseAsync([Message::user('q')], 'summarise'));

        self::assertSame('answered on m', $message->content, 'the retry drops the override and asks the session model');
    }

    public function testATransientFailureOnAnOverriddenSummaryModelDoesNotFallBack(): void
    {
        // The transient clause, pinned against a REAL EngineBackend carrying
        // an override so the instanceof leg cannot stand in for it. The typed
        // 429 is fed to the retry decision directly: through summariseAsync()
        // a forked host flattens it to plain text (the class docblock's honest
        // scope note documents that accepted trade), and only the decision
        // itself is shape-independent. Unmutated the guard rethrows the
        // original error before dispatching anything; neuter
        // `!TransientFailure::isTransient($error)` and it returns the fallback
        // promise instead of throwing, reddening the assertNull below.
        $provider = new ScriptedProvider([
            static fn (CompleteRequest $request): CompleteResponse => new CompleteResponse('answered on ' . $request->model),
        ]);
        $engine = EngineBackend::new($provider, 'm')->withoutHooks()->withRoot($this->sandbox . '/repo');
        $pair = CacheReusingSummaryBackend::new(new ToollessStub(), $engine->withSummaryModel('small'));
        $error = self::transientError();
        $retry = new \ReflectionMethod(CacheReusingSummaryBackend::class, 'retrySummaryOnce');

        $dispatched = null;
        $thrown = null;
        try {
            $dispatched = $retry->invoke($pair, $error, [Message::user('q')], 'summarise', null);
        } catch (\RuntimeException $caught) {
            // Capture only: an assertion inside this catch could be swallowed.
            $thrown = $caught;
        }

        self::assertNull($dispatched, 'a transient failure never reaches the fallback dispatch');
        self::assertSame($error, $thrown, 'the original rejection rides on to the heuristic fold');
        self::assertSame([], $provider->requests, 'no provider call was spent on a transient failure');
    }

    public function testAFailureWithoutAnOverriddenSummaryModelCostsExactlyOneProviderCall(): void
    {
        // The summaryModel clause, pinned against a REAL EngineBackend with no
        // override so the instanceof leg cannot stand in for it. The marker
        // file is the provider-call ground truth across BOTH engine shapes:
        // a fork child's in-memory ScriptedProvider is invisible to the
        // parent, only writes to disk survive. Deleting
        // `$this->engine->summaryModel() !== null` from the guard sends a
        // second dispatch to the session model that was never overridden, and
        // the marker gains a line in either shape.
        $marker = $this->sandbox . '/summary-calls.log';
        $provider = new ScriptedProvider([
            static function (CompleteRequest $request) use ($marker): CompleteResponse {
                file_put_contents($marker, $request->model . "\n", FILE_APPEND | LOCK_EX);

                throw new \RuntimeException('a plain summary refusal without an override');
            },
        ]);
        $engine = EngineBackend::new($provider, 'm')->withoutHooks()->withRoot($this->sandbox . '/repo');
        $pair = CacheReusingSummaryBackend::new(new ToollessStub(), $engine);

        $failure = $this->settleFailure($pair->summariseAsync([Message::user('q')], 'summarise'));

        self::assertStringContainsString('plain summary refusal', $failure->getMessage());
        self::assertSame(
            ['m'],
            array_map('trim', (array) \file($marker, FILE_IGNORE_NEW_LINES)),
            'without an override to drop, the fold path costs exactly one provider call',
        );
    }

    public function testAnOverflowTheClipCannotRelieveIsNotRetried(): void
    {
        // A history inside the clip's keep window comes back byte-identical
        // (pinned in testClippingKeepsTheNewestRowsAndOnlyCutsOldToolOutputs),
        // so the overflow leg would re-send the refused request verbatim. The
        // guard folds on the first answer instead of spending the one billed
        // retry on a guaranteed second refusal; drop the
        // `$clipped === $history` check and the pair dispatches a second,
        // identical call.
        $seen = new \ArrayObject();
        $error = self::overflowError();
        $engine = new RejectingEngine($seen, $error, thenResolve: 'should never run');
        $pair = CacheReusingSummaryBackend::new(new ToollessStub(), $engine);

        $failure = $this->settleFailure($pair->summariseAsync(self::fatToolHistory(rows: 4), 'summarise'));

        self::assertSame(1, $seen['calls'], 'an overflow the clip cannot relieve is not re-sent');
        self::assertSame($error, $failure);
    }

    public function testClippingKeepsTheNewestRowsAndOnlyCutsOldToolOutputs(): void
    {
        $history = self::fatToolHistory(rows: 12);
        $clipped = CompactionService::clipOlderToolOutputs($history);

        self::assertCount(12, $clipped);
        // Old tool rows beyond the keep window are clipped…
        self::assertNotSame($history[1], $clipped[1]);
        self::assertStringContainsString('older tool output clipped', $clipped[1]->content);
        self::assertStringStartsWith('b', $clipped[1]->content, 'the head survives');
        self::assertStringEndsWith('b', $clipped[1]->content, 'the tail survives');
        // …inside the keep window they ride verbatim…
        self::assertSame($history[11], $clipped[11]);
        // …rows without tool results are never candidates.
        self::assertSame($history[0], $clipped[0], 'a plain prompt row is not a tool output');
        self::assertSame($history[2], $clipped[2], 'a plain prompt row is not a tool output');

        // A short old tool output already fits head plus tail: same object.
        $short = [
            Message::user('tiny')->withToolResults([new ToolResult('Read', 'contents', arguments: [])]),
            Message::user('pad two'),
            Message::user('pad three'),
            Message::user('pad four'),
            Message::user('pad five'),
            Message::user('pad six'),
            Message::user('pad seven'),
            Message::user('pad eight'),
            Message::user('pad nine'),
            Message::user('pad ten'),
        ];
        self::assertSame($short, CompactionService::clipOlderToolOutputs($short));

        // A history no longer than the keep window is returned unchanged.
        $small = self::fatToolHistory(rows: 4);
        self::assertSame($small, CompactionService::clipOlderToolOutputs($small));
    }

    private static function overflowError(): \RuntimeException
    {
        return new \RuntimeException(ContextOverflow::MESSAGE_PREFIX . 'maximum context length exceeded, no more window');
    }

    private static function transientError(): \RuntimeException
    {
        return new class ('try later') extends \RuntimeException {
            public function getStatusCode(): int
            {
                return 429;
            }
        };
    }

    /**
     * Exactly $rows messages: plain prompts on even indexes, tool rows on odd
     * ones. Every tool row before the last four entries carries a
     * 40k-character output; the newest ones are short, so the clip has exactly
     * the shape the rescue describes.
     *
     * @return list<Message>
     */
    private static function fatToolHistory(int $rows): array
    {
        $history = [];
        for ($index = 0; $index < $rows; $index++) {
            if ($index % 2 === 0) {
                $history[] = Message::user('question ' . $index);

                continue;
            }
            $body = $index < $rows - 4
                ? str_repeat((string) chr(97 + ($index % 26)), 40000)
                : 'short output ' . $index;
            $history[] = Message::user($body)
                ->withToolResults([new ToolResult('Read', 'contents', arguments: ['file_path' => 'src/Router.php'])])
                ->withIdentity('row-' . intdiv($index, 2), null);
        }

        return $history;
    }

    private function settle(PromiseInterface $promise): Message
    {
        $value = $this->drizzle($promise);
        if ($value instanceof \Throwable) {
            $this->fail('the promise failed: ' . $value->getMessage());
        }

        self::assertInstanceOf(Message::class, $value);

        return $value;
    }

    private function settleFailure(PromiseInterface $promise): \Throwable
    {
        $value = $this->drizzle($promise);
        self::assertInstanceOf(\Throwable::class, $value, 'the test expects a rejection');

        return $value;
    }

    /**
     * @return mixed the settled value or the rejection throwable
     */
    private function drizzle(PromiseInterface $promise): mixed
    {
        $loop = Loop::get();
        $settled = false;
        $value = null;
        $promise->then(
            static function ($v) use (&$settled, &$value, $loop): void {
                $settled = true;
                $value = $v;
                $loop->stop();
            },
            static function (\Throwable $e) use (&$settled, &$value, $loop): void {
                $settled = true;
                $value = $e;
                $loop->stop();
            },
        );
        if (!$settled) {
            $watchdog = $loop->addTimer(60.0, static function () use ($loop, &$settled): void {
                $settled = true;
                $loop->stop();
            });
            $loop->run();
            $loop->cancelTimer($watchdog);
            if (!$settled) {
                $this->fail('the promise never settled');
            }
        }

        return $value;
    }
}

/**
 * The pair's tool-less half: never asked in these tests except that the
 * class requires one, so it fails loudly if anything routes through it.
 */
final class ToollessStub implements Backend
{
    public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
    {
        throw new \LogicException('the summary retry must never touch the tool-less half');
    }

    public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
    {
        throw new \LogicException('the summary retry must never touch the tool-less half');
    }
}

/**
 * A counting engine: rejects every call with its configured error unless a
 * later call may resolve to `thenResolve`, recording each history and
 * instruction it saw.
 *
 * @extends \ArrayObject<string,mixed>
 */
final class RejectingEngine implements SummarisesWithCache
{
    /**
     * @param \ArrayObject<string,mixed> $seen
     */
    public function __construct(
        private readonly \ArrayObject $seen,
        private readonly \Throwable $failure,
        private readonly ?string $thenResolve,
    ) {
        $this->seen['calls'] = 0;
        $this->seen['histories'] = new \ArrayObject();
        $this->seen['instructions'] = new \ArrayObject();
    }

    public function summariseAsync(array $history, string $instruction, ?CancellationToken $cancellation = null): PromiseInterface
    {
        $calls = ++$this->seen['calls'];
        $this->seen['histories'][] = $history;
        $this->seen['instructions'][] = $instruction;
        $reply = $calls >= 2 ? $this->thenResolve : null;
        if ($reply !== null) {
            return \React\Promise\resolve(Message::assistant($reply));
        }

        return \React\Promise\reject($this->failure);
    }
}
