<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Context\TurnContextBlock;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Usage;

/**
 * Step 1.A-2: the `<turn-context>` row is persisted into the history at the
 * top of a step, only when its bytes changed — so an unchanged state is sent
 * ONCE, across the steps of a turn and across turns, and the row reports the
 * context-window share once it passes the notice mark.
 *
 * The fixture work tree is DIRTY on purpose: its diff is what a naive
 * re-render after a read-only step would drop (the P3.S5 write signal), and a
 * diff-less copy of the row would then be appended for nothing.
 */
final class TurnContextPersistedTest extends TestCase
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

    public function testAnUnchangedStateIsSentOnceAcrossReadOnlyStepsAndTheNextTurn(): void
    {
        $root = $this->dirtyGitFixture();
        $provider = new ScriptedProvider([
            new CompleteResponse(content: 'reading', toolCalls: [new ToolCall('c0', 'Read', ['file_path' => 'src/Alpha.php'])]),
            new CompleteResponse(content: 'reading more', toolCalls: [new ToolCall('c1', 'Read', ['file_path' => 'src/Alpha.php'])]),
            new CompleteResponse(content: 'first answer'),
            new CompleteResponse(content: 'second answer'),
        ]);
        $backend = $this->backend($provider, $root);

        $reply = $backend->complete([Message::user('go')]);
        $this->assertSame('first answer', $reply->content);

        foreach (\array_slice($provider->requests, 0, 3) as $k => $request) {
            $this->assertCount(1, self::rows($request), "step {$k} carries the one row step 0 persisted");
        }
        $this->assertStringContainsString('+// uncommitted', (string) TurnContextBlock::latestIn($provider->requests[2]->messages), 'the read-only steps kept the diff the first step showed');

        // The next turn, as Chat holds the history after settling the first.
        [$history, $settled] = Message::settleTurnTranscript([Message::user('go')], $reply);
        $next = $backend->complete([...$history, $settled, Message::user('again')]);
        $this->assertSame('second answer', $next->content);

        $this->assertCount(1, self::rows($provider->requests[3]), 'nothing moved, so the next turn sends no new row');
        $this->assertSame([], array_values(array_filter(
            $next->turnTranscript,
            static fn (Message $m): bool => TurnContextBlock::isTurnContext($m),
        )), 'and its transcript adds none');
    }

    public function testThePersistedRowRidesTheTranscriptBackHidden(): void
    {
        $root = $this->dirtyGitFixture();
        $provider = new ScriptedProvider([new CompleteResponse(content: 'answer')]);

        $reply = $this->backend($provider, $root)->complete([Message::user('go')]);

        $rows = array_values(array_filter($reply->turnTranscript, static fn (Message $m): bool => TurnContextBlock::isTurnContext($m)));
        $this->assertCount(1, $rows);
        $this->assertFalse($rows[0]->userVisible, 'harness metadata, never on screen');
        $this->assertSame(Message::agentVisible([$rows[0]]), [$rows[0]], 'but sent to the model on the next turn');
    }

    public function testTheRowReportsTheContextShareOnceItPassesTheNoticeMark(): void
    {
        $root = $this->dirtyGitFixture();
        $provider = new ScriptedProvider([
            new CompleteResponse(
                content: 'reading',
                toolCalls: [new ToolCall('c0', 'Read', ['file_path' => 'src/Alpha.php'])],
                usage: Usage::new(totalTokens: 7_100, inputTokens: 7_000, outputTokens: 100),
            ),
            new CompleteResponse(content: 'answer'),
        ], contextWindow: 10_000);

        $this->backend($provider, $root)->complete([Message::user('go')]);

        $this->assertStringNotContainsString('Context window:', (string) TurnContextBlock::latestIn($provider->requests[0]->messages), 'below 60% the share is noise');
        $this->assertStringContainsString('Context window: 70% used.', (string) TurnContextBlock::latestIn($provider->requests[1]->messages));
    }

    /** @return list<mixed> the turn-context rows $request carries */
    private static function rows(CompleteRequest $request): array
    {
        return array_values(array_filter($request->messages, static fn ($m): bool => TurnContextBlock::isTurnContext($m)));
    }

    private function backend(ScriptedProvider $provider, string $root): EngineBackend
    {
        return EngineBackend::new($provider, 'm')
            ->withoutHooks()
            ->withRoot($root)
            ->withTools([self::readTool()]);
    }

    /** A committed repository with one uncommitted edit, so `git diff` has a body. */
    private function dirtyGitFixture(): string
    {
        $home = $this->tempDir();
        mkdir($home . '/.sugar-crush', 0o700, true);
        $this->useHomeSandbox($home);

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
        file_put_contents($root . '/src/Alpha.php', "// uncommitted\n", FILE_APPEND);

        return $root;
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/crush-tcrow-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o700, true);
        $this->dirs[] = $dir;

        return $dir;
    }

    private static function readTool(): Tool
    {
        return new class () implements Tool {
            public function name(): string
            {
                return 'Read';
            }

            public function description(): string
            {
                return 'Read fixture';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                return new ToolResult(toolCallId: (string) ($args['toolCallId'] ?? ''), content: 'contents');
            }
        };
    }
}
