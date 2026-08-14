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
use SugarCraft\Crush\Tools\CarriesSessionState;
use SugarCraft\Crush\Tools\ParallelSafe;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Tools\BuiltIn\Bash;
use SugarCraft\Crush\Tools\BuiltIn\Edit;
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
    }

    protected function tearDown(): void
    {
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

        $results = $this->execute(
            $this->rendezvousCalls(['a', 'b', 'c'], peers: 2, wait: self::RENDEZVOUS_WAIT),
            [$this->rendezvousTool()],
        );

        // Positive control: the rewrite really reached the forked children, so a
        // failure below can only be the context that was threaded alongside it.
        $ran = array_map('basename', glob($this->dir . '/markers/*') ?: []);
        sort($ran);
        $this->assertSame(['a-rw', 'b-rw', 'c-rw'], $ran);
        $this->assertSame('saw=2', $results[0]->content());

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
     * the reaping generator: an escaping \Throwable would abandon the children
     * after it (never reaped, payloads never unlinked) and then land in
     * EngineBackend's turn-level boundary, which discards every sibling result
     * and all assistant content produced so far. The worst a failed merge may
     * cost is one announce-once mark.
     */
    public function testAThrowingSessionStateMergeCostsOnlyThatCallsMark(): void
    {
        $before = $this->childPids();
        $ipcBefore = $this->runtimeIpcFiles();

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
        $this->assertSame($ipcBefore, $this->runtimeIpcFiles(), 'every payload must still have been unlinked');
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
     */
    public function testAChildsPayloadIsNeverReadableByAnotherUser(): void
    {
        $tool = new class (sys_get_temp_dir()) implements Tool, ParallelSafe {
            /** @var array<string, true> */
            private array $preexisting;

            public function __construct(private string $tmp)
            {
                $this->preexisting = array_fill_keys($this->payloads(), true);
            }

            public function name(): string { return 'modeprobe'; }
            public function description(): string { return 'reports the mode of a sibling payload'; }
            public function inputSchema(): array { return ['type' => 'object']; }
            public function isParallelSafe(): bool { return true; }

            public function execute(array $args): ToolResult
            {
                if (($args['role'] ?? '') !== 'probe') {
                    return new ToolResult(toolCallId: '', content: 'quick');
                }

                $deadline = microtime(true) + 3.0;
                do {
                    foreach ($this->payloads() as $path) {
                        if (isset($this->preexisting[$path])) {
                            continue;
                        }

                        clearstatcache(true, $path);
                        $perms = @fileperms($path);
                        if ($perms !== false) {
                            return new ToolResult(toolCallId: '', content: substr(sprintf('%o', $perms), -4));
                        }
                    }
                    usleep(1_000);
                } while (microtime(true) < $deadline);

                return new ToolResult(toolCallId: '', content: 'no sibling payload observed');
            }

            /** @return list<string> */
            private function payloads(): array
            {
                return array_values(array_filter(
                    glob($this->tmp . '/sc_runtime_tool_*') ?: [],
                    static fn (string $p): bool => !str_ends_with($p, '.partial'),
                ));
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

        $this->assertSame('0600', $results[0]->content());
        $this->assertSame('quick', $results[1]->content());
    }

    /**
     * And a group that ran cleanly leaves nothing behind — the normal-path
     * unlink that {@see \SugarCraft\Crush\Support\ToolIpcFiles::sweep()} is
     * only the backstop for.
     */
    public function testACompletedGroupLeavesNoPayloadFilesBehind(): void
    {
        $before = $this->runtimeIpcFiles();

        $this->execute(
            $this->rendezvousCalls(['a', 'b', 'c'], peers: 3, wait: self::RENDEZVOUS_WAIT),
            [$this->rendezvousTool()],
        );

        $this->assertSame($before, $this->runtimeIpcFiles());
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
     * Every payload file either dispatcher could have left in the temp
     * directory, sorted so a before/after comparison is stable.
     *
     * @return list<string>
     */
    private function runtimeIpcFiles(): array
    {
        return $this->sorted(glob(sys_get_temp_dir() . '/sc_runtime_tool_*') ?: []);
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
