<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Providers;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Context\InstructionDiffTracker;
use SugarCraft\Code\Context\InstructionFileLoader;
use SugarCraft\Code\Context\TurnContextBlock;
use SugarCraft\Code\Message as RootMessage;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Providers\CustomProvider;
use SugarCraft\Code\Providers\SglangProvider;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tests\Support\ScriptedProvider;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolCall;
use SugarCraft\Code\Tools\ToolResult;

/**
 * Steps 1.A-1/1.A-2's regression pin: two consecutive steps of one agentic
 * turn send byte-identical wire messages for EVERYTHING the earlier step sent
 * — its `<turn-context>` row included, which 1.A-2 persists into the history.
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
 * `runTurn()` loop, {@see \SugarCraft\Code\Runtime::run()} — inside a git
 * work tree with a tool that genuinely WRITES, so the git state does change
 * between the steps; the recorded requests are then rendered through both
 * OpenAI-shaped providers' `formatMessages()`, the code that produces the
 * wire bytes.
 *
 * Since step 1.A-2 the row is persisted at the top of each step
 * (EngineBackend::runTurn), so step k+1 carries step k's row in place and
 * appends a new one only when the bytes changed: the comparison below covers
 * all n rows of the earlier request.
 *
 * CTX-3 extends the property across SESSIONS of complete() calls on one
 * backend: an edit to CLAUDE.md between two turns must move the tail row —
 * the delta rides the next `<turn-context>` — while every earlier row AND the
 * system prompt stay byte-identical, because the instruction slab is a
 * PerSession layer and this feature exists precisely to leave it frozen.
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
    public function testConsecutiveStepsShareEveryWireRowTheEarlierStepSent(string $providerClass): void
    {
        $requests = $this->driveATurnThatWrites();
        $this->assertCount(3, $requests, 'write step, read step, answer');

        for ($k = 0; $k + 1 < \count($requests); $k++) {
            $earlier = self::wire($providerClass, $requests[$k]);
            $later = self::wire($providerClass, $requests[$k + 1]);

            $tail = $earlier[array_key_last($earlier)];
            $this->assertStringStartsWith(
                TurnContextBlock::FENCE . "\n",
                (string) ($tail['content'] ?? ''),
                "step {$k} must end on its <turn-context> row",
            );
            $this->assertSame('user', $tail['role']);

            $this->assertSame(
                json_encode($earlier),
                json_encode(\array_slice($later, 0, \count($earlier))),
                "step " . ($k + 1) . " must send ALL of step {$k}'s rows 0.." . (\count($earlier) - 1) . ' byte-identically, its turn-context row included',
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

    /**
     * CTX-3, full stack: two complete() turns on one backend, the instruction
     * file edited between them. The prefix — system prompt AND every row turn
     * 1 sent — must survive the edit byte-for-byte; only the new turn's
     * appended row may carry it, as the tracker's delta.
     */
    public function testAMidSessionInstructionEditRidesTheTailRowAndNotTheFrozenPrefix(): void
    {
        $home = $this->tempDir();
        mkdir($home . '/.candy-code', 0o700, true);
        $this->useHomeSandbox($home);

        $root = $this->gitFixture();
        file_put_contents($root . '/CLAUDE.md', "Alpha rule\nBeta rule\n");

        $provider = new ScriptedProvider([
            new CompleteResponse(content: 'first answer'),
            new CompleteResponse(content: 'second answer'),
        ]);
        $backend = EngineBackend::new($provider, 'm')
            ->withoutHooks()
            ->withRoot($root)
            ->withInstructionLoader(new InstructionFileLoader($root));

        $history = [RootMessage::system(self::LAUNCH_NOTICE), RootMessage::user('go')];
        $reply = $backend->complete($history);
        $this->assertSame('first answer', $reply->content);
        $this->assertCount(1, $provider->requests, 'a no-tool turn is one step');

        $turnOne = $provider->requests[0];
        $firstRow = (string) TurnContextBlock::latestIn($turnOne->messages);
        $this->assertStringStartsWith(TurnContextBlock::FENCE, $firstRow);
        $this->assertStringNotContainsString(InstructionDiffTracker::HEADER, $firstRow, 'nothing has moved yet');
        $this->assertStringNotContainsString(InstructionDiffTracker::HEADER, (string) $turnOne->systemPrompt);
        $this->assertStringContainsString('Alpha rule', (string) $turnOne->systemPrompt, 'the slab froze the file — the fixture only works if it did');

        // The user edits the instruction file mid-session.
        file_put_contents($root . '/CLAUDE.md', "Alpha rule\nBeta rule\nGamma rule\n");

        // The next turn runs on the history the first actually ended with —
        // the persisted row included, exactly as the Chat owner carries it —
        // plus the reply and the new question. beginSessionTurn keys its
        // rewrite detection on these leading rows unchanged, so the memo (and
        // with it the frozen baseline) survives into turn 2.
        $historyTwo = [
            RootMessage::system(self::LAUNCH_NOTICE),
            RootMessage::user('go'),
            RootMessage::user($firstRow),
            RootMessage::assistant('first answer'),
            RootMessage::user('more'),
        ];
        $reply = $backend->complete($historyTwo);
        $this->assertSame('second answer', $reply->content);
        $this->assertCount(2, $provider->requests);

        $second = $provider->requests[1];
        $this->assertSame((string) $turnOne->systemPrompt, (string) $second->systemPrompt, 'the frozen prefix must not feel the edit');
        $this->assertStringNotContainsString('Gamma rule', (string) $second->systemPrompt);
        $this->assertStringNotContainsString(InstructionDiffTracker::HEADER, (string) $second->systemPrompt, 'the delta lands in the tail, never the slab');

        $tail = $second->messages[array_key_last($second->messages)];
        $this->assertTrue(TurnContextBlock::isTurnContext($tail), 'the edit must show up on the appended row — that is the whole feature');
        $secondRow = (string) TurnContextBlock::latestIn($second->messages);
        $this->assertStringContainsString(InstructionDiffTracker::HEADER, $secondRow);
        $this->assertStringContainsString('+Gamma rule', $secondRow);
        $this->assertStringContainsString('CLAUDE.md (', $secondRow);

        // And every row turn 1 sent is still in turn 2 byte-for-byte — the
        // persisted row included, edit notwithstanding.
        $earlier = self::wire(CustomProvider::class, $turnOne);
        $later = self::wire(CustomProvider::class, $second);
        $this->assertSame(
            json_encode($earlier),
            json_encode(\array_slice($later, 0, \count($earlier))),
            'the instruction edit must not disturb a single byte the earlier turn already sent',
        );
    }

    /**
     * C-CTX-1, the fork-path twin of the test above: the same two turns with
     * the same mid-session edit, driven through {@see EngineBackend::completeAsync()}
     * so each turn really forks its child. There the tracker was captured in
     * the CHILD until the parent-side prime landed: every child froze "frozen
     * = current disk" for itself and its tracker died with it, so turn 2's
     * tail row carried no diff — the feature simply did not fire on a forked
     * session. Requests are recorded through disk because a child's
     * `$provider->requests` never reaches this process.
     */
    public function testAMidSessionInstructionEditReachesTheForkedTurnToo(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('the forked turn path needs ext-pcntl');
        }

        $home = $this->tempDir();
        mkdir($home . '/.candy-code', 0o700, true);
        $this->useHomeSandbox($home);

        $root = $this->gitFixture();
        file_put_contents($root . '/CLAUDE.md', "Alpha rule\nBeta rule\n");

        $wireDir = $this->tempDir();
        // One closure entry does the whole script: a forked child calls the
        // provider in ITS memory, so the parent's request count never
        // advances and every child would otherwise replay entry 0. The
        // request itself says which turn it is — the new question row.
        $capture = static function (CompleteRequest $request) use ($wireDir): CompleteResponse {
            $turn = 'turn-1';
            $answer = 'first answer';
            // Turn 1's request carries its three rows; turn 2's carries the
            // persisted tail row, the reply and the new question on top.
            if (\count($request->messages) >= 5) {
                $turn = 'turn-2';
                $answer = 'second answer';
            }
            // Runs in the forked child; the file is the channel back.
            file_put_contents($wireDir . '/' . $turn . '.json', json_encode([
                'systemPrompt' => (string) $request->systemPrompt,
                'messages' => self::wire(CustomProvider::class, $request),
            ]));

            return new CompleteResponse(content: $answer);
        };

        $provider = new ScriptedProvider([$capture]);
        $backend = EngineBackend::new($provider, 'm')
            ->withoutHooks()
            ->withRoot($root)
            ->withInstructionLoader(new InstructionFileLoader($root));

        $reply = $this->drainUntilSettled($backend->completeAsync([
            RootMessage::system(self::LAUNCH_NOTICE),
            RootMessage::user('go'),
        ]));
        $this->assertSame('first answer', $reply->content);

        $first = json_decode((string) file_get_contents($wireDir . '/turn-1.json'), true);
        $this->assertIsArray($first);
        $firstRow = (string) $first['messages'][array_key_last($first['messages'])]['content'];
        $this->assertStringStartsWith(TurnContextBlock::FENCE, $firstRow);
        $this->assertStringNotContainsString(InstructionDiffTracker::HEADER, $firstRow, 'nothing has moved yet');
        $this->assertStringNotContainsString(InstructionDiffTracker::HEADER, (string) $first['systemPrompt']);
        $this->assertStringContainsString('Alpha rule', (string) $first['systemPrompt'], 'the slab froze the file — the fixture only works if it did');

        // The user edits the instruction file mid-session, AFTER the whole
        // forked turn 1 is done: every child for turn 2 reads the EDITED disk
        // at its own start-up. Only a tracker captured in the parent, before
        // the first fork, can still tell the two apart.
        file_put_contents($root . '/CLAUDE.md', "Alpha rule\nBeta rule\nGamma rule\n");

        $reply = $this->drainUntilSettled($backend->completeAsync([
            RootMessage::system(self::LAUNCH_NOTICE),
            RootMessage::user('go'),
            RootMessage::user($firstRow),
            RootMessage::assistant('first answer'),
            RootMessage::user('more'),
        ]));
        $this->assertSame('second answer', $reply->content);

        $second = json_decode((string) file_get_contents($wireDir . '/turn-2.json'), true);
        $this->assertIsArray($second);
        $this->assertSame((string) $first['systemPrompt'], (string) $second['systemPrompt'], 'the forked prefix must not feel the edit');
        $this->assertStringNotContainsString('Gamma rule', (string) $second['systemPrompt']);
        $this->assertStringNotContainsString(InstructionDiffTracker::HEADER, (string) $second['systemPrompt'], 'the delta lands in the tail, never the slab');

        $tail = (string) $second['messages'][array_key_last($second['messages'])]['content'];
        $this->assertStringStartsWith(TurnContextBlock::FENCE . "\n", $tail, 'the forked turn must end on its <turn-context> row');
        $this->assertStringContainsString(InstructionDiffTracker::HEADER, $tail, 'the edit must reach the FORKED turn — that is the whole feature');
        $this->assertStringContainsString('+Gamma rule', $tail);
        $this->assertStringContainsString('CLAUDE.md (', $tail);

        // And every row turn 1's child sent is still in turn 2's wire byte for
        // byte, edit notwithstanding.
        $this->assertSame(
            json_encode($first['messages']),
            json_encode(\array_slice($second['messages'], 0, \count($first['messages']))),
            'the instruction edit must not disturb a single byte the earlier forked turn already sent',
        );
    }

    /**
     * Run the loop until the forked completion settles (the house fork-test
     * idiom, sibling of EngineBackend's own reap discipline).
     */
    private function drainUntilSettled(\React\Promise\PromiseInterface $promise): \SugarCraft\Code\Message
    {
        $loop = \React\EventLoop\Loop::get();
        $settled = false;
        $value = null;
        $failure = null;

        $promise->then(
            static function ($v) use (&$settled, &$value, $loop): void {
                $settled = true;
                $value = $v;
                $loop->stop();
            },
            static function (\Throwable $e) use (&$settled, &$failure, $loop): void {
                $settled = true;
                $failure = $e;
                $loop->stop();
            },
        );

        if (!$settled) {
            $watchdog = $loop->addTimer(30.0, static function () use ($loop, &$failure): void {
                $failure = new \RuntimeException('the forked completion never settled within the safety window');
                $loop->stop();
            });
            $loop->run();
            $loop->cancelTimer($watchdog);
        }

        if ($failure !== null) {
            $this->fail('forked turn failed: ' . $failure->getMessage());
        }
        $this->assertInstanceOf(\SugarCraft\Code\Message::class, $value);

        return $value;
    }

    private const MARKER = '// written by step one';

    private const LAUNCH_NOTICE = 'Launch notice: one MCP server is offline.';

    private const CANCEL_NOTICE = '_Request cancelled._';

    /** @return list<CompleteRequest> */
    private function driveATurnThatWrites(): array
    {
        $home = $this->tempDir();
        mkdir($home . '/.candy-code', 0o700, true);
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
