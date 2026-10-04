<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Crush\AssistantMsg;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Memory\AutoMemoryConsolidator;
use SugarCraft\Crush\Memory\MemoryStore;
use SugarCraft\Crush\MemoryConsolidatedMsg;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Usage;

/**
 * Roadmap 5.2 wiring: a settled turn schedules auto-memory consolidation on
 * the summary backend, and the Msg it resolves to is accounted and reported
 * in one display-only notice.
 */
final class AutoMemoryConsolidationWiringTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/amcw_' . uniqid((string) getmypid(), true);
        mkdir($this->dir . '/home', 0700, true);
        mkdir($this->dir . '/root', 0700, true);
        putenv(AutoMemoryConsolidator::ENV_DISABLE);
    }

    protected function tearDown(): void
    {
        putenv(AutoMemoryConsolidator::ENV_DISABLE);
        $this->rmTree($this->dir);
    }

    public function testASettledTurnConsolidatesAndTheOutcomeIsAccountedAndAnnounced(): void
    {
        $store = new MemoryStore($this->dir . '/home');
        $summary = $this->summaryBackend('{"operations":[{"op":"add","content":"The API is versioned under /v2."}]}');
        $chat = $this->chat($store, $summary);

        [$settled, $cmd] = $chat->update(new AssistantMsg(Message::assistant(str_repeat('The API now lives under /v2 and v1 is gone. ', 8))));

        self::assertInstanceOf(\Closure::class, $cmd);
        $landed = $this->resolve($cmd);
        self::assertInstanceOf(MemoryConsolidatedMsg::class, $landed);
        self::assertCount(1, $summary->asked, 'one request, on the summary backend');
        self::assertCount(1, $store->list('project'), 'the note is written as the call settles');

        [$shown, $none] = $settled->update($landed);
        self::assertNull($none);
        $last = $shown->history[\count($shown->history) - 1];
        self::assertTrue($last->uiOnly, 'the notice is display-only');
        self::assertStringStartsWith('Auto-memory saved 1 note', $last->content);
        self::assertEqualsWithDelta(0.002, $shown->spentUsd(), 1e-9);
    }

    public function testARunThatSavesNothingIsAccountedButSilent(): void
    {
        $chat = $this->chat(new MemoryStore($this->dir . '/home'), $this->summaryBackend('{"operations":[]}'));
        [$settled, $cmd] = $chat->update(new AssistantMsg(Message::assistant(str_repeat('Nothing durable here at all. ', 10))));

        [$shown] = $settled->update($this->resolve($cmd));

        self::assertSame(\count($settled->history), \count($shown->history));
        self::assertEqualsWithDelta(0.002, $shown->spentUsd(), 1e-9);
    }

    public function testNoMemoryStoreOrNoSummaryBackendSchedulesNothing(): void
    {
        $reply = new AssistantMsg(Message::assistant(str_repeat('A long enough answer. ', 20)));
        $history = [Message::user(str_repeat('A long enough question. ', 20))];

        [, $cmd] = (new Chat(history: $history, inFlight: true, summaryBackend: $this->summaryBackend('{}'), projectRoot: $this->dir . '/root'))->update($reply);
        self::assertNull($cmd);

        [, $cmd] = (new Chat(history: $history, inFlight: true, memoryStore: new MemoryStore($this->dir . '/home'), projectRoot: $this->dir . '/root'))->update($reply);
        self::assertNull($cmd);
    }

    private function chat(MemoryStore $store, Backend $summary): Chat
    {
        return new Chat(
            history: [Message::user(str_repeat('Move the API under /v2 and drop v1 entirely. ', 6))],
            inFlight: true,
            memoryStore: $store,
            summaryBackend: $summary,
            projectRoot: $this->dir . '/root',
        );
    }

    private function resolve(\Closure $cmd): mixed
    {
        $async = $cmd();
        self::assertInstanceOf(AsyncCmd::class, $async);
        $resolved = null;
        $async->promise->then(static function (mixed $msg) use (&$resolved): void {
            $resolved = $msg;
        });

        return $resolved;
    }

    private function summaryBackend(string $reply): Backend
    {
        return new class ($reply) implements Backend {
            /** @var list<list<Message>> */
            public array $asked = [];

            public function __construct(private readonly string $reply)
            {
            }

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                return Message::assistant($this->reply);
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                $this->asked[] = $history;

                return \React\Promise\resolve(Message::assistant($this->reply)->withUsage(Usage::new(20, 0.002)));
            }
        };
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            is_dir($path) && !is_link($path) ? $this->rmTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
