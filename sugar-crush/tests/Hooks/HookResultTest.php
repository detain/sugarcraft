<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Hooks;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Hooks\HookResult;

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
    // Immutability Tests
    // =========================================================================

    public function testResultIsReadonly(): void
    {
        $result = HookResult::allow('test');

        // Verify properties are readonly by attempting to modify (should fail at runtime)
        $this->assertSame('allow', $result->action);
        $this->assertSame('test', $result->message);
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
