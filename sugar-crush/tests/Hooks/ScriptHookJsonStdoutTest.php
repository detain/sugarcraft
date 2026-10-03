<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Hooks;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Hooks\ScriptHook;

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
}
