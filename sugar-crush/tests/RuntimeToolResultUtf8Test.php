<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Providers\SglangProvider;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Tools\BuiltIn\Bash;
use SugarCraft\Crush\Tools\BuiltIn\Read;
use SugarCraft\Crush\Tools\ParallelSafe;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Audit A6 / F-T1: one non-UTF-8 byte in a tool result must not kill the turn.
 *
 * BEFORE: Read of a latin-1 file (or Bash `printf 'caf\xe9'`, a WebFetch of an
 * ISO-8859-1 page, an MCP reply) put raw bytes into the {@see ToolResultMessage},
 * and Guzzle's `'json' => $params` in {@see SglangProvider} threw "Malformed
 * UTF-8 characters" on that request and on every later one that replayed the
 * row. The repair lives in {@see Runtime}'s settle/failure choke point, so these
 * tests drive the REAL tool through the private dispatch and then hand the
 * yielded messages to a real provider over a Guzzle mock — the hop that threw.
 *
 * The provider-side throw for a caller-supplied `jsonSchema` is deliberately
 * NOT relaxed by this fix; it stays pinned in
 * {@see \SugarCraft\Crush\Tests\Providers\SglangProviderRequestBuildingTest}.
 */
final class RuntimeToolResultUtf8Test extends TestCase
{
    private const REPAIR_NOTE_OPENER = '[encoding: ';

    private string $scratchRoot;
    private HookRegistry $registry;
    private Runtime $runtime;
    private ProviderInterface $stubProvider;

    protected function setUp(): void
    {
        $this->scratchRoot = sys_get_temp_dir() . '/sc_utf8_tool_result_' . getmypid() . '_' . bin2hex(random_bytes(6));
        mkdir($this->scratchRoot, 0o755, true);
        $this->scratchRoot = (string) realpath($this->scratchRoot);

        $this->stubProvider = $this->createMock(ProviderInterface::class);
        $this->stubProvider->method('name')->willReturn('utf8-stub');

        $this->registry = new HookRegistry();
        $this->runtime = new Runtime($this->stubProvider, new HookManager($this->registry));
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->scratchRoot) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($this->scratchRoot . '/' . $entry);
            }
        }
        @rmdir($this->scratchRoot);

        parent::tearDown();
    }

    public function testReadOfALatin1FileReachesTheProviderAsValidUtf8WithARepairNote(): void
    {
        file_put_contents($this->scratchRoot . '/latin1.txt', "caf\xe9\n");

        $call = new ToolCall('call_read_latin1', 'Read', ['file_path' => 'latin1.txt']);
        [$messages, $finished] = $this->dispatchThroughRuntime([$call], [new Read($this->scratchRoot)]);

        $this->assertCount(1, $messages);
        $content = $messages[0]->content();
        $this->assertTrue(mb_check_encoding($content, 'UTF-8'));
        $this->assertStringContainsString("caf\u{FFFD}", $content);
        $this->assertStringContainsString(self::REPAIR_NOTE_OPENER . '1 invalid UTF-8 sequence(s)', $content);

        // The UI renders exactly what the model reads.
        $this->assertCount(1, $finished);
        $this->assertSame($content, $finished[0]->result->content());

        $wire = $this->sglangToolContentOnTheWire($call, $messages);
        $this->assertSame($content, $wire);
    }

    public function testBashPrintfOfAnInvalidByteReachesTheProviderRepaired(): void
    {
        $call = new ToolCall('call_bash_latin1', 'Bash', ['command' => "printf 'caf\\xe9 na\\xefve\\n'"]);
        [$messages] = $this->dispatchThroughRuntime([$call], [new Bash($this->scratchRoot)]);

        $this->assertCount(1, $messages);
        $content = $messages[0]->content();
        $this->assertTrue(mb_check_encoding($content, 'UTF-8'));
        $this->assertStringContainsString("caf\u{FFFD} na\u{FFFD}ve", $content);
        $this->assertStringContainsString(self::REPAIR_NOTE_OPENER . '2 invalid UTF-8 sequence(s)', $content);

        $this->assertSame($content, $this->sglangToolContentOnTheWire($call, $messages));
    }

    public function testValidUtf8OutputIsByteIdenticalAndCarriesNoNote(): void
    {
        // A genuine U+FFFD in valid output is text, not a repair.
        $raw = "café \u{FFFD} naïve — 日本語\n";
        $call = new ToolCall('call_clean', 'utf8_clean_tool', []);
        [$messages, $finished] = $this->dispatchThroughRuntime([$call], [$this->fixedOutputTool('utf8_clean_tool', $raw, false)]);

        $this->assertSame($raw, $messages[0]->content());
        $this->assertSame($raw, $finished[0]->result->content());
        $this->assertStringNotContainsString(self::REPAIR_NOTE_OPENER, $messages[0]->content());
    }

    public function testAGenuineReplacementCharacterIsNotCountedAsARepair(): void
    {
        $call = new ToolCall('call_mixed', 'utf8_mixed_tool', []);
        [$messages] = $this->dispatchThroughRuntime(
            [$call],
            [$this->fixedOutputTool('utf8_mixed_tool', "already \u{FFFD} here, bad \xff byte", false)],
        );

        $content = $messages[0]->content();
        $this->assertStringContainsString("already \u{FFFD} here, bad \u{FFFD} byte", $content);
        $this->assertStringContainsString(self::REPAIR_NOTE_OPENER . '1 invalid UTF-8 sequence(s)', $content);
    }

    /**
     * A PreToolUse deny reason is a hook's stdout — bytes Runtime does not
     * control — and it ends in {@see Runtime}'s failure() arm, not settle().
     */
    public function testADenyReasonCarryingInvalidBytesIsRepairedOnTheFailureArm(): void
    {
        $this->registry->register(new class implements HookInterface {
            public function name(): string { return 'latin1-deny'; }
            public function event(): HookEvent { return HookEvent::PreToolUse; }
            public function matcher(): string { return '.*'; }
            public function execute(HookContext $context): HookResult { return HookResult::deny("refus\xe9"); }
        });

        $call = new ToolCall('call_denied_latin1', 'utf8_never_runs', []);
        [$messages, $finished] = $this->dispatchThroughRuntime(
            [$call],
            [$this->fixedOutputTool('utf8_never_runs', 'must not run', false)],
        );

        $content = $messages[0]->content();
        $this->assertTrue($messages[0]->isError());
        $this->assertTrue(mb_check_encoding($content, 'UTF-8'));
        $this->assertStringContainsString("refus\u{FFFD}", $content);
        $this->assertStringContainsString(self::REPAIR_NOTE_OPENER, $content);
        $this->assertSame($content, $finished[0]->result->content());

        $this->assertSame($content, $this->sglangToolContentOnTheWire($call, $messages));
    }

    /**
     * The concurrent arm hands results back over `serialize()` IPC, which keeps
     * the bad bytes intact — so it must meet the same scrub as the sequential
     * arm. Degrades to the in-process arm without ext-pcntl, which still passes
     * through the same seam.
     */
    public function testTheConcurrentArmRepairsResultsReadBackFromForkedChildren(): void
    {
        $tool = $this->fixedOutputTool('utf8_parallel_tool', "bin\xff\xfeary", true);
        $calls = [
            new ToolCall('call_par_one', 'utf8_parallel_tool', ['n' => 1]),
            new ToolCall('call_par_two', 'utf8_parallel_tool', ['n' => 2]),
        ];

        [$messages, $finished] = $this->dispatchThroughRuntime($calls, [$tool]);

        $this->assertSame(
            ['call_par_one', 'call_par_two'],
            array_map(static fn (ToolResultMessage $m): string => $m->toolCallId(), $messages),
        );
        foreach ($messages as $index => $message) {
            $this->assertTrue(mb_check_encoding($message->content(), 'UTF-8'));
            $this->assertStringStartsWith("bin\u{FFFD}\u{FFFD}ary", $message->content());
            $this->assertStringContainsString(self::REPAIR_NOTE_OPENER . '2 invalid UTF-8 sequence(s)', $message->content());
            $this->assertSame($message->content(), $finished[$index]->result->content());
        }
    }

    /**
     * @param list<ToolCall> $calls
     * @param list<Tool> $tools
     * @return array{0: list<ToolResultMessage>, 1: list<ToolFinished>}
     */
    private function dispatchThroughRuntime(array $calls, array $tools): array
    {
        $app = App::new($this->stubProvider, 'm')->withTools($tools);

        $finished = [];
        $onEvent = static function (object $event) use (&$finished): void {
            if ($event instanceof ToolFinished) {
                $finished[] = $event;
            }
        };

        $method = new \ReflectionMethod(Runtime::class, 'executeToolCalls');
        $messages = array_values(iterator_to_array($method->invoke($this->runtime, $calls, $app, $onEvent), false));

        return [$messages, $finished];
    }

    /**
     * Send the history a real step would replay through {@see SglangProvider::complete()}
     * and return the tool row's content as the server would decode it. Before the
     * fix this threw `json_encode error: Malformed UTF-8 characters`.
     *
     * @param list<ToolResultMessage> $toolMessages
     */
    private function sglangToolContentOnTheWire(ToolCall $call, array $toolMessages): string
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok']]],
            ])),
        ]));
        $stack->push(Middleware::history($history));

        $provider = new SglangProvider(
            'http://utf8.invalid/v1',
            'm',
            null,
            new Client(['handler' => $stack, 'base_uri' => 'http://utf8.invalid/v1/']),
        );

        $provider->complete(new CompleteRequest(model: 'm', messages: [
            new UserMessage('go'),
            new AssistantMessage('', [$call]),
            ...$toolMessages,
        ]));

        $this->assertCount(1, $history);
        $body = (string) $history[0]['request']->getBody();
        $this->assertNotSame('', $body);
        $this->assertTrue(mb_check_encoding($body, 'UTF-8'));

        $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        foreach ($decoded['messages'] as $row) {
            if (($row['role'] ?? null) === 'tool' && ($row['tool_call_id'] ?? null) === $call->id()) {
                return (string) $row['content'];
            }
        }

        $this->fail('the tool row for ' . $call->id() . ' never reached the request body');
    }

    private function fixedOutputTool(string $name, string $output, bool $parallel): Tool
    {
        if (!$parallel) {
            $tool = $this->createMock(Tool::class);
            $tool->method('name')->willReturn($name);
            $tool->method('description')->willReturn('fixed output');
            $tool->method('inputSchema')->willReturn([]);
            $tool->method('execute')->willReturn(new ToolResult(toolCallId: $name, content: $output));

            return $tool;
        }

        return new class($name, $output) implements Tool, ParallelSafe {
            public function __construct(private string $toolName, private string $output) {}

            public function name(): string
            {
                return $this->toolName;
            }

            public function description(): string
            {
                return 'fixed output, parallel-safe';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object'];
            }

            public function isParallelSafe(): bool
            {
                return true;
            }

            public function execute(array $args): ToolResult
            {
                return new ToolResult(toolCallId: $this->toolName, content: $this->output);
            }
        };
    }
}
