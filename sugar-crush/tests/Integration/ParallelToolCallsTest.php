<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Context\InstructionFileLoader;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Events\ToolStarted;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Support\ForkedChild;
use SugarCraft\Crush\Support\ToolIpcFiles;
use SugarCraft\Crush\Tools\CarriesSessionState;
use SugarCraft\Crush\Tools\ParallelSafe;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Tools\BuiltIn\Bash;
use SugarCraft\Crush\Tools\BuiltIn\Edit;
use SugarCraft\Crush\Tools\BuiltIn\Grep;
use SugarCraft\Crush\Tools\BuiltIn\Read;
use SugarCraft\Crush\Tools\BuiltIn\WebSearch;

/**
 * {@see Runtime}'s same-turn tool batch, executed concurrently
 * (crush_code.md Phase 0 item 14).
 *
 * The witness for "these really overlapped" is a RENDEZVOUS, not a stopwatch:
 * each call drops a marker file and then watches the shared directory for its
 * siblings' markers, reporting how many it ever saw. Sequential execution
 * cannot fake it — the first call runs when no sibling has started, so it can
 * only ever report 1, and the counts come back `1,2,3`. Genuine concurrency
 * reports `3,3,3`. Every wait is bounded, so a regression to sequential
 * dispatch fails the assertion instead of hanging the suite.
 *
 * Nothing here touches the network, and every forked child is reaped by the
 * production code under test (that is part of what is under test).
 */
final class ParallelToolCallsTest extends TestCase
{
    private string $dir;

    private ProviderInterface $provider;

    private HookRegistry $hookRegistry;

    private HookManager $hookManager;

    /**
     * Generous because the rendezvous exits the instant the last sibling
     * arrives: a concurrent group pays milliseconds and only a genuinely
     * serialized run pays the whole budget. Big enough that a loaded CI box
     * cannot turn a passing overlap into a flake.
     */
    private const RENDEZVOUS_WAIT = 3.0;

    /**
     * The same budget for the runs that are SUPPOSED to serialize — paid in
     * full, once per call that never meets its siblings, so it stays small.
     */
    private const SERIAL_WAIT = 0.25;

    protected function setUp(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('Concurrent tool dispatch requires ext-pcntl.');
        }

        $this->dir = sys_get_temp_dir() . '/sc_parallel_tools_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, true);

        $this->provider = $this->createMock(ProviderInterface::class);
        $this->provider->method('name')->willReturn('test-provider');

        $this->hookRegistry = new HookRegistry();
        $this->hookManager = new HookManager($this->hookRegistry);

        // Arms (and clears) the payload ledger this class's leak detection
        // reads -- see strandedRuntimePayloads(). Per TEST rather than per
        // class because the assertions below are before/after within a single
        // test, and a ledger accumulating across the file would make every
        // later test inherit every earlier test's reservations.
        //
        // Arming here cannot disturb the other armers, and there are TWO, not
        // one -- this comment said "ChatTest, the only other armer" and
        // tests/Support/ToolIpcFilesTest.php arms and disarms it in five of its
        // own cases. The conclusion is unchanged and the reason is structural
        // rather than a headcount: PHPUnit runs one test class at a time in one
        // process, ChatTest arms in setUpBeforeClass() and disarms in
        // tearDownAfterClass(), and ToolIpcFilesTest disarms in a `finally` in
        // every case that arms -- so no other armer's window can be open while
        // this class is running, whatever the order.
        ToolIpcFiles::recordReservations(true);
    }

    protected function tearDown(): void
    {
        ToolIpcFiles::recordReservations(false);

        $this->removeTree($this->dir);

        parent::tearDown();
    }

    // =========================================================================
    // Overlap — the headline claim, and the controls that make it mean something
    // =========================================================================

    /**
     * Driven through the PUBLIC {@see Runtime::run()} on the batch-provider
     * path every real provider-backed session uses, not through the private
     * dispatcher: the claim is about the live path.
     */
    public function testAParallelSafeBatchRunsItsCallsConcurrently(): void
    {
        $calls = $this->rendezvousCalls(['a', 'b', 'c'], peers: 3, wait: self::RENDEZVOUS_WAIT);

        $this->provider->method('supportsStreaming')->willReturn(false);
        $this->provider->method('complete')->willReturn(new CompleteResponse(content: 'go', toolCalls: $calls));

        $app = App::new($this->provider, 'gpt-4')->withTools([$this->rendezvousTool()]);

        $messages = iterator_to_array($this->runtime()->run($app));

        $this->assertInstanceOf(AssistantMessage::class, $messages[0]);
        $this->assertSame(
            ['saw=3', 'saw=3', 'saw=3'],
            $this->contents(array_slice($messages, 1)),
            'every call must have observed all three markers, which is only possible if all three ran at once',
        );
    }

    /**
     * The control that makes the witness above worth anything: the identical
     * batch, against a tool that declines {@see ParallelSafe}, reports
     * `1,2,3` — each call seeing only the markers its predecessors left.
     */
    public function testTheSameBatchSerializesWhenTheToolIsNotParallelSafe(): void
    {
        $results = $this->execute(
            $this->rendezvousCalls(['a', 'b', 'c'], peers: 3, wait: self::SERIAL_WAIT),
            [$this->rendezvousTool(parallelSafe: false)],
        );

        $this->assertSame(['saw=1', 'saw=2', 'saw=3'], $this->contents($results));
    }

    /**
     * And the same again with the dispatcher itself switched off, so the
     * escape hatch is not a comment.
     */
    public function testTheSameBatchSerializesWhenParallelDispatchIsDisabled(): void
    {
        $results = $this->execute(
            $this->rendezvousCalls(['a', 'b', 'c'], peers: 3, wait: self::SERIAL_WAIT),
            [$this->rendezvousTool()],
            $this->runtime(parallelToolCalls: false),
        );

        $this->assertSame(['saw=1', 'saw=2', 'saw=3'], $this->contents($results));
    }

    /**
     * A lone parallel-safe call is NOT forked — forking to run one call
     * alongside nothing is pure cost, and keeping it in-process is what lets a
     * tool with in-process side effects still work on the single-call turns
     * that dominate a real session.
     */
    public function testALoneParallelSafeCallStaysInThisProcess(): void
    {
        $tool = new class implements Tool, ParallelSafe {
            public int $runs = 0;
            public function name(): string { return 'counter'; }
            public function description(): string { return 'counts in-process runs'; }
            public function inputSchema(): array { return ['type' => 'object']; }
            public function isParallelSafe(): bool { return true; }
            public function execute(array $args): ToolResult
            {
                $this->runs++;

                return new ToolResult(toolCallId: '', content: 'run ' . $this->runs);
            }
        };

        $results = $this->execute([new ToolCall('call_solo', 'counter', [])], [$tool]);

        $this->assertSame(['run 1'], $this->contents($results));
        $this->assertSame(1, $tool->runs, 'a lone call must have run in THIS process, where its side effect is visible');
    }

    // =========================================================================
    // Ordering and determinism
    // =========================================================================

    /**
     * Completion order is whatever the scheduler decides; delivery order is
     * not. The model correlates results by id, but a batch replayed in
     * completion order would make the transcript — and every replay of it —
     * nondeterministic for no gain.
     */
    public function testResultsAndFinishEventsFollowProviderOrderNotCompletionOrder(): void
    {
        // Deliberately inverted: 'a' finishes last, 'c' first.
        $calls = [
            $this->rendezvousCall('a', peers: 3, wait: self::RENDEZVOUS_WAIT, sleep: 0.6),
            $this->rendezvousCall('b', peers: 3, wait: self::RENDEZVOUS_WAIT, sleep: 0.3),
            $this->rendezvousCall('c', peers: 3, wait: self::RENDEZVOUS_WAIT, sleep: 0.0),
        ];

        $events = [];
        $results = $this->execute($calls, [$this->rendezvousTool()], onEvent: function ($event) use (&$events): void {
            $events[] = $event;
        });

        $this->assertSame(
            ['c', 'b', 'a'],
            $this->finishLog(),
            'the sleeps must have made the calls COMPLETE in reverse order',
        );
        $this->assertSame(
            ['call_a', 'call_b', 'call_c'],
            array_map(static fn (ToolResultMessage $m): string => $m->toolCallId(), $results),
            'results must still reach the model in the order the provider asked for them',
        );
        $this->assertSame(
            ['call_a', 'call_b', 'call_c'],
            array_map(
                static fn (ToolFinished $e): string => $e->toolCallId,
                array_values(array_filter($events, static fn ($e): bool => $e instanceof ToolFinished)),
            ),
            'ToolFinished — and with it PostToolUse — must fire in provider order, not in whichever order won the race',
        );
    }

    public function testEveryCallInAConcurrentBatchEmitsExactlyOneStartedAndOneFinished(): void
    {
        $events = [];
        $this->execute(
            $this->rendezvousCalls(['a', 'b', 'c'], peers: 3, wait: self::RENDEZVOUS_WAIT),
            [$this->rendezvousTool()],
            onEvent: function ($event) use (&$events): void { $events[] = $event; },
        );

        $started = array_filter($events, static fn ($e): bool => $e instanceof ToolStarted);
        $finished = array_filter($events, static fn ($e): bool => $e instanceof ToolFinished);

        $this->assertCount(3, $started);
        $this->assertCount(3, $finished);
        $this->assertSame(
            ['call_a', 'call_b', 'call_c'],
            array_map(static fn (ToolStarted $e): string => $e->toolCallId, array_values($started)),
        );
        // Every Started precedes every Finished: the group is announced whole,
        // then released whole, so a renderer sees three spinners rather than
        // one at a time.
        $this->assertSame(
            [ToolStarted::class, ToolStarted::class, ToolStarted::class, ToolFinished::class, ToolFinished::class, ToolFinished::class],
            array_map(static fn ($e): string => $e::class, $events),
        );
    }

    /**
     * The concurrency-safety rule, stated as a test: a call whose tool is not
     * parallel-safe is a BARRIER, ordered against both of its neighbours. This
     * is what keeps an `Edit` from racing a `Read` of the same path, and what
     * preserves read-after-write within a turn.
     */
    public function testANonParallelSafeCallIsABarrierBetweenItsNeighbours(): void
    {
        $calls = [
            $this->rendezvousCall('a', peers: 2, wait: self::RENDEZVOUS_WAIT, group: 'left'),
            $this->rendezvousCall('b', peers: 2, wait: self::RENDEZVOUS_WAIT, group: 'left'),
            $this->rendezvousCall('w', peers: 1, wait: self::SERIAL_WAIT, group: 'mid', tool: 'barrier'),
            $this->rendezvousCall('c', peers: 2, wait: self::RENDEZVOUS_WAIT, group: 'right'),
            $this->rendezvousCall('d', peers: 2, wait: self::RENDEZVOUS_WAIT, group: 'right'),
        ];

        $results = $this->execute($calls, [
            $this->rendezvousTool(),
            $this->rendezvousTool(name: 'barrier', parallelSafe: false),
        ]);

        $this->assertSame(
            ['saw=2', 'saw=2', 'saw=1', 'saw=2', 'saw=2'],
            $this->contents($results),
            'each read pair must overlap, and the barrier must run with nothing beside it',
        );

        $log = $this->finishLog();
        $this->assertSame('w', $log[2], 'the barrier must finish after BOTH predecessors and before either successor');
        $this->assertSame(['a', 'b'], $this->sorted(array_slice($log, 0, 2)));
        $this->assertSame(['c', 'd'], $this->sorted(array_slice($log, 3, 2)));
    }

    // =========================================================================
    // Hook gating
    // =========================================================================

    /**
     * A DENY is still a DENY, it still costs only its own call, and the tool
     * behind it never runs — proved by the absence of its marker file, which
     * only an executing child could have created.
     */
    public function testAHookDenyBlocksOnlyItsOwnCallAndItsToolNeverRuns(): void
    {
        $this->hookRegistry->register($this->hook(HookEvent::PreToolUse, static function (HookContext $context): HookResult {
            return ($context->toolArgs['marker'] ?? null) === 'b'
                ? HookResult::deny('b is not allowed')
                : HookResult::allow();
        }));

        $results = $this->execute(
            $this->rendezvousCalls(['a', 'b', 'c'], peers: 2, wait: self::RENDEZVOUS_WAIT),
            [$this->rendezvousTool()],
        );

        $this->assertSame(['saw=2', 'Hook denied: b is not allowed', 'saw=2'], $this->contents($results));
        $this->assertFalse($results[0]->isError());
        $this->assertTrue($results[1]->isError());
        $this->assertFalse($results[2]->isError());
        $this->assertFileDoesNotExist(
            $this->dir . '/markers/b',
            'a denied call must never reach a child, so it can never have dropped a marker',
        );
    }

    /**
     * The property that has to hold once task #11 makes the permission prompt
     * a real, blocking UI: every question is put, and answered, while NOTHING
     * is running. An asking hook therefore cannot be bypassed by a sibling
     * that happened to finish first — there are no siblings yet.
     *
     * The approver counts marker files at the moment it is asked; a nonzero
     * count would mean a child was already executing behind the question.
     */
    public function testEveryPermissionQuestionIsAnsweredBeforeAnyCallStarts(): void
    {
        $this->hookRegistry->register($this->hook(
            HookEvent::PreToolUse,
            static fn (HookContext $context): HookResult => HookResult::ask('approve ' . ($context->toolArgs['marker'] ?? '?')),
        ));

        $asked = [];
        $runningWhenAsked = [];
        $approver = function (ToolCall $call, HookResult $ask) use (&$asked, &$runningWhenAsked): bool {
            $asked[] = $call->arguments()['marker'];
            $runningWhenAsked[] = count(glob($this->dir . '/markers/*') ?: []);

            return $call->arguments()['marker'] !== 'b';
        };

        $results = $this->execute(
            $this->rendezvousCalls(['a', 'b', 'c'], peers: 2, wait: self::RENDEZVOUS_WAIT),
            [$this->rendezvousTool()],
            onPermissionRequest: $approver,
        );

        $this->assertSame(['a', 'b', 'c'], $asked, 'questions must be put in provider order');
        $this->assertSame([0, 0, 0], $runningWhenAsked, 'no call may be executing while a question is outstanding');

        $this->assertSame('saw=2', $results[0]->content());
        $this->assertTrue($results[1]->isError(), 'a rejected ASK must not run its tool');
        $this->assertSame('saw=2', $results[2]->content());
        $this->assertFileDoesNotExist($this->dir . '/markers/b');
    }

    /**
     * No approver attached: an unanswered ASK is not permission, and fails
     * closed for every member of the group rather than one of them slipping
     * through on a race.
     */
    public function testAnUnansweredAskFailsClosedForTheWholeGroup(): void
    {
        $this->hookRegistry->register($this->hook(
            HookEvent::PreToolUse,
            static fn (HookContext $context): HookResult => HookResult::ask('who decides?'),
        ));

        $results = $this->execute(
            $this->rendezvousCalls(['a', 'b'], peers: 2, wait: self::SERIAL_WAIT),
            [$this->rendezvousTool()],
        );

        foreach ($results as $result) {
            $this->assertTrue($result->isError());
            $this->assertStringContainsString('Permission required', $result->content());
        }
        $this->assertSame([], glob($this->dir . '/markers/*') ?: []);
    }

    /**
     * PostToolUse still observes every executed call's real output, and still
     * does NOT run for the one the pre-hook refused.
     */
    public function testPostToolUseObservesEveryExecutedCallInProviderOrder(): void
    {
        $observed = [];
        $this->hookRegistry->register($this->hook(HookEvent::PreToolUse, static function (HookContext $context): HookResult {
            return ($context->toolArgs['marker'] ?? null) === 'b'
                ? HookResult::deny('nope')
                : HookResult::allow();
        }));
        $this->hookRegistry->register($this->hook(HookEvent::PostToolUse, static function (HookContext $context) use (&$observed): HookResult {
            $observed[] = $context->toolArgs['marker'] . ':' . $context->toolOutput;

            return HookResult::allow();
        }));

        $this->execute(
            $this->rendezvousCalls(['a', 'b', 'c'], peers: 2, wait: self::RENDEZVOUS_WAIT),
            [$this->rendezvousTool()],
        );

        $this->assertSame(['a:saw=2', 'c:saw=2'], $observed);
    }

    /**
     * The concurrent twin of
     * {@see \SugarCraft\Crush\Tests\RuntimeTest::testPostToolUseObservesTheArgumentsThatActuallyRan}.
     *
     * {@see Runtime::gate()} hands back the rewritten HookContext as its third
     * element, and the concurrent path has to CARRY it into the job it queues —
     * per call, with no cross-contamination between siblings. Dropping that
     * element (`[$args, $denial] = $this->gate(...)`) left each job holding the
     * context built from the model's PROPOSAL, so `AuditHook` recorded three
     * commands none of which ran, on precisely the calls whose record anybody
     * would want. It passed the whole suite: the serial path had a test and this
     * one did not.
     *
     * Distinct rewrites per call are the point — one shared rewrite would pass
     * even if every job were handed the same context.
     */
    public function testPostToolUseObservesEachConcurrentCallsOwnRewrittenArguments(): void
    {
        $observed = [];
        $this->hookRegistry->register($this->hook(HookEvent::PreToolUse, static function (HookContext $context): HookResult {
            $args = $context->toolArgs;
            $marker = (string) ($args['marker'] ?? '');

            // The re-scan hands this hook its own rewrite back; re-proposing a
            // second suffix forever would exhaust the rewrite budget instead of
            // settling (see HookRegistry::MAX_REWRITE_PASSES).
            if (str_ends_with($marker, '-rw')) {
                return HookResult::allow();
            }

            $args['marker'] = $marker . '-rw';

            return HookResult::modify((string) json_encode($args));
        }));
        $this->hookRegistry->register($this->hook(HookEvent::PostToolUse, static function (HookContext $context) use (&$observed): HookResult {
            $observed[] = $context->toolArgs['marker'];

            return HookResult::allow();
        }));

        // peers: 3 rather than 2, and that is a correctness fix rather than a
        // stronger claim for its own sake. All three of these calls run and
        // share one marker directory, so with peers: 2 the rendezvous returns
        // on its FIRST look -- and that look reports however many markers
        // happen to exist at that instant, which is 3 whenever the third
        // sibling gets there first. `saw=2` was therefore a coin flip:
        // measured on this host (PHP 8.3.6), 3 failures in 150 unloaded runs
        // of this test alone, every one of them `saw=3`. Requiring all three
        // peers makes the count exact, because 3 is also the ceiling.
        $results = $this->execute(
            $this->rendezvousCalls(['a', 'b', 'c'], peers: 3, wait: self::RENDEZVOUS_WAIT),
            [$this->rendezvousTool()],
        );

        // Positive control: the rewrite really reached the forked children, so a
        // failure below can only be the context that was threaded alongside it.
        $ran = array_map('basename', glob($this->dir . '/markers/*') ?: []);
        sort($ran);
        $this->assertSame(['a-rw', 'b-rw', 'c-rw'], $ran);
        $this->assertSame('saw=3', $results[0]->content());

        $this->assertSame(['a-rw', 'b-rw', 'c-rw'], $observed, 'PostToolUse must see each call OWN rewrite, in provider order');
    }

    // =========================================================================
    // Failure modes: throw, hang
    // =========================================================================

    /**
     * The guarantee step 1 of this effort established, held across the fork
     * boundary: a throwing tool degrades to an error result for its own call
     * and nothing else.
     */
    public function testAThrowingToolInAConcurrentGroupCostsOnlyItsOwnCall(): void
    {
        $calls = [
            $this->rendezvousCall('a', peers: 2, wait: self::RENDEZVOUS_WAIT),
            $this->rendezvousCall('b', peers: 2, wait: self::RENDEZVOUS_WAIT, throw: true),
            $this->rendezvousCall('c', peers: 2, wait: self::RENDEZVOUS_WAIT),
        ];

        $results = $this->execute($calls, [$this->rendezvousTool()]);

        $this->assertFalse($results[0]->isError());
        $this->assertTrue($results[1]->isError());
        $this->assertStringContainsString(
            'Error: rendezvous failed with RuntimeException: b exploded',
            $results[1]->content(),
        );
        $this->assertFalse($results[2]->isError());
        // a and c never met b, so they fall back on each other.
        $this->assertSame('saw=2', $results[0]->content());
        $this->assertSame('saw=2', $results[2]->content());
    }

    /**
     * A tool that never returns is SIGKILLed at the group deadline and
     * reported as one failed call. Its siblings' results survive intact —
     * which is the whole reason the deadline lives here rather than being left
     * to EngineBackend's turn-level idle timer, which would take the turn.
     */
    public function testAHangingToolIsKilledAtTheDeadlineAndReportedAsAnError(): void
    {
        $calls = [
            $this->rendezvousCall('a', peers: 1, wait: 0.0),
            $this->rendezvousCall('b', peers: 1, wait: 0.0, hang: true),
            $this->rendezvousCall('c', peers: 1, wait: 0.0),
        ];

        $started = microtime(true);
        $results = $this->execute($calls, [$this->rendezvousTool()], $this->runtime(deadlineSeconds: 1));
        $elapsed = microtime(true) - $started;

        // The survivors report a count rather than an exact one: they ran
        // beside each other, so either may have seen the other's marker.
        $this->assertFalse($results[0]->isError());
        $this->assertStringStartsWith('saw=', $results[0]->content());
        $this->assertTrue($results[1]->isError());
        $this->assertStringContainsString('killed at the 1s parallel-tool deadline', $results[1]->content());
        $this->assertFalse($results[2]->isError());
        $this->assertStringStartsWith('saw=', $results[2]->content());
        $this->assertLessThan(20.0, $elapsed, 'the deadline must bound the group, not the hung tool');
    }

    // =========================================================================
    // What has to survive the fork
    // =========================================================================

    /**
     * Binary payloads round-trip: a tool result carrying raw image bytes is
     * exactly why the child's payload is `serialize()`d rather than
     * JSON-encoded (`json_encode()` rejects non-UTF-8 and would drop it).
     */
    public function testImageBearingResultsSurviveTheForkBoundary(): void
    {
        $bytes = "\x89PNG\r\n\x1a\n" . random_bytes(64);

        $tool = new class ($bytes) implements Tool, ParallelSafe {
            public function __construct(private string $bytes) {}
            public function name(): string { return 'shot'; }
            public function description(): string { return 'returns bytes'; }
            public function inputSchema(): array { return ['type' => 'object']; }
            public function isParallelSafe(): bool { return true; }
            public function execute(array $args): ToolResult
            {
                return new ToolResult(
                    toolCallId: '',
                    content: 'captured',
                    imageBytes: $this->bytes,
                    imageProtocol: 'kitty',
                    diff: '--- a/x\n+++ b/x',
                );
            }
        };

        $results = $this->execute(
            [new ToolCall('call_i1', 'shot', []), new ToolCall('call_i2', 'shot', [])],
            [$tool],
        );

        foreach ($results as $result) {
            $this->assertSame('captured', $result->content());
            $this->assertSame($bytes, $result->imageBytes());
            $this->assertSame('kitty', $result->imageProtocol());
        }
    }

    /**
     * Session-scoped state a tool accumulates inside a child would otherwise
     * die with that child's copy-on-write memory. The
     * {@see CarriesSessionState} seam carries it back, and the merge is a
     * union so two concurrent children cannot clobber each other.
     */
    public function testSessionStateMutatedInsideAChildIsMergedBackIntoTheParent(): void
    {
        $tool = new class implements Tool, ParallelSafe, CarriesSessionState {
            /** @var list<string> */
            public array $seen = [];
            public function name(): string { return 'stateful'; }
            public function description(): string { return 'remembers what it touched'; }
            public function inputSchema(): array { return ['type' => 'object']; }
            public function isParallelSafe(): bool { return true; }
            public function execute(array $args): ToolResult
            {
                $this->seen[] = (string) $args['marker'];

                return new ToolResult(toolCallId: '', content: 'ok');
            }
            public function exportSessionState(): array { return ['seen' => $this->seen]; }
            public function mergeSessionState(array $state): void
            {
                $this->seen = array_values(array_unique([...$this->seen, ...($state['seen'] ?? [])]));
            }
        };

        $this->execute(
            [new ToolCall('call_s1', 'stateful', ['marker' => 'x']), new ToolCall('call_s2', 'stateful', ['marker' => 'y'])],
            [$tool],
        );

        $this->assertSame(['x', 'y'], $this->sorted($tool->seen), 'both children must have reported their marks home');
    }

    /**
     * A merge that THROWS costs that one call's mark and nothing else.
     *
     * {@see CarriesSessionState} promises unknown or malformed keys are never
     * fatal, but nothing enforces that caller-side, and this call sits inside
     * the reaping generator: an escaping \Throwable abandons the group at this
     * call and then lands in EngineBackend's turn-level boundary, which
     * discards every sibling result and all assistant content produced so far.
     * The worst a failed merge may cost is one announce-once mark.
     *
     * WHAT THIS SAID about the children after it: "never reaped, payloads never
     * unlinked". WHAT IS TRUE NOW: {@see Runtime::executeConcurrently()}'s
     * `finally` covers a throw unwinding out of the generator exactly as it
     * covers a consumer walking away (PHP 8.3.6, verified) -- one WNOHANG pass,
     * then a discard of every settled-but-uncollected payload -- so an
     * already-exited sibling IS reaped and unlinked. A still-RUNNING one is
     * not, by design; it is left to {@see ToolIpcFiles::sweep()}. WHY THE POINT
     * STANDS: the cost of the throw was never the temp files, it is the
     * discarded turn, and that is undiminished.
     */
    public function testAThrowingSessionStateMergeCostsOnlyThatCallsMark(): void
    {
        $before = $this->childPids();

        // Each child exports exactly its own marker, so only 'b' makes the
        // parent's merge throw.
        $exporting = new class implements Tool, ParallelSafe, CarriesSessionState {
            public function name(): string { return 'brittle'; }
            public function description(): string { return 'its merge explodes on one mark'; }
            public function inputSchema(): array { return ['type' => 'object']; }
            public function isParallelSafe(): bool { return true; }
            /** @var list<string> */
            public array $seen = [];
            public function execute(array $args): ToolResult
            {
                $this->seen = [(string) $args['marker']];

                return new ToolResult(toolCallId: '', content: 'ok ' . $args['marker']);
            }
            public function exportSessionState(): array { return ['seen' => $this->seen]; }
            public function mergeSessionState(array $state): void
            {
                if (in_array('b', $state['seen'] ?? [], true)) {
                    throw new \LogicException('merge exploded');
                }
                $this->seen = array_values(array_unique([...$this->seen, ...($state['seen'] ?? [])]));
            }
        };

        $results = $this->execute([
            new ToolCall('call_m1', 'brittle', ['marker' => 'a']),
            new ToolCall('call_m2', 'brittle', ['marker' => 'b']),
            new ToolCall('call_m3', 'brittle', ['marker' => 'c']),
        ], [$exporting]);

        $this->assertCount(3, $results, 'the group must be reaped to the end despite the throw');
        $this->assertSame('ok a', $results[0]->content());
        $this->assertSame('ok c', $results[2]->content());

        // The failed call keeps its real output and is NOT flipped to an
        // error: the tool ran and succeeded, only the bookkeeping fell over.
        $this->assertStringStartsWith('ok b', $results[1]->content());
        $this->assertStringContainsString(
            '[Session-state merge failed: LogicException: merge exploded]',
            $results[1]->content(),
        );
        $this->assertFalse($results[1]->isError());

        $this->assertSame(
            ['a', 'c'],
            $this->sorted(array_values(array_diff($exporting->seen, ['b']))),
            'the surviving merges must still have landed',
        );

        $this->assertSame($before, $this->childPids(), 'every child must still have been reaped');
        $this->assertSame(3, $this->reservedRuntimePayloadCount(), 'all three calls must have gone through the fork path');
        $this->assertSame([], $this->strandedRuntimePayloads(), 'every payload must still have been unlinked');
    }

    // =========================================================================
    // Who is allowed into a group
    // =========================================================================

    /**
     * The opt-in list, asserted rather than described: the non-mutating
     * built-ins say yes, and the ones that touch the workspace or the shell
     * are not even candidates — {@see ParallelSafe} is not implemented, so
     * {@see Runtime::segments()} treats them as barriers.
     */
    public function testOnlyTheNonMutatingBuiltInsOptIntoConcurrency(): void
    {
        $this->assertInstanceOf(ParallelSafe::class, new WebSearch());
        $this->assertTrue((new WebSearch())->isParallelSafe());

        $this->assertNotInstanceOf(ParallelSafe::class, new Bash());
        $this->assertNotInstanceOf(ParallelSafe::class, new Edit($this->dir));
    }

    /**
     * {@see WebSearch} is the one built-in that is not `final` (it fetches
     * with no injected client, so subclassing is the only stub seam its tests
     * have). Its parallel-safety promise is therefore about THIS class, not
     * about whatever a subclass does in `execute()`.
     *
     * A plain `return true` would have been fail-OPEN — a subclass, including
     * every PHPUnit mock, inheriting a promise it never made, which inverts
     * {@see ParallelSafe}'s own rule that saying nothing means barrier. A
     * subclass that IS safe re-states it by overriding, exactly as every other
     * tool declares by implementing the interface at all.
     */
    public function testWebSearchesParallelSafetyPromiseDoesNotExtendToSubclasses(): void
    {
        $sideEffecting = new class extends WebSearch {
            public function execute(array $args): ToolResult
            {
                return new ToolResult(toolCallId: '', content: 'stubbed, and not necessarily safe');
            }
        };

        $this->assertFalse($sideEffecting->isParallelSafe());
        $this->assertFalse($this->createMock(WebSearch::class)->isParallelSafe());

        $declaresItself = new class extends WebSearch {
            public function isParallelSafe(): bool { return true; }
        };

        $this->assertTrue($declaresItself->isParallelSafe(), 'a subclass that says so is still allowed in');
    }

    // =========================================================================
    // The IPC files the payloads cross on
    // =========================================================================

    /**
     * The payload a child hands back is the whole tool result — file bodies,
     * grep hits, fetched pages — sitting in a world-listable `/tmp`. It must
     * never be readable by another local user, and an unguessable filename is
     * not a mode.
     *
     * Observed from INSIDE the group, which is the only place it is visible:
     * the parent unlinks each payload the moment it collects it. The prober is
     * call #1 and results are released in provider order, so the quick call's
     * payload is guaranteed to still be on disk while the prober looks. The
     * umask is deliberately wide open — a regression to a plain
     * `file_put_contents()` would show 0666 here, not the machine's default.
     *
     * WHERE THE ATTRIBUTION HAPPENS, and why it moved TWICE.
     *
     * WHAT THIS TEST DID, FIRST: the probe snapshotted
     * `glob('/tmp/sc_runtime_tool_*')` in its constructor and returned the mode
     * of the first path that was not in the snapshot. That is a before/after
     * diff over a directory shared with every process on the box; a concurrent
     * `sugar-crush` run, or a sibling test lane, drops an `sc_runtime_tool_*`
     * file into the window and this test reports a FOREIGN file's mode as
     * though it were ours. Not hypothetical: round 44's baseline run of this
     * suite failed for exactly that reason.
     *
     * WHAT IT DID NEXT: the child kept globbing and reported EVERY candidate it
     * had seen, and the parent -- which holds
     * {@see ToolIpcFiles::reservations()} -- intersected that report with its
     * own names, so a foreign file could be sighted but not read for an answer.
     * That closed the false RED. What it did not close was the flake: the scan
     * still had to decide when to stop looking at a directory it did not own,
     * and it did that with a settle window (a fixed 0.25s after the first
     * sighting). A box loaded badly enough that the sibling's fork-and-write
     * ran past that window after a foreign file was sighted first left the
     * probe reporting zero of our payloads, and the test failed. Right
     * direction, still a coin flip.
     *
     * WHAT IS TRUE NOW: the child does not look at the directory at all.
     * {@see Runtime::executeConcurrently()} reserves every payload name in
     * phase 1, before it forks anything, so each child inherits the WHOLE
     * group's ledger rather than the prefix that happened to exist at its own
     * fork. The probe reads {@see ToolIpcFiles::reservations()} and polls
     * exactly those paths. A foreign file can no longer even be sighted, and
     * the termination condition is an exact count rather than a window ("every
     * reserved path except my own, which is not written until after execute()
     * returns").
     *
     * WHAT IS LEFT OF THE RACE, stated because "the settle window is gone" is
     * easy to read as "the timing is gone" and it is not: the probe still
     * carries a 3.0s deadline, so a sibling that takes longer than that to
     * fork and write still produces a red. What changed is which direction the
     * timing can hurt in. The window could be ENDED EARLY by a foreign file --
     * one sighting started a 0.25s clock the sibling then had to beat -- so the
     * failure mode was a coin flip on an unrelated process's timing. The
     * deadline can only be exceeded, by our own sibling, with 12x the slack.
     * A weakened race, not an eliminated one.
     *
     * WHY THIS STILL EARNS ITS PLACE rather than being folded into the leak
     * detector: the leak detector asks whether a payload survived; this asks
     * what a payload's MODE was while it was alive, which is only answerable
     * from inside the group and only by another child.
     */
    public function testAChildsPayloadIsNeverReadableByAnotherUser(): void
    {
        $tool = new class () implements Tool, ParallelSafe {
            public function name(): string { return 'modeprobe'; }
            public function description(): string { return 'reports the mode of every payload its group reserved'; }
            public function inputSchema(): array { return ['type' => 'object']; }
            public function isParallelSafe(): bool { return true; }

            public function execute(array $args): ToolResult
            {
                if (($args['role'] ?? '') !== 'probe') {
                    return new ToolResult(toolCallId: '', content: 'quick');
                }

                // IDENTITY, IN THE CHILD. Inherited across the fork from a
                // ledger the parent finished filling before it forked
                // anything, so this is the whole group's set of names and
                // nothing else's -- see this method's doc-block.
                $reserved = ToolIpcFiles::reservations();

                // Every reserved path except this child's own, which the
                // dispatcher writes only after execute() has returned.
                $expected = count($reserved) - 1;

                $deadline = microtime(true) + 3.0;

                /** @var array<string, string> $seen path => four-digit octal mode */
                $seen = [];

                while (count($seen) < $expected && microtime(true) < $deadline) {
                    foreach ($reserved as $path) {
                        if (isset($seen[$path])) {
                            continue;
                        }

                        clearstatcache(true, $path);
                        $perms = @fileperms($path);
                        if ($perms !== false) {
                            $seen[$path] = substr(sprintf('%o', $perms), -4);
                        }
                    }

                    if (count($seen) >= $expected) {
                        break;
                    }

                    usleep(1_000);
                }

                return new ToolResult(
                    toolCallId: '',
                    content: (string) json_encode(['reserved' => $reserved, 'modes' => $seen]),
                );
            }
        };

        $previous = umask(0o000);

        try {
            $results = $this->execute([
                new ToolCall('call_p1', 'modeprobe', ['role' => 'probe']),
                new ToolCall('call_p2', 'modeprobe', ['role' => 'quick']),
            ], [$tool]);
        } finally {
            umask($previous);
        }

        $this->assertSame('quick', $results[1]->content());

        $reserved = ToolIpcFiles::reservations();
        $this->assertCount(
            2,
            $reserved,
            'both calls must have gone through the fork path, or there is no sibling payload to have a mode',
        );

        $observed = json_decode($results[0]->content(), true);
        $this->assertIsArray($observed, 'the probe did not report a payload map: ' . $results[0]->content());

        // THE PHASE-1 RESERVATION ITSELF. The child saw the same ledger the
        // parent holds, which is only true if every name in the group was
        // chosen before the first fork. Move the reservation back next to the
        // fork that uses it and the probe (job 0) inherits one path -- its own
        // -- and this goes red before any of the mode assertions are reached.
        // An empty list here means setUp()'s recordReservations() never ran.
        $this->assertSame(
            $reserved,
            $observed['reserved'] ?? null,
            'the probe child must inherit the WHOLE group\'s reservation ledger, not the prefix of it '
                . 'that existed when it was forked',
        );

        $this->assertSame(
            [$reserved[1]],
            array_keys($observed['modes'] ?? []),
            'the payload the probe caught is not the SIBLING\'s: phase 1 reserves names in provider '
                . 'order, so the probe (job 0) holds its own name first and the sibling\'s second, and '
                . 'its own is not on disk until after execute() returns',
        );

        $this->assertSame(['0600'], array_values($observed['modes']));
    }

    /**
     * And a group that ran cleanly leaves nothing behind — the normal-path
     * unlink that {@see \SugarCraft\Crush\Support\ToolIpcFiles::sweep()} is
     * only the backstop for.
     */
    public function testACompletedGroupLeavesNoPayloadFilesBehind(): void
    {
        $this->execute(
            $this->rendezvousCalls(['a', 'b', 'c'], peers: 3, wait: self::RENDEZVOUS_WAIT),
            [$this->rendezvousTool()],
        );

        $reserved = $this->reservedRuntimePayloads();
        $this->assertCount(3, $reserved, 'the detector must have had three payloads to be wrong about');

        // KNOWN-POSITIVE FIXTURE, same scanner, same test, and NOT the same
        // thing as the count above it. The count is a control over the LEDGER;
        // it says three names were reserved, which stays true however broken
        // the scanner is. Measured: with strandedReservations() mutated to
        // `return []`, the three cases around this one go red and this one
        // stayed GREEN -- 3 of 4, and this was the 4th. So plant a file on one
        // of this group's own reserved paths and make the scanner prove it can
        // still report one before believing that it reports none.
        file_put_contents($reserved[0], 'x');
        $this->assertSame(
            [$reserved[0]],
            $this->strandedRuntimePayloads(),
            'the leak scanner is dead: it did not report a file planted on one of this group\'s own '
                . 'reserved paths, so the empty list below would mean nothing',
        );
        unlink($reserved[0]);

        $this->assertSame([], $this->strandedRuntimePayloads());
    }

    /**
     * ...and so does a group the CONSUMER walks away from part-way through.
     *
     * The collect-side `discard()` only runs for a job that is actually
     * released, and releases stop the moment nobody pulls the next value.
     * `executeConcurrently()` is a Generator, so PHP runs its `finally` then
     * (verified on PHP 8.3.6 rather than assumed). Without one, every payload
     * past the release cursor sits in a world-listable temp directory until
     * {@see ToolIpcFiles::sweep()}'s one-hour backstop, which is a reaper of
     * last resort and not a lifecycle.
     *
     * WHICH CONSUMER ACTUALLY DOES THIS.
     * WHAT THIS SAID: that "nobody pulls the next value" is "not an exotic
     * state", and named a consumer that `break`s as the first example.
     * WHAT IS TRUE NOW: source-checked rather than assumed. The only
     * production consumer of {@see Runtime::run()} is the `foreach` in
     * {@see \SugarCraft\Crush\Backend\EngineBackend}'s agentic loop, and it
     * DRAINS -- there is no `break` in it. The abandonment this test drives is
     * therefore a test-and-future-caller shape, not a live one. What IS live is
     * the other half of the same sentence, an exception unwinding out of the
     * generator (an `onEvent` listener, a hook, a merge that escaped its
     * guard), and that has its own case now:
     * {@see testAThrowUnwindingOutOfTheGroupAlsoDiscardsTheUncollectedPayloads()}.
     * WHY THIS STILL EARNS ITS PLACE: both shapes end in the same `finally`,
     * and destruction is the one that can be driven with no exception in
     * flight -- so it isolates the cleanup from the unwind. Keeping the pair is
     * what makes it possible to say which of them a future regression broke.
     *
     * THE POSITIVE CONTROL IS IN THE TEST, not in a sibling case. An empty
     * stranded list proves nothing on its own -- the round-44 lesson is that a
     * dead scanner reports exactly the same empty list as a clean tree. So this
     * asserts the leak IS detectable at the moment of abandonment (the same
     * {@see ToolIpcFiles::strandedReservations()} call, on the same paths, in
     * the same test), and only then that destroying the generator clears it.
     *
     * Job 0 is the slow one so the shape is deterministic rather than raced:
     * by the time its result is released, its two siblings exited long enough
     * ago that phase 3's WNOHANG pass has already reaped them, so their
     * payloads are on disk and uncollected — which is precisely the population
     * the `finally` exists for.
     */
    public function testAbandoningTheGroupMidReleaseDiscardsTheUncollectedPayloads(): void
    {
        $app = App::new($this->provider, 'gpt-4')->withTools([$this->rendezvousTool()]);
        $runtime = $this->runtime();

        $method = new \ReflectionMethod($runtime, 'executeToolCalls');
        $generator = $method->invoke($runtime, [
            // A GROUP DIRECTORY EACH, so `saw=` is 1 for every call and not
            // whichever of 1..3 markers happened to exist at the first glob:
            // with peers=1 the rendezvous returns on its first look, so a
            // shared directory makes the reported count a race. Measured: the
            // shared-directory version reported saw=3 on a loaded box.
            $this->rendezvousCall('slow', peers: 1, wait: 0.0, sleep: 0.5, group: 'abandon0'),
            $this->rendezvousCall('fast1', peers: 1, wait: 0.0, group: 'abandon1'),
            $this->rendezvousCall('fast2', peers: 1, wait: 0.0, group: 'abandon2'),
        ], $app, null, null);

        // Drives phase 1 + 2 and suspends at the first release.
        $this->assertSame('saw=1', $generator->current()->content());

        $this->assertSame(
            3,
            $this->reservedRuntimePayloadCount(),
            'the detector must have had three payloads to be wrong about',
        );

        // POSITIVE CONTROL: the two siblings' payloads are on disk right now,
        // uncollected, and this scanner sees them. If this is empty the
        // scanner is dead and the assertion below means nothing.
        $abandoned = $this->strandedRuntimePayloads();
        $this->assertNotSame(
            [],
            $abandoned,
            'the sibling payloads should still be uncollected while the group is suspended at its first '
                . 'release -- an empty list here means the leak detector, not the leak, is missing',
        );

        unset($generator);

        $this->assertSame(
            [],
            $this->strandedRuntimePayloads(),
            'destroying the generator must run executeConcurrently()\'s finally and discard every payload '
                . 'past the release cursor; these were stranded a moment ago: ' . implode(', ', $abandoned),
        );
    }

    /**
     * THE DECISION THE `finally` DELIBERATELY DOES NOT TAKE: it never kills.
     *
     * That is the loudest comment in {@see Runtime::executeConcurrently()}'s
     * cleanup and it had no test, which under this repo's own rule (dormant
     * behaviour gets pinned, not just described) is the same as not having
     * decided it. A `posix_kill()` added to that loop by a future reader
     * "while we're here" would make every case above it greener, not redder:
     * killing a child DOES stop its payload leaking.
     *
     * What it also does is truncate a side effect half-way, which is the whole
     * argument, so that is what this asserts -- the child's OWN completion
     * record, written after its work, not its process state at an instant.
     *
     * NO SLEEP AND NO WINDOW. The surviving child is held at the rendezvous
     * (`peers: 2` in a group directory only this test can complete) rather than
     * paused for a guessed duration, so "still running at the moment of
     * abandonment" is a fact the test arranges, not a race it hopes to win.
     * Releasing it afterwards is one file_put_contents().
     */
    public function testTheAbandonmentCleanupNeverKillsAStillRunningChild(): void
    {
        $before = $this->childPids();

        $app = App::new($this->provider, 'gpt-4')->withTools([$this->rendezvousTool()]);
        $runtime = $this->runtime();

        $method = new \ReflectionMethod($runtime, 'executeToolCalls');
        $generator = $method->invoke($runtime, [
            $this->rendezvousCall('quick', peers: 1, wait: 0.0, group: 'nokill0'),
            // Blocks until this test drops the second marker into its group
            // directory. The wait is a ceiling on a wedged box, not a timing
            // assumption: nothing else can ever satisfy `peers: 2` here.
            $this->rendezvousCall('survivor', peers: 2, wait: 10.0, group: 'nokill1'),
        ], $app, null, null);

        $this->assertSame('saw=1', $generator->current()->content());
        $this->assertSame(
            ['quick'],
            $this->finishLog(),
            'the survivor must still be at the rendezvous when the group is abandoned',
        );

        $after = $this->childPids();
        $survivorPid = ($before === null || $after === null)
            ? null
            : array_values(array_diff($after, $before));

        unset($generator);

        $this->assertSame(
            ['quick'],
            $this->finishLog(),
            'the cleanup must not have waited for the survivor either -- a blocking reap here would '
                . 'hold the whole turn hostage to the slowest abandoned child',
        );

        // Release it, and let it prove it was never killed by finishing.
        $release = $this->dir . '/nokill1/release';
        file_put_contents($release, '1');

        $deadline = microtime(true) + self::RENDEZVOUS_WAIT;
        while (!in_array('survivor', $this->finishLog(), true) && microtime(true) < $deadline) {
            usleep(2_000);
        }

        $this->assertContains(
            'survivor',
            $this->finishLog(),
            'the abandoned child was killed mid-flight: its side effect never completed. The cleanup '
                . 'is documented as deliberately NOT killing -- a timeout is a verdict on the call, an '
                . 'abandoned generator is a verdict on the consumer',
        );

        if ($survivorPid !== null) {
            $this->assertCount(1, $survivorPid, 'exactly one child should have outlived the group');
            pcntl_waitpid((int) $survivorPid[0], $status);
        }

        // ...and the other half of the same decision: its payload is left
        // where sweep() will find it, because the parent has no idea whether
        // the child had finished writing. This doubles as the known-positive
        // control that the scanner below is alive.
        $deadline = microtime(true) + self::RENDEZVOUS_WAIT;
        while ($this->strandedRuntimePayloads() === [] && microtime(true) < $deadline) {
            usleep(2_000);
        }

        $left = $this->strandedRuntimePayloads();
        $this->assertCount(
            1,
            $left,
            'the survivor\'s payload should have been left behind for ToolIpcFiles::sweep()',
        );

        // Exact path, never a glob: sibling lanes own the other
        // sc_runtime_tool_* files in this directory.
        ToolIpcFiles::discard($left[0]);
        $this->assertSame([], $this->strandedRuntimePayloads());
    }
    /**
     * ...and so does an EXCEPTION unwinding out of the group, which is a
     * different code path from the one above and the one production actually
     * takes.
     *
     * Destroying an abandoned generator is a refcount event; a throw is a
     * resume that unwinds. Both end in the same `finally`, but only one of them
     * was pinned, and the justification in
     * {@see Runtime::collectChildResult()} -- which is about a merge that
     * throws INSIDE the generator, not about a consumer that walks away --
     * rests on this one. `Generator::throw()` injects at the suspended yield,
     * which is exactly where a throwing `release()` would leave the frame.
     *
     * The positive control is the same shape as its neighbour's and for the
     * same reason: an empty stranded list is worth nothing unless something in
     * this test has just shown the scanner reporting a real one.
     */
    public function testAThrowUnwindingOutOfTheGroupAlsoDiscardsTheUncollectedPayloads(): void
    {
        $app = App::new($this->provider, 'gpt-4')->withTools([$this->rendezvousTool()]);
        $runtime = $this->runtime();

        $method = new \ReflectionMethod($runtime, 'executeToolCalls');
        $generator = $method->invoke($runtime, [
            // A group directory each, for the reason the neighbouring test
            // spells out: with peers=1 a shared directory makes `saw=` a race.
            $this->rendezvousCall('slow', peers: 1, wait: 0.0, sleep: 0.5, group: 'unwind0'),
            $this->rendezvousCall('fast1', peers: 1, wait: 0.0, group: 'unwind1'),
            $this->rendezvousCall('fast2', peers: 1, wait: 0.0, group: 'unwind2'),
        ], $app, null, null);

        $this->assertSame('saw=1', $generator->current()->content());
        $this->assertSame(
            3,
            $this->reservedRuntimePayloadCount(),
            'the detector must have had three payloads to be wrong about',
        );

        $abandoned = $this->strandedRuntimePayloads();
        $this->assertNotSame(
            [],
            $abandoned,
            'POSITIVE CONTROL: the two siblings\' payloads are uncollected right now and this scanner '
                . 'must be able to see them -- an empty list here means the detector is dead',
        );

        $caught = null;
        try {
            $generator->throw(new \RuntimeException('the release step blew up'));
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(
            \RuntimeException::class,
            $caught,
            'the finally must not swallow what unwound through it',
        );
        $this->assertSame('the release step blew up', $caught->getMessage());

        // Still referenced, so nothing here is destruction doing the work.
        $this->assertSame(
            [],
            $this->strandedRuntimePayloads(),
            'an exception unwinding through executeConcurrently() must discard every payload past the '
                . 'release cursor; these were stranded a moment ago: ' . implode(', ', $abandoned),
        );
    }
    /**
     * WHAT HAPPENS WHEN `fork()` ITSELF FAILS -- exercised, not described.
     *
     * WHAT THE CODE SAID: that {@see Runtime::executeConcurrently()}'s
     * fork-failure branch is "NOT EXERCISED BY THE SUITE", because reaching it
     * "needs a real fork(2) failure, i.e. RLIMIT_NPROC exhausted, which no test
     * here can arrange without setting a process-wide rlimit that would then
     * apply to every other test in the same PHPUnit process".
     *
     * WHAT IS TRUE NOW: an rlimit is per-PROCESS, and this suite already forks.
     * A child that caps its OWN `RLIMIT_NPROC` gets `pcntl_fork() === -1`
     * (EAGAIN) for the rest of its short life while the parent goes on forking
     * normally, and the cap dies with the child. Measured on PHP 8.3.6, this
     * box: `setrlimit=true fork=-1` in the child, parent still forking. So the
     * degraded path was arrangeable all along, and this drives a whole
     * three-call group down it -- every fork fails, every call runs in this
     * process instead, and the provider-ordered results are still exactly the
     * results.
     *
     * WHAT IT STILL DOES NOT COVER, said plainly because a green test is easy
     * to over-read: the two BOOKKEEPING statements on that branch --
     * `ToolIpcFiles::discard($file)` and blanking the job's `file` -- have no
     * observable effect, here or anywhere. Nothing was ever written at the
     * reserved name, so the discard is two no-op `@unlink`s; and
     * `Runtime::release()` reads `$job['result'] ?? collectChildResult($job)`,
     * so a blanked `file` is never looked at. Deleting both lines leaves this
     * file green (measured). The reason they cannot be mutation-killed is
     * UNOBSERVABILITY, not unreachability -- which is a different claim, and
     * the one the branch comment now makes.
     *
     * The child catches everything and leaves via
     * {@see ForkedChild::exitNow()} for the reason
     * {@see MultiAgentRefactorTest::runCoderChild()} spells out: this class's
     * tearDown() deletes a directory the parent is still using.
     */
    public function testAGroupWhoseForksAllFailStillReturnsEveryResultAndStrandsNothing(): void
    {
        if (!function_exists('posix_setrlimit') || !defined('POSIX_RLIMIT_NPROC')) {
            $this->markTestSkipped('Arranging a fork(2) failure needs posix_setrlimit() + RLIMIT_NPROC.');
        }

        $tool = new class () implements Tool, ParallelSafe {
            public function name(): string { return 'plain'; }
            public function description(): string { return 'returns its marker'; }
            public function inputSchema(): array { return ['type' => 'object']; }
            public function isParallelSafe(): bool { return true; }
            public function execute(array $args): ToolResult
            {
                return new ToolResult(toolCallId: '', content: 'ok ' . $args['marker'] . ' pid=' . getmypid());
            }
        };

        $report = $this->dir . '/forkfail.json';

        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'the harness fork itself must succeed');

        if ($pid === 0) {
            try {
                // THIS PROCESS ONLY. Per-process, gone when this child is, and
                // the parent keeps forking throughout -- which is the whole
                // reason the branch is reachable from inside a suite.
                $armed = posix_setrlimit(POSIX_RLIMIT_NPROC, 1, 1);

                // POSITIVE CONTROL for the arrangement itself: without this, a
                // silently-ineffective setrlimit would make the group below
                // fork normally and this test would pass while covering
                // nothing at all.
                $probe = @pcntl_fork();
                if ($probe === 0) {
                    ForkedChild::exitNow(0);
                }

                $childPid = getmypid();
                $results = $this->execute([
                    new ToolCall('call_f1', 'plain', ['marker' => 'a']),
                    new ToolCall('call_f2', 'plain', ['marker' => 'b']),
                    new ToolCall('call_f3', 'plain', ['marker' => 'c']),
                ], [$tool]);

                $reserved = $this->reservedRuntimePayloads();

                // KNOWN-POSITIVE FIXTURE, same scanner, same test. An empty
                // stranded list is evidence only if the scanner could still
                // see a file: plant one on a path this group reserved, confirm
                // it is reported, take it away again.
                $planted = $reserved[0] ?? '';
                @file_put_contents($planted, 'x');
                $withPlant = ToolIpcFiles::strandedReservations();
                @unlink($planted);

                @file_put_contents($report, (string) json_encode([
                    'armed' => $armed,
                    'probe' => $probe,
                    'contents' => array_map(static fn ($m): string => $m->content(), $results),
                    'childPid' => $childPid,
                    'reserved' => count($reserved),
                    'withPlant' => $withPlant,
                    'stranded' => ToolIpcFiles::strandedReservations(),
                ]));
            } catch (\Throwable $e) {
                @file_put_contents($report, (string) json_encode(['threw' => $e::class . ': ' . $e->getMessage()]));
            }

            ForkedChild::exitNow(0);
        }

        $status = 0;
        pcntl_waitpid($pid, $status);

        $this->assertFileExists($report, 'the child reported nothing at all');
        $observed = json_decode((string) file_get_contents($report), true);
        $this->assertIsArray($observed);
        $this->assertArrayNotHasKey('threw', $observed, (string) ($observed['threw'] ?? ''));

        $this->assertTrue($observed['armed'], 'posix_setrlimit() refused the cap');
        $this->assertSame(-1, $observed['probe'], 'the cap did not actually stop this child forking');

        // Every call still produced its result, in provider order, from the
        // dispatching process itself.
        $this->assertSame(
            ['ok a pid=' . $observed['childPid'], 'ok b pid=' . $observed['childPid'], 'ok c pid=' . $observed['childPid']],
            $observed['contents'],
            'a group whose forks all failed must still answer every call, in process',
        );

        $this->assertSame(3, $observed['reserved'], 'phase 1 reserves a name per call whether or not the fork lands');
        $this->assertCount(
            1,
            $observed['withPlant'],
            'the leak scanner is dead: it did not report a file planted on one of this group\'s own reserved paths',
        );
        $this->assertSame([], $observed['stranded'], 'a failed fork writes no payload, so nothing may be left behind');
    }
    /**
     * The same thing with the REAL production wiring: two concurrent `Read`s
     * under a directory carrying a nested CLAUDE.md.
     *
     * Both children emit that file — neither can see the other's decision, and
     * that within-group duplicate is the accepted cost of the concurrency. The
     * mark still has to reach the parent, or every LATER read of that
     * directory would emit it again for the rest of the session.
     */
    public function testRealReadToolsMergeTheirAnnounceOnceMarksBackAcrossTheFork(): void
    {
        $repo = $this->dir . '/repo';
        mkdir($repo . '/sub', 0o777, true);
        file_put_contents($repo . '/sub/CLAUDE.md', 'nested project instructions');
        file_put_contents($repo . '/sub/a.txt', 'alpha');
        file_put_contents($repo . '/sub/b.txt', 'bravo');

        $loader = new InstructionFileLoader($repo);
        $read = new Read($repo, instructionLoader: $loader);

        $results = $this->execute([
            new ToolCall('call_r1', 'Read', ['file_path' => $repo . '/sub/a.txt', 'description' => 'read a']),
            new ToolCall('call_r2', 'Read', ['file_path' => $repo . '/sub/b.txt', 'description' => 'read b']),
        ], [$read]);

        $this->assertStringContainsString('nested project instructions', $results[0]->content());
        $this->assertStringContainsString('nested project instructions', $results[1]->content());

        $this->assertContains(
            realpath($repo . '/sub/CLAUDE.md'),
            $loader->emittedPaths(),
            'the emit-once mark set inside a child must reach the parent, or every later read re-emits it',
        );

        // And the mark is honoured from here on: a third read of the same
        // directory, run in this process, no longer carries the document.
        $third = $read->execute(['file_path' => $repo . '/sub/a.txt', 'description' => 'read a again']);
        $this->assertStringNotContainsString('nested project instructions', $third->content());
    }

    /**
     * The same claim for `Grep`, which took the announce-once pair in P8.9 and
     * therefore took the fork problem with it.
     *
     * `Grep`'s own unit tests simulate the fork boundary in-process by calling
     * {@see \SugarCraft\Crush\Tools\BuiltIn\Grep::exportSessionState()} and
     * {@see \SugarCraft\Crush\Tools\BuiltIn\Grep::mergeSessionState()} by hand.
     * That pins the pair's SHAPE but not that {@see Runtime} actually asks —
     * a Grep that forgot to implement {@see CarriesSessionState} would still
     * pass those, because the methods would still exist and still work. This
     * one runs the real `pcntl_fork()` path.
     *
     * Two searches, two different governed directories, so a merge that
     * carried only ONE child's marks back would be visible rather than
     * indistinguishable from a merge that carried both.
     */
    public function testRealGrepToolsMergeTheirAnnounceOnceMarksBackAcrossTheFork(): void
    {
        $repo = $this->dir . '/greprepo';
        foreach (['alpha', 'bravo'] as $name) {
            mkdir($repo . '/' . $name, 0o777, true);
            file_put_contents($repo . '/' . $name . '/CLAUDE.md', 'RULE-' . strtoupper($name));
            file_put_contents($repo . '/' . $name . '/hit.txt', "needle\n");
        }

        $loader = new InstructionFileLoader($repo);
        $grep = new Grep($repo, instructionLoader: $loader);

        $results = $this->execute([
            new ToolCall('call_g1', 'Grep', [
                'pattern' => 'needle',
                'path' => $repo . '/alpha',
                'description' => 'search alpha',
            ]),
            new ToolCall('call_g2', 'Grep', [
                'pattern' => 'needle',
                'path' => $repo . '/bravo',
                'description' => 'search bravo',
            ]),
        ], [$grep]);

        $this->assertStringContainsString('RULE-ALPHA', $results[0]->content());
        $this->assertStringContainsString('RULE-BRAVO', $results[1]->content());

        $emitted = $loader->emittedPaths();
        foreach (['alpha', 'bravo'] as $name) {
            $this->assertContains(
                realpath($repo . '/' . $name . '/CLAUDE.md'),
                $emitted,
                "the mark {$name}'s child set must reach the parent, or every later search re-emits it",
            );
        }

        // And it is honoured from here on: a third search of one of those
        // directories, run in THIS process, no longer carries the document.
        $third = $grep->execute([
            'pattern' => 'needle',
            'path' => $repo . '/alpha',
            'description' => 'search alpha again',
        ]);
        $this->assertStringContainsString('/alpha/hit.txt:1:', $third->content(), 'the hit itself must still be there');
        $this->assertStringNotContainsString('RULE-ALPHA', $third->content());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function runtime(bool $parallelToolCalls = true, int $deadlineSeconds = 30): Runtime
    {
        return new Runtime(
            $this->provider,
            $this->hookManager,
            null,
            $parallelToolCalls,
            $deadlineSeconds,
        );
    }

    /**
     * @param list<ToolCall> $calls
     * @param list<Tool>     $tools
     * @return list<ToolResultMessage>
     */
    private function execute(
        array $calls,
        array $tools,
        ?Runtime $runtime = null,
        ?callable $onEvent = null,
        ?callable $onPermissionRequest = null,
    ): array {
        $app = App::new($this->provider, 'gpt-4')->withTools($tools);

        $runtime ??= $this->runtime();
        $method = new \ReflectionMethod($runtime, 'executeToolCalls');
        $method->setAccessible(true);

        return array_values(iterator_to_array(
            $method->invoke($runtime, $calls, $app, $onEvent, $onPermissionRequest),
        ));
    }

    /**
     * @param list<string> $markers
     * @return list<ToolCall>
     */
    private function rendezvousCalls(array $markers, int $peers, float $wait): array
    {
        return array_map(fn (string $marker): ToolCall => $this->rendezvousCall($marker, $peers, $wait), $markers);
    }

    private function rendezvousCall(
        string $marker,
        int $peers,
        float $wait,
        float $sleep = 0.0,
        string $group = 'markers',
        string $tool = 'rendezvous',
        bool $throw = false,
        bool $hang = false,
    ): ToolCall {
        return new ToolCall('call_' . $marker, $tool, [
            'marker' => $marker,
            'peers' => $peers,
            'wait' => $wait,
            'sleep' => $sleep,
            'group' => $group,
            'throw' => $throw,
            'hang' => $hang,
        ]);
    }

    /**
     * The interleaving witness. Drops `<dir>/<group>/<marker>`, then watches
     * that directory until it holds `peers` entries or the bounded wait runs
     * out, and reports the highest count it ever saw. Appends its marker to a
     * shared finish log on the way out, which is how completion ORDER is
     * observed separately from delivery order.
     */
    private function rendezvousTool(string $name = 'rendezvous', bool $parallelSafe = true): Tool
    {
        return new class ($name, $parallelSafe, $this->dir) implements Tool, ParallelSafe {
            public function __construct(
                private string $toolName,
                private bool $parallelSafe,
                private string $root,
            ) {}

            public function name(): string { return $this->toolName; }

            public function description(): string { return 'rendezvous witness'; }

            public function inputSchema(): array { return ['type' => 'object']; }

            public function isParallelSafe(): bool { return $this->parallelSafe; }

            public function execute(array $args): ToolResult
            {
                $marker = (string) $args['marker'];

                if ($args['throw'] ?? false) {
                    throw new \RuntimeException($marker . ' exploded');
                }

                if ($args['hang'] ?? false) {
                    // Killed at the group deadline; the sleep is only ever a
                    // ceiling on how long a broken deadline could wedge this.
                    sleep(20);
                }

                $dir = $this->root . '/' . $args['group'];
                if (!is_dir($dir)) {
                    @mkdir($dir, 0o777, true);
                }
                file_put_contents($dir . '/' . $marker, '1');

                $peers = (int) $args['peers'];
                $deadline = microtime(true) + (float) $args['wait'];
                $seen = 0;
                do {
                    $seen = max($seen, count(glob($dir . '/*') ?: []));
                    if ($seen >= $peers) {
                        break;
                    }
                    usleep(1_000);
                } while (microtime(true) < $deadline);

                $sleep = (float) ($args['sleep'] ?? 0.0);
                if ($sleep > 0.0) {
                    usleep((int) ($sleep * 1_000_000));
                }

                // O_APPEND on a short line is atomic, so concurrent children
                // cannot interleave mid-record.
                file_put_contents($this->root . '/finish.log', $marker . "\n", FILE_APPEND);

                return new ToolResult(toolCallId: '', content: 'saw=' . $seen);
            }
        };
    }

    /**
     * Completion order, as recorded by the children themselves.
     *
     * @return list<string>
     */
    private function finishLog(): array
    {
        $raw = is_file($this->dir . '/finish.log') ? (string) file_get_contents($this->dir . '/finish.log') : '';

        return array_values(array_filter(explode("\n", $raw), static fn (string $line): bool => $line !== ''));
    }

    /**
     * @param list<ToolResultMessage> $results
     * @return list<string>
     */
    private function contents(array $results): array
    {
        return array_map(static fn (ToolResultMessage $m): string => $m->content(), array_values($results));
    }

    /**
     * Of the payload paths THIS PROCESS reserved during this test, the ones
     * still on disk. Empty is the pass.
     *
     * ATTRIBUTION IS BY IDENTITY, NOT BY WINDOW, and that is the whole content
     * of this helper. WHAT IT USED TO DO: `glob(sys_get_temp_dir() .
     * '/sc_runtime_tool_*')`, snapshotted before the group and compared after.
     * WHAT IS TRUE ABOUT THAT: it does not measure "did this group leak", it
     * measures "did the set of payload files in a directory shared with every
     * other process on the box change while this test ran" — and a sibling
     * test lane, or the developer's own `sugar-crush` session, changes it. WHY
     * THE ASSERTION STILL EARNS ITS PLACE: the leak it looks for is real —
     * {@see \SugarCraft\Crush\Runtime::executeConcurrently()}'s collect-side
     * `discard()` is the only unlink on the normal path, and losing it strands
     * a serialized ToolResult in a world-listable directory until
     * {@see ToolIpcFiles::sweep()}'s one-hour backstop — so the fix is to
     * narrow the WINDOW to an identity, not to loosen the assertion.
     *
     * This is E96, and it is the same defect E63 fixed one dispatcher over:
     * {@see \SugarCraft\Crush\Tests\ChatTest::tearDownAfterClass()} carries
     * the argument in full, and {@see ToolIpcFiles::strandedReservations()}
     * carries why the parent that CHOSE the name is the only place identity
     * exists at all. It was not hypothetical here either: round 44's baseline
     * run of this suite failed both call sites of this helper, with foreign
     * `sc_runtime_tool_*` files from concurrent lanes on both sides of the
     * snapshot.
     *
     * @return list<string>
     */
    private function strandedRuntimePayloads(): array
    {
        return $this->sorted(ToolIpcFiles::strandedReservations());
    }

    /**
     * How many payload paths this test reserved — the control that keeps
     * {@see strandedRuntimePayloads()} honest.
     *
     * An empty stranded list means "nothing leaked" ONLY if something was
     * reserved. A group that never forked reserves nothing and strands
     * nothing, and reads as a clean pass; see {@see ToolIpcFiles::reservations()}
     * for why the detector needs both halves.
     */
    private function reservedRuntimePayloadCount(): int
    {
        return \count($this->reservedRuntimePayloads());
    }

    /**
     * The payload paths {@see Runtime::executeConcurrently()} reserved in this
     * process, as paths rather than a headcount — what a test needs to plant a
     * known-positive fixture on one of its OWN names instead of inventing a
     * path the scanner is not looking at.
     *
     * @return list<string>
     */
    private function reservedRuntimePayloads(): array
    {
        return array_values(array_filter(
            ToolIpcFiles::reservations(),
            static fn (string $path): bool
                => str_contains(basename($path), ToolIpcFiles::RUNTIME_PREFIX),
        ));
    }

    /**
     * This process's live AND zombie children, straight from procfs — the one
     * reading that catches a child nobody reaped without stealing an exit
     * status from a concurrently-running test that owns children of its own
     * (which a blind `pcntl_waitpid(-1, ...)` would).
     *
     * Returns null where procfs is not available (macOS), which the callers
     * treat as "nothing to compare".
     *
     * @return ?list<string>
     */
    private function childPids(): ?array
    {
        $path = '/proc/self/task/' . getmypid() . '/children';
        if (!is_file($path)) {
            return null;
        }

        return $this->sorted(preg_split('/\s+/', trim((string) @file_get_contents($path)), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return array_values($values);
    }

    private function hook(HookEvent $event, \Closure $decide): HookInterface
    {
        return new class ($event, $decide) implements HookInterface {
            public function __construct(private HookEvent $hookEvent, private \Closure $decide) {}
            public function name(): string { return 'test_hook_' . $this->hookEvent->value; }
            public function event(): HookEvent { return $this->hookEvent; }
            public function matcher(): string { return '.*'; }
            public function execute(HookContext $context): HookResult { return ($this->decide)($context); }
        };
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
