<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Hooks;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Hooks\BuiltIn\ProtectFilesHook;
use SugarCraft\Code\Hooks\HookContext;
use SugarCraft\Code\Hooks\HookEvent;
use SugarCraft\Code\Hooks\HookInterface;
use SugarCraft\Code\Hooks\HookRegistry;
use SugarCraft\Code\Hooks\HookResult;
use SugarCraft\Code\Tools\BuiltIn\McpCallTool;
use SugarCraft\Code\Tools\McpToolBridge;

/**
 * C-MCP-1.b: a deferred MCP call is the bridged call it dispatches, seen
 * from two spellings, and a hook chain must not go blind between them.
 *
 * Before this step {@see HookRegistry::findMatches()} tested every matcher
 * against the frame the model sent, so the moment the schema floor deferred
 * a server, a guard written `mcp__server__*` — the ONLY spelling a hooks.yaml
 * author can name a server tool with — stopped seeing its calls: the call
 * arrived as `mcp_call` and the server lived inside its arguments. These
 * tests pin the dual subject (the matcher now sees either spelling of the
 * same call) at the registry, through the real ProtectFilesHook, and in the
 * negative: names that are NOT a resolved dispatch keep exactly the single
 * subject they always had.
 */
final class McpCallHookParityTest extends TestCase
{
    private static int $serial = 0;

    private HookRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new HookRegistry();
    }

    /** A server name unique to this test process, so fixtures cannot collide. */
    private function uniqueServer(): string
    {
        return 'cmcp1b-' . (++self::$serial) . '-' . getmypid();
    }

    /** @param array<string,mixed> $args */
    private function context(string $toolName, array $args): HookContext
    {
        return new HookContext(
            sessionId: 'mcp-call-hook-parity',
            toolName: $toolName,
            toolArgs: $args,
            toolInput: json_encode($args) ?: '{}',
            toolOutput: '',
            model: 'test-model',
            provider: 'test-provider',
            projectRoot: '/tmp/mcp-call-hook-parity',
        );
    }

    /** A PreToolUse hook that refuses, by whatever matcher is given. */
    private function refusingHook(string $name, string $matcher): HookInterface
    {
        return new class($name, $matcher) implements HookInterface {
            public function __construct(
                private string $guardName,
                private string $guardMatcher,
            ) {}

            public function name(): string
            {
                return $this->guardName;
            }

            public function event(): HookEvent
            {
                return HookEvent::PreToolUse;
            }

            public function matcher(): string
            {
                return $this->guardMatcher;
            }

            public function execute(HookContext $context): HookResult
            {
                return HookResult::deny('refused by ' . $this->guardName);
            }
        };
    }

    // (1) A server-scoped matcher (`mcp__srv__`-prefixed) keeps its force
    //     over the deferred args form — the headline fix. The inline hook
    //     also records the payload: the PAYLOAD never moves, only the
    //     matcher's view widens.
    public function testAServerScopedMatcherStillSeesTheDeferredCall(): void
    {
        $srv = $this->uniqueServer();

        $hook = new class($srv) implements HookInterface {
            public ?string $sawToolName = null;

            public function __construct(private string $server) {}

            public function name(): string
            {
                return 'wire-guard';
            }

            public function event(): HookEvent
            {
                return HookEvent::PreToolUse;
            }

            public function matcher(): string
            {
                return '^mcp__' . $this->server . '__.*$';
            }

            public function execute(HookContext $context): HookResult
            {
                $this->sawToolName = $context->toolName;

                return HookResult::deny('refused by the wire guard');
            }
        };
        $this->registry->register($hook);

        $result = $this->registry->executeHooks(
            'PreToolUse',
            $this->context(McpCallTool::NAME, ['server' => $srv, 'tool' => 'drop_table']),
        );

        $this->assertFalse($result->isAllowed(), 'the floor deferring the server must not unblind the guard');
        $this->assertSame('wire-guard', $result->refusedBy);
        $this->assertSame(
            McpCallTool::NAME,
            $hook->sawToolName,
            'the matcher saw either spelling; what the hook executes against is still the dispatcher frame',
        );
    }

    // (2) An exact anchored wire matcher — the spelling a Claude-imported
    //     guard arrives as — binds the args form, and nothing else on that
    //     server does.
    public function testAnExactWireMatcherBindsTheArgsForm(): void
    {
        $srv = $this->uniqueServer();
        $this->registry->register($this->refusingHook('drop-exact', '^mcp__' . $srv . '__drop$'));

        $denied = $this->registry->executeHooks(
            'PreToolUse',
            $this->context(McpCallTool::NAME, ['server' => $srv, 'tool' => 'drop']),
        );
        $this->assertFalse($denied->isAllowed(), 'the named tool via the dispatcher is that tool');

        $other = $this->registry->executeHooks(
            'PreToolUse',
            $this->context(McpCallTool::NAME, ['server' => $srv, 'tool' => 'list_rows']),
        );
        $this->assertTrue($other->isAllowed(), 'and only that tool — the rest of the server is untouched');
    }

    // (3) The real guard: ProtectFilesHook fires for a deferred call whose
    //     nested arguments name a protected file. Its matcher names the
    //     `mcp__` family and never the dispatcher, so before the dual
    //     subject the dot-env inside `arguments` sailed through unjudged.
    public function testProtectFilesHookRefusesADeferredCallNamingAProtectedFile(): void
    {
        $srv = $this->uniqueServer();
        $this->registry->register(new ProtectFilesHook());

        $danger = $this->registry->executeHooks('PreToolUse', $this->context(McpCallTool::NAME, [
            'server' => $srv,
            'tool' => 'fetch',
            'arguments' => ['path' => '/home/deploy/.env'],
        ]));

        $this->assertFalse($danger->isAllowed(), 'a server reached through the dispatcher gets the same file guard as the bridged spelling');
        $this->assertSame('protect-files', $danger->refusedBy);

        $benign = $this->registry->executeHooks('PreToolUse', $this->context(McpCallTool::NAME, [
            'server' => $srv,
            'tool' => 'fetch',
            'arguments' => ['path' => '/tmp/mcp-call-hook-parity/notes.md'],
        ]));
        $this->assertTrue($benign->isAllowed(), 'the guard widened its view, not its appetite');
    }

    // (4) The old direction survives: a matcher naming the dispatcher
    //     itself still selects the frame (guards written for the deferred
    //     world during the deferral keep working).
    public function testADispatcherNamedMatcherStillFires(): void
    {
        $this->registry->register($this->refusingHook('call-guard', '^' . McpCallTool::NAME . '$'));

        $result = $this->registry->executeHooks(
            'PreToolUse',
            $this->context(McpCallTool::NAME, ['server' => $this->uniqueServer(), 'tool' => 'anything']),
        );

        $this->assertFalse($result->isAllowed());
        $this->assertSame('call-guard', $result->refusedBy);
    }

    // (5) Nothing moved for names that are not a resolved dispatch.
    public function testNonDispatchedSubjectsKeepTheirSingleSubject(): void
    {
        $srv = $this->uniqueServer();
        $wire = McpToolBridge::wireNameFor($srv, 'drop');
        $this->registry->register($this->refusingHook('wire-only', '^mcp__' . $srv . '__drop$'));

        // Malformed args form: no wire name resolves, so only the literal
        // subject is matched — the wire guard cannot see it, exactly as a
        // restrictive rule with no arguments to read cannot.
        $malformed = $this->registry->executeHooks(
            'PreToolUse',
            $this->context(McpCallTool::NAME, ['server' => $srv]),
        );
        $this->assertTrue($malformed->isAllowed(), 'no resolved wire name, no wire-subject selection');

        // A bridged first-class call is judged under its own name only.
        $bridged = $this->registry->executeHooks('PreToolUse', $this->context($wire, []));
        $this->assertFalse($bridged->isAllowed(), 'the direct spelling matched directly, as ever');

        // …and neither guard speaks for an unrelated tool.
        $this->registry->register($this->refusingHook('call-only', '^' . McpCallTool::NAME . '$'));
        $bash = $this->registry->executeHooks('PreToolUse', $this->context('Bash', ['command' => 'ls']));
        $this->assertTrue($bash->isAllowed(), 'the dual subject speaks for the dispatcher name and nothing else');
    }
}
