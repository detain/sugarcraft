<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Hooks;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Async\ChildPump;
use SugarCraft\Async\ChildResult;
use SugarCraft\Code\Config\StatusLineCommand;
use SugarCraft\Code\Hooks\HookContext;
use SugarCraft\Code\Hooks\HookEvent;
use SugarCraft\Code\Hooks\HookManager;
use SugarCraft\Code\Hooks\HookRegistry;
use SugarCraft\Code\Hooks\HookResult;
use SugarCraft\Code\Hooks\ScriptHook;
use SugarCraft\Code\Support\DrainsStreamedChild;
use SugarCraft\Code\Support\ProcessContainment;

/**
 * Step 3.D-1: an exit-0 script hook may print a Claude Code-shaped JSON
 * envelope (`decision`, `reason`, `additionalContext`, `updatedInput`,
 * `continue`/`stopReason`, the same keys under `hookSpecificOutput`) instead
 * of a plain note — and a stdout that is NOT an envelope stays a note, byte for
 * byte, so a hook printing JSON-as-context keeps reaching the model unchanged.
 *
 * @see ScriptHook
 * @see HookResult::stop()
 */
final class ScriptHookJsonStdoutTest extends TestCase
{
    public function testAnObjectWithNoKnownKeyStaysANote(): void
    {
        $result = $this->runHook('{"errors":[{"line":3}]}');

        $this->assertTrue($result->isAllowed());
        $this->assertSame('{"errors":[{"line":3}]}', $result->additionalContext);
        $this->assertNull($result->modifiedInput);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notAnEnvelope(): iterable
    {
        yield 'a JSON list' => ['[{"decision":"deny"}]'];
        yield 'JSON after other text' => ['lint: {"decision":"deny"}'];
        yield 'text after JSON' => ['{"decision":"deny"} trailing'];
        yield 'broken JSON' => ['{"decision":"deny"'];
    }

    #[DataProvider('notAnEnvelope')]
    public function testAnythingButAWholeObjectStaysANote(string $stdout): void
    {
        $result = $this->runHook($stdout);

        $this->assertTrue($result->isAllowed(), 'only a whole-stdout JSON object may steer the verdict');
        $this->assertSame($stdout, $result->additionalContext);
    }

    public function testAnAllowEnvelopeCarriesItsAdditionalContextAndNotItsJson(): void
    {
        $result = $this->runHook('{"decision":"approve","additionalContext":"3 warnings in Foo.php"}');

        $this->assertTrue($result->isAllowed());
        $this->assertSame('', $result->message);
        $this->assertSame('3 warnings in Foo.php', $result->additionalContext);
    }

    public function testHookSpecificOutputAdditionalContextIsTheNote(): void
    {
        $result = $this->runHook('{"hookSpecificOutput":{"hookEventName":"PostToolUse","additionalContext":"nested note"}}', HookEvent::PostToolUse);

        $this->assertTrue($result->isAllowed());
        $this->assertSame('nested note', $result->additionalContext);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusingDecisions(): iterable
    {
        yield 'deny' => ['deny'];
        yield 'block (Claude Code\'s older spelling)' => ['block'];
        yield 'case-insensitive' => ['DENY'];
    }

    #[DataProvider('refusingDecisions')]
    public function testARefusingDecisionDeniesWithItsReason(string $decision): void
    {
        $result = $this->runHook(json_encode(['decision' => $decision, 'reason' => 'no writes to vendor/', 'additionalContext' => 'dropped']));

        $this->assertTrue($result->isDenied());
        $this->assertFalse($result->permitsExecution());
        $this->assertSame('no writes to vendor/', $result->message);
        $this->assertSame('', $result->additionalContext, 'a refusal carries no note, exactly as exit 2 does');
        $this->assertFalse($result->haltsTurn());
    }

    public function testARefusalWithNoReasonStillSaysWhoRefused(): void
    {
        $result = $this->runHook('{"decision":"deny"}');

        $this->assertTrue($result->isDenied());
        $this->assertSame('Hook json-hook blocked the call', $result->message);
    }

    public function testPermissionDecisionUnderHookSpecificOutputWinsOverTheTopLevelSpelling(): void
    {
        $result = $this->runHook(json_encode([
            'decision' => 'allow',
            'reason' => 'top-level',
            'hookSpecificOutput' => [
                'hookEventName' => 'PreToolUse',
                'permissionDecision' => 'deny',
                'permissionDecisionReason' => 'nested wins',
            ],
        ]));

        $this->assertTrue($result->isDenied());
        $this->assertSame('nested wins', $result->message);
    }

    public function testAskPutsTheReasonAndCarriesTheRewriteAsAProposal(): void
    {
        $result = $this->runHook(json_encode([
            'decision' => 'ask',
            'reason' => 'Touches prod config - run it?',
            'updatedInput' => ['command' => 'make deploy DRY_RUN=1'],
            'additionalContext' => 'target is staging',
        ]));

        $this->assertTrue($result->isAsk());
        $this->assertSame('Touches prod config - run it?', $result->message);
        $this->assertSame(['command' => 'make deploy DRY_RUN=1'], $result->rewrittenArgs());
        $this->assertSame('target is staging', $result->additionalContext);
    }

    public function testAskWithNoReasonFallsBackToTheDescription(): void
    {
        $result = $this->runHook('{"decision":"ask"}', description: 'Confirm deploys');

        $this->assertTrue($result->isAsk());
        $this->assertSame('Confirm deploys', $result->message);
    }

    public function testUpdatedInputIsAModifyThatKeepsItsNote(): void
    {
        $result = $this->runHook('{"updatedInput":{"command":"ls -la","timeout":5},"additionalContext":"widened ls"}');

        $this->assertTrue($result->isModified());
        $this->assertTrue($result->permitsExecution());
        $this->assertSame(['command' => 'ls -la', 'timeout' => 5], $result->rewrittenArgs());
        $this->assertSame('widened ls', $result->additionalContext);
    }

    public function testAnEmptyObjectRewriteIsHonouredAsNoArguments(): void
    {
        $result = $this->runHook('{"hookSpecificOutput":{"updatedInput":{}}}');

        $this->assertTrue($result->isModified());
        $this->assertSame('{}', $result->modifiedInput);
    }

    public function testARewriteOverTheCeilingIsRefusedNotTruncated(): void
    {
        $result = $this->runHook(json_encode(['updatedInput' => ['content' => str_repeat('x', 20000)]]));

        $this->assertTrue($result->isDenied());
        $this->assertStringContainsString('ceiling', $result->message);
    }

    public function testContinueFalseRefusesTheCallAndAsksTheRunToStop(): void
    {
        $result = $this->runHook('{"continue":false,"stopReason":"Build is red; stop and report.","decision":"allow"}');

        $this->assertTrue($result->isDenied(), 'stopping the run cannot be weaker than refusing the call it was raised on');
        $this->assertTrue($result->haltsTurn());
        $this->assertSame('Build is red; stop and report.', $result->stopReason);
        $this->assertSame('Build is red; stop and report.', $result->message);
    }

    public function testContinueTrueIsAnOrdinaryAllow(): void
    {
        $result = $this->runHook('{"continue":true}');

        $this->assertTrue($result->isAllowed());
        $this->assertFalse($result->haltsTurn());
        $this->assertSame('', $result->additionalContext);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function malformedEnvelopes(): iterable
    {
        yield 'unknown decision' => ['{"decision":"maybe"}', '"decision" must be one of'];
        yield 'continue as a string' => ['{"continue":"no"}', '"continue" must be a boolean'];
        yield 'reason as a number' => ['{"decision":"deny","reason":42}', '"reason" must be a string'];
        yield 'note as an object' => ['{"additionalContext":{"a":1}}', '"additionalContext" must be a string'];
        yield 'updatedInput as a list' => ['{"updatedInput":["rm","-rf","/"]}', '"updatedInput" must be a JSON object'];
        yield 'hookSpecificOutput as a string' => ['{"hookSpecificOutput":"x"}', '"hookSpecificOutput" must be an object'];
    }

    #[DataProvider('malformedEnvelopes')]
    public function testAnEnvelopeThatCannotBeHonouredFailsClosed(string $stdout, string $why): void
    {
        $result = $this->runHook($stdout);

        $this->assertFalse($result->permitsExecution(), 'a hook that meant to steer the call has not approved it');
        $this->assertStringContainsString($why, $result->message);
    }

    public function testOnlyExitZeroIsParsedSoABlockingExitKeepsItsStderrReason(): void
    {
        $hook = $this->hook('printf \'{"decision":"allow"}\'; echo "real reason" >&2; exit 2');

        $result = $hook->execute($this->context());

        $this->assertTrue($result->isDenied());
        $this->assertSame('real reason', $result->message);
    }

    /**
     * The stop flag has to survive the chain: {@see HookRegistry::executeHooks()}
     * returns a refusal verbatim and stamps who refused, and both have to land
     * on the verdict the gate reads.
     */
    public function testTheStopFlagSurvivesTheLiveChain(): void
    {
        $manager = new HookManager(new HookRegistry());
        $manager->register($this->hook('printf \'{"continue":false,"stopReason":"halt"}\''));

        $result = $manager->preToolUse($this->context());

        $this->assertTrue($result->haltsTurn());
        $this->assertSame('halt', $result->stopReason);
        $this->assertSame('json-hook', $result->refusingHook());
    }

    private function runHook(string $stdout, HookEvent $event = HookEvent::PreToolUse, string $description = ''): HookResult
    {
        return $this->hook('printf %s ' . escapeshellarg($stdout), $event, $description)->execute($this->context());
    }

    private function hook(string $command, HookEvent $event = HookEvent::PreToolUse, string $description = ''): ScriptHook
    {
        return new ScriptHook(
            name: 'json-hook',
            event: $event,
            matcher: '.*',
            command: $command,
            description: $description,
        );
    }

    private function context(): HookContext
    {
        return new HookContext(
            sessionId: 'session',
            toolName: 'Bash',
            toolArgs: ['command' => 'ls'],
            toolInput: '{"command":"ls"}',
            toolOutput: '',
            model: 'm',
            provider: 'p',
            projectRoot: sys_get_temp_dir(),
        );
    }

    /**
     * Run a PHP child through ScriptHook's own ChildRun wiring
     * ({@see ScriptHook::runChild()}), returning the result and the two
     * streams as the verdict sees them (ChildRun's retained head plus the
     * {@see ScriptHook::capped()} stamp).
     *
     * @return array{0: string, 1: string, 2: ChildResult}
     */
    private function runThroughChildRun(string $source, float $budget = 20.0): array
    {
        $hook = new ScriptHook('child_run', HookEvent::PreToolUse, '.*', 'true', '');
        $run = (new \ReflectionMethod($hook, 'runChild'))->invoke(
            $hook,
            [PHP_BINARY, '-r', $source],
            null,
            ProcessContainment::scrubbedEnv(),
            '',
            $budget,
        );
        $this->assertInstanceOf(ChildResult::class, $run, 'the child was refused a spawn');
        $capped = new \ReflectionMethod(ScriptHook::class, 'capped');

        return [
            $capped->invoke(null, $run->stdout, $run->droppedBytes - $run->droppedStderrBytes),
            $capped->invoke(null, $run->stderr, $run->droppedStderrBytes),
            $run,
        ];
    }

    /**
     * CC-1 (ChildRun): THE CEILING TRIMS EACH STREAM, not the pair. A cap
     * shared across both buffers would let stdout take the whole budget and
     * return stderr empty. 1,026 x 8 KiB down BOTH pipes — the 8 MiB ceiling
     * plus two reads — so each stream answers with its own kept window and its
     * own stamp naming its own refused tail.
     */
    public function testTheChildRunCeilingStillTrimsEachStreamSeparately(): void
    {
        $source = '$s = str_repeat("A", 8192); for ($i = 0; $i < 1026; ++$i) { fwrite(STDOUT, $s); }'
            . ' $s = str_repeat("B", 8192); for ($i = 0; $i < 1026; ++$i) { fwrite(STDERR, $s); }';

        [$stdout, $stderr, $run] = $this->runThroughChildRun($source);

        $marker = ' … [hook output capped at 8388608 bytes; 16384 further bytes dropped]';
        $this->assertFalse($run->timedOut(), 'neither ceiling is a deadline and neither may report one');
        $this->assertStringStartsWith(str_repeat('A', 8192), $stdout);
        $this->assertStringStartsWith(str_repeat('B', 8192), $stderr);
        $this->assertStringEndsWith($marker, $stdout);
        $this->assertStringEndsWith($marker, $stderr);
        $this->assertSame(
            2 * (8388608 + \strlen($marker)),
            \strlen($stdout) + \strlen($stderr),
            'each stream gets the full cap — one budget shared by both is the wrong policy',
        );
    }

    /**
     * CC-1 (ChildRun): ScriptHook runs its child on ChildRun and keeps no pump
     * of its own — no select loop, no `proc_open()`, no shared walker. The
     * walker stays for StatusLineCommand, its one remaining caller, and holds
     * exactly one select. The `@` prefix separates the CALL from the prose.
     */
    public function testTheHookRunsOnChildRunAndTheWalkerKeepsOnlyTheStatusLine(): void
    {
        $hook = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Hooks/ScriptHook.php');
        $this->assertStringContainsString('ChildRun::new(', $hook, 'ScriptHook must spawn through ChildRun');
        $this->assertStringContainsString('->run()', $hook, 'ScriptHook must wait through ChildRun::run()');
        $this->assertStringNotContainsString('DrainsStreamedChild', $hook, 'ScriptHook went back to the shared walker');
        $this->assertStringNotContainsString('@stream_select(', $hook, 'ScriptHook re-grew its own select loop');
        $this->assertStringNotContainsString('@proc_open(', $hook, 'ScriptHook re-grew its own spawn');

        $statusLine = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Config/StatusLineCommand.php');
        $this->assertStringContainsString('DrainsStreamedChild::walk(', $statusLine, 'StatusLineCommand must route its drain through the walker');
        $this->assertStringNotContainsString('@stream_select(', $statusLine, 'StatusLineCommand re-grew its own select loop');

        $walker = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Support/DrainsStreamedChild.php');
        $this->assertSame(
            1,
            substr_count($walker, '@stream_select('),
            'the walker holds exactly one select, or the fold has become another copy',
        );
    }

    /**
     * CC-1 (ChildRun): the select retry budget is the pump's own. ScriptHook's
     * alias for the walker's figure is gone with the walker call, ChildPump
     * rides a signal out on the same 128, and StatusLineCommand still keeps no
     * copy.
     */
    public function testTheRetryBudgetIsThePumpsAndNoHookCopySurvives(): void
    {
        $this->assertFalse(
            (new \ReflectionClass(ScriptHook::class))->getReflectionConstant('DRAIN_SELECT_RETRIES'),
            'ScriptHook kept a retry figure for a pump it no longer runs',
        );
        $this->assertSame(128, ChildPump::SELECT_RETRIES, 'the figure docs/HOOKS.md states in prose');
        $this->assertSame(DrainsStreamedChild::SELECT_RETRIES, ChildPump::SELECT_RETRIES, 'the walker and the pump ride a signal out alike');
        $this->assertFalse(
            (new \ReflectionClass(StatusLineCommand::class))->getReflectionConstant('DRAIN_SELECT_RETRIES'),
            'the second copy of the figure came back',
        );
    }

    /**
     * CC-1 (ChildRun): interleaved streams, EOF mid-line, and the clean-exit
     * status, pinned as BYTES. Two streams ping-ponged so the per-stream
     * buffers come out right only if neither saw the other's chunk; the stdout
     * tail has no newline, because an EOF that lands mid-line is the shape a
     * trim-on-newline rewrite would silently eat; and the run must read EXITED,
     * not timed out.
     */
    public function testInterleavedStreamsAndAMidLineEofArriveAsTheExactPerStreamBytes(): void
    {
        $source = 'for ($i = 1; $i <= 4; ++$i) { echo "o" . $i; fflush(STDOUT); fwrite(STDERR, "e" . $i); fflush(STDERR); }'
            . ' echo "no-newline-tail";';

        [$stdout, $stderr, $run] = $this->runThroughChildRun($source);

        $this->assertSame('o1o2o3o4no-newline-tail', $stdout, 'stdout carried a stderr chunk or lost the mid-line tail');
        $this->assertSame('e1e2e3e4', $stderr, 'the other direction');
        $this->assertSame(ChildResult::EXITED, $run->status, 'a child that exited on its own finished — nothing timed out');
        $this->assertSame(0, $run->exitCode);
    }

    /**
     * CC-1 (ChildRun): the DEADLINE leg. The child writes its bytes at once and
     * then sits on the pipes past the 0.15 s budget: what it already said
     * survives, and the run reports TIMED_OUT, because it was the budget, not
     * the child, that ended it — the status the hook's deny is built from.
     */
    public function testTheDeadlinePathKeepsTheBufferedBytesAndReportsTimedOut(): void
    {
        [$stdout, $stderr, $run] = $this->runThroughChildRun('echo "swept"; fflush(STDOUT); usleep(3_000_000);', 0.15);

        $this->assertSame('swept', $stdout, 'the deadline exit lost the bytes that were already there');
        $this->assertSame('', $stderr);
        $this->assertTrue($run->timedOut(), 'the budget ended this run and the hook must be told so it denies');
    }
}
