<?php

/**
 * W4-C (plan_comparison remediation 1021): candy-code has TWO turn paths and
 * no shared seam — the `-p` headless path (`NonInteractive::run()` driving
 * `EngineBackend::complete()`) and the interactive host path
 * (`TurnRunner::start()` dispatching `completeAsync`/`completeInteractive`).
 * They already diverged once for real (ISS-006: the spend cap existed only on
 * the host side while the unattended CI loop ran a paid agent forever). This
 * suite is the semantic-parity net the plan requires to exist BEFORE the
 * W5/W7 coordinator splits: every aspect the two paths are supposed to agree
 * on is pinned at the level a user of either surface can observe, and every
 * place they LEGITIMATELY differ is pinned as a difference, with the reason
 * written down, so a future unification (or a silent drift back) trips here.
 *
 * Aspect × what each path does × how it is pinned:
 *
 *  max-steps resolution .... both production backends come from the SAME
 *                            `withResolvedMaxToolSteps()` reconciler behind
 *                            `Bootstrap::backend()`/`backendFor()`; pinned by
 *                            door-slice + flag behaviour (test 8).
 *  spend cap ............... `-p` wraps via `NonInteractive::withHeadlessSpendCap`
 *                            (= `withSpendCap(cap, 0.0)`), host wraps via
 *                            `TurnRunner::backendForTurn` (= the same
 *                            `withSpendCap(cap, spent)`); pinned as config
 *                            equality (test 4) and as same-billing-same-breach
 *                            (test 5). DIFFERENCE that stays: the host knows
 *                            the session's prior spend, `-p` starts at zero —
 *                            both are "session spend at start", see test 4.
 *  tool-execution wiring ... the same engine loop runs tools mid-turn on both
 *                            paths; pinned as identical provider call counts,
 *                            identical fed-back results, and identical
 *                            ToolStarted/ToolFinished stories on the wire vs
 *                            the host inbox (tests 1–3).
 *  truncation flags ........ `lengthStopped`/`stepsTruncated` fold identically
 *                            (shared `runTurn`) and BOTH surfaces announce
 *                            them — the host as transcript notices, `-p` via
 *                            the D1-fix stop trailers (test 9).
 *  refusal mapping ......... one `ToolRefusal::fromEvent` choke feeds the `-p`
 *                            refusal rows; the host reads the same structural
 *                            stamp off the same event objects (test 6).
 *  interactive tools ....... AskUser/PlanExit are closed by
 *                            `withoutInteractiveUser()` ONLY on `-p` — a
 *                            deliberate, documented difference (test 7).
 */

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Backend;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Code\App\AssistantMsg;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\App\BackendToolEventsMsg;
use SugarCraft\Code\Cli\ArgvParser;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\LaunchContext;
use SugarCraft\Code\Cli\NonInteractive;
use SugarCraft\Code\Events\SpendCapBreached;
use SugarCraft\Code\Events\ToolFinished;
use SugarCraft\Code\Host\SessionEvent;
use SugarCraft\Code\Host\TurnRunner;
use SugarCraft\Code\Message;
use SugarCraft\Code\Permissions\DenialKind;
use SugarCraft\Code\Permissions\PermissionAction;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Permissions\PermissionRule;
use SugarCraft\Code\Permissions\SessionPermissionMemo;
use SugarCraft\Code\Permissions\ToolRefusal;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tests\Support\SlicesDeclaredMethodsTrait;
use SugarCraft\Code\Tests\Support\ScriptedProvider;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolCall as EngineToolCall;
use SugarCraft\Code\Tools\ToolResult as EngineToolResult;

/**
 * @see NonInteractive
 * @see TurnRunner
 * @see EngineBackend
 */
final class TurnPathContractTest extends TestCase
{
    use HomeSandboxTrait;
    use SlicesDeclaredMethodsTrait;

    private string $contractHome = '';
    private string|false $originalCap = false;

    protected function setUp(): void
    {
        $this->contractHome = sys_get_temp_dir() . '/w4c_turnpath_' . uniqid('', true);
        $this->useHomeSandbox($this->contractHome);
        mkdir($this->contractHome . '/.candy-code', 0700, true);

        $this->originalCap = getenv('CANDY_CODE_MAX_COST');
        putenv('CANDY_CODE_MAX_COST');

        // The flag statics that feed the max-steps reconciler must not leak
        // from any sibling shard: a clean null is the pre-test world.
        Bootstrap::useMaxTurns(null);
        NonInteractive::pinStreamOutputForTesting(null);
    }

    protected function tearDown(): void
    {
        putenv(false === $this->originalCap ? 'CANDY_CODE_MAX_COST' : 'CANDY_CODE_MAX_COST=' . $this->originalCap);
        Bootstrap::useMaxTurns(null);
        NonInteractive::pinStreamOutputForTesting(null);
        $this->restoreHomeSandbox();
        if (is_dir($this->contractHome)) {
            // ChildStderrCaptureTest: fd 2 must not ride onto the suite's own —
            // merge it into the stdout exec() already captures.
            exec('rm -rf ' . escapeshellarg($this->contractHome) . ' 2>&1');
        }
    }

    // ── tool-execution wiring shape ─────────────────────────────────────

    public function testAnAnswerRoundTripEndsWithTheSameReplyAndTheSameToolStoryOnBothPaths(): void
    {
        [$hostEngine] = $this->contractEngine('answer');
        [$headlessEngine] = $this->contractEngine('answer');

        $msg = $this->hostTurn($hostEngine)[0];
        $this->assertTrue($msg instanceof BackendToolEventsMsg, 'a tool-using turn settles as an event queue on the host path');
        $this->assertSame('done', $msg->message->content);
        $this->assertSame(
            ['started:echo', 'finished:echo'],
            $this->toolStoryOf($msg->events),
            'the host turn observed exactly one echo round'
        );

        [$code, $doc] = $this->driveHeadlessJson($headlessEngine);
        $this->assertSame(NonInteractive::EXIT_OK, $code);
        $this->assertSame('done', $doc['result'], 'the -p document answers with the same final text');
    }

    public function testStreamJsonReportsTheToolStoryTheHostInboxObserved(): void
    {
        [$hostEngine] = $this->contractEngine('answer');
        [$headlessEngine] = $this->contractEngine('answer');

        $hostStory = $this->toolStoryOf($this->hostTurn($hostEngine)[0]->events);

        $lines = $this->driveHeadlessStream($headlessEngine);
        $wireStory = [];
        foreach ($lines as $line) {
            if (($line['type'] ?? null) === SessionEvent::TOOL_STARTED) {
                $wireStory[] = 'started:' . $line['data']['name'];
            }
            if (($line['type'] ?? null) === SessionEvent::TOOL_FINISHED) {
                $wireStory[] = 'finished:' . $line['data']['name'];
            }
        }

        $this->assertSame($hostStory, $wireStory, 'both surfaces tell the same tool story, in order');
    }

    /**
     * Fork note: `completeAsync` runs the engine loop in a worker, so the
     * parent-side provider recorder sees nothing from the host path — the
     * observable host proof is the settled story itself (the answer only
     * exists because the loop re-asked after executing the tool). The `-p`
     * sync path exposes the recorder directly.
     */
    public function testBothPathsExecuteTheToolBeforeAskingTheModelAgain(): void
    {
        [$hostEngine] = $this->contractEngine('answer');
        [$headlessEngine, $headlessProvider] = $this->contractEngine('answer');

        $msg = $this->hostTurn($hostEngine)[0];
        $this->assertTrue($msg instanceof BackendToolEventsMsg);
        $finished = null;
        foreach ($msg->events as $event) {
            if ($event instanceof ToolFinished) {
                $finished = $event;
            }
        }
        $this->assertTrue($finished instanceof ToolFinished, 'the host turn observed the finished tool');
        $this->assertSame('echoed back', $finished->result->content(), 'the host tool actually ran');
        $this->assertSame('done', $msg->message->content, 'the host answer only exists because the loop re-asked after the tool');

        $this->driveHeadlessJson($headlessEngine);
        $this->assertCount(2, $headlessProvider->requests, 'the -p path asked the model once per step');
        $this->assertTrue(str_contains($this->requestDump($headlessProvider->requests[1]), 'echoed back'), 'the -p second request carries the executed tool result');
    }

    // ── spend cap ────────────────────────────────────────────────────────

    public function testTheHeadlessCapWrapAndTheHostCapArgumentLandOnTheSameEngineKnob(): void
    {
        [$plain] = $this->contractEngine('answer');
        $headless = NonInteractive::withHeadlessSpendCap($plain, 1.0);
        $host = TurnRunner::backendForTurn($plain, 1.0, 0.0, null, null, SessionPermissionMemo::new());

        $this->assertSame(1.0, $this->engineFloat($headless, 'spendCapUsd'));
        $this->assertSame(1.0, $this->engineFloat($host, 'spendCapUsd'), 'one knob, one value, however the path got there');
        // THE ONE LEGITIMATE SHAPE DIFFERENCE: the host passes the session's
        // spend so far; `-p` starts at 0.0 because a one-shot process has no
        // prior spend. Both arguments mean "session spend at start" — feed a
        // non-zero baseline to the host wrapper and this pin is the arm that
        // would catch a path reinterpreting the slot.
        $hostWithHistory = TurnRunner::backendForTurn($plain, 1.0, 0.25, null, null, SessionPermissionMemo::new());
        $this->assertSame(0.25, $this->engineFloat($hostWithHistory, 'sessionSpendAtStartUsd'));
        $this->assertSame(0.0, $this->engineFloat(NonInteractive::withHeadlessSpendCap($plain, 1.0), 'sessionSpendAtStartUsd'));
    }

    public function testACappedTurnBillsTheSameNumberOfCallsAndBreachesOnceOnBothPaths(): void
    {
        putenv('CANDY_CODE_MAX_COST=1.0');

        [$hostEngine] = $this->contractEngine('loop');
        [$headlessEngine, $headlessProvider] = $this->contractEngine('loop');

        $hostMsg = $this->hostTurn($hostEngine, capUsd: 1.0)[0];
        $this->assertTrue($hostMsg instanceof BackendToolEventsMsg);
        $breach = null;
        foreach ($hostMsg->events as $event) {
            if ($event instanceof SpendCapBreached) {
                $this->assertNull($breach, 'the cap breaches exactly once on the host path');
                $breach = $event;
            }
        }
        $this->assertTrue($breach instanceof SpendCapBreached, 'the host turn carried the breach event through to the settled story');
        $this->assertFalse($hostMsg->message->stepsTruncated, 'a capped turn is NOT a step-truncated turn on the host path');

        [$code, $doc] = $this->driveHeadlessJson($headlessEngine);
        $this->assertSame(NonInteractive::EXIT_OK, $code, 'a capped -p run still answers with what it has');
        $this->assertCount(1, $doc['refusals'] ?? [], 'the -p path reports exactly one refusal row');
        $row = $doc['refusals'][0];
        $this->assertSame('turn', $row['tool']);
        $this->assertSame('budget', $row['kind']);
        $this->assertTrue(str_contains($row['reason'], sprintf('%.4f', $breach->capUsd)), 'both surfaces state the same ceiling');
        $this->assertTrue(str_contains($row['reason'], (string) $breach->completedCalls), 'both surfaces count the same billed calls');

        // The -p recorder is the honest cross-check that the SAME cap on the
        // SAME script bought a looping turn that actually stopped: more than
        // one paid call, and exactly as many as the breach event counted —
        // the host-side recorder is fork-blind (see test above), so the
        // payload arithmetic is pinned through the refusal row instead.
        $this->assertSame($breach->completedCalls, count($headlessProvider->requests), 'the cap stopped the -p loop at the call the breach counted');
    }

    // ── refusal mapping ──────────────────────────────────────────────────

    public function testADeniedToolCallRefusesThroughTheSharedChokeOnBothPaths(): void
    {
        $gate = new PermissionGate(PermissionMode::Default, [new PermissionRule('echo', PermissionAction::Deny)]);
        [$hostEngine] = $this->contractEngine('answer', gate: $gate);
        [$headlessEngine] = $this->contractEngine('answer', gate: $gate);

        $hostMsg = $this->hostTurn($hostEngine)[0];
        $this->assertTrue($hostMsg instanceof BackendToolEventsMsg);
        $denied = null;
        foreach ($hostMsg->events as $event) {
            if ($event instanceof ToolFinished && $event->result->denial() !== null) {
                $denied = $event;
            }
        }
        $this->assertTrue($denied instanceof ToolFinished, 'the host path saw the structural refusal stamp on the finished tool');

        // THE SHARED CHOKE: the exact host-side event object fed to the same
        // classifier `-p` uses internally must produce the row -p emitted.
        $refusal = ToolRefusal::fromEvent($denied);
        $this->assertTrue($refusal !== null);
        // CURRENT behaviour on BOTH paths: gate enforcement rides the engine
        // permission hook, so a policy Deny is stamped DenialKind::Hook —
        // the stamp is identical on either surface (the row/kind equality
        // below is the parity contract; the case identity only documents
        // where the stamp comes from).
        $this->assertSame(DenialKind::Hook, $refusal->kind, 'the gate denial lands on the hook-stamped refusal shape on both paths');

        [$code, $doc] = $this->driveHeadlessJson($headlessEngine);
        $this->assertSame(NonInteractive::EXIT_OK, $code);
        $this->assertCount(1, $doc['refusals'] ?? []);
        $row = $doc['refusals'][0];
        $this->assertSame('echo', $row['tool']);
        $this->assertSame($refusal->kind->token(), $row['kind'], 'one classification feeds both surfaces');
        $this->assertSame($refusal->reason, $row['reason'], 'one reason text feeds both surfaces');
    }

    // ── deliberate differences ───────────────────────────────────────────

    /**
     * NOT a defect: AskUser/PlanExit stay interactive for a human at a TTY
     * and are forced into "no user will answer" only on `-p`. Pinned as a
     * DIFFERENCE on purpose — the day a coordinator unifies the paths, this
     * is the arm that must move together with an explicit decision, not with
     * a refactor sweep.
     */
    public function testTheInteractiveToolsAreClosedOnlyOnTheHeadlessPath(): void
    {
        $runBody = $this->methodBody(NonInteractive::class, 'run');
        $this->assertTrue(str_contains($runBody, 'withoutInteractiveUser'), '-p must close the interactive-user tools');

        $hostBody = $this->methodBody(TurnRunner::class, 'backendForTurn');
        $this->assertFalse(str_contains($hostBody, 'withoutInteractiveUser'), 'the host path must leave them open for the human');
    }

    /**
     * The ISS-006-era reconciliation: both backend doors funnel max-steps
     * through the ONE private reconciler. The door slices prove neither path
     * can quietly drop it again; the flag probe proves the reconciler still
     * reads the shared flag and turns it into the engine knob.
     */
    public function testBothBackendDoorsReconcileMaxToolStepsThroughTheOneResolver(): void
    {
        // W7-E C4 (v4:S1b, 2026-10-21): backendFor()'s body moved onto
        // LaunchContext; the funnel pin follows the builder, so the pair now
        // reads the resident door and the migrated builder, not two doors.
        foreach ([
            [Bootstrap::class, 'backend'],
            [LaunchContext::class, 'backendFor'],
        ] as [$owner, $door]) {
            $body = $this->methodBody($owner, $door);
            $this->assertSame(
                1,
                substr_count($body, 'withResolvedMaxToolSteps('),
                $owner . '::' . $door . '() must resolve max-steps exactly once through the shared reconciler'
            );
        }

        Bootstrap::useMaxTurns('2');
        $this->assertSame(2, Bootstrap::resolvedMaxToolSteps());

        [$engine] = $this->contractEngine('answer');
        $apply = new \ReflectionMethod(Bootstrap::class, 'withResolvedMaxToolSteps');
        $this->assertSame(2, $this->engineInt($apply->invoke(null, $engine), 'maxSteps'), 'the flag lands on the engine both paths run on');
    }

    // ── truncation-flag consumption ──────────────────────────────────────

    /**
     * DIVERGENCE RESOLVED (D1-fix, plan_comparison audit 2026-10-21): the
     * engine folds `stepsTruncated`/`lengthStopped` IDENTICALLY on both paths
     * (shared runTurn), and now BOTH surfaces announce them — the TUI appends
     * `stepsTruncatedNotice()`/`outputLengthStoppedNotice()` to the
     * transcript, and `-p` carries the same facts as typed keys in its json
     * document (`steps_truncated`/`length_stopped`/`loop_guard_stopped_by`,
     * absent-until-set) and as plain-text trailer lines under `text`. When
     * this suite first shipped, `-p` was silent: the pin below was written as
     * a silence pin on purpose, and its own docblock predicted that a fix
     * lane would arrive and force this contract to be re-blessed with the
     * fix. D1-fix is that lane; this is that re-blessing, per the terms the
     * dated docblock set. The exit status stays OK by ruling — a stopped but
     * answered run is not a failure — the loudness is the fix, not the code.
     */
    public function testAStepTruncatedTurnFoldsTheSameFlagAndBothSurfacesAnnounceIt(): void
    {
        [$hostEngine] = $this->contractEngine('loop', maxSteps: 1);
        $hostMsg = $this->hostTurn($hostEngine)[0];
        $this->assertTrue($hostMsg->message->stepsTruncated, 'a step-capped loop is stepsTruncated on the host path');

        [$headlessEngine, $headlessProvider] = $this->contractEngine('loop', maxSteps: 1);
        $reply = $headlessEngine->complete([Message::user('go')]);
        $this->assertTrue($reply->stepsTruncated, 'the same script and the same knob fold the same flag on the -p engine');
        $this->assertCount(2, $headlessProvider->requests, 'the step ceiling lets the looped step run, then the engine pays one closing call before stopping (the host recorder is fork-blind)');

        [$code, $doc] = $this->driveHeadlessJson($headlessEngine);
        $this->assertSame(NonInteractive::EXIT_OK, $code, 'a stopped-but-answered -p run is not a failure: the answer is complete, the STOP is the caveat');
        $this->assertFalse(isset($doc['refusals']), 'a step cap is not a refusal — the refusals row stays for its own facts');
        $this->assertSame(true, $doc['steps_truncated'] ?? null, 'LOUD contract (post D1-fix): the -p json document carries the folded stop fact');
        foreach (['length_stopped', 'loop_guard_stopped_by'] as $untouched) {
            $this->assertFalse(isset($doc[$untouched]), "only the flag the script earned lands: {$untouched} stays absent-until-set");
        }
    }

    // ── harness ──────────────────────────────────────────────────────────

    /**
     * @return array{0: EngineBackend, 1: ScriptedProvider}
     */
    private function contractEngine(string $scriptKey, ?PermissionGate $gate = null, ?int $maxSteps = null): array
    {
        $provider = new ScriptedProvider($this->contractScript($scriptKey), contextWindow: 1_000_000);
        $engine = EngineBackend::new($provider, 'contract-model')
            ->withoutHooks()
            ->withRoot($this->contractHome)
            ->withTools([$this->contractEchoTool()]);
        if ($gate !== null) {
            $engine = $engine->withPermissionGate($gate);
        }
        if ($maxSteps !== null) {
            $engine = $engine->withMaxSteps($maxSteps);
        }

        return [$engine, $provider];
    }

    /**
     * @return list<CompleteResponse>
     */
    private function contractScript(string $scriptKey): array
    {
        return match ($scriptKey) {
            // one tool round, then the answer
            'answer' => [
                new CompleteResponse(content: 'looking', toolCalls: [new EngineToolCall('c1', 'echo', [])]),
                new CompleteResponse(content: 'done'),
            ],
            // a paid call that never stops answering — the cap (or the step
            // ceiling) is the only thing that ends the loop
            'loop' => [
                new CompleteResponse(content: 'again', toolCalls: [new EngineToolCall('c1', 'echo', [])], costUsd: 0.4),
            ],
            default => $this->fail('unknown contract script ' . $scriptKey),
        };
    }

    private function contractEchoTool(): Tool
    {
        return new class () implements Tool {
            public function name(): string
            {
                return 'echo';
            }

            public function description(): string
            {
                return 'answers with a fixed body';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): EngineToolResult
            {
                return new EngineToolResult(toolCallId: '', content: 'echoed back');
            }
        };
    }

    /**
     * Drive the host path exactly as the TUI entry does: wrap through
     * backendForTurn, dispatch through TurnRunner::start, resolve the promise.
     *
     * @return array{0: AssistantMsg|BackendToolEventsMsg, 1: \ArrayObject}
     */
    private function hostTurn(EngineBackend $engine, ?float $capUsd = null): array
    {
        $runner = TurnRunner::new();
        $inbox = new \ArrayObject();
        $wrapped = TurnRunner::backendForTurn($engine, $capUsd, 0.0, null, null, SessionPermissionMemo::new());
        $thunk = $runner->start($wrapped, [Message::user('go')], $inbox, 1, new CancellationToken(), false, transcripts: null, sessionId: null);
        $msg = $this->resolveDispatchedTurn($thunk());

        $this->assertTrue($msg instanceof AssistantMsg || $msg instanceof BackendToolEventsMsg);

        return [$msg, $inbox];
    }

    /**
     * @return array{0: int, 1: array<string,mixed>}
     */
    private function driveHeadlessJson(EngineBackend $engine): array
    {
        ob_start();
        $code = NonInteractive::run(ArgvParser::parse(['candy-code', '-p', 'go']), $engine, NonInteractive::FORMAT_JSON);
        $stdout = (string) ob_get_clean();

        return [$code, json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR)];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function driveHeadlessStream(EngineBackend $engine): array
    {
        $stream = fopen('php://memory', 'w+b');
        $this->assertTrue(is_resource($stream));
        NonInteractive::pinStreamOutputForTesting($stream);

        ob_start();
        $code = NonInteractive::run(ArgvParser::parse(['candy-code', '-p', 'go']), $engine, NonInteractive::FORMAT_STREAM_JSON);
        ob_get_clean();
        $this->assertSame(NonInteractive::EXIT_OK, $code);

        rewind($stream);
        $lines = [];
        foreach (explode("\n", (string) stream_get_contents($stream)) as $raw) {
            if (trim($raw) !== '') {
                $lines[] = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            }
        }
        fclose($stream);
        NonInteractive::pinStreamOutputForTesting(null);

        return $lines;
    }

    /**
     * @param PromiseInterface<AssistantMsg|BackendToolEventsMsg> $promise
     */
    private function resolveDispatchedTurn(PromiseInterface $promise): AssistantMsg|BackendToolEventsMsg
    {
        $loop = Loop::get();
        $settled = false;
        $value = null;
        $failure = null;
        $promise->then(
            static function ($v) use (&$settled, &$value, $loop): void {
                $settled = true;
                $value = $v;
                $loop->stop();
            },
            static function (\Throwable $e) use (&$settled, &$failure, $loop): void {
                $settled = true;
                $failure = $e->getMessage();
                $loop->stop();
            },
        );
        if (!$settled) {
            $watchdog = $loop->addTimer(30.0, static function () use ($loop, &$failure): void {
                $failure = 'the dispatched turn never settled within the safety window';
                $loop->stop();
            });
            $loop->run();
            $loop->cancelTimer($watchdog);
        }
        if ($failure !== null) {
            $this->fail('host-path turn failed: ' . $failure);
        }
        $this->assertTrue($value instanceof AssistantMsg || $value instanceof BackendToolEventsMsg);

        return $value;
    }

    /**
     * @param list<ToolStarted|ToolFinished|object> $events
     *
     * @return list<string>
     */
    private function toolStoryOf(array $events): array
    {
        $story = [];
        foreach ($events as $event) {
            if ($event instanceof \SugarCraft\Code\Events\ToolStarted) {
                $story[] = 'started:' . $event->toolName;
            }
            if ($event instanceof ToolFinished) {
                $story[] = 'finished:' . $event->toolName;
            }
        }

        return $story;
    }

    /**
     * @param object $request
     */
    private function requestDump(object $request): string
    {
        return (string) print_r($request, true);
    }

    private function engineFloat(EngineBackend $engine, string $property): float
    {
        $ref = new \ReflectionProperty(EngineBackend::class, $property);

        return (float) $ref->getValue($engine);
    }

    private function engineInt(EngineBackend $engine, string $property): int
    {
        $ref = new \ReflectionProperty(EngineBackend::class, $property);

        return (int) $ref->getValue($engine);
    }

    /**
     * The declared body of $method on $class — reflection offsets, then the
     * shared slicer refuses ranges that do not start at the declaration.
     */
    private function methodBody(string $class, string $method): string
    {
        $ref = new \ReflectionMethod($class, $method);
        $lines = (array) file((string) $ref->getFileName());

        return self::declaredSlice($lines, $method, (int) $ref->getStartLine(), (int) $ref->getEndLine());
    }
}
