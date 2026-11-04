<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Hooks;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Config\StatusLineCommand;
use SugarCraft\Code\Hooks\HookContext;
use SugarCraft\Code\Hooks\HookEvent;
use SugarCraft\Code\Hooks\HookManager;
use SugarCraft\Code\Hooks\HookRegistry;
use SugarCraft\Code\Hooks\HookResult;
use SugarCraft\Code\Hooks\ScriptHook;
use SugarCraft\Code\Support\DrainsStreamedChild;

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
     * PHP-m1 (G1-L5): THE PUMP IS SHARED, THE POLICY IS NOT.
     *
     * {@see ScriptHook::drain()} and `StatusLineCommand::drain()` now delegate
     * the whole `stream_select()` walk to one class, so what each file owns is
     * what it does with the bytes. The hook's ceiling TRIMS EACH STREAM and
     * keeps reading to EOF; a cap shared across the buffer would let stdout
     * take the whole budget and return stderr empty, which is exactly the
     * merge these two copies could have taken. 130 × 8 KiB down BOTH pipes, so
     * each stream answers with its own kept window and its own stamp naming its
     * own refused tail.
     */
    public function testTheSharedWalkerStillTrimsEachStreamSeparately(): void
    {
        $hook = new ScriptHook('two_caps', HookEvent::PreToolUse, '.*', 'true', '');
        $drain = new \ReflectionMethod($hook, 'drain');

        $source = 'for ($i = 0; $i < 130; ++$i) { echo str_repeat("A", 8192); }'
            . ' for ($i = 0; $i < 130; ++$i) { fwrite(STDERR, str_repeat("B", 8192)); }';
        $pipes = [];
        $proc = proc_open(
            [PHP_BINARY, '-r', $source],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);

        try {
            [$stdout, $stderr, $timedOut] = $drain->invoke($hook, $pipes[1], $pipes[2], microtime(true) + 20.0);
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
        }

        $marker = ' … [hook output capped at 1048576 bytes; 16384 further bytes dropped]';
        $this->assertFalse($timedOut, 'neither ceiling is a deadline and neither may report one');
        $this->assertStringStartsWith(str_repeat('A', 8192), $stdout);
        $this->assertStringStartsWith(str_repeat('B', 8192), $stderr);
        $this->assertStringEndsWith($marker, $stdout);
        $this->assertStringEndsWith($marker, $stderr);
        $this->assertSame(
            2 * (1048576 + \strlen($marker)),
            \strlen($stdout) + \strlen($stderr),
            'each stream gets the full cap — one budget shared by both is the wrong policy',
        );
    }

    /**
     * PHP-m1 (G1-L5): ONE PUMP, STRUCTURALLY. Both callers route through
     * {@see DrainsStreamedChild::walk()} and neither keeps a select loop of its
     * own — the finding was copies of the same walk drifting apart, and a
     * re-spread is silent otherwise. The `@` prefix is what separates the CALL
     * from the prose, which quotes `stream_select()` in backticks on purpose.
     */
    public function testBothDrainCallersDelegateAndNeitherKeepsASelectLoop(): void
    {
        $sources = [
            'ScriptHook' => (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Hooks/ScriptHook.php'),
            'StatusLineCommand' => (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Config/StatusLineCommand.php'),
        ];

        foreach ($sources as $label => $source) {
            $this->assertStringContainsString(
                'DrainsStreamedChild::walk(',
                $source,
                \sprintf('%s must route its drain through the shared walker', $label),
            );
            $this->assertStringNotContainsString(
                '@stream_select(',
                $source,
                \sprintf('%s re-grew its own select loop', $label),
            );
        }

        $walker = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Support/DrainsStreamedChild.php');
        $this->assertSame(
            1,
            substr_count($walker, '@stream_select('),
            'the walker holds exactly one select, or the fold has become another copy',
        );
    }

    /**
     * PHP-m1 (G1-L5): the retry budget lives in ONE class. ScriptHook keeps the
     * name because docs/HOOKS.md states the figure and
     * `DocFigureProseDriftTest::testHookDrainSweepAndMarkerSentencesSurviveTheirSources()`
     * derives that sentence from this constant — so the name is an alias, not a
     * second copy — and StatusLineCommand's copy is gone, bounded by the
     * walker's default.
     */
    public function testTheRetryBudgetLivesInExactlyOneClass(): void
    {
        $budget = (int) (new \ReflectionClassConstant(ScriptHook::class, 'DRAIN_SELECT_RETRIES'))->getValue();

        $this->assertSame(DrainsStreamedChild::SELECT_RETRIES, $budget, 'the rostered name must track the walker');
        $this->assertSame(128, $budget, 'the figure docs/HOOKS.md states in prose');
        $this->assertFalse(
            (new \ReflectionClass(StatusLineCommand::class))->getReflectionConstant('DRAIN_SELECT_RETRIES'),
            'the second copy of the figure came back',
        );
    }

    /**
     * PHP-m1 (G1-L5): interleaved streams, EOF mid-line, and the clean-close
     * flag — the shapes the shared walker used to walk twice, pinned as BYTES.
     * Two streams ping-ponged line by line so the per-slot buffers can only
     * come out right if slot 0 never saw a slot 1 chunk and vice versa; the
     * stdout tail deliberately has no newline, because an EOF that lands
     * mid-line is the shape a trim-on-newline rewrite would silently eat; and
     * the flag must read FALSE — the child closed both pipes itself, so the
     * walk ended CLOSED, and neither the cap nor the deadline had a say.
     */
    public function testInterleavedStreamsAndAMidLineEofDrainToTheExactPerSlotBytes(): void
    {
        $hook = new ScriptHook('interleave', HookEvent::PreToolUse, '.*', 'true', '');
        $drain = new \ReflectionMethod($hook, 'drain');

        $source = 'for ($i = 1; $i <= 4; ++$i) { echo "o" . $i; fflush(STDOUT); fwrite(STDERR, "e" . $i); fflush(STDERR); }'
            . ' echo "no-newline-tail";';
        $pipes = [];
        $proc = proc_open(
            [PHP_BINARY, '-r', $source],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);

        try {
            [$stdout, $stderr, $timedOut] = $drain->invoke($hook, $pipes[1], $pipes[2], microtime(true) + 20.0);
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
        }

        $this->assertSame('o1o2o3o4no-newline-tail', $stdout, 'stdout carried a stderr chunk or lost the mid-line tail');
        $this->assertSame('e1e2e3e4', $stderr, 'the other direction');
        $this->assertFalse($timedOut, 'a child that closed both pipes finished — nothing timed out');
    }

    /**
     * PHP-m1 (G1-L5): the DEADLINE leg, with its sweep, reported as the hook
     * has always reported it. The child writes its bytes at once and then
     * sits on the pipes for longer than the 0.15 s budget, so either the
     * first iteration reads "swept" or the deadline exit sweeps it out of
     * the kernel buffer — both routes must land the same triple, and the
     * third element must be TRUE because it was the budget, not the child,
     * that ended the walk. The terminate in the finally is the child's
     * teardown; the drain's own stdin contract is untouched here ($stdin
     * stays null, so no close obligation existed or was taken).
     */
    public function testTheDeadlinePathSweepsTheBufferedBytesAndReportsTimedOut(): void
    {
        $hook = new ScriptHook('sweep', HookEvent::PreToolUse, '.*', 'true', '');
        $drain = new \ReflectionMethod($hook, 'drain');

        $pipes = [];
        $proc = proc_open(
            [PHP_BINARY, '-r', 'echo "swept"; fflush(STDOUT); usleep(3_000_000);'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);

        try {
            [$stdout, $stderr, $timedOut] = $drain->invoke($hook, $pipes[1], $pipes[2], microtime(true) + 0.15);
        } finally {
            proc_terminate($proc);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
        }

        $this->assertSame('swept', $stdout, 'the deadline exit lost the bytes that were already there');
        $this->assertSame('', $stderr);
        $this->assertTrue($timedOut, 'the budget ended this walk and the caller must be told so it kills');
    }
}
