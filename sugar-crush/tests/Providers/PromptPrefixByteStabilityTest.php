<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Context\TurnContextBlock;
use SugarCraft\Crush\Message as RootMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\CustomProvider;
use SugarCraft\Crush\Providers\SglangProvider;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Step 1.A-1's regression pin: two consecutive steps of one agentic turn
 * send byte-identical wire messages for everything the earlier step sent,
 * except its trailing `<turn-context>` row.
 *
 * WHY THIS IS THE PROPERTY THAT MATTERS. A prefix cache (SGLang's
 * RadixAttention, Anthropic/OpenAI prompt caching) reuses work only up to the
 * first differing byte. Before 1.A-1 that byte sat inside message 0 on the
 * first write of a session — the live git status and diff were the tail of
 * the system prompt — and on SGLang any history notice (a cancellation
 * marker, a compaction notice) was hoisted into message 0 as well, so every
 * step re-prefilled the whole conversation. Now the volatile state is the
 * request's LAST row and history notices stay where they happened, so step
 * k+1 re-uses everything step k sent ahead of that row.
 *
 * The turn is driven for real — {@see EngineBackend::complete()}, its
 * `runTurn()` loop, {@see \SugarCraft\Crush\Runtime::run()} — inside a git
 * work tree with a tool that genuinely WRITES, so the git state does change
 * between the steps; the recorded requests are then rendered through both
 * OpenAI-shaped providers' `formatMessages()`, the code that produces the
 * wire bytes.
 *
 * WHAT IS NOT YET IDENTICAL, and why: the trailing row itself. Runtime
 * appends it to the wire request of each step; persisting it into the
 * history — so step k+1 carries step k's row in place and sends a new one
 * only when the bytes changed — is step 1.A-2 (EngineBackend::runTurn).
 * Once it lands, the comparison below extends to all n rows.
 */
final class PromptPrefixByteStabilityTest extends TestCase
{
    use HomeSandboxTrait;

    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        foreach ($this->dirs as $dir) {
            exec('rm -rf ' . escapeshellarg($dir) . ' 2>&1');
        }
    }

    /** @return iterable<string, array{string}> */
    public static function providers(): iterable
    {
        yield 'sglang' => [SglangProvider::class];
        yield 'custom' => [CustomProvider::class];
    }

    /** @dataProvider providers */
    public function testConsecutiveStepsShareEveryWireRowAheadOfTheTurnContext(string $providerClass): void
    {
        $requests = $this->driveATurnThatWrites();
        $this->assertCount(3, $requests, 'write step, read step, answer');

        for ($k = 0; $k + 1 < \count($requests); $k++) {
            $earlier = self::wire($providerClass, $requests[$k]);
            $later = self::wire($providerClass, $requests[$k + 1]);

            $tail = array_pop($earlier);
            $this->assertStringStartsWith(
                TurnContextBlock::FENCE . "\n",
                (string) ($tail['content'] ?? ''),
                "step {$k} must end on its <turn-context> row",
            );
            $this->assertSame('user', $tail['role']);

            $this->assertSame(
                json_encode($earlier),
                json_encode(\array_slice($later, 0, \count($earlier))),
                "step " . ($k + 1) . " must send step {$k}'s rows 0.." . (\count($earlier) - 1) . ' byte-identically',
            );
        }
    }

    public function testTheSystemPromptIsByteIdenticalAcrossAWriteAndTheGitStateMovesToTheTail(): void
    {
        $requests = $this->driveATurnThatWrites();

        $this->assertSame((string) $requests[0]->systemPrompt, (string) $requests[1]->systemPrompt);
        $this->assertSame((string) $requests[1]->systemPrompt, (string) $requests[2]->systemPrompt);
        $this->assertStringNotContainsString('Current branch:', (string) $requests[0]->systemPrompt);
        $this->assertStringContainsString("\nIs directory a git repo: Yes\n", (string) $requests[0]->systemPrompt);

        // The volatile half did move — the instrument fired: the write landed
        // in the row of the step after it, and only there.
        $before = (string) TurnContextBlock::latestIn($requests[0]->messages);
        $after = (string) TurnContextBlock::latestIn($requests[1]->messages);
        $this->assertStringContainsString('Current branch:', $before);
        $this->assertStringNotContainsString(self::MARKER, $before);
        $this->assertStringContainsString('+' . self::MARKER, $after);
        $this->assertStringContainsString("Files you modified this session (most recent first):\n- src/Alpha.php", $after);
    }

    public function testAHistoryNoticeStaysInPlaceSoMessageZeroIsTheSystemPromptAlone(): void
    {
        $requests = $this->driveATurnThatWrites();

        foreach ([SglangProvider::class, CustomProvider::class] as $class) {
            $wire = self::wire($class, $requests[0]);
            $this->assertSame(
                ['role' => 'system', 'content' => "{$requests[0]->systemPrompt}\n\n" . self::LAUNCH_NOTICE],
                $wire[0],
                "{$class}: only the LEADING notice may join message 0",
            );
            $this->assertContains(
                ['role' => 'user', 'content' => SglangProvider::systemNoticeContent(self::CANCEL_NOTICE)],
                $wire,
                "{$class}: the mid-history notice must ride in place",
            );
        }
    }

    private const MARKER = '// written by step one';

    private const LAUNCH_NOTICE = 'Launch notice: one MCP server is offline.';

    private const CANCEL_NOTICE = '_Request cancelled._';

    /** @return list<CompleteRequest> */
    private function driveATurnThatWrites(): array
    {
        $home = $this->tempDir();
        mkdir($home . '/.sugar-crush', 0o700, true);
        $this->useHomeSandbox($home);

        $root = $this->gitFixture();

        $provider = new ScriptedProvider([
            new CompleteResponse(content: 'editing', toolCalls: [new ToolCall('c0', 'Edit', ['file_path' => 'src/Alpha.php'])]),
            new CompleteResponse(content: 'reading', toolCalls: [new ToolCall('c1', 'Read', ['file_path' => 'src/Alpha.php'])]),
            new CompleteResponse(content: 'done'),
        ]);

        $backend = EngineBackend::new($provider, 'm')
            ->withoutHooks()
            ->withRoot($root)
            ->withTools([
                self::tool('Edit', static function () use ($root): string {
                    file_put_contents($root . '/src/Alpha.php', self::MARKER . "\n", FILE_APPEND);

                    return 'edited';
                }),
                self::tool('Read', static fn (): string => 'contents'),
            ]);

        $reply = $backend->complete([
            RootMessage::system(self::LAUNCH_NOTICE),
            RootMessage::user('first'),
            RootMessage::assistant('first answer'),
            RootMessage::system(self::CANCEL_NOTICE),
            RootMessage::user('go'),
        ]);
        $this->assertSame('done', $reply->content);

        return $provider->requests;
    }

    /**
     * The wire `messages` list $class's formatMessages() builds for one
     * recorded request (the same call its complete()/completeStream() make).
     *
     * @param class-string $class
     * @return list<array<string, mixed>>
     */
    private static function wire(string $class, CompleteRequest $request): array
    {
        // Never sends: formatMessages() is a pure function of the request.
        $client = new Client(['base_uri' => 'http://127.0.0.1:1/']);
        $provider = $class === SglangProvider::class
            ? new SglangProvider('http://127.0.0.1:1', 'm', null, $client)
            : new CustomProvider('custom', 'http://127.0.0.1:1', 'm', null, $client, false, true);
        $format = new \ReflectionMethod($provider, 'formatMessages');
        $format->setAccessible(true);

        return array_values($format->invoke($provider, $request->messages, $request->systemPrompt));
    }

    private function gitFixture(): string
    {
        $root = $this->tempDir();
        mkdir($root . '/src');
        file_put_contents($root . '/src/Alpha.php', "<?php\n\nfinal class Alpha {}\n");
        foreach ([
            ['init', '-q'],
            ['config', 'user.email', 'fixture@example.invalid'],
            ['config', 'user.name', 'Fixture'],
            ['config', 'commit.gpgsign', 'false'],
            ['add', '-A'],
            ['commit', '-q', '-m', 'fixture'],
        ] as $argv) {
            exec('git -C ' . escapeshellarg($root) . ' ' . implode(' ', array_map('escapeshellarg', $argv)) . ' 2>&1', $out, $code);
            if ($code !== 0) {
                $this->markTestSkipped('git is unavailable: ' . implode("\n", $out));
            }
        }

        return $root;
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/crush-prefix-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o700, true);
        $this->dirs[] = $dir;

        return $dir;
    }

    /**
     * @param \Closure(): string $run
     */
    private static function tool(string $name, \Closure $run): Tool
    {
        return new class ($name, $run) implements Tool {
            public function __construct(private readonly string $toolName, private readonly \Closure $run)
            {
            }

            public function name(): string
            {
                return $this->toolName;
            }

            public function description(): string
            {
                return $this->toolName . ' fixture';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                return new ToolResult(toolCallId: (string) ($args['toolCallId'] ?? ''), content: ($this->run)());
            }
        };
    }
}
