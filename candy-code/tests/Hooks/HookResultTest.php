<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Hooks;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Hooks\HookResult;

/**
 * @see HookResult
 */
final class HookResultTest extends TestCase
{
    // =========================================================================
    // Factory Method Tests - ALLOW
    // =========================================================================

    public function testAllow(): void
    {
        $result = HookResult::allow();

        $this->assertSame(HookResult::ALLOW, $result->action);
        $this->assertSame('', $result->message);
        $this->assertNull($result->modifiedInput);
        $this->assertSame('', $result->additionalContext);
    }

    public function testAllowWithMessage(): void
    {
        $message = 'Operation allowed by policy';

        $result = HookResult::allow($message);

        $this->assertSame(HookResult::ALLOW, $result->action);
        $this->assertSame($message, $result->message);
        $this->assertNull($result->modifiedInput);
    }

    // =========================================================================
    // Factory Method Tests - DENY
    // =========================================================================

    public function testDeny(): void
    {
        $message = 'Operation denied';

        $result = HookResult::deny($message);

        $this->assertSame(HookResult::DENY, $result->action);
        $this->assertSame($message, $result->message);
        $this->assertNull($result->modifiedInput);
    }

    public function testDenyRequiresMessage(): void
    {
        $result = HookResult::deny('Security policy violation');

        $this->assertSame(HookResult::DENY, $result->action);
        $this->assertNotSame('', $result->message);
    }

    // =========================================================================
    // Factory Method Tests - MODIFY
    // =========================================================================

    public function testModify(): void
    {
        $newInput = 'modified tool input';
        $message = 'Input was modified';

        $result = HookResult::modify($newInput, $message);

        $this->assertSame(HookResult::MODIFY, $result->action);
        $this->assertSame($message, $result->message);
        $this->assertSame($newInput, $result->modifiedInput);
    }

    public function testModifyWithEmptyMessage(): void
    {
        $newInput = 'new content';

        $result = HookResult::modify($newInput);

        $this->assertSame(HookResult::MODIFY, $result->action);
        $this->assertSame('', $result->message);
        $this->assertSame($newInput, $result->modifiedInput);
    }

    // =========================================================================
    // Predicate Method Tests - isAllowed
    // =========================================================================

    public function testIsAllowed(): void
    {
        $result = HookResult::allow();

        $this->assertTrue($result->isAllowed());
    }

    public function testIsAllowedReturnsFalseForDeny(): void
    {
        $result = HookResult::deny('Denied');

        $this->assertFalse($result->isAllowed());
    }

    public function testIsAllowedReturnsFalseForModify(): void
    {
        $result = HookResult::modify('changed');

        $this->assertFalse($result->isAllowed());
    }

    // =========================================================================
    // Predicate Method Tests - isDenied
    // =========================================================================

    public function testIsDenied(): void
    {
        $result = HookResult::deny('Denied');

        $this->assertTrue($result->isDenied());
    }

    public function testIsDeniedReturnsFalseForAllow(): void
    {
        $result = HookResult::allow();

        $this->assertFalse($result->isDenied());
    }

    public function testIsDeniedReturnsFalseForModify(): void
    {
        $result = HookResult::modify('changed');

        $this->assertFalse($result->isDenied());
    }

    // =========================================================================
    // Predicate Method Tests - isModified
    // =========================================================================

    public function testIsModified(): void
    {
        $result = HookResult::modify('changed');

        $this->assertTrue($result->isModified());
    }

    public function testIsModifiedReturnsFalseForAllow(): void
    {
        $result = HookResult::allow();

        $this->assertFalse($result->isModified());
    }

    public function testIsModifiedReturnsFalseForDeny(): void
    {
        $result = HookResult::deny('Denied');

        $this->assertFalse($result->isModified());
    }

    // =========================================================================
    // Message Preservation Tests
    // =========================================================================

    public function testAllowMessage(): void
    {
        $message = 'Custom allow message';

        $result = HookResult::allow($message);

        $this->assertSame($message, $result->message);
    }

    public function testModifyPreservesMessage(): void
    {
        $newInput = 'modified input';
        $message = 'Modification reason';

        $result = HookResult::modify($newInput, $message);

        $this->assertSame($message, $result->message);
        $this->assertSame($newInput, $result->modifiedInput);
    }

    public function testDenyPreservesMessage(): void
    {
        $message = 'Access denied due to policy';

        $result = HookResult::deny($message);

        $this->assertSame($message, $result->message);
    }

    // =========================================================================
    // Factory Method Tests - ASK
    // =========================================================================

    public function testAsk(): void
    {
        $message = 'Run `rm -rf /tmp/build`?';

        $result = HookResult::ask($message);

        $this->assertSame(HookResult::ASK, $result->action);
        $this->assertSame($message, $result->message);
        $this->assertNull($result->modifiedInput);
    }

    public function testAskIsDistinctFromTheOtherThreeActions(): void
    {
        $result = HookResult::ask('Proceed?');

        $this->assertTrue($result->isAsk());
        $this->assertFalse($result->isAllowed());
        $this->assertFalse($result->isDenied());
        $this->assertFalse($result->isModified());
    }

    public function testIsAskReturnsFalseForOtherActions(): void
    {
        $this->assertFalse(HookResult::allow()->isAsk());
        $this->assertFalse(HookResult::deny('nope')->isAsk());
        $this->assertFalse(HookResult::modify('changed')->isAsk());
    }

    // =========================================================================
    // Fail-Closed Gate Tests - permitsExecution
    // =========================================================================

    public function testPermitsExecutionForAllowAndModify(): void
    {
        $this->assertTrue(HookResult::allow()->permitsExecution());
        $this->assertTrue(HookResult::modify('changed')->permitsExecution());
    }

    public function testPermitsExecutionIsFalseForDeny(): void
    {
        $this->assertFalse(HookResult::deny('Security policy violation')->permitsExecution());
    }

    public function testAskDoesNotPermitExecution(): void
    {
        // An unanswered question is not permission: the tool must not run
        // while the prompt is outstanding.
        $this->assertFalse(HookResult::ask('Proceed?')->permitsExecution());
    }

    public function testUnrecognisedActionDoesNotPermitExecution(): void
    {
        // Regression guard for the fail-open shape: a gate written as
        // "!isDenied()" would have permitted this, since a malformed or
        // future action is neither deny nor allow.
        $result = new HookResult('quarantine', 'unknown verdict');

        $this->assertFalse($result->permitsExecution());
        $this->assertFalse($result->isDenied());
    }

    // =========================================================================
    // Immutability Tests
    // =========================================================================

    public function testResultIsReadonly(): void
    {
        $result = HookResult::allow('test');

        // Verify properties are readonly by attempting to modify (should fail at runtime)
        $this->assertSame('allow', $result->action);
        $this->assertSame('test', $result->message);
        $this->assertSame('', $result->additionalContext);

        // P7.S1: the 4th payload channel is readonly too. Writing to it must
        // throw, not silently mutate the shared verdict the chain handed up.
        $this->expectException(\Error::class);
        /** @phpstan-ignore-next-line intentionally writing a readonly property */
        $result->additionalContext = 'attempted mutation';
    }

    // =========================================================================
    // additionalContext tests (P7.S1 — the model-visible note field)
    // =========================================================================

    public function testAllowCarriesAdditionalContext(): void
    {
        $result = HookResult::allow('shown to the deny gate', 'shown to the model');

        $this->assertSame('shown to the deny gate', $result->message);
        $this->assertSame('shown to the model', $result->additionalContext);
    }

    public function testDenyAndModifyCarryAdditionalContext(): void
    {
        $deny = HookResult::deny('no', 'model note');
        $modify = HookResult::modify('{"a":1}', 'why', 'model note');

        $this->assertSame('model note', $deny->additionalContext);
        $this->assertSame('model note', $modify->additionalContext);
        // modifiedInput stays the JSON arguments — never conflated with the note.
        $this->assertSame('{"a":1}', $modify->modifiedInput);
    }

    public function testAskCarriesAdditionalContext(): void
    {
        $ask = HookResult::ask('Allow Bash?', '{"command":"ls"}', 'model note');

        $this->assertSame('Allow Bash?', $ask->message);
        $this->assertSame('{"command":"ls"}', $ask->modifiedInput);
        $this->assertSame('model note', $ask->additionalContext);
    }

    public function testWithContextSetReplacesAndIsImmutable(): void
    {
        $result = HookResult::allow('');

        $with = $result->withContextSet('the note');

        $this->assertSame('', $result->additionalContext, 'the original must stay untouched');
        $this->assertSame('the note', $with->additionalContext);
        $this->assertSame('allow', $with->action);
    }

    public function testWithContextSetIsANoOpWhenTheValueAlreadyMatches(): void
    {
        $result = HookResult::allow('', 'same');

        // Byte-identical no-op path: an unchanged value returns the SAME instance,
        // which is what lets the empty-context chain add nothing to a settled
        // result without a fresh allocation.
        $this->assertSame($result, $result->withContextSet('same'));
        $this->assertSame($result, $result->withContextSet('same')->withContextSet('same'));
    }

    public function testAdditionalContextCapConstantIsBytes(): void
    {
        // R-3: the ceiling is 10,000 BYTES (the plan step said "characters"; the
        // subsystem is byte-denominated — see the const docblock).
        $this->assertSame(10000, HookResult::MAX_ADDITIONAL_CONTEXT_BYTES);
    }

    public function testModifiedInputIsPreserved(): void
    {
        $originalInput = 'original';
        $modifiedInput = 'modified';

        $result = HookResult::modify($modifiedInput);

        $this->assertSame($modifiedInput, $result->modifiedInput);
        $this->assertNotSame($originalInput, $result->modifiedInput);
    }
}
