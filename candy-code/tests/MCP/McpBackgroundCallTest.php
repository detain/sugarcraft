<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\McpBackgroundInbox;
use SugarCraft\Code\Events\ToolProgress;
use SugarCraft\Code\Hooks\HookResult;
use SugarCraft\Code\Lang;
use SugarCraft\Code\MCP\AnswersServerRequests;
use SugarCraft\Code\MCP\McpBackgroundCalls;
use SugarCraft\Code\MCP\McpClient;
use SugarCraft\Code\MCP\McpServer;
use SugarCraft\Code\MCP\McpServerRequests;
use SugarCraft\Code\MCP\McpTool;
use SugarCraft\Code\MCP\McpToolShape;
use SugarCraft\Code\Permissions\ApprovalVerdict;
use SugarCraft\Code\Support\HomeDirectory;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tools\BuiltIn\AskUserTool;
use SugarCraft\Code\Tools\BuiltIn\McpCallTool;
use SugarCraft\Code\Tools\McpToolBridge;
use SugarCraft\Code\Tools\ToolCall;

/**
 * C-MCP-3.d — a long MCP call hands the TURN over at the threshold while the
 * exchange keeps running in a forked child, and its answer arrives later
 * through the turn inbox as a message.
 *
 * THE HAZARD THIS SUITE MANAGES: every armed call here forks from inside the
 * PHPUnit process. The child leaves through {@see ForkedChild::exitNow()} —
 * SIGKILL on itself, no shutdown sequence, no republished output buffer, no
 * second runner — exactly as the census in
 * {@see \SugarCraft\Code\Tests\Support\ForkedChildExitConventionTest} demands,
 * and the parent REAPS it (bounded poll, then a defensive kill) in tearDown
 * before the sandboxed home is removed, so no test can leak a sleeper child
 * onto the machine or into another test's registry.
 *
 * EVERY THRESHOLD IS INJECTED (0.15 s) and every "slow" server sleeps 0.35 s;
 * the product figure of 120 s is pinned against the docs once, as a figure,
 * and never waited out here. Timeouts are polling BUDGETS, never assertion
 * bases — an exchange that finishes late is still the same exchange.
 *
 * The fake servers are plain {@see McpServer} implementations (not PHPUnit
 * mocks) because the call must run INSIDE a forked copy of this process,
 * where a mock's invocation bookkeeping would be meaningless — the sleep and
 * the reply are the whole contract.
 */
final class McpBackgroundCallTest extends TestCase
{
    use HomeSandboxTrait;

    private const THRESHOLD = 0.15;
    private const BACKGROUND_CALL_SLEEP_SECONDS = 0.35;

    private string $sandboxHome = '';

    /** @var list<int> */
    private array $expectedPids = [];

    protected function setUp(): void
    {
        // Lane-cf law: the trait snapshots on its FIRST call, so no env
        // clearing may precede it.
        $this->sandboxHome = $this->useHomeSandbox(sys_get_temp_dir() . '/cc-mcp-bg-' . getmypid() . '-' . uniqid('t', true));
        McpBackgroundCalls::resetPendingChildrenForTesting();
        $this->expectedPids = [];
    }

    protected function tearDown(): void
    {
        // Reap every child this test forked, bounded: poll to exit, then a
        // defensive SIGKILL, then the blocking reap that proves it is gone.
        // Nothing below may leave an orphan that outlives the temp home.
        foreach (McpBackgroundCalls::pendingChildren() as $pid) {
            $this->reap($pid);
        }
        foreach ($this->expectedPids as $pid) {
            $this->reap($pid);
        }
        McpBackgroundCalls::resetPendingChildrenForTesting();

        $registry = $this->sandboxHome . '/.candy-code/' . McpBackgroundCalls::RELATIVE_DIR;
        if (is_dir($registry)) {
            foreach (scandir($registry) ?: [] as $leftover) {
                if ($leftover !== '.' && $leftover !== '..') {
                    @unlink($registry . '/' . $leftover);
                }
            }
            @rmdir($registry);
        }
        @rmdir($this->sandboxHome . '/.candy-code');
        @rmdir($this->sandboxHome);

        $this->restoreHomeSandbox();
    }

    // =========================================================================
    // The hand-off
    // =========================================================================

    public function testASlowBridgedCallHandsOffAtTheThresholdWithAJobIdPlaceholder(): void
    {
        $bridge = $this->bridgingSlowCall('slow answer');

        $result = $bridge->executeWithHeartbeat(['id' => 'call_bg1'], static function (): void {
        });

        self::assertFalse($result->isError(), 'a handed-off call is not an error, it is a promise kept');
        self::assertStringContainsString('is still running as background MCP job', $result->content());
        self::assertStringContainsString('the result will arrive as a message', $result->content());
        self::assertStringNotContainsString('slow answer', $result->content(), 'the placeholder never pre-delivers the result');

        // The job id the placeholder quotes is the registry name the child
        // will publish under, and it carries the server/tool slug prefix.
        self::assertSame(1, preg_match('/background MCP job `(srv-ping-\d{13}-[0-9a-f]{8})`/', $result->content(), $m), $result->content());
        $pending = McpBackgroundCalls::pendingChildren();
        self::assertArrayHasKey($m[1], $pending, 'the handed-off child is this process\'s until it is reaped');
        $this->expectedPids[] = $pending[$m[1]];

        // The child finishes on its own schedule; poll to publication, then
        // drain so the same job cannot leak into a later test.
        self::assertTrue($this->waitUntilPublished(), 'the child publishes its finished exchange');
        self::assertCount(1, McpBackgroundCalls::takeFinished());
    }

    public function testTheFinishedResultArrivesThroughTheInboxAsAMessage(): void
    {
        $bridge = $this->bridgingSlowCall('slow answer');
        $bridge->executeWithHeartbeat(['id' => 'call_bg2'], static function (): void {
        });
        self::assertTrue($this->waitUntilPublished());

        $inbox = McpBackgroundInbox::forTurn();
        self::assertNotNull($inbox, 'the sandboxed home can host the registry');
        self::assertTrue($inbox->pending());

        $rows = $inbox->drain(1);
        self::assertCount(1, $rows);
        self::assertSame('user', $rows[0]->role(), 'the answer arrives as a message to the model, like steer rows');
        $text = $rows[0]->content();
        self::assertStringContainsString('has finished:', $text);
        self::assertStringContainsString('slow answer', $text, 'the child\'s rendered row arrives verbatim');
        self::assertFalse($inbox->pending(), 'one claim, one delivery');
        self::assertSame([], $inbox->drain(1));
    }

    public function testTheDispatchedSpellingHandsOffTheSameWay(): void
    {
        $descriptor = new McpTool(name: 'ping', description: str_repeat('a', 3000), inputSchema: [], serverName: 'srv');
        $client = $this->clientHolding($this->slowServer('slow answer', $descriptor), $descriptor);
        $shape = McpToolShape::derive($client, [$descriptor], [], 10);
        self::assertTrue($shape->lazy, 'fixture: the pair must be dispatched, not first-class');

        $tool = (new McpCallTool($shape))->withBackgroundAfter(self::THRESHOLD);
        $result = $tool->executeWithHeartbeat(
            ['id' => 'call_bg3', 'server' => 'srv', 'tool' => 'ping', 'arguments' => []],
            static function (): void {
            },
        );

        self::assertFalse($result->isError());
        self::assertStringContainsString('is still running as background MCP job', $result->content());
        self::assertStringContainsString(McpToolBridge::wireNameFor('srv', 'ping'), $result->content(), 'the dispatched placeholder names the wire tool');
        self::assertTrue($this->waitUntilPublished());
        self::assertCount(1, McpBackgroundCalls::takeFinished());
    }

    // =========================================================================
    // The promises the feature makes around the hand-off
    // =========================================================================

    public function testAFastArmedCallReturnsThePlainPathBytes(): void
    {
        $descriptor = $this->descriptor();

        $plain = $this->bridgeWith($this->slowServer('quick answer', $descriptor, sleep: 0.0), $descriptor)
            ->execute(['id' => 'call_fast']);

        $armed = $this->bridgeWith($this->slowServer('quick answer', $descriptor, sleep: 0.0), $descriptor)
            ->withBackgroundAfter(self::THRESHOLD)
            ->executeWithHeartbeat(['id' => 'call_fast'], static function (): void {
            });

        self::assertSame($plain->content(), $armed->content(), 'below the threshold the forked render is byte-for-byte the inline one');
        self::assertSame($plain->isError(), $armed->isError());
        self::assertSame('call_fast', $armed->toolCallId());
        self::assertSame([], McpBackgroundCalls::pendingChildren(), 'a collected child is reaped inside await()');
        self::assertFalse(McpBackgroundCalls::hasFinished(), 'the parent claimed its own child\'s row');
    }

    public function testAHeadlessLatchWaitsForTheRealResultAndNeverForks(): void
    {
        $bridge = $this->bridgingSlowCall('plain answer')->withoutInteractiveUser('test headless');

        $result = $bridge->executeWithHeartbeat(['id' => 'call_p'], static function (): void {
        });

        self::assertFalse($result->isError());
        self::assertStringContainsString('plain answer', $result->content(), 'the `-p` caller gets the exchange it waited for, as ever');
        self::assertStringNotContainsString('background MCP job', $result->content());
        self::assertSame([], McpBackgroundCalls::pendingChildren(), 'a latched run never forks a background child');
        self::assertDirectoryDoesNotExist($this->registryDir());
    }

    public function testTheChildIsReapedNotMerelyForgotten(): void
    {
        $bridge = $this->bridgingSlowCall('slow answer');
        $bridge->executeWithHeartbeat(['id' => 'call_reap'], static function (): void {
        });

        $pending = McpBackgroundCalls::pendingChildren();
        self::assertCount(1, $pending, 'the hand-off leaves exactly one live child');
        $pid = reset($pending);

        self::assertTrue($this->waitUntilPublished());
        // Poll-then-reap, the census idiom: the registry scan takes the row
        // AND reaps the child — but publication can outrun exit by a few
        // scheduler ticks, so the reap is polled to settled, never assumed.
        $frames = McpBackgroundCalls::takeFinished();
        $deadline = hrtime(true) + 2_000_000_000;
        while (McpBackgroundCalls::pendingChildren() !== [] && hrtime(true) < $deadline) {
            $frames = array_merge($frames, McpBackgroundCalls::takeFinished());
            usleep(10_000);
        }
        self::assertCount(1, $frames);
        self::assertSame([], McpBackgroundCalls::pendingChildren());

        $status = 0;
        self::assertSame(-1, @pcntl_waitpid($pid, $status, WNOHANG), 'ECHILD: the pid is reaped, not just dropped from the roster');
    }

    public function testAChildThatDiesWithoutPublishingReportsLostAndNeverSilence(): void
    {
        $outcome = McpBackgroundCalls::await(
            static function (): never {
                throw new \RuntimeException('the exchange blew up');
            },
            5.0,
            null,
            'srv',
            'ping',
            'call_lost',
        );

        self::assertSame('lost', $outcome['kind'], 'a child that exited with no payload is a loud error row, never a promise nobody keeps');
        self::assertSame([], McpBackgroundCalls::pendingChildren());
    }

    // =========================================================================
    // The fixed figure, pinned across code / lang / docs in one breath
    // =========================================================================

    public function testTheHandOffFigureAndSentenceAgreeAcrossCodeLangAndDocs(): void
    {
        self::assertSame(120.0, McpBackgroundCalls::DEFAULT_AFTER_SECONDS, 'the CC-parity figure is the product threshold');

        $running = Lang::t('mcp.background.running', ['tool' => 'T', 'server' => 'S', 'id' => 'J']);
        self::assertStringContainsString('still running as background MCP job `J`', $running);
        self::assertStringContainsString('the result will arrive as a message', $running);

        $docs = (string) file_get_contents(__DIR__ . '/../../docs/MCP.md');
        self::assertStringContainsString('still running as', $docs);
        self::assertStringContainsString('background MCP job', $docs);
        self::assertStringContainsString('the result will arrive as a message', $docs);
        self::assertStringContainsString('120 seconds', $docs, 'the docs quote the same figure the code arms by default');

        $en = require __DIR__ . '/../../lang/en.php';
        $keys = array_filter(array_keys($en), static fn (string $key): bool => str_starts_with($key, 'mcp.background.'));
        self::assertSame(
            ['mcp.background.running', 'mcp.background.lost', 'mcp.background.finished'],
            array_values($keys),
            'the three rows, in the order en.php ships them',
        );
    }

    // =========================================================================
    // Round 1: the wiring seat, the doors, the dial, and E646's two halves
    // =========================================================================

    /**
     * The dial is NOT a library default and nothing in a class arms it:
     * production hands the product figure to a real call in exactly one place
     * — the C-MCP-3.d arm of the turn's tool bind in
     * {@see \SugarCraft\Code\Backend\EngineBackend}. Delete that arm, narrow it
     * to one spelling, or stop passing the figure there, and every other test
     * in this file stays green while the feature decays into code only a
     * fixture can trigger. This pin is what makes the rest of the file mean
     * something about the shipped program rather than about the test doubles.
     */
    public function testTheTurnBindingIsWhatHandsTheProductFigureToRealCalls(): void
    {
        $arm = self::sliceTurnArmingWindow();

        self::assertStringContainsString('Tools\\McpToolBridge', $arm, 'the bridge spelling is armed');
        self::assertStringContainsString('BuiltIn\\McpCallTool', $arm, 'and so is the dispatched one — half the pair is not the feature');
        self::assertStringContainsString('McpBackgroundCalls::DEFAULT_AFTER_SECONDS', $arm, 'the turn passes the product figure, never a copy of it');
        self::assertSame(1, substr_count($arm, 'withBackgroundAfter('), 'one arming call covers both spellings, so neither half can be armed twice or forgotten');
    }

    /**
     * The hand-off arm is a BOUNDARY, and a pin sitting on one side of a
     * boundary proves nothing about it: a test that only ever saw the
     * placeholder would stay green if the arm never fired (the inline path
     * returns an answer instead — which is the OTHER leg here), and one that
     * only ever saw an answer would stay green if the arm fired for every
     * call. So: one slow exchange, two dial settings, both outcomes asserted
     * in the same breath.
     */
    public function testTheDialDecidesTheBranchASweepAcrossTheBoundaryShows(): void
    {
        $handsOff = $this->bridgeWith($this->slowServer('late answer', $this->descriptor()))
            ->withBackgroundAfter(0.05)
            ->executeWithHeartbeat(['id' => 'call_sweep_a'], static function (): void {
            });

        self::assertStringContainsString('still running as background MCP job', $handsOff->content(), 'a threshold the exchange cannot beat releases the turn');
        self::assertStringNotContainsString('late answer', $handsOff->content(), 'and the placeholder never pre-delivers the answer');
        self::assertTrue($this->waitUntilPublished(), 'the released child goes on working');
        self::assertCount(1, McpBackgroundCalls::takeFinished());
        self::assertTrue($this->waitUntilQuiet(), 'and a child that has published exits on its own');

        $delivers = $this->bridgeWith($this->slowServer('late answer', $this->descriptor(), sleep: 0.05))
            ->withBackgroundAfter(5.0)
            ->executeWithHeartbeat(['id' => 'call_sweep_b'], static function (): void {
            });

        self::assertStringContainsString('late answer', $delivers->content(), 'a threshold the exchange beats delivers the row to the caller, as the inline path always did');
        self::assertStringNotContainsString('background MCP job', $delivers->content());
        self::assertSame([], McpBackgroundCalls::pendingChildren(), 'the boundary case leaves nothing running');
    }

    /**
     * E646's family law, pinned BOTH ways because the behavior alone can be
     * explained away. Structurally: the wait loop carries no way to stop a
     * child — no signal, no alarm, no kill, no terminate — so an edit that
     * "cleans up the orphan" has to fight this pin rather than slide in.
     * Behaviorally: the child is ALIVE at the instant the turn leaves, and it
     * publishes its own answer afterwards, which only a running process can
     * do. The threshold is a hand-off dial; the child's bound stays its
     * transport's own `toolTimeout`.
     */
    public function testTheHandOffReleasesTheTurnAndNeverSignalsTheChild(): void
    {
        $bridge = $this->bridgingSlowCall('slow answer');
        $result = $bridge->executeWithHeartbeat(['id' => 'call_nokill'], static function (): void {
        });

        self::assertStringContainsString('still running as background MCP job', $result->content());

        $pending = McpBackgroundCalls::pendingChildren();
        self::assertCount(1, $pending, 'the hand-off leaves exactly one child');
        $pid = (int) reset($pending);

        if (\function_exists('posix_kill')) {
            self::assertTrue(@posix_kill($pid, 0), 'a hand-off is a release, not a signal: the child is alive when the turn walks away');
        }

        self::assertTrue($this->waitUntilPublished(), 'a live child answers on its own schedule; a killed one never would');
        $frames = McpBackgroundCalls::takeFinished();
        self::assertCount(1, $frames);
        self::assertStringContainsString('slow answer', $frames[0]['result']->content(), 'the answer the turn gave up on is the answer that arrives');

        $wait = self::sliceFunctionBody('McpBackgroundCalls.php', 'public static function await(');
        foreach (['posix_kill', 'pcntl_alarm', 'pcntl_signal', 'pcntl_sigprocmask', 'SIGKILL', 'SIGTERM', 'SIGINT', 'proc_terminate', 'terminate('] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $wait, 'E646: the threshold dial is not a wall-clock kill, not here and not later');
        }
    }

    /**
     * Threshold arbitration is a set of doors, and the docs promise the inline
     * path wherever a door fails: a `-p` run, a machine that cannot fork
     * (Windows, a pcntl-less build), a run with no home to keep a registry in,
     * a dial that is not live, and — new at round 1 — a call no turn is
     * waiting on. Every one of them is decided in ONE method, so the table is
     * pinned by behavior where the door is reachable and by shape where it is
     * a property of the machine.
     */
    public function testArbitrationArmsOnlyOnACapableTurnDrivenCall(): void
    {
        $capable = \function_exists('pcntl_fork') && \function_exists('pcntl_waitpid') && McpBackgroundCalls::directory() !== null;
        $figure = McpBackgroundCalls::DEFAULT_AFTER_SECONDS;

        self::assertSame($capable, McpBackgroundCalls::armed($figure, false, true, false), 'with every door open the answer is the machine\'s own, never an assumption about it');
        self::assertFalse(McpBackgroundCalls::armed($figure, false, false, false), 'no beat, no fork: a call nobody waits on keeps the inline path');
        self::assertFalse(McpBackgroundCalls::armed($figure, false, true, true), 'a call holding the turn\'s ask channel or live tail is never forked away from it');
        self::assertFalse(McpBackgroundCalls::armed(0.0, false, true, false), 'zero is the pre-feature shape and disables');
        self::assertFalse(McpBackgroundCalls::armed(-1.0, false, true, false), 'nonsense disables rather than backgrounding everything instantly');
        self::assertFalse(McpBackgroundCalls::armed($figure, true, true, false), 'the -p latch outranks a live threshold, and re-arming cannot un-close it');

        $doors = self::sliceFunctionBody('McpBackgroundCalls.php', 'public static function armed(');
        foreach (['$turnDriven', '!$serverRequestChannelsBound', '$afterSeconds > 0.0', '!$latchedNonInteractive', 'pcntl_fork', 'pcntl_waitpid', 'self::directory()'] as $door) {
            self::assertStringContainsString($door, $doors, 'every door is named at the one decision point');
        }
    }

    /**
     * Round-1 CRITICAL, pinned as behavior: the fork copies the turn's
     * closures, so a backgrounded call that was bound an approver could put no
     * question to the human and a backgrounded call that was bound the live
     * tail would write frames to nobody. The remedy taken is the cheap honest
     * one — such a call is never backgrounded — which means the C-MCP-3.b
     * contract (an elicitation reaches the turn's own approver, in the process
     * that owns the answer) holds even for a call the threshold would
     * otherwise release.
     *
     * RED under reversion (drop the channel door: the bound legs fork, the
     * approver is never called, the frames never land). RED under the opposite
     * choice (refuse the hand-off for everything: the control leg stops
     * producing a job). One test, both directions.
     */
    public function testACallHoldingTheTurnsChannelsBlocksWhileACallHoldingNeitherHandsOff(): void
    {
        $question = 'tmcpsev' . uniqid();

        $asked = [];
        $approver = static function (ToolCall $call, HookResult $ask) use (&$asked): ApprovalVerdict {
            $asked[] = $call;

            return ApprovalVerdict::rejectedByUser('the human answered no');
        };
        $asking = $this->bridgeWith($this->serverAskingTheHuman($this->descriptor(), $question))
            ->withBackgroundAfter(self::THRESHOLD)
            ->withPermissionApprover($approver);

        $result = $asking->executeWithHeartbeat(['id' => 'call_ask'], static function (): void {
        });

        self::assertCount(1, $asked, 'the question reached the approver the turn bound, in the process that can answer it');
        self::assertSame(AskUserTool::NAME, $asked[0]->name());
        self::assertStringContainsString($question, (string) $asked[0]->arguments()['question']);
        self::assertStringNotContainsString('still running as background MCP job', $result->content(), 'an ask-capable call is never handed off');
        self::assertSame([], McpBackgroundCalls::pendingChildren());

        $tailed = [];
        $emitter = static function (ToolProgress $frame) use (&$tailed): void {
            $tailed[] = $frame;
        };
        $streaming = $this->bridgeWith($this->serverPushingProgress($this->descriptor()))
            ->withBackgroundAfter(self::THRESHOLD)
            ->withToolProgressEmitter($emitter);

        $streamed = $streaming->executeWithHeartbeat(['id' => 'call_tail'], static function (): void {
        });

        self::assertCount(3, $tailed, 'the tail frames arrived in this process');
        self::assertStringNotContainsString('still running as background MCP job', $streamed->content(), 'a tail-bound call is never handed off either');
        self::assertSame([], McpBackgroundCalls::pendingChildren());

        $control = $this->bridgingSlowCall('eventually answers');
        $released = $control->executeWithHeartbeat(['id' => 'call_control'], static function (): void {
        });
        self::assertStringContainsString('still running as background MCP job', $released->content(), 'the call holding neither channel still gets the feature — this is not a revert dressed as a door');
        self::assertCount(1, McpBackgroundCalls::pendingChildren());
        self::assertTrue($this->waitUntilPublished());
        self::assertCount(1, McpBackgroundCalls::takeFinished());
        self::assertTrue($this->waitUntilQuiet());
    }

    /**
     * The behavior half of the beat door: an armed tool, driven the way a
     * non-turn caller drives it, must never fork. Red the moment the turn
     * argument is dropped from either call site.
     */
    public function testAnArmedToolCalledWithoutATurnBeatNeverForks(): void
    {
        $bridge = $this->bridgeWith($this->slowServer('inline answer', $this->descriptor(), sleep: 0.0))
            ->withBackgroundAfter(self::THRESHOLD);

        $result = $bridge->execute(['id' => 'call_nobeat']);

        self::assertStringContainsString('inline answer', $result->content());
        self::assertStringNotContainsString('background MCP job', $result->content(), 'no beat means no turn is waiting, so there is nothing to release');
        self::assertSame([], McpBackgroundCalls::pendingChildren());
        self::assertDirectoryDoesNotExist($this->registryDir(), 'and no registry is even created');
    }

    /**
     * The `mcp_call` half of the progress sink shares
     * {@see McpToolBridge::progressSink()} with the bridge, and the three
     * shapes that make up a tail were pinned for the bridge spelling only: a
     * blank `message` falling back to the server's own pacing, the percent
     * form's one-decimal floor, and the tail clamped to its budget from the
     * left. Two spellings of one capability owe the same three shapes.
     */
    public function testTheDispatchedSpellingRendersProgressInTheSiblingShapes(): void
    {
        $descriptor = new McpTool(name: 'ping', description: str_repeat('a', 3000), inputSchema: [], serverName: 'srv');
        $client = $this->clientHolding($this->serverPushingProgress($descriptor), $descriptor);
        $shape = McpToolShape::derive($client, [$descriptor], [], 10);

        /** @var list<ToolProgress> $frames */
        $frames = [];
        $tool = (new McpCallTool($shape))->withToolProgressEmitter(static function (ToolProgress $frame) use (&$frames): void {
            $frames[] = $frame;
        });

        $result = $tool->execute(['id' => 'call_prog', 'server' => 'srv', 'tool' => 'ping', 'arguments' => []]);

        self::assertFalse($result->isError());
        self::assertCount(3, $frames, 'one frame per notification the server pushed');
        self::assertSame([1, 2, 3], array_map(static fn (ToolProgress $f): int => $f->seq, $frames), 'seq counts from one, per call');
        self::assertSame('call_prog', $frames[0]->toolCallId, 'the dispatched row keys its frames by the call id, as the bridge does');
        self::assertSame(McpCallTool::NAME, $frames[0]->toolName);

        self::assertSame('33.3%', $frames[0]->tail, 'a whitespace message is no message: the server\'s own pacing speaks');
        self::assertFalse($frames[0]->truncated);
        self::assertSame('0.1', $frames[1]->tail, 'no total, so the bare figure, at the one-decimal floor with trailing zeros trimmed');
        self::assertSame(McpToolBridge::PROGRESS_TAIL_BUDGET, strlen($frames[2]->tail), 'an over-budget message keeps its TAIL at exactly the budget');
        self::assertTrue($frames[2]->truncated, 'and says so');
    }

    // =========================================================================
    // Harness
    // =========================================================================

    private function descriptor(): McpTool
    {
        return new McpTool(name: 'ping', description: 'drive me', inputSchema: [], serverName: 'srv');
    }

    /** @param McpServer $server */
    private function clientHolding(McpServer $server, McpTool $descriptor): McpClient
    {
        $client = new McpClient('/nonexistent/mcp-background/' . uniqid('c', true) . '.json', unrestricted: true);
        $servers = new \ReflectionProperty($client, 'servers');
        $servers->setAccessible(true);
        $servers->setValue($client, [$descriptor->serverName => $server]);

        return $client;
    }

    /**
     * A fake whose callTool sleeps $sleep seconds and then answers $text.
     * The sleep is the WHOLE simulation: the child forked by await() carries
     * this closure's process, wakes, publishes, and leaves through exitNow.
     */
    private function slowServer(string $text, McpTool $descriptor, ?float $sleep = null): McpServer
    {
        $sleep ??= self::BACKGROUND_CALL_SLEEP_SECONDS;

        return new class ($descriptor, $text, $sleep) implements McpServer {
            public function __construct(
                private readonly McpTool $tool,
                private readonly string $text,
                private readonly float $sleep,
            ) {
            }

            public function start(): void
            {
            }

            public function stop(): void
            {
            }

            public function listTools(): array
            {
                return [$this->tool];
            }

            public function callTool(string $toolName, array $args): array
            {
                if ($this->sleep > 0.0) {
                    usleep((int) round($this->sleep * 1_000_000));
                }

                return ['content' => [['type' => 'text', 'text' => $this->text]]];
            }
        };
    }

    /**
     * A server that puts a question to the human mid-call before answering —
     * the `elicitation/create` shape C-MCP-3.b built the per-call desk for.
     */
    private function serverAskingTheHuman(McpTool $descriptor, string $question): McpServer
    {
        return new class ($descriptor, $question) implements McpServer, AnswersServerRequests {
            public function __construct(private readonly McpTool $tool, private readonly string $question)
            {
            }

            public function start(): void
            {
            }

            public function stop(): void
            {
            }

            public function listTools(): array
            {
                return [$this->tool];
            }

            public function callTool(string $toolName, array $args): array
            {
                return ['content' => [['type' => 'text', 'text' => 'answered without a desk']]];
            }

            public function callToolAnsweringRequests(string $toolName, array $args, ?\Closure $onWait = null, ?McpServerRequests $serverRequests = null): array
            {
                $reply = $serverRequests === null ? null : $serverRequests->handle('elicitation/create', [
                    'requestedSchema' => [
                        'type' => 'object',
                        'properties' => ['note' => ['type' => 'string', 'description' => $this->question]],
                        'required' => ['note'],
                    ],
                ], false);

                return ['content' => [['type' => 'text', 'text' => 'answered after asking: ' . json_encode($reply['result'] ?? $reply['error'] ?? null, JSON_THROW_ON_ERROR)]]];
            }
        };
    }

    /**
     * A fake that pushes the three progress shapes the bridge sink is made of
     * and then answers: a blank message over a ratio, a bare figure with no
     * total, and a message well past the tail budget.
     */
    private function serverPushingProgress(McpTool $descriptor): McpServer
    {
        return new class ($descriptor) implements McpServer, AnswersServerRequests {
            public function __construct(private readonly McpTool $tool)
            {
            }

            public function start(): void
            {
            }

            public function stop(): void
            {
            }

            public function listTools(): array
            {
                return [$this->tool];
            }

            public function callTool(string $toolName, array $args): array
            {
                return ['content' => [['type' => 'text', 'text' => 'answered without a desk']]];
            }

            public function callToolAnsweringRequests(string $toolName, array $args, ?\Closure $onWait = null, ?McpServerRequests $serverRequests = null): array
            {
                if ($serverRequests !== null) {
                    $serverRequests->handle(McpServerRequests::PROGRESS_METHOD, ['progress' => 1, 'total' => 3, 'message' => '   '], true);
                    $serverRequests->handle(McpServerRequests::PROGRESS_METHOD, ['progress' => 0.05], true);
                    $serverRequests->handle(McpServerRequests::PROGRESS_METHOD, [
                        'progress' => 2,
                        'message' => str_repeat('z', McpToolBridge::PROGRESS_TAIL_BUDGET + 400),
                    ], true);
                }

                return ['content' => [['type' => 'text', 'text' => 'answered with a desk']]];
            }
        };
    }

    /**
     * The window of the production arming: the C-MCP-3.d arm of the turn's
     * tool bind, from its own comment to the `continue` that ends the case.
     */
    private static function sliceTurnArmingWindow(): string
    {
        $src = (string) file_get_contents(__DIR__ . '/../../src/Backend/EngineBackend.php');
        $start = strpos($src, 'C-MCP-3.d: a turn-bound MCP call');
        self::assertNotFalse($start, 'fixture: the turn bind still carries the C-MCP-3.d arm at all');
        $end = strpos($src, 'continue;', $start);
        self::assertNotFalse($end, 'fixture: the arm still ends in the bind\'s own continue');

        return substr($src, $start, $end - $start);
    }

    /**
     * One method's own text, signature to matching brace, from a file in
     * src/MCP. Structural pins read the decision itself, so a rewrite that
     * keeps the behavior and drops a door still has to answer for it.
     */
    private static function sliceFunctionBody(string $file, string $signature): string
    {
        $src = (string) file_get_contents(__DIR__ . '/../../src/MCP/' . $file);
        $start = strpos($src, $signature);
        self::assertNotFalse($start, 'fixture: ' . $file . ' still declares ' . $signature);
        $open = strpos($src, '{', $start);
        self::assertNotFalse($open, 'fixture: the method still has a body');

        $depth = 0;
        $length = strlen($src);
        for ($i = $open; $i < $length; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $start, $i + 1 - $start);
                }
            }
        }

        self::fail('fixture: unbalanced braces reading ' . $signature . ' in ' . $file);
    }

    private function bridgeWith(McpServer $server, ?McpTool $descriptor = null): McpToolBridge
    {
        $descriptor ??= $this->descriptor();

        return new McpToolBridge($this->clientHolding($server, $descriptor), $descriptor);
    }

    private function bridgingSlowCall(string $text): McpToolBridge
    {
        return $this->bridgeWith($this->slowServer($text, $this->descriptor()))->withBackgroundAfter(self::THRESHOLD);
    }

    private function registryDir(): string
    {
        $home = HomeDirectory::owned();
        self::assertNotNull($home, 'fixture: the sandbox must expose an owned home');

        return rtrim($home, '/') . '/.candy-code/' . McpBackgroundCalls::RELATIVE_DIR;
    }

    /** Bounded poll until the child publishes; budgets, never assertion bases. */
    private function waitUntilPublished(float $budgetSeconds = 5.0): bool
    {
        $deadline = hrtime(true) + (int) round($budgetSeconds * 1_000_000_000);
        while (hrtime(true) < $deadline) {
            if (McpBackgroundCalls::hasFinished()) {
                return true;
            }
            usleep(10_000);
        }

        return McpBackgroundCalls::hasFinished();
    }

    /** Bounded poll until this process has no unreaped background child. */
    private function waitUntilQuiet(float $budgetSeconds = 5.0): bool
    {
        $deadline = hrtime(true) + (int) round($budgetSeconds * 1_000_000_000);
        while (hrtime(true) < $deadline) {
            McpBackgroundCalls::takeFinished();
            if (McpBackgroundCalls::pendingChildren() === []) {
                return true;
            }
            usleep(10_000);
        }

        return McpBackgroundCalls::pendingChildren() === [];
    }

    private function reap(int $pid): void
    {
        $deadline = hrtime(true) + 3_000_000_000;
        while (hrtime(true) < $deadline) {
            $status = 0;
            $gone = @pcntl_waitpid($pid, $status, WNOHANG);
            if ($gone === $pid) {
                return;
            }
            if ($gone === -1) {
                return; // already reaped (ECHILD)
            }
            usleep(10_000);
        }

        if (\function_exists('posix_kill')) {
            @posix_kill($pid, SIGKILL);
        }
        $status = 0;
        @pcntl_waitpid($pid, $status);
    }
}
