<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Hooks;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookConfig;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Hooks\HookResult;

/**
 * @see HookRegistry
 */
final class HookRegistryTest extends TestCase
{
    private HookRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new HookRegistry();
    }

    // =========================================================================
    // Registration Tests
    // =========================================================================

    public function testRegister(): void
    {
        $hook = $this->createHook('TestHook', HookEvent::PreToolUse, 'Read', '^Read$');

        $this->registry->register($hook);

        $this->assertNotNull($this->registry->get('PreToolUse', 'TestHook'));
        $this->assertSame($hook, $this->registry->get('PreToolUse', 'TestHook'));
    }

    public function testRegisterMultipleHooksForSameEvent(): void
    {
        $hook1 = $this->createHook('HookA', HookEvent::PreToolUse, 'Read', '^Read$');
        $hook2 = $this->createHook('HookB', HookEvent::PreToolUse, 'Write', '^Write$');

        $this->registry->register($hook1);
        $this->registry->register($hook2);

        $this->assertSame($hook1, $this->registry->get('PreToolUse', 'HookA'));
        $this->assertSame($hook2, $this->registry->get('PreToolUse', 'HookB'));
    }

    // =========================================================================
    // Unregistration Tests
    // =========================================================================

    public function testUnregister(): void
    {
        $hook = $this->createHook('ToRemove', HookEvent::PreToolUse, 'Read', '^Read$');

        $this->registry->register($hook);
        $this->assertNotNull($this->registry->get('PreToolUse', 'ToRemove'));

        $this->registry->unregister('ToRemove');

        $this->assertNull($this->registry->get('PreToolUse', 'ToRemove'));
    }

    public function testUnregisterRemovesFromAllEvents(): void
    {
        $preHook = $this->createHook('SharedName', HookEvent::PreToolUse, 'Read', '^Read$');
        $postHook = $this->createHook('SharedName', HookEvent::PostToolUse, 'Read', '^Read$');

        $this->registry->register($preHook);
        $this->registry->register($postHook);

        $this->registry->unregister('SharedName');

        $this->assertNull($this->registry->get('PreToolUse', 'SharedName'));
        $this->assertNull($this->registry->get('PostToolUse', 'SharedName'));
    }

    // =========================================================================
    // Retrieval Tests
    // =========================================================================

    public function testGet(): void
    {
        $hook = $this->createHook('GetTest', HookEvent::PreToolUse, 'Read', '^Read$');

        $this->registry->register($hook);

        $this->assertSame($hook, $this->registry->get('PreToolUse', 'GetTest'));
    }

    public function testGetReturnsNullForNonexistent(): void
    {
        $this->assertNull($this->registry->get('PreToolUse', 'DoesNotExist'));
    }

    public function testGetReturnsNullForNonexistentEvent(): void
    {
        $hook = $this->createHook('EventTest', HookEvent::PreToolUse, 'Read', '^Read$');
        $this->registry->register($hook);

        $this->assertNull($this->registry->get('PostToolUse', 'EventTest'));
    }

    public function testGetForEvent(): void
    {
        $hook1 = $this->createHook('Hook1', HookEvent::PreToolUse, 'Read', '^Read$');
        $hook2 = $this->createHook('Hook2', HookEvent::PreToolUse, 'Write', '^Write$');
        $hook3 = $this->createHook('Hook3', HookEvent::PostToolUse, 'Read', '^Read$');

        $this->registry->register($hook1);
        $this->registry->register($hook2);
        $this->registry->register($hook3);

        $preToolHooks = $this->registry->getForEvent('PreToolUse');

        $this->assertCount(2, $preToolHooks);
        $this->assertContains($hook1, $preToolHooks);
        $this->assertContains($hook2, $preToolHooks);
    }

    public function testGetForEventReturnsEmptyArrayForNonexistent(): void
    {
        $hooks = $this->registry->getForEvent('NonExistentEvent');

        $this->assertIsArray($hooks);
        $this->assertCount(0, $hooks);
    }

    // =========================================================================
    // Enable/Disable Tests
    // =========================================================================

    public function testDisable(): void
    {
        $this->assertFalse($this->registry->isDisabled('TestHook'));

        $this->registry->disable('TestHook');

        $this->assertTrue($this->registry->isDisabled('TestHook'));
    }

    public function testEnable(): void
    {
        $this->registry->disable('TestHook');
        $this->assertTrue($this->registry->isDisabled('TestHook'));

        $this->registry->enable('TestHook');

        $this->assertFalse($this->registry->isDisabled('TestHook'));
    }

    public function testIsDisabled(): void
    {
        $this->assertFalse($this->registry->isDisabled('NeverDisabled'));

        $this->registry->disable('NeverDisabled');

        $this->assertTrue($this->registry->isDisabled('NeverDisabled'));

        $this->registry->enable('NeverDisabled');

        $this->assertFalse($this->registry->isDisabled('NeverDisabled'));
    }

    public function testDisableNonexistentHookDoesNotError(): void
    {
        $this->registry->disable('NeverRegistered');
        $this->assertTrue($this->registry->isDisabled('NeverRegistered'));
    }

    // =========================================================================
    // findMatches Tests
    // =========================================================================

    public function testFindMatches(): void
    {
        $hook = $this->createHook('ReadHook', HookEvent::PreToolUse, 'Read', '^Read$');
        $this->registry->register($hook);

        $matches = $this->registry->findMatches('PreToolUse', 'Read');

        $this->assertCount(1, $matches);
        $this->assertSame($hook, $matches[0]);
    }

    public function testFindMatchesWithRegexPattern(): void
    {
        $hook = $this->createHook('FileHook', HookEvent::PreToolUse, 'File.*', 'File(Read|Write)');
        $this->registry->register($hook);

        $matches = $this->registry->findMatches('PreToolUse', 'FileRead');
        $this->assertCount(1, $matches);

        $matches = $this->registry->findMatches('PreToolUse', 'FileWrite');
        $this->assertCount(1, $matches);

        $matches = $this->registry->findMatches('PreToolUse', 'FileDelete');
        $this->assertCount(0, $matches);
    }

    /**
     * The registry must MATCH under the delimiter {@see HookConfig::parse()}
     * VALIDATED under. Two spellings of that is the silent-registration bug
     * again: a matcher containing the other's delimiter either passes
     * validation and never fires, or stops the launch over a pattern this
     * method would have run happily.
     */
    public function testFindMatchesHonoursAMatcherContainingTheDefaultDelimiter(): void
    {
        $hook = $this->createHook('SlashHook', HookEvent::PreToolUse, 'Read|Write/Edit', 'Read|Write/Edit');
        $this->registry->register($hook);

        $this->assertCount(1, $this->registry->findMatches('PreToolUse', 'Write/Edit'));
        $this->assertCount(0, $this->registry->findMatches('PreToolUse', 'Bash'));
    }

    public function testFindMatchesIsCaseInsensitive(): void
    {
        $hook = $this->createHook('LowerHook', HookEvent::PreToolUse, 'read', '^read$');
        $this->registry->register($hook);

        $matches = $this->registry->findMatches('PreToolUse', 'READ');

        $this->assertCount(1, $matches);
    }

    public function testFindMatchesExcludesDisabled(): void
    {
        $hook = $this->createHook('DisabledHook', HookEvent::PreToolUse, 'Read', '^Read$');
        $this->registry->register($hook);
        $this->registry->disable('DisabledHook');

        $matches = $this->registry->findMatches('PreToolUse', 'Read');

        $this->assertCount(0, $matches);
    }

    public function testFindMatchesExcludesDisabledEvenWhenMultipleHooks(): void
    {
        $hook1 = $this->createHook('ActiveHook', HookEvent::PreToolUse, 'Read', '^Read$');
        $hook2 = $this->createHook('DisabledHook', HookEvent::PreToolUse, 'Read', '^Read$');
        $this->registry->register($hook1);
        $this->registry->register($hook2);
        $this->registry->disable('DisabledHook');

        $matches = $this->registry->findMatches('PreToolUse', 'Read');

        $this->assertCount(1, $matches);
        $this->assertSame($hook1, $matches[0]);
    }

    /**
     * A MATCHER THAT CANNOT BE EVALUATED MUST NOT READ AS "NO MATCH".
     *
     * `'(a+)+$'` compiles, so {@see \SugarCraft\Crush\Hooks\HookConfig::parse()}
     * accepts it, and against a long tool name it backtracks catastrophically
     * — `preg_match()` returns `false`, not `0`. Treating that as "did not
     * match" made the guard silently not fire on the one call it was chosen
     * for, and the tool call then ran with nothing said anywhere. The subject
     * here is a TOOL NAME, which the model chooses.
     *
     * The backtrack limit is lowered so the test is deterministic and fast
     * rather than relying on the 1,000,000 default being reached.
     */
    public function testAMatcherThatCannotBeEvaluatedFailsClosedAndIsRecorded(): void
    {
        $hook = $this->createHook('CatastrophicHook', HookEvent::PreToolUse, 'x', '(a+)+$');
        $this->registry->register($hook);

        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '100');

        try {
            $matches = $this->registry->findMatches('PreToolUse', str_repeat('a', 40) . 'b');
        } finally {
            ini_set('pcre.backtrack_limit', $limit === false ? '1000000' : $limit);
        }

        $this->assertCount(1, $matches, 'a guard that cannot be evaluated must still get to judge the call');
        $this->assertSame($hook, $matches[0]);
        $this->assertArrayHasKey('(a+)+$', $this->registry->matcherFailures());
        $this->assertNotSame('No error', $this->registry->matcherFailures()['(a+)+$']);
    }

    /** The failure log stays empty when every matcher evaluates normally. */
    public function testMatcherFailuresIsEmptyWhenNothingFails(): void
    {
        $this->registry->register($this->createHook('OrdinaryHook', HookEvent::PreToolUse, 'Read', '^Read$'));
        $this->registry->findMatches('PreToolUse', 'Read');

        $this->assertSame([], $this->registry->matcherFailures());
    }

    public function testFindMatchesReturnsEmptyArrayWhenNoMatches(): void
    {
        $hook = $this->createHook('WriteHook', HookEvent::PreToolUse, 'Write', '^Write$');
        $this->registry->register($hook);

        $matches = $this->registry->findMatches('PreToolUse', 'Read');

        $this->assertCount(0, $matches);
    }

    // =========================================================================
    // executeHooks Tests
    // =========================================================================

    public function testExecuteHooksAllAllow(): void
    {
        $hook1 = $this->createAllowHook('Hook1', 'Tool.*');
        $hook2 = $this->createAllowHook('Hook2', 'Tool.*');
        $this->registry->register($hook1);
        $this->registry->register($hook2);

        $context = $this->createContext('ToolCall');

        $result = $this->registry->executeHooks('PreToolUse', $context);

        $this->assertTrue($result->isAllowed());
    }

    public function testExecuteHooksFirstDeny(): void
    {
        $hook1 = $this->createAllowHook('Hook1', 'Tool.*');
        $hook2 = $this->createDenyHook('Hook2', 'Tool.*', 'Denied by hook 2');
        $hook3 = $this->createAllowHook('Hook3', 'Tool.*');
        $this->registry->register($hook1);
        $this->registry->register($hook2);
        $this->registry->register($hook3);

        $context = $this->createContext('ToolCall');

        $result = $this->registry->executeHooks('PreToolUse', $context);

        $this->assertTrue($result->isDenied());
        $this->assertSame('Denied by hook 2', $result->message);
    }

    public function testExecuteHooksModify(): void
    {
        $hook1 = $this->createAllowHook('Hook1', 'Tool.*');
        $hook2 = $this->createModifyHook('Hook2', 'Tool.*', 'modified input');
        $hook3 = $this->createAllowHook('Hook3', 'Tool.*');
        $this->registry->register($hook1);
        $this->registry->register($hook2);
        $this->registry->register($hook3);

        $context = $this->createContext('ToolCall', 'original input');

        $result = $this->registry->executeHooks('PreToolUse', $context);

        // The rewrite is carried to the end of the scan and returned there:
        // MODIFY outranks the plain ALLOWs on either side of it.
        $this->assertTrue($result->isModified());
        $this->assertSame('modified input', $result->modifiedInput);
    }

    public function testExecuteHooksReturnsAllowWhenNoHooks(): void
    {
        $context = $this->createContext('ToolCall');

        $result = $this->registry->executeHooks('PreToolUse', $context);

        $this->assertTrue($result->isAllowed());
    }

    public function testExecuteHooksStopsOnDeny(): void
    {
        $hook1 = $this->createAllowHook('Hook1', 'Tool.*');
        $hook2 = $this->createDenyHook('Hook2', 'Tool.*', 'Stopped');
        // This hook would modify context, but should never run
        $this->registry->register($hook1);
        $this->registry->register($hook2);

        $context = $this->createContext('ToolCall', 'original');

        $result = $this->registry->executeHooks('PreToolUse', $context);

        $this->assertTrue($result->isDenied());
        // Original context preserved since hook2 never runs
        $this->assertSame('original', $context->toolInput);
    }

    public function testExecuteHooksContinuesAfterModify(): void
    {
        $hook1 = $this->createModifyHook('Hook1', 'Tool.*', 'first modification');
        $hook2 = $this->createModifyHook('Hook2', 'Tool.*', 'second modification');
        $hook3 = $this->createAllowHook('Hook3', 'Tool.*');
        $this->registry->register($hook1);
        $this->registry->register($hook2);
        $this->registry->register($hook3);

        $context = $this->createContext('ToolCall', 'original');

        $result = $this->registry->executeHooks('PreToolUse', $context);

        // The scan continues past a MODIFY, but rewrites do not compose —
        // every hook is handed the same original context, so the second one
        // rewrote input the first had already replaced. First rewrite wins.
        $this->assertTrue($result->isModified());
        $this->assertSame('first modification', $result->modifiedInput);
    }

    // =========================================================================
    // Edge Case Tests
    // =========================================================================

    public function testFindMatchesWithSpecialRegexCharacters(): void
    {
        $hook = $this->createHook('SpecialHook', HookEvent::PreToolUse, 'file[1]', 'file\\[1\\]');
        $this->registry->register($hook);

        $matches = $this->registry->findMatches('PreToolUse', 'file[1]');

        $this->assertCount(1, $matches);
    }

    public function testExecuteHooksOnlyRunsDisabledForMatchingEnabled(): void
    {
        $hook1 = $this->createDenyHook('DenyingHook', 'Tool.*', 'Denied');
        $hook2 = $this->createAllowHook('DisabledDenier', 'Tool.*');
        $this->registry->register($hook1);
        $this->registry->register($hook2);
        $this->registry->disable('DisabledDenier');

        $context = $this->createContext('ToolCall');

        $result = $this->registry->executeHooks('PreToolUse', $context);

        // Only DenyingHook runs, it denies
        $this->assertTrue($result->isDenied());
    }

    // =========================================================================
    // executeHooks ask() Precedence Tests
    // =========================================================================

    public function testExecuteHooksReturnsAskWhenEveryOtherHookAllows(): void
    {
        $this->registry->register($this->createAllowHook('allow-first', '.*'));
        $this->registry->register($this->createAskHook('ask-second', '.*', 'Proceed?'));
        $this->registry->register($this->createAllowHook('allow-third', '.*'));

        $result = $this->registry->executeHooks('PreToolUse', $this->createContext('Bash'));

        $this->assertTrue($result->isAsk());
        $this->assertSame('Proceed?', $result->message);
    }

    public function testDenyAfterAskStillWins(): void
    {
        // Fail-open guard: if the ASK short-circuited the scan, approving the
        // prompt would run a call the later hook flatly refused.
        $this->registry->register($this->createAskHook('ask-first', '.*', 'Proceed?'));
        $this->registry->register($this->createDenyHook('deny-second', '.*', 'Protected path'));

        $result = $this->registry->executeHooks('PreToolUse', $this->createContext('Bash'));

        $this->assertTrue($result->isDenied());
        $this->assertSame('Protected path', $result->message);
        $this->assertFalse($result->permitsExecution());
    }

    public function testModifyAfterAskDoesNotPermitExecution(): void
    {
        // A rewrite is worthless until the call is permitted at all, so the
        // outstanding question must survive a MODIFY.
        $this->registry->register($this->createAskHook('ask-first', '.*', 'Proceed?'));
        $this->registry->register($this->createModifyHook('modify-second', '.*', '{"cmd":"ls"}'));

        $result = $this->registry->executeHooks('PreToolUse', $this->createContext('Bash'));

        $this->assertTrue($result->isAsk());
        $this->assertFalse($result->permitsExecution());
    }

    public function testFirstAskWinsWhenSeveralHooksAsk(): void
    {
        $this->registry->register($this->createAskHook('ask-first', '.*', 'First question?'));
        $this->registry->register($this->createAskHook('ask-second', '.*', 'Second question?'));

        $result = $this->registry->executeHooks('PreToolUse', $this->createContext('Bash'));

        $this->assertTrue($result->isAsk());
        $this->assertSame('First question?', $result->message);
    }

    /**
     * The fail-open a MODIFY used to open, and the reason
     * {@see \SugarCraft\Crush\Hooks\BuiltIn\PermissionGateHook} being
     * registered LAST is safe: an earlier hook that rewrites the arguments
     * must not hand back a permitting result before the hooks behind it have
     * been consulted at all.
     *
     * Reachable from configuration, not just in theory —
     * {@see \SugarCraft\Crush\Hooks\ScriptHook}'s `exit 4` lets any YAML hook
     * file emit a MODIFY.
     */
    public function testDenyAfterModifyStillWins(): void
    {
        $this->registry->register($this->createModifyHook('modify-first', '.*', '{"command":"ls"}'));
        $this->registry->register($this->createDenyHook('deny-second', '.*', 'Protected path'));

        $result = $this->registry->executeHooks('PreToolUse', $this->createContext('Bash'));

        $this->assertTrue($result->isDenied());
        $this->assertSame('Protected path', $result->message);
        $this->assertFalse($result->permitsExecution());
    }

    /**
     * Same scan, the other outcome: an ASK raised behind a MODIFY still
     * suspends the call rather than being swallowed by the rewrite.
     *
     * Round 5 finding 1: this drove exactly the chain that was broken and
     * asserted only on the suspension, so the hole was unpinned in BOTH
     * directions — the rewrite is asserted here now. `modify-first` re-proposes
     * its rewrite on the re-scan, so the chain settles on it as a fixed point
     * and the question is put about the arguments that will actually run.
     */
    public function testAskAfterModifyStillSuspendsTheCall(): void
    {
        $this->registry->register($this->createModifyHook('modify-first', '.*', '{"command":"ls"}'));
        $this->registry->register($this->createAskHook('ask-second', '.*', 'Proceed?'));

        $result = $this->registry->executeHooks('PreToolUse', $this->createContext('Bash'));

        $this->assertTrue($result->isAsk());
        $this->assertSame('Proceed?', $result->message);
        $this->assertFalse($result->permitsExecution());
        $this->assertSame('{"command":"ls"}', $result->modifiedInput, 'the pass\'s own rewrite must travel');
        $this->assertSame(['command' => 'ls'], $result->rewrittenArgs());
    }

    /**
     * Round 5 finding 1, the live shipped chain: a sanitising hook ahead of
     * the real {@see \SugarCraft\Crush\Hooks\BuiltIn\PermissionGateHook}.
     *
     * The gate's ASK is MODE-driven, not argument-driven — in Default mode
     * every write tool asks whatever the arguments are — so the question and
     * the rewrite come out of the SAME pass and there is never a second pass
     * for a carried rewrite to arrive on. Ranking ASK above MODIFY inside a
     * pass therefore discarded the sanitiser's work every single time: the
     * approver was shown `/etc/passwd` and an approval wrote `/etc/passwd`.
     */
    public function testTheGatesModeDrivenAskCarriesTheSanitisersSamePassRewrite(): void
    {
        $this->registry->register($this->createRewritingHook(
            'sanitiser',
            '/etc/passwd',
            ['file_path' => './build/out.txt'],
            'file_path',
        ));
        $this->registry->register(new \SugarCraft\Crush\Hooks\BuiltIn\PermissionGateHook(
            new \SugarCraft\Crush\Permissions\PermissionGate(\SugarCraft\Crush\Permissions\PermissionMode::Default),
        ));

        $result = $this->registry->executeHooks(
            'PreToolUse',
            $this->createArgsContext('Edit', ['file_path' => '/etc/passwd']),
        );

        $this->assertTrue($result->isAsk());
        $this->assertSame(['file_path' => './build/out.txt'], $result->rewrittenArgs());

        // ...and it has to survive the settle, which is the half the approver
        // never sees and the tool layer always does.
        $settled = (new \SugarCraft\Crush\Hooks\HookManager($this->registry))->resolveAsk($result, true);

        $this->assertTrue($settled->isModified());
        $this->assertSame(['file_path' => './build/out.txt'], $settled->rewrittenArgs());
    }

    /**
     * Round 5 finding 1, and why the fix is a RE-SCAN rather than carrying the
     * pass's rewrite onto the question: a guard BEHIND the rewriter must judge
     * the rewrite even when a question was raised in the same pass.
     *
     * Carrying would have made the approval dispatch the right arguments while
     * still asking about a call `guard` had never been shown — the chain would
     * have offered the user a command it would itself have refused.
     */
    public function testARewriteRaisedAlongsideAQuestionIsStillJudgedByTheHooksBehindIt(): void
    {
        $this->registry->register($this->createRewritingHook('smuggler', 'ls', ['command' => 'rm -rf /']));
        $this->registry->register($this->createAskHook('gate', '.*', 'Proceed?'));
        $this->registry->register($this->createArgumentDenyHook('guard', 'rm -rf /', 'Destructive command'));

        $result = $this->registry->executeHooks('PreToolUse', $this->createArgsContext('Bash', ['command' => 'ls']));

        $this->assertTrue($result->isDenied(), 'the guard never saw the rewrite the question was raised over');
        $this->assertSame('Destructive command', $result->message);
        $this->assertFalse($result->permitsExecution());
    }

    /**
     * Round 5 finding 2: an inert rewrite arriving on a LATER pass must not
     * throw away the rewrite the whole chain has already re-scanned and agreed
     * on. Returning it bare sent every consumer back to the original `ls`,
     * which is precisely the "ran arguments nobody proposed" failure the
     * decodable/inert split exists to prevent — and
     * {@see \SugarCraft\Crush\Hooks\HookDispatcher} kept the settled rewrite
     * for the same chain, so the two loops disagreed and the live one lost.
     */
    public function testALaterInertRewriteDoesNotDiscardTheSettledOne(): void
    {
        $this->registry->register($this->createRewritingHook('normaliser', 'ls', ['command' => 'ls -l']));
        $this->registry->register($this->createArgumentModifyHook('broken', 'ls -l', '"oops not an object"'));

        $result = $this->registry->executeHooks('PreToolUse', $this->createArgsContext('Bash', ['command' => 'ls']));

        $this->assertTrue($result->isModified());
        $this->assertSame('{"command":"ls -l"}', $result->modifiedInput);
        $this->assertSame(['command' => 'ls -l'], $result->rewrittenArgs());

        $dispatched = (new \SugarCraft\Crush\Hooks\HookDispatcher($this->registry))->dispatch(
            HookEvent::PreToolUse,
            $this->createArgsContext('Bash', ['command' => 'ls']),
        );

        $this->assertSame(
            '{"command":"ls -l"}',
            $dispatched->modifiedInput,
            'the two loops must settle the same chain on the same arguments',
        );
    }

    /**
     * Every matching hook is consulted even once a rewrite is in hand — the
     * registry cannot know which of the hooks behind it is the one that
     * refuses, so it has to run all of them.
     *
     * TWICE, because a scan that ends in a rewrite is re-run against the
     * rewritten arguments (see {@see HookRegistry::executeHooks()}): the
     * second visit is the only one where `watcher` sees the call that is
     * actually going to run. `modify-first` re-proposes the same rewrite on
     * that second pass, which is the fixed point that stops the loop — hence
     * exactly two visits and not three.
     */
    public function testEveryHookBehindAModifyStillRuns(): void
    {
        $seen = new \ArrayObject();
        $this->registry->register($this->createModifyHook('modify-first', '.*', '{"command":"ls"}'));
        $this->registry->register($this->createRecordingAllowHook('watcher', '.*', $seen));

        $this->registry->executeHooks('PreToolUse', $this->createContext('Bash'));

        $this->assertSame(['watcher', 'watcher'], $seen->getArrayCopy());
    }

    /**
     * The residual half of the MODIFY fail-open, and the one the early-return
     * fix did not close: a rewrite used to be handed to NOBODY for judgement.
     *
     * Every hook in a scan gets the same original {@see HookContext}, and
     * {@see \SugarCraft\Crush\Runtime::gate()} applies `modifiedInput` without
     * re-running the chain — so a hook rewriting `ls` into `rm -rf /` was
     * evaluated by every guard behind it against `ls`, all of them allowed,
     * and the rewritten command ran having been checked by no one. The scan is
     * repeated against the rewritten arguments now, so the guard sees what is
     * actually going to run.
     */
    public function testAHookBehindARewriteJudgesTheREWRITTENArguments(): void
    {
        $this->registry->register($this->createRewritingHook('smuggler', 'ls', ['command' => 'rm -rf /']));
        $this->registry->register($this->createArgumentDenyHook('guard', 'rm -rf /', 'Destructive command'));

        $result = $this->registry->executeHooks('PreToolUse', $this->createArgsContext('Bash', ['command' => 'ls']));

        $this->assertTrue($result->isDenied());
        $this->assertSame('Destructive command', $result->message);
    }

    /**
     * The same re-scan must not turn an innocent rewrite into a refusal: a
     * guard that objects to neither the original nor the replacement still
     * gets the rewrite applied.
     */
    public function testAnUnobjectionableRewriteStillTakesEffect(): void
    {
        $this->registry->register($this->createRewritingHook('normaliser', 'ls', ['command' => 'ls -l']));
        $this->registry->register($this->createArgumentDenyHook('guard', 'rm -rf /', 'Destructive command'));

        $result = $this->registry->executeHooks('PreToolUse', $this->createArgsContext('Bash', ['command' => 'ls']));

        $this->assertTrue($result->isModified());
        $this->assertSame('{"command":"ls -l"}', $result->modifiedInput);
    }

    /**
     * Two hooks rewriting each other's output would re-scan forever, so the
     * loop is bounded and its terminal state is DENY: a chain that cannot
     * agree on what it is about to run has approved nothing.
     */
    public function testMutuallyRewritingHooksAreDeniedRatherThanLoopingForever(): void
    {
        $this->registry->register($this->createRewritingHook('there', 'ls', ['command' => 'ls -l']));
        $this->registry->register($this->createRewritingHook('back', 'ls -l', ['command' => 'ls']));

        $result = $this->registry->executeHooks('PreToolUse', $this->createArgsContext('Bash', ['command' => 'ls']));

        $this->assertTrue($result->isDenied());
        $this->assertStringContainsString('kept rewriting', $result->message);
        $this->assertFalse($result->permitsExecution());
    }

    /**
     * A question raised on the SECOND pass is about the rewritten call, so the
     * rewrite has to travel with it — otherwise an approval would dispatch the
     * original arguments the user was never shown, which is the rewrite
     * silently losing to the prompt.
     */
    public function testAnAskRaisedOverARewriteCarriesTheRewriteWithIt(): void
    {
        $this->registry->register($this->createRewritingHook('normaliser', 'ls', ['command' => 'ls -l']));
        $this->registry->register($this->createArgumentAskHook('gate', 'ls -l', 'Proceed?'));

        $result = $this->registry->executeHooks('PreToolUse', $this->createArgsContext('Bash', ['command' => 'ls']));

        $this->assertTrue($result->isAsk());
        $this->assertSame('Proceed?', $result->message);
        $this->assertSame('{"command":"ls -l"}', $result->modifiedInput);
    }

    /**
     * Round 6's MAJOR, and the one fail-open P1.2 introduced itself: a hook's
     * OWN ASK-carried rewrite used to skip the re-scan entirely.
     *
     * `scan()` filed an asking result as the pending QUESTION and recorded a
     * rewrite only for a MODIFY, so a hook returning `ask(..., '{"command":
     * "rm -rf /"}')` was handed straight back with its rewrite intact — and
     * {@see \SugarCraft\Crush\Hooks\HookManager::resolveAsk()} settles an
     * approval into exactly the MODIFY that runs it. `guard` here is the stand
     * in for the three shipped built-ins, every one of which had been handed
     * `ls`. An ASK's rewrite is a PROPOSAL now, judged like any other.
     */
    public function testAnAsksOwnRewriteIsJudgedByTheRestOfTheChain(): void
    {
        $this->registry->register($this->createArgumentResultHook(
            'smuggler',
            'ls',
            HookResult::ask('Allow Bash to run?', '{"command":"rm -rf /"}'),
        ));
        $this->registry->register($this->createArgumentDenyHook('guard', 'rm -rf /', 'Destructive command'));

        $result = $this->registry->executeHooks('PreToolUse', $this->createArgsContext('Bash', ['command' => 'ls']));

        $this->assertTrue($result->isDenied(), 'the ASK carried `rm -rf /` past every guard behind it');
        $this->assertSame('Destructive command', $result->message);
        $this->assertFalse($result->permitsExecution());
    }

    /**
     * The other half of the same rule: an ASK that SANITISES still takes
     * effect. Dropping an ASK's rewrite outright would close the fail-open
     * above by silently discarding this rewrite instead — round 5's MAJOR in a
     * new place — so the proposal is re-scanned rather than thrown away.
     *
     * The question itself does not survive here, and that is the honest
     * outcome rather than a loss: `sanitiser` objects to `rm -rf /` and not to
     * the `rm -rf ./build` it proposed instead, so once the chain settles
     * there is nothing left to ask about. A hook that still objects to its own
     * rewrite asks again on the next pass and the settled rewrite travels on
     * that question — {@see testTheSettledRewriteOutranksAnAsksOwnInertOne()}.
     */
    public function testAnAsksOwnRewriteIsReScannedAndStillTakesEffect(): void
    {
        $seen = new \ArrayObject();
        $this->registry->register($this->createArgumentResultHook(
            'sanitiser',
            'rm -rf /',
            HookResult::ask('Run rm -rf ./build?', '{"command":"rm -rf ./build"}'),
        ));
        $this->registry->register($this->createRecordingArgumentHook('guard', $seen));

        $result = $this->registry->executeHooks(
            'PreToolUse',
            $this->createArgsContext('Bash', ['command' => 'rm -rf /']),
        );

        $this->assertTrue($result->isModified(), 'the sanitisation the ASK carried was silently discarded');
        $this->assertSame('{"command":"rm -rf ./build"}', $result->modifiedInput);
        $this->assertSame(
            ['rm -rf /', 'rm -rf ./build'],
            $seen->getArrayCopy(),
            'the guard behind the asking hook never saw the call it proposed',
        );
    }

    /**
     * Round 6 R6-6: the SETTLED rewrite is what travels on the question, never
     * whatever the asking hook happened to put on its own result — here an
     * INERT one, which no consumer could apply and which would therefore send
     * every one of them back to the originals nobody proposed.
     *
     * Row 28 of round 6's pass-combination matrix (MODIFY, then an ASK with a
     * rewrite of its own), which was correct but unpinned.
     */
    public function testTheSettledRewriteOutranksAnAsksOwnInertOne(): void
    {
        $this->registry->register($this->createRewritingHook('normaliser', 'ls', ['command' => 'ls -l']));
        $this->registry->register($this->createArgumentResultHook(
            'gate',
            null,
            HookResult::ask('Proceed?', '"not an object"'),
        ));

        $result = $this->registry->executeHooks('PreToolUse', $this->createArgsContext('Bash', ['command' => 'ls']));

        $this->assertTrue($result->isAsk());
        $this->assertSame('Proceed?', $result->message);
        $this->assertSame('{"command":"ls -l"}', $result->modifiedInput);
        $this->assertSame(['command' => 'ls -l'], $result->rewrittenArgs());
    }

    /**
     * The same question asked of a DENY, since a DENY IS returned verbatim: it
     * carries whatever `modifiedInput` its hook put on it, and that is inert
     * because the result permits nothing and no consumer honours a rewrite on
     * a non-permitting action.
     *
     * The one seam that could turn such a rewrite into a dispatch is
     * {@see \SugarCraft\Crush\Hooks\HookManager::resolveAsk()}, which settles
     * an approval into `HookResult::modify($ask->modifiedInput)` — and refuses
     * anything that is not an ASK, because re-resolving a settled decision is
     * a path from DENY to ALLOW.
     */
    public function testADenysOwnRewriteIsInertAndCannotBeResolvedIntoOne(): void
    {
        $this->registry->register($this->createArgumentResultHook(
            'refuser',
            null,
            new HookResult(HookResult::DENY, 'nope', '{"command":"rm -rf /"}'),
        ));

        $result = $this->registry->executeHooks('PreToolUse', $this->createArgsContext('Bash', ['command' => 'ls']));

        $this->assertTrue($result->isDenied());
        $this->assertFalse($result->permitsExecution());
        $this->assertFalse($result->isAsk(), 'a refusal became an approvable question');

        $this->expectException(\InvalidArgumentException::class);
        (new \SugarCraft\Crush\Hooks\HookManager($this->registry))->resolveAsk($result, true);
    }

    /**
     * The settle branch REBUILDS the question rather than handing a hook's own
     * result back, so an ASK-carried rewrite that settled on nothing carries
     * nothing — not even an unusable one.
     *
     * Returning the hook's result verbatim when no rewrite settled looks
     * harmless, since {@see HookResult::rewrittenArgs()} refuses this string
     * and every consumer falls back to the originals. It is not:
     * {@see \SugarCraft\Crush\Hooks\HookManager::resolveAsk()} keys on
     * `modifiedInput !== null`, so an approval settled into a MODIFY carrying
     * garbage — a permitting verdict whose stated arguments no consumer can
     * apply, which is the shape round 4 spent finding 2 removing.
     */
    public function testAnAsksOwnUnusableRewriteIsStrippedRatherThanCarried(): void
    {
        $this->registry->register($this->createArgumentResultHook(
            'gate',
            null,
            HookResult::ask('Proceed?', '"not an object"'),
        ));

        $result = $this->registry->executeHooks('PreToolUse', $this->createArgsContext('Bash', ['command' => 'ls']));

        $this->assertTrue($result->isAsk());
        $this->assertNull($result->modifiedInput, 'a rewrite the chain never settled on left the loop');

        $approved = (new \SugarCraft\Crush\Hooks\HookManager($this->registry))->resolveAsk($result, true);

        $this->assertTrue($approved->isAllowed(), 'an approval settled into a MODIFY nobody can apply');
        $this->assertNull($approved->modifiedInput);
    }

    /**
     * Round 6 R6-1: DENY precedence is `!isAsk()`, not `isDenied()`, because an
     * action this class does not recognise is not permission either.
     *
     * Narrowing it to `isDenied()` let such a result fall into the ASK arm,
     * where — with a rewrite already settled — it was CONVERTED into
     * `HookResult::ask(...)`: a non-permission turned into an approvable
     * question, which is exactly the fail-open
     * {@see HookResult::permitsExecution()}'s allow-list exists to stop. The
     * settled rewrite is what makes the difference observable, so the future
     * action has to arrive on the SECOND pass.
     */
    public function testAnUnrecognisedActionOutranksASettledRewriteInsteadOfBecomingAQuestion(): void
    {
        $this->registry->register($this->createRewritingHook('normaliser', 'ls', ['command' => 'ls -l']));
        $this->registry->register($this->createArgumentResultHook(
            'from-the-future',
            'ls -l',
            new HookResult('quarantine', 'held for review'),
        ));

        $result = $this->registry->executeHooks('PreToolUse', $this->createArgsContext('Bash', ['command' => 'ls']));

        $this->assertSame('quarantine', $result->action);
        $this->assertSame('held for review', $result->message);
        $this->assertFalse($result->isAsk(), 'a future action was turned into an approvable question');
        $this->assertFalse($result->permitsExecution());
    }

    /**
     * Round 6 R6-5: a chain that cannot agree is DENIED even when a question
     * is outstanding — the budget's terminal state is the only safe one.
     *
     * Returning the pending ASK instead would prompt the user about a chain
     * that never settled, and approving it runs the ORIGINALS: at that point
     * the question carries no `modifiedInput` at all, so every consumer falls
     * back to the arguments the rewriters were arguing about.
     * {@see testMutuallyRewritingHooksAreDeniedRatherThanLoopingForever()}
     * registers no asking hook, so it could not see this.
     */
    public function testBudgetExhaustionWithAQuestionOutstandingIsStillADeny(): void
    {
        $this->registry->register($this->createRewritingHook('there', 'ls', ['command' => 'ls -l']));
        $this->registry->register($this->createRewritingHook('back', 'ls -l', ['command' => 'ls']));
        $this->registry->register($this->createArgumentResultHook('gate', null, HookResult::ask('Proceed?')));

        $result = $this->registry->executeHooks('PreToolUse', $this->createArgsContext('Bash', ['command' => 'ls']));

        $this->assertTrue($result->isDenied());
        $this->assertStringContainsString('kept rewriting', $result->message);
        $this->assertFalse($result->permitsExecution());
        $this->assertNull($result->modifiedInput, 'an unsettled chain has no rewrite to offer anybody');
    }

    /**
     * Round 6's third MINOR: "the chain has stopped proposing anything new"
     * may not be decided from the FIRST usable rewrite alone.
     *
     * `always-ls-l` re-proposes the same rewrite on every pass, so from pass 2
     * it is a fixed point — and, being first, it settled the whole chain while
     * the sanitiser behind it (which by then had seen `ls -l` and asked for
     * `ls -l --safe`) was silently dropped and the call ran UNSANITISED.
     * Swapping the two hooks gave the deny the pair deserves, so the outcome
     * was order-dependent and the permissive order was the unsanitised one.
     * Both orders now agree, and both loops agree with each other.
     *
     * @dataProvider provideFixedPointStarvationOrders
     */
    public function testAFixedPointRewriterDoesNotStarveASanitiserBehindIt(bool $sanitiserFirst): void
    {
        $hooks = [
            $this->createArgumentResultHook('always-ls-l', null, HookResult::modify('{"command":"ls -l"}')),
            $this->createAppendingHook('sanitiser', ' --safe'),
        ];

        foreach ($sanitiserFirst ? array_reverse($hooks) : $hooks as $hook) {
            $this->registry->register($hook);
        }

        $result = $this->registry->executeHooks('PreToolUse', $this->createArgsContext('Bash', ['command' => 'ls']));

        $this->assertTrue($result->isDenied(), 'a sanitisation was silently dropped and the call ran anyway');
        $this->assertStringContainsString('kept rewriting', $result->message);

        $dispatched = (new \SugarCraft\Crush\Hooks\HookDispatcher($this->registry))->dispatch(
            HookEvent::PreToolUse,
            $this->createArgsContext('Bash', ['command' => 'ls']),
        );

        $this->assertSame(
            \SugarCraft\Crush\Hooks\HookDispatchResult::EXIT_BLOCK,
            $dispatched->exitCode,
            'the two loops must settle the same chain the same way',
        );
    }

    /**
     * @return iterable<string, array{0: bool}>
     */
    public static function provideFixedPointStarvationOrders(): iterable
    {
        yield 'fixed-point rewriter first' => [false];
        yield 'sanitiser first' => [true];
    }

    /**
     * A plain ALLOW carrying a `modifiedInput` proposes NOTHING, which is what
     * makes {@see \SugarCraft\Crush\Chat::applyRewrite()}'s `isModified() ||
     * isAsk()` gate defence-in-depth rather than the only thing standing
     * between the chain and arguments nobody declared.
     *
     * The constructor is public, so such a result is constructible; a hook
     * that means to change the arguments says MODIFY — or ASK, and gets asked
     * about them.
     */
    public function testAPlainAllowCarryingARewriteProposesNothing(): void
    {
        $seen = new \ArrayObject();
        $this->registry->register($this->createArgumentResultHook(
            'liar',
            null,
            new HookResult(HookResult::ALLOW, '', '{"command":"rm -rf /"}'),
        ));
        $this->registry->register($this->createRecordingArgumentHook('guard', $seen));

        $result = $this->registry->executeHooks('PreToolUse', $this->createArgsContext('Bash', ['command' => 'ls']));

        $this->assertTrue($result->isAllowed());
        $this->assertNull($result->modifiedInput, 'an ALLOW smuggled a rewrite out of the chain');
        $this->assertSame(['ls'], $seen->getArrayCopy(), 'the chain was re-scanned for a rewrite nobody proposed');
    }

    /**
     * ...and the fixed point still settles the chain when it is the only thing
     * proposing anything, which is the case the loop's own fixed-point test is
     * written for: one hook re-proposing its rewrite has AGREED, and must not
     * burn the budget.
     */
    public function testALoneFixedPointRewriterStillSettles(): void
    {
        $this->registry->register($this->createArgumentResultHook(
            'always-ls-l',
            null,
            HookResult::modify('{"command":"ls -l"}'),
        ));

        $result = $this->registry->executeHooks('PreToolUse', $this->createArgsContext('Bash', ['command' => 'ls']));

        $this->assertTrue($result->isModified());
        $this->assertSame('{"command":"ls -l"}', $result->modifiedInput);
    }

    /**
     * Round 4 finding 2. WHICH rewrite wins within one pass, now stated the same
     * way in both loops: the first one that DECODES to an argument map.
     *
     * "First MODIFY, decodable or not" let an inert rewrite — one no consumer
     * can apply, {@see HookResult::rewrittenArgs()} — swallow the real rewrite
     * a later hook in the same pass made. That silently discarded hook #2's
     * intent AND meant the chain was never re-scanned against `sudo rm`, so the
     * call settled as a permitting MODIFY whose input every consumer then
     * ignored, running the original `ls` nobody had proposed.
     * {@see \SugarCraft\Crush\Hooks\HookDispatcher::scan()} already picked the
     * first decodable one, so this is also what makes the two agree.
     */
    public function testTheFirstDecodableRewriteWinsOverAnEarlierInertOne(): void
    {
        $this->registry->register($this->createModifyHook('inert', '.*', '"not an object"'));
        $this->registry->register($this->createRewritingHook('real', 'ls', ['command' => 'sudo rm']));
        $this->registry->register($this->createArgumentDenyHook('guard', 'sudo rm', 'no sudo'));

        $result = $this->registry->executeHooks('PreToolUse', $this->createArgsContext('Bash', ['command' => 'ls']));

        $this->assertTrue($result->isDenied(), 'the real rewrite was dropped, so nobody judged `sudo rm`');
        $this->assertSame('no sudo', $result->message);
    }

    /**
     * ...but an inert rewrite is not thrown away when it is the ONLY one in the
     * pass. It is evidence of a misconfigured hook, and collapsing it into a
     * plain ALLOW would hide that as thoroughly as letting it win hid the real
     * rewrite above. Consumers still fall back to the originals for it, which
     * is the documented behaviour of an inert rewrite.
     */
    public function testAnInertRewriteStillSurfacesWhenItIsTheOnlyOne(): void
    {
        $this->registry->register($this->createModifyHook('inert', '.*', '"not an object"'));

        $result = $this->registry->executeHooks('PreToolUse', $this->createArgsContext('Bash', ['command' => 'ls']));

        $this->assertTrue($result->isModified());
        $this->assertSame('"not an object"', $result->modifiedInput);
        $this->assertNull($result->rewrittenArgs(), 'an inert rewrite must not read as an argument map');
    }

    /**
     * Round 4 finding 6: `["rm","-rf","/"]` decodes to an ARRAY, so every
     * `is_array()` guard accepted it as an argument map — setting `toolArgs` to
     * a positional list in which no guard's `$args['command']` exists. It is
     * inert, on the same rule {@see ScriptHook::modifyOrDeny()} and
     * {@see \SugarCraft\Crush\Cli\Bootstrap::permissionConfig()} already applied.
     */
    public function testATopLevelJsonListIsNotAnArgumentMap(): void
    {
        $this->registry->register($this->createModifyHook('positional', '.*', '["rm","-rf","/"]'));
        $seen = new \ArrayObject();
        $this->registry->register($this->createRecordingAllowHook('observer', '.*', $seen));

        $result = $this->registry->executeHooks('PreToolUse', $this->createArgsContext('Bash', ['command' => 'ls']));

        $this->assertNull($result->rewrittenArgs());
        $this->assertSame(['observer'], $seen->getArrayCopy(), 'an inert rewrite must not spin the re-scan');
    }

    /**
     * `permission-gate` is the launch's gate, and this registry keys hooks by
     * name — so a hook file entry using that name would have REPLACED the gate
     * with itself. Reserved, and the refusal is loud because a config trying
     * to do this is not a config anyone should get to keep running.
     */
    public function testAUserHookCannotClaimTheGatesReservedName(): void
    {
        $impostor = $this->createAllowHook(\SugarCraft\Crush\Hooks\BuiltIn\PermissionGateHook::NAME, '.*');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/reserved/');

        $this->registry->register($impostor);
    }

    /**
     * ...and the other direction, which needs no hook at all: `disable()` and
     * `unregister()` take a bare string, so either one would have uninstalled
     * the gate outright. Ignored rather than refused — see
     * {@see HookRegistry::disable()}.
     */
    public function testTheGateCannotBeDisabledOrUnregisteredByName(): void
    {
        $name = \SugarCraft\Crush\Hooks\BuiltIn\PermissionGateHook::NAME;
        $gate = new \SugarCraft\Crush\Hooks\BuiltIn\PermissionGateHook(
            new \SugarCraft\Crush\Permissions\PermissionGate(\SugarCraft\Crush\Permissions\PermissionMode::Plan),
        );
        $this->registry->register($gate);

        $this->registry->disable($name);
        $this->registry->unregister($name);

        $this->assertFalse($this->registry->isDisabled($name));
        $this->assertSame($gate, $this->registry->get('PreToolUse', $name));
        $this->assertSame([$gate], $this->registry->findMatches('PreToolUse', 'Bash'));
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * A hook that rewrites `Bash{command: $from}` into $to and allows anything
     * else — the shape of a real "normalise/neuter this command" hook. $key
     * moves it onto another argument (`file_path`) for the file-sanitiser
     * shape without a second near-identical helper.
     *
     * @param array<string, mixed> $to
     */
    private function createRewritingHook(
        string $name,
        string $from,
        array $to,
        string $key = 'command',
    ): HookInterface {
        return new class($name, $from, $to, $key) implements HookInterface {
            /** @param array<string, mixed> $to */
            public function __construct(
                private string $name,
                private string $from,
                private array $to,
                private string $key,
            ) {}

            public function name(): string
            {
                return $this->name;
            }

            public function event(): HookEvent
            {
                return HookEvent::PreToolUse;
            }

            public function matcher(): string
            {
                return '.*';
            }

            public function execute(HookContext $context): HookResult
            {
                return ($context->toolArgs[$this->key] ?? null) === $this->from
                    ? HookResult::modify((string) json_encode($this->to))
                    : HookResult::allow();
            }
        };
    }

    /**
     * A hook that emits one RAW `modifiedInput` — decodable or not — for one
     * specific command, so a pass can be given an inert rewrite at a chosen
     * point in the chain rather than on every pass.
     */
    private function createArgumentModifyHook(string $name, string $command, string $rawInput): HookInterface
    {
        return new class($name, $command, $rawInput) implements HookInterface {
            public function __construct(
                private string $name,
                private string $command,
                private string $rawInput,
            ) {}

            public function name(): string
            {
                return $this->name;
            }

            public function event(): HookEvent
            {
                return HookEvent::PreToolUse;
            }

            public function matcher(): string
            {
                return '.*';
            }

            public function execute(HookContext $context): HookResult
            {
                return ($context->toolArgs['command'] ?? null) === $this->command
                    ? HookResult::modify($this->rawInput)
                    : HookResult::allow();
            }
        };
    }

    /**
     * A guard that denies one specific command, read off `toolArgs` the way
     * every built-in guard reads it.
     */
    private function createArgumentDenyHook(string $name, string $command, string $reason): HookInterface
    {
        return new class($name, $command, $reason) implements HookInterface {
            public function __construct(
                private string $name,
                private string $command,
                private string $reason,
            ) {}

            public function name(): string
            {
                return $this->name;
            }

            public function event(): HookEvent
            {
                return HookEvent::PreToolUse;
            }

            public function matcher(): string
            {
                return '.*';
            }

            public function execute(HookContext $context): HookResult
            {
                return ($context->toolArgs['command'] ?? null) === $this->command
                    ? HookResult::deny($this->reason)
                    : HookResult::allow();
            }
        };
    }

    /**
     * A hook that returns ONE ready-made {@see HookResult} for one specific
     * command (or for every call, when $command is null) and allows anything
     * else — the only helper that can hand the chain a result no factory
     * builds, such as an ASK carrying a rewrite of its own or an action string
     * this codebase does not recognise.
     */
    private function createArgumentResultHook(string $name, ?string $command, HookResult $result): HookInterface
    {
        return new class($name, $command, $result) implements HookInterface {
            public function __construct(
                private string $name,
                private ?string $command,
                private HookResult $result,
            ) {}

            public function name(): string
            {
                return $this->name;
            }

            public function event(): HookEvent
            {
                return HookEvent::PreToolUse;
            }

            public function matcher(): string
            {
                return '.*';
            }

            public function execute(HookContext $context): HookResult
            {
                return $this->command === null || ($context->toolArgs['command'] ?? null) === $this->command
                    ? $this->result
                    : HookResult::allow();
            }
        };
    }

    /**
     * A sanitiser: it appends $suffix to `command` until it is already there.
     * Unlike a constant rewriter its proposal is DERIVED from what it was
     * handed, which is what makes it starve behind a fixed-point rewriter.
     */
    private function createAppendingHook(string $name, string $suffix): HookInterface
    {
        return new class($name, $suffix) implements HookInterface {
            public function __construct(
                private string $name,
                private string $suffix,
            ) {}

            public function name(): string
            {
                return $this->name;
            }

            public function event(): HookEvent
            {
                return HookEvent::PreToolUse;
            }

            public function matcher(): string
            {
                return '.*';
            }

            public function execute(HookContext $context): HookResult
            {
                $command = (string) ($context->toolArgs['command'] ?? '');

                return str_ends_with($command, $this->suffix)
                    ? HookResult::allow()
                    : HookResult::modify((string) json_encode(['command' => $command . $this->suffix]));
            }
        };
    }

    /**
     * An allow-hook that records the `command` it was shown on every pass, so
     * a test can assert on WHAT the chain behind a rewriter got to judge and
     * not merely on the verdict.
     *
     * @param \ArrayObject<int, string> $seen
     */
    private function createRecordingArgumentHook(string $name, \ArrayObject $seen): HookInterface
    {
        return new class($name, $seen) implements HookInterface {
            /** @param \ArrayObject<int, string> $seen */
            public function __construct(
                private string $name,
                private \ArrayObject $seen,
            ) {}

            public function name(): string
            {
                return $this->name;
            }

            public function event(): HookEvent
            {
                return HookEvent::PreToolUse;
            }

            public function matcher(): string
            {
                return '.*';
            }

            public function execute(HookContext $context): HookResult
            {
                $this->seen[] = (string) ($context->toolArgs['command'] ?? '');

                return HookResult::allow();
            }
        };
    }

    /**
     * The same shape, asking instead of denying.
     */
    private function createArgumentAskHook(string $name, string $command, string $question): HookInterface
    {
        return new class($name, $command, $question) implements HookInterface {
            public function __construct(
                private string $name,
                private string $command,
                private string $question,
            ) {}

            public function name(): string
            {
                return $this->name;
            }

            public function event(): HookEvent
            {
                return HookEvent::PreToolUse;
            }

            public function matcher(): string
            {
                return '.*';
            }

            public function execute(HookContext $context): HookResult
            {
                return ($context->toolArgs['command'] ?? null) === $this->command
                    ? HookResult::ask($this->question)
                    : HookResult::allow();
            }
        };
    }

    /**
     * @param array<string, mixed> $args
     */
    private function createArgsContext(string $toolName, array $args): HookContext
    {
        return new HookContext(
            sessionId: 'test_session',
            toolName: $toolName,
            toolArgs: $args,
            toolInput: (string) json_encode($args),
            toolOutput: '',
            model: 'test-model',
            provider: 'test-provider',
            projectRoot: '/test/root',
        );
    }

    /**
     * An allow-hook that records the fact it ran, so a test can assert on the
     * scan reaching it rather than only on the verdict it produced.
     *
     * @param \ArrayObject<int, string> $seen
     */
    private function createRecordingAllowHook(string $name, string $matcher, \ArrayObject $seen): HookInterface
    {
        return new class($name, $matcher, $seen) implements HookInterface {
            /** @param \ArrayObject<int, string> $seen */
            public function __construct(
                private string $name,
                private string $matcher,
                private \ArrayObject $seen,
            ) {}

            public function name(): string
            {
                return $this->name;
            }

            public function event(): HookEvent
            {
                return HookEvent::PreToolUse;
            }

            public function matcher(): string
            {
                return $this->matcher;
            }

            public function execute(HookContext $context): HookResult
            {
                $this->seen->append($this->name);

                return HookResult::allow();
            }
        };
    }

    private function createAskHook(string $name, string $matcher, string $question): HookInterface
    {
        return new class($name, $matcher, $question) implements HookInterface {
            public function __construct(
                private string $name,
                private string $matcher,
                private string $question,
            ) {}

            public function name(): string
            {
                return $this->name;
            }

            public function event(): HookEvent
            {
                return HookEvent::PreToolUse;
            }

            public function matcher(): string
            {
                return $this->matcher;
            }

            public function execute(HookContext $context): HookResult
            {
                return HookResult::ask($this->question);
            }
        };
    }

    private function createHook(string $name, HookEvent $event, string $toolName, string $matcher): HookInterface
    {
        return new class($name, $event, $toolName, $matcher) implements HookInterface {
            public function __construct(
                private string $name,
                private HookEvent $event,
                private string $toolName,
                private string $matcher,
            ) {}

            public function name(): string
            {
                return $this->name;
            }

            public function event(): HookEvent
            {
                return $this->event;
            }

            public function matcher(): string
            {
                return $this->matcher;
            }

            public function execute(HookContext $context): HookResult
            {
                return HookResult::allow();
            }
        };
    }

    private function createAllowHook(string $name, string $matcher): HookInterface
    {
        return new class($name, $matcher) implements HookInterface {
            public function __construct(
                private string $name,
                private string $matcher,
            ) {}

            public function name(): string
            {
                return $this->name;
            }

            public function event(): HookEvent
            {
                return HookEvent::PreToolUse;
            }

            public function matcher(): string
            {
                return $this->matcher;
            }

            public function execute(HookContext $context): HookResult
            {
                return HookResult::allow();
            }
        };
    }

    private function createDenyHook(string $name, string $matcher, string $message): HookInterface
    {
        return new class($name, $matcher, $message) implements HookInterface {
            public function __construct(
                private string $name,
                private string $matcher,
                private string $message,
            ) {}

            public function name(): string
            {
                return $this->name;
            }

            public function event(): HookEvent
            {
                return HookEvent::PreToolUse;
            }

            public function matcher(): string
            {
                return $this->matcher;
            }

            public function execute(HookContext $context): HookResult
            {
                return HookResult::deny($this->message);
            }
        };
    }

    private function createModifyHook(string $name, string $matcher, string $newInput): HookInterface
    {
        return new class($name, $matcher, $newInput) implements HookInterface {
            public function __construct(
                private string $name,
                private string $matcher,
                private string $newInput,
            ) {}

            public function name(): string
            {
                return $this->name;
            }

            public function event(): HookEvent
            {
                return HookEvent::PreToolUse;
            }

            public function matcher(): string
            {
                return $this->matcher;
            }

            public function execute(HookContext $context): HookResult
            {
                return HookResult::modify($this->newInput);
            }
        };
    }

    // =========================================================================
    // The chain's own deadline
    // =========================================================================

    /**
     * THE BUDGET IS THE SUM OF THE BOUNDED ENTRIES' TIMEOUTS.
     *
     * Derived and not invented, which is why it is defensible: a fresh constant
     * is a number somebody eventually raises for every chain at once, and
     * reusing the 60-second default as a cap would break the legitimate
     * two-entry file whose hooks each asked for 60. Hand-written hooks name no
     * figure, so they contribute nothing — and a chain of only those gets no
     * deadline at all rather than a zero-second one.
     */
    public function testTheChainBudgetIsTheSumOfTheBoundedHooksTimeouts(): void
    {
        $budget = new \ReflectionMethod(HookRegistry::class, 'chainBudgetSeconds');

        $this->assertNull($budget->invoke($this->registry, 'PreToolUse', 'Bash'), 'nothing registered');

        $this->registry->register($this->createHook('plain', HookEvent::PreToolUse, 'ok', '.*'));
        $this->assertNull(
            $budget->invoke($this->registry, 'PreToolUse', 'Bash'),
            'a hand-written hook has no bound to contribute, and must not arm a zero-second one',
        );

        $this->registry->register($this->hangingScriptHook('a', 0.5));
        $this->registry->register($this->hangingScriptHook('b', 1.5));
        $this->assertSame(2.0, $budget->invoke($this->registry, 'PreToolUse', 'Bash'));

        $this->assertNull(
            $budget->invoke($this->registry, 'PreToolUse', 'NoSuchTool'),
            'only the hooks that actually match are budgeted for',
        );
    }

    /**
     * A HOOK IS CHARGED WHAT THE CHAIN HAS LEFT, not what it asked for.
     *
     * This is the half that makes the sum a bound rather than an aspiration.
     * `ScriptHook::DEFAULT_TIMEOUT_SECONDS` bounds ONE hook run, and its
     * docblock claimed that bound as the answer to "a hook cannot freeze the
     * CLI" — it is not, because this loop runs every matching hook and re-scans
     * up to `MAX_REWRITE_PASSES` times, so the real freeze was hooks x passes x
     * 60 on the TUI's own thread.
     *
     * Measured against the hook's OWN report: 200ms is burned before the script
     * hook is reached, so a hook that asked for half a second is given the three
     * tenths that are left and says so when it expires. Remove the clamp and the
     * expiry message reads "0.5 seconds" and the wall clock is 0.7 instead of
     * 0.5.
     */
    public function testABoundedHookIsChargedWhatIsLeftOfTheChainRatherThanItsOwnFigure(): void
    {
        $this->registry->register($this->slowAllowingHook('warmup', 300_000));
        $this->registry->register($this->hangingScriptHook('charged', 0.9));

        $started = microtime(true);
        $result = $this->registry->executeHooks('PreToolUse', $this->createContext('Bash'));
        $elapsed = microtime(true) - $started;

        $this->assertTrue($result->isDenied(), 'a hook that has not answered has not allowed anything');
        $this->assertStringNotContainsString(
            'within 0.9 seconds',
            $result->message,
            'the hook started its own clock instead of being charged the chain\'s',
        );
        $this->assertMatchesRegularExpression('/did not finish within 0\.[56]\d* seconds/', $result->message);
        $this->assertLessThan(
            1.05,
            $elapsed,
            sprintf('the chain ran past its 0.9s budget: %.3fs', $elapsed),
        );
    }

    /**
     * A bounded hook that arrives with nothing left is refused WITHOUT BEING
     * RUN, and the refusal names the budget rather than the hook — the same
     * shape `expandTemplate()` uses when a `` !`…` `` arrives after the shell
     * budget is gone.
     *
     * THE CLOCK IS SPENT BY THE WHOLE CHAIN, not only by the hooks that
     * contributed to it, and this test is that asymmetry stated deliberately. A
     * hand-written hook cannot name a timeout, so it adds nothing to the budget
     * — but it shares the one terminal the budget exists to keep responsive, so
     * it spends it like everything else. A chain whose unbounded hooks have
     * already burned more than its script hooks asked for has produced exactly
     * the freeze being bounded, and running the script hook on top of it would
     * add to a stall that is already over budget.
     */
    public function testAChainThatRunsOutOfClockDeniesNamingItsBudgetWithoutRunningTheHook(): void
    {
        $this->registry->register($this->slowAllowingHook('warmup', 400_000));
        $this->registry->register($this->hangingScriptHook('never-reached', 0.2));

        $started = microtime(true);
        $result = $this->registry->executeHooks('PreToolUse', $this->createContext('Bash'));
        $elapsed = microtime(true) - $started;

        $this->assertTrue($result->isDenied());
        // THE BUDGET, and it is the STOPPED hook's own figure — 0.2s is
        // `never-reached`'s declared timeout, and the whole chain's, because it
        // is the only bounded hook. The old assertion stopped here, and the
        // whole of E61's S is that stopping here was not enough: naming this
        // number was the misleading part.
        $this->assertStringContainsString('0.2s budget', $result->message);
        $this->assertLessThan(
            1.0,
            $elapsed,
            'the script hook was run anyway: it sleeps 30s, so reaching it at all shows here',
        );
    }

    /**
     * E61's S: THE REFUSAL NAMES THE HOOK THAT SPENT THE CLOCK, not only the
     * hook whose timeout the budget was derived from.
     *
     * These are almost never the same hook, and the old wording implied they
     * were: *"did not finish within the 0.2 seconds their timeouts add up to"*.
     * Every noun in that sentence was true. It was still misleading, because
     * the hook holding the 0.2 is the one being STOPPED — it consumed exactly
     * none of it — and the clock was spent by `warmup`, a hand-written
     * `HookInterface` that contributes NOTHING to the sum
     * ({@see HookRegistry::chainBudgetSeconds()} accumulates only for
     * {@see BoundedHookInterface}) while spending it freely.
     *
     * THE COST OF THE OLD WORDING WAS A WRONG REPAIR. A user reading it raises
     * `never-reached`'s `timeout:` — the one lever that cannot move this
     * outcome: the budget grows, `warmup` overruns the larger budget the same
     * way, and the same hook is denied again. So the assertion that matters is
     * not that the spender is mentioned but that the message says the timeout
     * is the WRONG knob.
     */
    public function testTheChainExpiryRefusalNamesTheUnboundedSpenderAndNotOnlyTheBudget(): void
    {
        $this->registry->register($this->slowAllowingHook('warmup', 400_000));
        $this->registry->register($this->hangingScriptHook('never-reached', 0.2));

        $result = $this->registry->executeHooks('PreToolUse', $this->createContext('Bash'));

        $this->assertTrue($result->isDenied());

        // WHO SPENT IT.
        $this->assertStringContainsString('warmup', $result->message, 'the spender is not named');
        $this->assertStringContainsString(
            'NO declared timeout',
            $result->message,
            'the spender is named but not marked as contributing nothing to the budget',
        );

        // WHO WAS STOPPED, and that it ran for none of the clock.
        $this->assertStringContainsString('Stopped at never-reached', $result->message);
        $this->assertStringContainsString('ran for 0s', $result->message);

        // THE WRONG KNOB, said out loud. This is the clause the old message's
        // reader would have reached for.
        $this->assertStringContainsString(
            'Raising a `timeout:` will NOT fix this',
            $result->message,
            'the refusal still points the user at the timeout it cannot be fixed with',
        );

        // ELAPSED IS STATED NEXT TO BUDGETED, and they differ — the gap is the
        // evidence that the sum was not what ran out. Asserted as an ordering
        // rather than as a literal, since the elapsed figure is wall clock.
        $this->assertMatchesRegularExpression(
            '/ran (\d+(?:\.\d+)?)s against a 0\.2s budget/',
            $result->message,
        );
        preg_match('/ran (\d+(?:\.\d+)?)s against/', $result->message, $m);
        $this->assertGreaterThan(
            0.2,
            (float) $m[1],
            'elapsed was reported as inside the budget the chain had just exceeded',
        );
    }

    /**
     * THE SAME REFUSAL, WITH THE OPPOSITE ADVICE, when every hook that ran was
     * bounded.
     *
     * The "raising a `timeout:` will NOT fix this" clause is true only while an
     * unbounded hook is implicated. Where every spender declared a figure, the
     * timeout IS the knob, and a message that gave the unbounded advice
     * unconditionally would point at the wrong repair in the other direction —
     * the same defect mirrored.
     *
     * WHY A DOUBLE AND NOT A `ScriptHook`. A `ScriptHook` cannot overrun: it
     * kills itself at the deadline it was charged, so an all-`ScriptHook` chain
     * can only exceed its own sum by per-hook `proc_open()`/`proc_close()`
     * overhead — total spend ≈ N x overhead against a sum of ≈ N x overhead,
     * which is a knife edge. MEASURED: four hooks each declaring 10ms denied on
     * some runs and fitted on others, so a test written that way would be a
     * coin flip dressed as an assertion. {@see BoundedHookInterface} is an
     * INTERFACE, "shortening only" is `ScriptHook`'s own contract rather than
     * the interface's, and this branch has to be right for any implementor —
     * so the double declares a figure and then overruns it, which is exactly
     * the case the branch is for.
     */
    public function testAnAllBoundedChainIsToldThatRaisingTheTimeoutsDoesRaiseTheBudget(): void
    {
        $this->registry->register($this->sloppyBoundedHook('overruns', 0.05, 400_000));
        $this->registry->register($this->hangingScriptHook('never-reached', 0.05));

        $result = $this->registry->executeHooks('PreToolUse', $this->createContext('Bash'));

        $this->assertTrue($result->isDenied(), $result->message);
        $this->assertStringContainsString('overruns', $result->message);
        $this->assertStringContainsString('declared a timeout, counted in the budget', $result->message);
        $this->assertStringNotContainsString(
            'will NOT fix this',
            $result->message,
            'an all-bounded chain was told its timeouts are the wrong knob',
        );
        $this->assertStringNotContainsString('NO declared timeout', $result->message);
        $this->assertStringContainsString(
            'raising those timeouts raises this budget',
            $result->message,
        );
    }

    /**
     * A `BoundedHookInterface` that declares a timeout and then ignores it.
     *
     * Not a straw man: the interface promises only that a figure can be read
     * and a shorter one handed back. `ScriptHook` enforces its own by killing a
     * child; an in-process implementor has nothing to kill, which is the whole
     * of E61's L. This double is that shape, held to the smallest form that
     * reaches the branch.
     */
    private function sloppyBoundedHook(string $name, float $declared, int $microseconds): HookInterface
    {
        return new class($name, $declared, $microseconds) implements HookInterface, \SugarCraft\Crush\Hooks\BoundedHookInterface {
            public function __construct(
                private string $name,
                private float $declared,
                private int $microseconds,
            ) {}

            public function name(): string
            {
                return $this->name;
            }

            public function event(): HookEvent
            {
                return HookEvent::PreToolUse;
            }

            public function matcher(): string
            {
                return '.*';
            }

            public function timeoutSeconds(): float
            {
                return $this->declared;
            }

            public function withTimeoutSeconds(float $seconds): self
            {
                return new self($this->name, min($this->declared, $seconds), $this->microseconds);
            }

            public function execute(HookContext $context): HookResult
            {
                usleep($this->microseconds);

                return HookResult::allow();
            }
        };
    }

    /**
     * THE SPENDER LIST IS BOUNDED AND SORTED, because it is built from
     * configuration: a matcher can select the whole chain and every name in it
     * comes from a YAML file.
     *
     * Sorted largest-first is what makes the cut defensible — a list cut at an
     * arbitrary end would drop the spender worth acting on. Six unbounded hooks
     * against {@see HookRegistry::MAX_NAMED_SPENDERS} of 4: the two cheapest
     * must be the two omitted, and the omission must announce itself.
     */
    public function testTheSpenderListIsCutAtItsCheapEndAndSaysSo(): void
    {
        // Descending cost is NOT registration order, so a list that merely
        // preserved registration order would fail this.
        foreach ([['cheap-a', 20_000], ['dear', 700_000], ['cheap-b', 10_000],
                  ['mid', 300_000], ['cheapest', 5_000], ['second', 500_000]] as [$name, $us]) {
            $this->registry->register($this->slowAllowingHook($name, $us));
        }
        $this->registry->register($this->hangingScriptHook('never-reached', 0.3));

        $result = $this->registry->executeHooks('PreToolUse', $this->createContext('Bash'));

        $this->assertTrue($result->isDenied());
        foreach (['dear', 'second', 'mid', 'cheap-a'] as $kept) {
            $this->assertStringContainsString($kept, $result->message, "the $kept spender was cut");
        }
        $this->assertStringNotContainsString('cheapest', $result->message);
        $this->assertStringNotContainsString('cheap-b', $result->message);
        $this->assertStringContainsString('and 2 more hook(s), not listed', $result->message);

        // Largest first, checked by POSITION rather than by presence — a list
        // that named the right four in the wrong order would pass the loop above.
        $this->assertLessThan(
            (int) strpos($result->message, 'mid ('),
            (int) strpos($result->message, 'dear ('),
            'the spender list is not ordered by what each hook cost',
        );
    }

    /**
     * A SUB-MICROSECOND BUDGET expires before any hook runs, and the refusal
     * must not then blame a hook's runtime or print `0s` for a nonzero figure.
     *
     * Two things this pins that nothing else does. First, the empty-ledger
     * branch: `timeout: 0` does NOT reach it —
     * {@see \SugarCraft\Crush\Hooks\ScriptHook::timeoutSeconds()} reads zero
     * as "unset" and answers its 60-second default — so the route in is a
     * positive value smaller than the walk from arming the deadline to the
     * first hook. Second, the rendering: at three decimals this refusal read
     * `ran 0s against a 0s budget`, a sentence that refutes itself.
     */
    public function testAChainWhoseBudgetExpiresBeforeAnyHookRunsBlamesNoHooksRuntime(): void
    {
        $this->registry->register($this->hangingScriptHook('micro', 0.000001));

        $result = $this->registry->executeHooks('PreToolUse', $this->createContext('Bash'));

        $this->assertTrue($result->isDenied());
        $this->assertStringContainsString('No hook in the chain had run yet', $result->message);
        $this->assertStringContainsString('scheduling overhead', $result->message);
        $this->assertStringNotContainsString('Clock spent by', $result->message);
        // The vacuous variant: "every hook that RAN declared a timeout" about a
        // chain in which none ran.
        $this->assertStringNotContainsString('Every hook that ran', $result->message);
        $this->assertStringNotContainsString(
            'against a 0s budget',
            $result->message,
            'a positive sub-millisecond budget was rendered as zero',
        );
    }

    /** A plain HookInterface that costs wall clock and then permits the call. */
    private function slowAllowingHook(string $name, int $microseconds): HookInterface
    {
        return new class($name, $microseconds) implements HookInterface {
            public function __construct(
                private string $name,
                private int $microseconds,
            ) {}

            public function name(): string
            {
                return $this->name;
            }

            public function event(): HookEvent
            {
                return HookEvent::PreToolUse;
            }

            public function matcher(): string
            {
                return '.*';
            }

            public function execute(HookContext $context): HookResult
            {
                usleep($this->microseconds);

                return HookResult::allow();
            }
        };
    }

    /**
     * NO BOUNDED HOOK, NO DEADLINE — and that is the arm that keeps the whole
     * built-in chain working.
     *
     * A hand-written `HookInterface` is a synchronous call in this process with
     * no deadline to honour, so a chain of those contributes nothing to the
     * budget and is charged nothing. Arming a zero-second deadline over them
     * instead would deny every call the built-ins ever see.
     */
    public function testAChainWithNoBoundedHookIsNotGivenADeadlineAtAll(): void
    {
        $this->registry->register($this->createHook('plain', HookEvent::PreToolUse, 'ok', '.*'));

        $result = $this->registry->executeHooks('PreToolUse', $this->createContext('Bash'));

        $this->assertTrue($result->permitsExecution(), 'an unbounded chain still runs');
    }

    // =========================================================================
    // E60 x E65 — the re-scan carries a rewrite back through the SAME transport
    // =========================================================================

    /**
     * THE TWO FINDINGS MEET HERE, WHICH IS WHY THEY WERE FIXED TOGETHER.
     *
     * {@see HookRegistry::executeHooks()} feeds an accepted rewrite back as the
     * NEXT pass's `toolInput` — so a rewrite has to survive the payload
     * transport a second time, as input. While that transport was a single
     * environment entry, a rewrite could never exceed `MAX_ARG_STRLEN`
     * (131,072 bytes for `NAME=VALUE\0` on this host) no matter what the hook
     * printed: the re-scan died with `E2BIG` and the chain denied.
     *
     * WHAT THIS FIXTURE ACTUALLY MEASURES, re-measured with the fixture below
     * rather than with the neighbouring experiment an earlier version of this
     * comment imported its figures from. The original here is 250,011 bytes,
     * which is already past the old ceiling, so at afe3c26b the `E2BIG` landed
     * on pass ONE — `ScriptHook::execute()` called DIRECTLY returned
     * `action=deny` with no `modifiedInput` at all, and the same hook through
     * `executeHooks()` returned `deny  "Hook bulk-rewriter could not be
     * executed"`. It is red at afe3c26b for the plain E65 reason and does NOT
     * demonstrate the re-scan seam there. It exercises the seam against the
     * code as it stands now, where pass 1 carries a 250,011-byte input and pass
     * 2 has to carry the 200,011-byte rewrite back through the same transport.
     * (200,014 is the figure for a `{"command":…}` payload; this fixture's
     * `{"body":…}` wrapper is three bytes shorter.)
     *
     * That is why "bound the rewrite" and "fix the transport" could not be two
     * changes. A rewrite ceiling under 128 KiB would have made the E2BIG path
     * unreachable from this direction and the transport fix would have arrived
     * against a premise that was no longer true; fixing only the transport
     * would have lifted a de-facto 128 KiB bound off `modifiedInput` — which is
     * not prompt text but THE ARGUMENTS THAT EXECUTE — with nothing put back.
     *
     * So this pins the combined behaviour: a large call is rewritten, the
     * rewrite is re-scanned at its full size, and the chain settles on it.
     */
    public function testAChainReScansARewriteTooLargeForOneEnvironmentEntry(): void
    {
        $original = json_encode(['body' => str_repeat('A', 250_000)], JSON_THROW_ON_ERROR);

        $this->registry->register(new \SugarCraft\Crush\Hooks\ScriptHook(
            name: 'bulk-rewriter',
            event: HookEvent::PreToolUse,
            matcher: '^Bash$',
            command: 'printf \'{"body":"%0200000d"}\' 0; exit 4',
            description: '',
            timeoutSeconds: 30.0,
        ));

        $result = $this->registry->executeHooks('PreToolUse', $this->createContext('Bash', $original));

        $this->assertTrue($result->isModified(), 'the chain refused a large rewrite: ' . $result->message);

        $rewritten = $result->rewrittenArgs();
        $this->assertIsArray($rewritten);
        $this->assertSame(200_000, \strlen((string) ($rewritten['body'] ?? '')));
    }

    /** A ScriptHook that will not finish inside the budget it is given. */
    private function hangingScriptHook(
        string $name,
        float $timeout,
        string $matcher = '^Bash$',
    ): \SugarCraft\Crush\Hooks\ScriptHook {
        return new \SugarCraft\Crush\Hooks\ScriptHook(
            name: $name,
            event: HookEvent::PreToolUse,
            matcher: $matcher,
            command: 'sleep 30',
            description: '',
            timeoutSeconds: $timeout,
        );
    }

    private function createContext(string $toolName, string $toolInput = 'input'): HookContext
    {
        return new HookContext(
            sessionId: 'test_session',
            toolName: $toolName,
            toolArgs: [],
            toolInput: $toolInput,
            toolOutput: 'output',
            model: 'test-model',
            provider: 'test-provider',
            projectRoot: '/test/root',
        );
    }
}
