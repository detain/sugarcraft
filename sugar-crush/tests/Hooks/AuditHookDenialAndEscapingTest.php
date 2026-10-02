<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Hooks;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Hooks\BuiltIn\AuditHook;
use SugarCraft\Crush\Hooks\BuiltIn\ProtectFilesHook;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Permissions\DenialKind;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Tools\ParallelSafe;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Audit F-H2: the audit log used to record only completed calls (a refused
 * call never reaches PostToolUse), wrote the output excerpt raw — so a newline
 * in attacker-chosen output forged extra records — and logged the input whole.
 *
 * @see AuditHook::recordDenial()
 * @see Runtime::gate()
 */
final class AuditHookDenialAndEscapingTest extends TestCase
{
    private string $logFile;
    private ProviderInterface $provider;
    private HookRegistry $registry;
    private Runtime $runtime;

    protected function setUp(): void
    {
        $this->logFile = sys_get_temp_dir() . '/audit-denial-test-' . uniqid((string) getmypid(), true) . '.log';
        $this->provider = $this->createMock(ProviderInterface::class);
        $this->provider->method('name')->willReturn('test-provider');
        $this->registry = new HookRegistry();
        $this->runtime = new Runtime($this->provider, new HookManager($this->registry));
    }

    protected function tearDown(): void
    {
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
    }

    public function testAProtectFilesDenialIsAudited(): void
    {
        $this->registry->register(new ProtectFilesHook());
        $this->registry->register(new AuditHook($this->logFile));
        $tool = self::stubTool('Read', 'SECRET=1');

        $results = $this->dispatchCalls([new ToolCall('call_env', 'Read', ['file_path' => '.env'])], $tool);

        $this->assertSame(0, $tool->calls, 'the protected read must not run');
        $this->assertStringStartsWith(DenialKind::Hook->value, $results[0]->content());
        $lines = $this->lines();
        $this->assertCount(1, $lines);
        $this->assertMatchesRegularExpression(
            '/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]  Read \{"file_path":"\.env"\} =! DENY hook: This hook prevents modification/',
            $lines[0],
        );
    }

    public function testAnUnansweredAskIsAuditedWithItsKind(): void
    {
        $this->registry->register(self::verdictHook(HookEvent::PreToolUse, HookResult::ask('run this?')));
        $this->registry->register(new AuditHook($this->logFile));
        $tool = self::stubTool('Bash', 'ran');

        $this->dispatchCalls([new ToolCall('call_q', 'Bash', ['command' => 'make'])], $tool);

        $this->assertSame(0, $tool->calls);
        $lines = $this->lines();
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('=! DENY unanswered: ', $lines[0]);
    }

    public function testAnUnencodableInputRefusalIsAudited(): void
    {
        $this->registry->register(new AuditHook($this->logFile));
        $tool = self::stubTool('Bash', 'ran');

        $this->dispatchCalls([new ToolCall('call_inf', 'Bash', ['n' => INF])], $tool);

        $this->assertSame(0, $tool->calls);
        $lines = $this->lines();
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('Bash [arguments not encodable as JSON] =! DENY hook: ', $lines[0]);
    }

    /**
     * Registered AHEAD of the audit hook, so the chain returns before AuditHook
     * runs: the withheld record is the only line, and it carries the reason.
     */
    public function testAWithheldOutputIsAuditedWithTheReason(): void
    {
        $this->registry->register(self::verdictHook(HookEvent::PostToolUse, HookResult::deny('contains a key')));
        $this->registry->register(new AuditHook($this->logFile));

        $this->dispatchCalls([new ToolCall('call_w', 'Bash', ['command' => 'env'])], self::stubTool('Bash', 'AKIAEXAMPLE'));

        $lines = $this->lines();
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('=! WITHHELD "verdict": contains a key', $lines[0]);
        $this->assertStringNotContainsString('AKIA', $lines[0]);
    }

    public function testTheConcurrentPathAuditsEveryDenial(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the concurrent dispatch arm');
        }

        $this->registry->register(new ProtectFilesHook());
        $this->registry->register(new AuditHook($this->logFile));
        $tool = self::stubTool('Read', null, parallelSafe: true);

        $results = $this->dispatchCalls([
            new ToolCall('call_a', 'Read', ['file_path' => '.env']),
            new ToolCall('call_b', 'Read', ['file_path' => 'README.md']),
            new ToolCall('call_c', 'Read', ['file_path' => '.env.local']),
        ], $tool);

        $this->assertSame(['call_a', 'call_b', 'call_c'], array_map(
            static fn (ToolResultMessage $m): string => $m->toolCallId(),
            $results,
        ));
        $denials = array_values(array_filter($this->lines(), static fn (string $l): bool => str_contains($l, '=! DENY hook:')));
        $this->assertCount(2, $denials);
        $this->assertStringContainsString('".env"', $denials[0] . $denials[1]);
        $this->assertStringContainsString('".env.local"', $denials[0] . $denials[1]);
    }

    public function testOutputCannotForgeASecondRecord(): void
    {
        $hook = new AuditHook($this->logFile);

        $hook->execute(self::context(
            toolOutput: "ok\n[2026-01-01 00:00:00] forged Bash {\"command\":\"rm -rf /\"} => \r\x1b[2Jdone\u{2028}x",
        ));

        $content = (string) file_get_contents($this->logFile);
        $this->assertSame(1, substr_count($content, "\n"), 'one record must be one line');
        $this->assertStringEndsWith("\n", $content);
        $this->assertStringContainsString('=> ok\n[2026-01-01 00:00:00] forged', $content);
        $this->assertStringContainsString('\r\x1B[2Jdone\u{2028}x', $content);
        $this->assertStringNotContainsString("\x1b", $content);
    }

    public function testEveryInterpolatedFieldIsEscaped(): void
    {
        $hook = new AuditHook($this->logFile);

        $hook->recordDenial(
            self::context(sessionId: "s\n[x]", toolName: "Bash\r", toolInput: "{\"a\":\"b\"}\n"),
            DenialKind::Refused,
            "nope\nnope",
        );

        $content = (string) file_get_contents($this->logFile);
        $this->assertSame(1, substr_count($content, "\n"));
        $this->assertStringContainsString('] s\n[x] Bash\r {"a":"b"}\n =! DENY refused: nope\nnope', $content);
    }

    public function testACutNeverSplitsAUtf8Character(): void
    {
        $hook = new AuditHook($this->logFile);

        // 199 ASCII bytes then a 3-byte character straddling the 200-byte cut.
        $hook->execute(self::context(toolOutput: str_repeat('a', 199) . '€tail'));

        $content = (string) file_get_contents($this->logFile);
        $this->assertTrue(mb_check_encoding($content, 'UTF-8'));
        $this->assertStringNotContainsString("\u{FFFD}", $content);
        $this->assertStringEndsWith('=> ' . str_repeat('a', 199) . "\n", $content);
    }

    public function testALargeInputIsCappedWithItsLengthNamed(): void
    {
        $hook = new AuditHook($this->logFile);
        $input = json_encode(['file_path' => 'big.txt', 'content' => str_repeat('z', 100_000)]);
        $this->assertIsString($input);

        $hook->execute(self::context(toolName: 'Write', toolInput: $input, toolOutput: 'written'));

        $content = (string) file_get_contents($this->logFile);
        $this->assertLessThan(AuditHook::INPUT_CAP_BYTES + 400, strlen($content));
        $this->assertStringContainsString(sprintf(' [truncated: %d bytes] => written', strlen($input)), $content);
        $this->assertStringContainsString(substr($input, 0, AuditHook::INPUT_CAP_BYTES), $content);
    }

    public function testASmallInputIsLoggedVerbatim(): void
    {
        $hook = new AuditHook($this->logFile);

        $hook->execute(self::context(toolInput: '{"command":"ls -la /tmp"}', toolOutput: 'x'));

        $this->assertStringContainsString(' {"command":"ls -la /tmp"} => x', (string) file_get_contents($this->logFile));
        $this->assertStringNotContainsString('truncated', (string) file_get_contents($this->logFile));
    }

    /**
     * @param list<ToolCall> $calls
     *
     * @return list<ToolResultMessage>
     */
    private function dispatchCalls(array $calls, Tool $tool): array
    {
        $app = App::new($this->provider, 'test-model')->withTools([$tool]);
        $method = new \ReflectionMethod($this->runtime, 'executeToolCalls');

        return array_values(iterator_to_array($method->invoke($this->runtime, $calls, $app, null), false));
    }

    /**
     * @return list<string>
     */
    private function lines(): array
    {
        if (!is_file($this->logFile)) {
            return [];
        }

        return array_values(array_filter(explode("\n", (string) file_get_contents($this->logFile)), static fn (string $l): bool => $l !== ''));
    }

    private static function context(
        string $sessionId = 'sess',
        string $toolName = 'Bash',
        string $toolInput = '{}',
        string $toolOutput = '',
    ): HookContext {
        return new HookContext(
            sessionId: $sessionId,
            toolName: $toolName,
            toolArgs: [],
            toolInput: $toolInput,
            toolOutput: $toolOutput,
            model: 'm',
            provider: 'p',
            projectRoot: sys_get_temp_dir(),
        );
    }

    /**
     * A tool named $name that prints $output (or, when null, its `file_path`)
     * and counts its calls.
     */
    private static function stubTool(string $name, ?string $output, bool $parallelSafe = false): Tool&ParallelSafe
    {
        return new class ($name, $output, $parallelSafe) implements Tool, ParallelSafe {
            public int $calls = 0;

            public function __construct(
                private readonly string $name,
                private readonly ?string $output,
                private readonly bool $parallelSafe,
            ) {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function description(): string
            {
                return 'stub';
            }

            public function inputSchema(): array
            {
                return [];
            }

            public function execute(array $args): ToolResult
            {
                $this->calls++;

                return new ToolResult(toolCallId: 'stub', content: $this->output ?? (string) ($args['file_path'] ?? ''));
            }

            public function isParallelSafe(): bool
            {
                return $this->parallelSafe;
            }
        };
    }

    private static function verdictHook(HookEvent $event, HookResult $verdict): HookInterface
    {
        return new class ($event, $verdict) implements HookInterface {
            public function __construct(private readonly HookEvent $event, private readonly HookResult $verdict)
            {
            }

            public function name(): string
            {
                return 'verdict';
            }

            public function event(): HookEvent
            {
                return $this->event;
            }

            public function matcher(): string
            {
                return '.*';
            }

            public function execute(HookContext $context): HookResult
            {
                return $this->verdict;
            }
        };
    }
}
