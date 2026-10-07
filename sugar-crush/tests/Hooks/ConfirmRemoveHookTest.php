<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Hooks;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Hooks\BuiltIn\ConfirmRemoveHook;
use SugarCraft\Crush\Hooks\BuiltIn\PermissionGateHook;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;

/**
 * @see ConfirmRemoveHook
 */
final class ConfirmRemoveHookTest extends TestCase
{
    // =========================================================================
    // Basic Interface Tests
    // =========================================================================

    public function testName(): void
    {
        $hook = new ConfirmRemoveHook();

        $this->assertSame('confirm-rm', $hook->name());
    }

    public function testEvent(): void
    {
        $hook = new ConfirmRemoveHook();

        $this->assertSame(HookEvent::PreToolUse, $hook->event());
    }

    public function testMatcher(): void
    {
        $hook = new ConfirmRemoveHook();

        $this->assertSame('^Bash$', $hook->matcher());
    }

    // =========================================================================
    // Dangerous rm Command Denial Tests
    // =========================================================================

    public function testDenyRecursiveRm(): void
    {
        $hook = new ConfirmRemoveHook();
        $context = $this->createContext('rm -rf /important');

        $result = $hook->execute($context);

        $this->assertTrue($result->isDenied());
        $this->assertStringContainsString('recursive', $result->message);
    }

    public function testDenyRecursiveRmWithSpace(): void
    {
        $hook = new ConfirmRemoveHook();
        $context = $this->createContext('rm -r /important');

        $result = $hook->execute($context);

        $this->assertTrue($result->isDenied());
    }

    public function testDenyForceRm(): void
    {
        $hook = new ConfirmRemoveHook();
        $context = $this->createContext('rm -f file.txt');

        $result = $hook->execute($context);

        $this->assertTrue($result->isDenied());
        $this->assertStringContainsString('force', $result->message);
    }

    public function testDenyRecursiveForceRm(): void
    {
        $hook = new ConfirmRemoveHook();
        $context = $this->createContext('rm -rf ./my-project');

        $result = $hook->execute($context);

        $this->assertTrue($result->isDenied());

        // Owner ruling 2026-10-06: the same hook wired to a bypass session
        // stands down, and a stricter mode does not. A hook with no reader at
        // all — the line above — enforces exactly as it always did.
        $bypassing = new ConfirmRemoveHook(static fn (): PermissionMode => PermissionMode::BypassPermissions);
        $this->assertTrue($bypassing->execute($context)->isAllowed(), 'bypass is above this guard-rail');
        $asking = new ConfirmRemoveHook(static fn (): PermissionMode => PermissionMode::Default);
        $this->assertTrue($asking->execute($context)->isDenied(), 'only bypass stands the hook down');
    }

    public function testDenyCombinedFlags(): void
    {
        $hook = new ConfirmRemoveHook();
        $context = $this->createContext('rm -r -f -v directory');

        $result = $hook->execute($context);

        $this->assertTrue($result->isDenied());
    }

    // =========================================================================
    // Extended Destructive-Form Denial Tests (long flags + other tools)
    // =========================================================================

    public function testDenyLongFormRecursiveRm(): void
    {
        $hook = new ConfirmRemoveHook();
        $context = $this->createContext('rm --recursive /important');

        $result = $hook->execute($context);

        $this->assertTrue($result->isDenied());
    }

    public function testDenyLongFormForceRm(): void
    {
        $hook = new ConfirmRemoveHook();
        $context = $this->createContext('rm --force secret.txt');

        $result = $hook->execute($context);

        $this->assertTrue($result->isDenied());
    }

    public function testDenyFindDelete(): void
    {
        $hook = new ConfirmRemoveHook();
        $context = $this->createContext("find . -name '*.log' -delete");

        $result = $hook->execute($context);

        $this->assertTrue($result->isDenied());
    }

    public function testDenyShred(): void
    {
        $hook = new ConfirmRemoveHook();
        $context = $this->createContext('shred -u secret.key');

        $result = $hook->execute($context);

        $this->assertTrue($result->isDenied());
    }

    public function testDenyDdWithOutputFile(): void
    {
        $hook = new ConfirmRemoveHook();
        $context = $this->createContext('dd if=/dev/zero of=/dev/sda bs=1M');

        $result = $hook->execute($context);

        $this->assertTrue($result->isDenied());
    }

    public function testShellIndirectionIsNotDetected(): void
    {
        // Documents the acknowledged blind spot: regex cannot see through
        // variable indirection, so `x=rf; rm -$x` slips past. This asserts the
        // heuristic's known limit, NOT a desired behaviour.
        $hook = new ConfirmRemoveHook();
        $context = $this->createContext('x=rf; rm -$x /tmp/data');

        $result = $hook->execute($context);

        $this->assertTrue($result->isAllowed());
    }

    /**
     * AUDIT F-P1: the patterns ran on the raw text and want whitespace right
     * before the flag, so a QUOTED flag — the same argv once bash removes the
     * quotes — passed. Each is matched against the quote-removed words now.
     *
     * @return iterable<string, array{string}>
     */
    public static function quotedDestructiveCases(): iterable
    {
        yield 'single-quoted rm flag' => ["rm '-rf' x"];
        yield 'double-quoted rm flag' => ['rm "-rf" x'];
        yield 'single-quoted rm flag, home' => ["rm '-rf' ~"];
        yield 'quoted -r only' => ["rm '-r' ./dir"];
        yield 'flag split by quotes' => ["rm -'r'f x"];
        yield 'ANSI-C quoted flag' => ["rm \$'-rf' x"];
        yield 'quoted long flag' => ["rm '--recursive' x"];
        yield 'long-option abbreviation' => ['rm --rec x'];
        yield 'single-quoted find -delete' => ["find . '-delete'"];
        yield 'double-quoted find -delete' => ['find . -name "*.o" "-delete"'];
        yield 'quoted dd of=' => ["dd 'of=/dev/sda' if=/dev/zero"];
        yield 'double-quoted dd of=' => ['dd if=/dev/zero "of=/dev/sda"'];
        // Unterminated quote: unparseable, so the quote-stripped raw text is
        // the second candidate.
        yield 'unparseable line' => ["rm '-rf' x 'oops"];
    }

    #[DataProvider('quotedDestructiveCases')]
    public function testQuotedFlagsAreDenied(string $command): void
    {
        $result = (new ConfirmRemoveHook())->execute($this->createContext($command));

        $this->assertTrue($result->isDenied(), json_encode($command) . ' must be denied');
    }

    /**
     * The audit's repro, end to end, re-measured after the owner ruling of
     * 2026-10-06 (bypass is allow-all): under `bypass-permissions` this hook
     * stands down, so the ONLY thing still refusing destructive Bash is the
     * gate's step-0 breaker — which is quoted-flag aware and catches every
     * `rm`-shaped row here but not `find -delete`. Each row states its bypass
     * verdict; under a non-bypass session every row still denies, pinned in
     * the same run so the pair cannot drift apart.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function fullChainCases(): iterable
    {
        yield 'rm quoted flag, home' => ["rm '-rf' ~", true];
        yield 'rm double-quoted flag, root' => ['rm "-rf" /', true];
        yield 'find quoted -delete' => ["find . '-delete'", false];
        yield 'rm second target root' => ['rm -rf ./x /', true];
        yield 'rm HOME variable' => ['rm -rf $HOME', true];
    }

    #[DataProvider('fullChainCases')]
    public function testFullBuiltInChainInBypassKeepsOnlyTheBreakerFloor(string $command, bool $breakerCaught): void
    {
        $verdict = $this->chain($command, PermissionMode::BypassPermissions);
        $this->assertSame(
            $breakerCaught,
            $verdict->isDenied(),
            json_encode($command) . ($breakerCaught ? ' is breaker territory and must still deny' : ' is hook territory and must run') . " under bypass: {$verdict->message}",
        );

        // Control: with the hook in force, every row denies — none of these
        // shapes is a licence the mode switch silently widens outside bypass.
        $this->assertTrue(
            $this->chain($command, PermissionMode::Default)->isDenied(),
            json_encode($command) . ' must deny outside bypass',
        );
    }

    private function chain(string $command, PermissionMode $mode): HookResult
    {
        $manager = new HookManager(new HookRegistry());
        $manager->registerBuiltIns();
        $manager->register(new PermissionGateHook(new PermissionGate($mode)));

        return $manager->preToolUse($this->createContext($command));
    }

    public function testQuotedTextThatIsNotAnRmStaysAllowed(): void
    {
        foreach (["echo '~'", "grep -e '-r' notes.txt", 'ls "-l"', "find . -name '*.php'"] as $command) {
            $this->assertTrue(
                (new ConfirmRemoveHook())->execute($this->createContext($command))->isAllowed(),
                json_encode($command) . ' must stay allowed',
            );
        }
    }

    // =========================================================================
    // Safe rm Command Allow Tests
    // =========================================================================

    public function testAllowSimpleRm(): void
    {
        $hook = new ConfirmRemoveHook();
        $context = $this->createContext('rm file.txt');

        $result = $hook->execute($context);

        $this->assertTrue($result->isAllowed());
    }

    public function testAllowRmWithSpaces(): void
    {
        $hook = new ConfirmRemoveHook();
        $context = $this->createContext('rm  file.txt');

        $result = $hook->execute($context);

        $this->assertTrue($result->isAllowed());
    }

    public function testAllowRmSingleFile(): void
    {
        $hook = new ConfirmRemoveHook();
        $context = $this->createContext('rm single-file.txt');

        $result = $hook->execute($context);

        $this->assertTrue($result->isAllowed());
    }

    // =========================================================================
    // Edge Case Tests
    // =========================================================================

    public function testAllowEmptyInput(): void
    {
        $hook = new ConfirmRemoveHook();
        $context = $this->createContext('');

        $result = $hook->execute($context);

        $this->assertTrue($result->isAllowed());
    }

    public function testAllowInteractiveRm(): void
    {
        $hook = new ConfirmRemoveHook();
        // Interactive rm (no flags) should be allowed
        $context = $this->createContext('rm -i file.txt');

        $result = $hook->execute($context);

        $this->assertTrue($result->isAllowed());
    }

    public function testAllowVerboseRm(): void
    {
        $hook = new ConfirmRemoveHook();
        // Verbose flag only (not recursive or force) should be allowed
        $context = $this->createContext('rm -v file.txt');

        $result = $hook->execute($context);

        $this->assertTrue($result->isAllowed());
    }

    public function testAllowRmWithOtherSafeFlags(): void
    {
        $hook = new ConfirmRemoveHook();
        $context = $this->createContext('rm -iv file.txt');

        $result = $hook->execute($context);

        // -i is interactive (safe), -v is verbose (safe) - only r/f should deny
        $this->assertTrue($result->isAllowed());
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    private function createContext(string $command): HookContext
    {
        return new HookContext(
            sessionId: 'test-session-456',
            toolName: 'Bash',
            toolArgs: ['command' => $command],
            toolInput: json_encode(['command' => $command]),
            toolOutput: '',
            model: 'test-model',
            provider: 'test-provider',
            projectRoot: '/tmp/test-project',
        );
    }
}
