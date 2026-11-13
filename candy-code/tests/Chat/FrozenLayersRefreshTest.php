<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Backend\RefreshesPromptLayers;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Context\CompactorConfig;
use SugarCraft\Code\Context\InstructionFileLoader;
use SugarCraft\Code\Message;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tests\Support\ScriptedProvider;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * S-X03: `/clear` and `/compact` re-freeze the frozen prompt layers at once.
 *
 * Before, only the next TURN noticed a rewritten history
 * ({@see \SugarCraft\Code\Context\SessionPromptMemo::observeTurn()}); in
 * between, `/context --raw` still printed the old repo map and instruction
 * slab beside fresh byte statistics. A session SWITCH stays out of it
 * (ISS-054) — pinned in SessionPromptMemoPrimeTest.
 */
final class FrozenLayersRefreshTest extends TestCase
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

    public function testClearRebuildsTheFrozenLayersBeforeTheNextTurn(): void
    {
        [$backend, $root] = $this->frozenBackend();
        $this->assertStringContainsString('RULE-ONE', $backend->dumpSystemPrompt()['prompt']);

        file_put_contents($root . '/CLAUDE.md', "RULE-TWO\n");
        $this->assertStringContainsString('RULE-ONE', $backend->dumpSystemPrompt()['prompt'], 'frozen: nothing has refreshed yet');

        $chat = $this->chat($backend, '/clear');
        [$cleared] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertSame([], $cleared->history);
        $dump = $backend->dumpSystemPrompt()['prompt'];
        $this->assertStringContainsString('RULE-TWO', $dump, '/clear re-froze the layers');
        $this->assertStringNotContainsString('RULE-ONE', $dump);
    }

    public function testManualCompactRebuildsTheFrozenLayers(): void
    {
        [$backend, $root] = $this->frozenBackend();
        file_put_contents($root . '/CLAUDE.md', "RULE-TWO\n");

        $chat = $this->chat($backend, '/compact', $this->longHistory());
        $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertStringContainsString('RULE-TWO', $backend->dumpSystemPrompt()['prompt'], '/compact re-froze the layers');
    }

    public function testModelCompactionResultRebuildsTheFrozenLayers(): void
    {
        [$backend, $root] = $this->frozenBackend();
        file_put_contents($root . '/CLAUDE.md', "RULE-TWO\n");

        $chat = new Chat(
            history: $this->longHistory(),
            inputBuf: '/compact',
            backend: $backend,
            compactorConfig: CompactorConfig::new()->withRecentPreserveCount(2),
            summaryBackend: $this->summarizer(),
        );
        [$pending, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertNotNull($cmd);
        $async = $cmd();
        $this->assertInstanceOf(AsyncCmd::class, $async);
        $msg = null;
        $async->promise->then(static function ($m) use (&$msg): void {
            $msg = $m;
        });
        $this->assertNotNull($msg);
        $this->assertStringContainsString('RULE-ONE', $backend->dumpSystemPrompt()['prompt'], 'still frozen until the compaction lands');

        $pending->update($msg);

        $this->assertStringContainsString('RULE-TWO', $backend->dumpSystemPrompt()['prompt']);
    }

    public function testTheNextTurnAfterAClearRunsOnRebuiltLayers(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'one'), new CompleteResponse(content: 'two')]);
        [$backend, $root] = $this->frozenBackend($provider);
        $backend->complete([Message::user('a')]);

        file_put_contents($root . '/CLAUDE.md', "RULE-TWO\n");
        $chat = $this->chat($backend, '/clear');
        [$cleared] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $backend->complete([...$cleared->history, Message::user('b')]);

        $this->assertStringContainsString('RULE-ONE', (string) $provider->requests[0]->systemPrompt);
        $this->assertStringContainsString('RULE-TWO', (string) $provider->requests[1]->systemPrompt);
        $this->assertStringNotContainsString('RULE-ONE', (string) $provider->requests[1]->systemPrompt);
    }

    public function testABackendWithoutTheCapabilityIsUntouched(): void
    {
        $spy = new class implements Backend {
            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                return Message::assistant('x');
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                return \React\Promise\resolve(Message::assistant('x'));
            }
        };
        $this->assertNotInstanceOf(RefreshesPromptLayers::class, $spy);

        [$cleared] = $this->chat($spy, '/clear')->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertSame([], $cleared->history);
    }

    /**
     * @return array{0: EngineBackend, 1: string}
     */
    private function frozenBackend(?ScriptedProvider $provider = null): array
    {
        $home = $this->tempDir();
        mkdir($home . '/.candy-code', 0o700, true);
        $this->useHomeSandbox($home);
        $root = $this->tempDir();
        file_put_contents($root . '/CLAUDE.md', "RULE-ONE\n");

        $backend = EngineBackend::new($provider ?? new ScriptedProvider([new CompleteResponse(content: 'x')]), 'm')
            ->withoutHooks()
            ->withRoot($root)
            ->withInstructionLoader(new InstructionFileLoader($root));
        $backend->dumpSystemPrompt(); // the first build freezes the layers

        return [$backend, $root];
    }

    /**
     * @param list<Message>|null $history
     */
    private function chat(Backend $backend, string $draft, ?array $history = null): Chat
    {
        return new Chat(
            history: $history ?? [Message::user('hello'), Message::assistant('hi')],
            inputBuf: $draft,
            backend: $backend,
            compactorConfig: CompactorConfig::new()->withRecentPreserveCount(2),
        );
    }

    /** @return list<Message> */
    private function longHistory(): array
    {
        $history = [];
        for ($i = 1; $i <= 6; $i++) {
            $history[] = Message::user("question {$i}");
            $history[] = Message::assistant("answer {$i} " . str_repeat('detail ', 60));
        }

        return $history;
    }

    private function summarizer(): Backend
    {
        return new class implements Backend {
            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                return Message::assistant("1.\nasked: something");
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                return \React\Promise\resolve(Message::assistant("1.\nasked: something"));
            }
        };
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/frozen-layers-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o700, true);
        $this->dirs[] = $dir;

        return $dir;
    }
}
