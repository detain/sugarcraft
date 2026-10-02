<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Hooks;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Hooks\ScriptHook;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Permissions\DenialKind;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Tools\ParallelSafe;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Audit F-H3, on the live engine path: the JSON a hook reads as
 * `CRUSH_TOOL_INPUT` must spell paths and non-ASCII the way the model wrote
 * them, so the documented `grep` guard idiom actually fires — and arguments
 * that cannot be encoded at all must be refused, never handed to the chain
 * as `{}`.
 *
 * @see Runtime::hookContext()
 */
final class RuntimeHookInputEncodingTest extends TestCase
{
    private const PASSWD_GUARD = 'if printf %s "$CRUSH_TOOL_INPUT" | grep -qF "/etc/passwd"; then '
        . 'echo "blocked passwd" >&2; exit 2; fi; exit 0';

    private ProviderInterface $provider;
    private HookRegistry $registry;
    private Runtime $runtime;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->provider = $this->createMock(ProviderInterface::class);
        $this->provider->method('name')->willReturn('test-provider');
        $this->registry = new HookRegistry();
        $this->runtime = new Runtime($this->provider, new HookManager($this->registry));
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->tempFiles = [];
    }

    public function testAGrepDenyHookFiresOnAnUnescapedPath(): void
    {
        $this->registry->register(new ScriptHook('deny-passwd', HookEvent::PreToolUse, '.*', self::PASSWD_GUARD, ''));
        $tool = self::readTool();

        $results = $this->run1([new ToolCall('call_passwd', 'Read', ['file_path' => '/etc/passwd'])], $tool);

        $this->assertCount(1, $results);
        $this->assertTrue($results[0]->isError());
        $this->assertStringStartsWith(DenialKind::Hook->value . ' ', $results[0]->content());
        $this->assertStringContainsString('blocked passwd', $results[0]->content());
        $this->assertSame(0, $tool->calls, 'a denied Read must never execute');
    }

    public function testTheSameGuardPermitsAnUnrelatedPath(): void
    {
        $this->registry->register(new ScriptHook('deny-passwd', HookEvent::PreToolUse, '.*', self::PASSWD_GUARD, ''));
        $tool = self::readTool();

        $results = $this->run1([new ToolCall('call_ok', 'Read', ['file_path' => '/etc/hostname'])], $tool);

        $this->assertFalse($results[0]->isError());
        $this->assertSame('read ok', $results[0]->content());
        $this->assertSame(1, $tool->calls);
    }

    public function testTheHookSeesSlashesAndUnicodeVerbatim(): void
    {
        $capture = $this->tempFile();
        $this->registry->register(new ScriptHook(
            'capture',
            HookEvent::PreToolUse,
            '.*',
            'printf %s "$CRUSH_TOOL_INPUT" > ' . escapeshellarg($capture) . '; exit 0',
            '',
        ));

        $this->run1([new ToolCall('call_u', 'Read', ['file_path' => '/tmp/café/ü.txt'])], self::readTool());

        $this->assertSame('{"file_path":"/tmp/café/ü.txt"}', file_get_contents($capture));
    }

    public function testInvalidUtf8IsSubstitutedRatherThanEmptyingTheInput(): void
    {
        $capture = $this->tempFile();
        $this->registry->register(new ScriptHook(
            'capture',
            HookEvent::PreToolUse,
            '.*',
            'printf %s "$CRUSH_TOOL_INPUT" > ' . escapeshellarg($capture) . '; exit 0',
            '',
        ));

        $this->run1([new ToolCall('call_l1', 'Read', ['file_path' => "/etc/passwd\xe9"])], self::readTool());

        $this->assertSame("{\"file_path\":\"/etc/passwd\u{FFFD}\"}", file_get_contents($capture));
    }

    /**
     * The old `?: '{}'` arm: an argument map json_encode() refuses (INF here)
     * reached every guard as an EMPTY object and the call ran. Now it is
     * refused before the chain, which is never consulted on input it cannot
     * see.
     */
    public function testUnencodableArgumentsAreDeniedNotHandedOverAsAnEmptyObject(): void
    {
        $observer = self::observingHook();
        $this->registry->register($observer);
        $tool = self::readTool();

        $results = $this->run1([new ToolCall('call_inf', 'Read', ['file_path' => '/etc/passwd', 'offset' => INF])], $tool);

        $this->assertTrue($results[0]->isError());
        $this->assertStringStartsWith(DenialKind::Hook->value . ' ', $results[0]->content());
        $this->assertStringContainsString('could not be encoded as JSON', $results[0]->content());
        $this->assertSame([], $observer->inputs, 'no hook may judge a stand-in for the real arguments');
        $this->assertSame(0, $tool->calls);
    }

    public function testTheConcurrentGateDeniesTheSameWay(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the concurrent dispatch arm');
        }

        $this->registry->register(new ScriptHook('deny-passwd', HookEvent::PreToolUse, '.*', self::PASSWD_GUARD, ''));
        $tool = self::readTool(parallelSafe: true);

        $results = $this->run1([
            new ToolCall('call_a', 'Read', ['file_path' => '/etc/passwd']),
            new ToolCall('call_b', 'Read', ['file_path' => '/etc/hostname']),
            new ToolCall('call_c', 'Read', ['file_path' => '/etc/hosts', 'offset' => NAN]),
        ], $tool);

        $this->assertCount(3, $results);
        $this->assertSame(['call_a', 'call_b', 'call_c'], array_map(
            static fn (ToolResultMessage $m): string => $m->toolCallId(),
            $results,
        ));
        $this->assertTrue($results[0]->isError());
        $this->assertStringContainsString('blocked passwd', $results[0]->content());
        $this->assertFalse($results[1]->isError());
        $this->assertSame('read ok', $results[1]->content());
        $this->assertTrue($results[2]->isError());
        $this->assertStringContainsString('could not be encoded as JSON', $results[2]->content());
    }

    /**
     * @param list<ToolCall> $calls
     *
     * @return list<ToolResultMessage>
     */
    private function run1(array $calls, Tool $tool): array
    {
        $app = App::new($this->provider, 'test-model')->withTools([$tool]);
        $method = new \ReflectionMethod($this->runtime, 'executeToolCalls');

        return array_values(iterator_to_array($method->invoke($this->runtime, $calls, $app), false));
    }

    private function tempFile(): string
    {
        $path = sys_get_temp_dir() . '/crush-fh3-' . bin2hex(random_bytes(6));
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * A `Read` stand-in that never touches the filesystem and counts its calls.
     */
    private static function readTool(bool $parallelSafe = false): Tool&ParallelSafe
    {
        return new class ($parallelSafe) implements Tool, ParallelSafe {
            public int $calls = 0;

            public function __construct(private readonly bool $parallelSafe)
            {
            }

            public function name(): string
            {
                return 'Read';
            }

            public function description(): string
            {
                return 'stub read';
            }

            public function inputSchema(): array
            {
                return [];
            }

            public function execute(array $args): ToolResult
            {
                $this->calls++;

                return new ToolResult(toolCallId: 'stub', content: 'read ok');
            }

            public function isParallelSafe(): bool
            {
                return $this->parallelSafe;
            }
        };
    }

    /**
     * Permits everything and records the input it was shown.
     */
    private static function observingHook(): HookInterface
    {
        return new class implements HookInterface {
            /** @var list<string> */
            public array $inputs = [];

            public function name(): string
            {
                return 'observer';
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
                $this->inputs[] = $context->toolInput;

                return HookResult::allow();
            }
        };
    }
}
