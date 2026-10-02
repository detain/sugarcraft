<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Session\SessionStore;

/**
 * Audit 15b-12: with no tool-less title backend, `scheduleTitleGeneration()`
 * used to fall back to the MAIN backend. `Bootstrap::titleBackend()` is null on
 * the `SUGARCRUSH_BACKEND_CMD[_STREAM]` tier, so an agentic CLI command backend
 * was handed the first prompt twice - once as the turn, once as the "title"
 * job - and an EngineBackend ran a full tool-enabled turn just to name the
 * session. The assertions land on what the main backend RECEIVED, because that
 * is the boundary the defect crossed.
 */
final class TitleBackendFallbackTest extends TestCase
{
    public function testWithoutATitleBackendTheFirstPromptReachesTheMainBackendExactlyOnce(): void
    {
        $store = self::store('sess-no-titler');
        $main = self::recorder('Done.');
        $chat = new Chat(
            backend: $main,
            sessionStore: $store,
            currentSessionId: 'sess-no-titler',
            titleBackend: null,
        );

        $chat = $this->enter($chat, 'delete the stale branches');

        $this->assertCount(1, $main->calls, 'the turn went out once and nothing else rode the main backend');
        $this->assertSame([], self::titleCalls($main), 'no title request was sent to the main backend');
        $this->assertNull($chat->currentSessionName(), 'the session stays unnamed');
        $this->assertNull($store->getSession('sess-no-titler')['name']);
    }

    public function testWithoutATitleBackendSubmitSchedulesOnlyTheTurn(): void
    {
        $chat = new Chat(
            inputBuf: 'delete the stale branches',
            backend: self::recorder('Done.'),
            sessionStore: self::store('sess-no-batch'),
            currentSessionId: 'sess-no-batch',
        );

        [, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertInstanceOf(\Closure::class, $cmd);
        $msg = $cmd();
        $this->assertNotInstanceOf(BatchMsg::class, $msg, 'no title Cmd was batched alongside the completion');
        $this->assertInstanceOf(AsyncCmd::class, $msg);
    }

    public function testWithATitleBackendTheTitleRunsThereAndNotOnTheMainBackend(): void
    {
        $store = self::store('sess-titler');
        $main = self::recorder('Done.');
        $titler = self::recorder('Pruning stale git branches');
        $chat = new Chat(
            backend: $main,
            sessionStore: $store,
            currentSessionId: 'sess-titler',
            titleBackend: $titler,
        );

        $chat = $this->enter($chat, 'delete the stale branches');

        $this->assertCount(1, $main->calls, 'the main backend saw the turn only');
        $this->assertSame([], self::titleCalls($main));
        // The title backend also answers prompt suggestions, so pick the title
        // call out by its instruction rather than by position.
        $this->assertCount(1, self::titleCalls($titler), 'the title job ran on the tool-less backend');
        $this->assertSame('Pruning stale git branches', $chat->currentSessionName());
        $this->assertSame('Pruning stale git branches', $store->getSession('sess-titler')['name']);
    }

    // =========================================================================

    private static function store(string $sessionId): SessionStore
    {
        $store = new SessionStore(':memory:');
        $store->createSession($sessionId, 'sugarcrush', 'test-model');

        return $store;
    }

    /**
     * Type $draft and press Enter, running every Cmd that settles
     * synchronously (every backend here resolves immediately).
     */
    private function enter(Chat $chat, string $draft): Chat
    {
        $chat = (new \ReflectionMethod(Chat::class, 'mutate'))->invoke($chat, ['inputBuf' => $draft]);
        [$chat, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        return $this->pump($chat, $cmd);
    }

    private function pump(Chat $chat, ?\Closure $cmd, int $depth = 0): Chat
    {
        if ($cmd === null || $depth > 20) {
            return $chat;
        }
        foreach ($this->runCmd($cmd) as $msg) {
            [$chat, $next] = $chat->update($msg);
            $chat = $this->pump($chat, $next, $depth + 1);
        }

        return $chat;
    }

    /** @return list<Msg> */
    private function runCmd(\Closure $cmd): array
    {
        $out = [];
        $msg = $cmd();
        if ($msg instanceof BatchMsg) {
            foreach ($msg->cmds as $inner) {
                if ($inner !== null) {
                    array_push($out, ...$this->runCmd($inner));
                }
            }

            return $out;
        }
        if ($msg instanceof AsyncCmd) {
            $msg->promise->then(static function ($v) use (&$out): void {
                if ($v instanceof Msg) {
                    $out[] = $v;
                }
            });

            return $out;
        }
        if ($msg instanceof Msg) {
            $out[] = $msg;
        }

        return $out;
    }

    /**
     * The calls on $backend that were title requests (led by Chat's title
     * instruction).
     *
     * @return list<array<int, Message>>
     */
    private static function titleCalls(Backend $backend): array
    {
        $instruction = (new \ReflectionClassConstant(Chat::class, 'TITLE_PROMPT'))->getValue();

        return array_values(array_filter(
            $backend->calls,
            static fn(array $h): bool => ($h[0] ?? null)?->content === $instruction,
        ));
    }

    /** A backend that records every history it is handed and answers $reply. */
    private static function recorder(string $reply): Backend
    {
        return new class ($reply) implements Backend {
            /** @var list<array<int, Message>> */
            public array $calls = [];

            public function __construct(private readonly string $reply) {}

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                $this->calls[] = $history;

                return Message::assistant($this->reply);
            }

            public function completeAsync(
                array $history,
                ?callable $onToken = null,
                ?CancellationToken $cancellation = null,
                ?callable $onEvent = null,
            ): PromiseInterface {
                $this->calls[] = $history;

                return \React\Promise\resolve(Message::assistant($this->reply));
            }
        };
    }
}
