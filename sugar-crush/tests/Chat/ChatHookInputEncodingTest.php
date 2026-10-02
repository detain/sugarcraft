<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Hooks\ScriptHook;
use SugarCraft\Crush\Permissions\DenialKind;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\ToolResult;

/**
 * Audit F-H3, the Chat mirrors: {@see Chat::gateToolCall()} built
 * `CRUSH_TOOL_INPUT` with a bare json_encode(), so `/etc/passwd` reached a
 * hook as `\/etc\/passwd` and the documented `grep -qF /etc/passwd` guard
 * never fired, and its `?: '{}'` fallback handed a deny hook an EMPTY map
 * while the call ran with its real arguments. The engine path was fixed in
 * `fa74a16be`; Chat now encodes through the same {@see \SugarCraft\Crush\Runtime::hookInput()}.
 */
final class ChatHookInputEncodingTest extends TestCase
{
    public function testTheToolInputKeepsSlashesAndUnicode(): void
    {
        $seen = [];
        $this->gate(['file_path' => '/etc/passwd', 'note' => 'café'], $this->recordingHook($seen));

        $this->assertSame(['{"file_path":"/etc/passwd","note":"café"}'], $seen);
    }

    /** The audit's repro shape: a real grep guard now fires on the path. */
    public function testAGrepGuardOnAPathFires(): void
    {
        [, $denied] = $this->gate(['file_path' => '/etc/passwd'], new ScriptHook(
            'no-passwd',
            HookEvent::PreToolUse,
            '.*',
            'printf %s "$CRUSH_TOOL_INPUT" | grep -qF /etc/passwd && { echo "no passwd" >&2; exit 2; }; exit 0',
            'guard',
        ));

        $this->assertInstanceOf(ToolResult::class, $denied, 'the guard never matched the escaped path');
        $this->assertSame(DenialKind::Hook, $denied->denial);
        $this->assertStringContainsString('no passwd', (string) $denied->error);
    }

    /** Invalid UTF-8 costs U+FFFD in the hook's copy, never the whole encoding. */
    public function testInvalidUtf8IsSubstitutedNotDropped(): void
    {
        $seen = [];
        $this->gate(['command' => "cat \xff/etc/shadow"], $this->recordingHook($seen));

        $this->assertSame(["{\"command\":\"cat \u{FFFD}/etc/shadow\"}"], $seen);
    }

    /**
     * Arguments that cannot be encoded at all are REFUSED before any hook
     * runs, never handed to the chain as `{}`.
     */
    public function testUnencodableArgumentsAreRefusedBeforeAnyHookRuns(): void
    {
        $seen = [];
        [, $denied, $context] = $this->gate(['n' => INF], $this->recordingHook($seen));

        $this->assertSame([], $seen, 'a hook was handed arguments it could not have read');
        $this->assertNull($context, 'a refused call has no PostToolUse context');
        $this->assertInstanceOf(ToolResult::class, $denied);
        $this->assertSame(DenialKind::Hook, $denied->denial);
        $this->assertStringStartsWith('Hook denied: the arguments of probe could not be encoded as JSON', (string) $denied->error);
    }

    /** The turn-lifecycle context (UserPromptSubmit / SessionStart) uses the same encoding. */
    public function testATurnHookPromptKeepsSlashesAndUnicode(): void
    {
        $method = new \ReflectionMethod(Chat::class, 'turnHookContext');
        /** @var HookContext $context */
        $context = $method->invoke(new Chat(), 'UserPromptSubmit', 'read /etc/hosts — café', false);

        $this->assertSame('{"prompt":"read /etc/hosts — café"}', $context->toolInput);
    }

    // ---- fixtures ----------------------------------------------------------

    /**
     * Run the PreToolUse gate for one `probe` call.
     *
     * @param array<string, mixed> $arguments
     * @return array{0: ToolCall, 1: ?ToolResult, 2: ?HookContext, 3: ?HookResult, 4: string}
     */
    private function gate(array $arguments, HookInterface $hook): array
    {
        $hooks = new HookManager(new HookRegistry());
        $hooks->register($hook);
        $chat = (new Chat())->registerTool('probe', static fn(): string => 'ran')->withHooks($hooks);

        $method = new \ReflectionMethod(Chat::class, 'gateToolCall');

        return $method->invoke($chat, new ToolCall('probe', $arguments, 'call_1'));
    }

    /** @param list<string> $seen */
    private function recordingHook(array &$seen): HookInterface
    {
        return new class ($seen) implements HookInterface {
            /** @param list<string> $seen */
            public function __construct(private array &$seen) {}
            public function name(): string { return 'record'; }
            public function event(): HookEvent { return HookEvent::PreToolUse; }
            public function matcher(): string { return '.*'; }

            public function execute(HookContext $context): HookResult
            {
                $this->seen[] = $context->toolInput;

                return HookResult::allow();
            }
        };
    }
}
