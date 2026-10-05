<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\ReportsContextWindow;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Context\IdleCompactionPolicy;
use SugarCraft\Crush\Message;

/**
 * Roadmap N-P4b, the user decision's hard rule, driven through Chat: the
 * absolute caps are on by default (reminder 100k, automatic compaction 150k)
 * and an absolute tier must NEVER make the thrash breaker refuse a prompt.
 *
 * The shape is a 1M-token session whose ten preserved exchanges alone weigh
 * ~200k estimated tokens — over the 150k cap, so no compaction could get under
 * it. The tier stands down: every prompt goes straight out, nothing is paid
 * for a summary that buys nothing, and the breaker's run never moves.
 */
final class AbsoluteCapBreakerTest extends TestCase
{
    private const MILLION = 1_000_000;

    public function testAnOversizedKeptTailNeverTripsTheBreaker(): void
    {
        $main = self::backend(self::MILLION);
        $summarizer = self::summarizer();
        $chat = new Chat(
            history: self::heavyTail(),
            inputBuf: self::heavyDraft(0),
            backend: $main,
            summaryBackend: $summarizer,
        );

        for ($attempt = 1; $attempt <= IdleCompactionPolicy::REFILL_LIMIT + 2; $attempt++) {
            [$after, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
            $this->assertNotNull($cmd, "attempt {$attempt}: the prompt is dispatched");
            $this->assertSame(0, self::refills($after), "attempt {$attempt}: an absolute tier counts nothing toward the breaker");
            $this->assertSame(0, self::saying($after->history, 'Context compaction has run'), "attempt {$attempt}: and refuses nothing");

            [$chat] = $after->update(self::resolve($cmd));
            $this->assertSame($attempt, $main->calls, "attempt {$attempt}: the conversation backend was reached");
            $chat = (new \ReflectionMethod(Chat::class, 'mutate'))->invoke($chat, ['inputBuf' => self::heavyDraft($attempt)]);
        }

        $this->assertSame(0, $summarizer->calls, 'no summary was paid for: compacting could not get under the cap');
    }

    /**
     * The other side of the same rule: where condensing the older exchanges
     * DOES get under 150k, a 1M session compacts there — at 150k, not 850k.
     */
    public function testAMillionTokenSessionCompactsAtTheCap(): void
    {
        $main = self::backend(self::MILLION);
        $summarizer = self::summarizer();
        $chat = new Chat(
            history: self::condensable(),
            inputBuf: 'what changed?',
            backend: $main,
            summaryBackend: $summarizer,
        );

        [$parked, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertNotNull($cmd);
        $this->assertSame(0, $main->calls, 'the turn is parked behind the summary');
        self::resolve($cmd);
        $this->assertSame(1, $summarizer->calls, 'the 150k cap fired the automatic tier');
        $this->assertSame(0, self::refills($parked));
    }

    /** A window whose percentages sit under the caps is untouched by them. */
    public function testOnAWindowWhosePercentageIsBelowTheCapNothingChanges(): void
    {
        $main = self::backend(160_000);
        $chat = new Chat(
            history: self::small(),
            inputBuf: 'hello',
            backend: $main,
            summaryBackend: self::summarizer(),
        );

        [, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertNotNull($cmd);
        self::resolve($cmd);
        $this->assertSame(1, $main->calls, 'a small history on a small window goes straight out');
    }

    // =====================================================================

    /**
     * A ~15k-token prompt, so the preserved window stays heavier than the cap
     * as the session grows — every exchange this test adds is as futile to
     * compact as the ones it started with. (With trivial prompts the heavy
     * exchanges slide out of the kept ten and compaction rightly resumes.)
     */
    private static function heavyDraft(int $attempt): string
    {
        return str_repeat('z', 60_000) . " {$attempt}";
    }

    /** @return list<Message> one small older exchange, then ten of ~20k tokens */
    private static function heavyTail(): array
    {
        $history = [Message::user('first question'), Message::assistant('first answer')];
        for ($i = 0; $i < 10; $i++) {
            $history[] = Message::user(str_repeat(chr(97 + $i), 40_000));
            $history[] = Message::assistant(str_repeat(chr(107 + $i), 40_000));
        }

        return $history;
    }

    /** @return list<Message> eight older ~20k exchanges, then ten trivial ones: ~160k, condensable */
    private static function condensable(): array
    {
        $history = [];
        for ($i = 0; $i < 8; $i++) {
            $history[] = Message::user(str_repeat(chr(97 + $i), 40_000));
            $history[] = Message::assistant(str_repeat(chr(107 + $i), 40_000));
        }
        for ($i = 0; $i < 10; $i++) {
            $history[] = Message::user("q{$i}");
            $history[] = Message::assistant("r{$i}");
        }

        return $history;
    }

    /** @return list<Message> */
    private static function small(): array
    {
        return [Message::user('hi'), Message::assistant('hello')];
    }

    private static function backend(int $window): Backend&ReportsContextWindow
    {
        return new class ($window) implements Backend, ReportsContextWindow {
            public int $calls = 0;

            public function __construct(private readonly int $window)
            {
            }

            public function contextWindow(): int
            {
                return $this->window;
            }

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                $this->calls++;

                return Message::assistant('ok');
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                $this->calls++;

                return \React\Promise\resolve(Message::assistant('ok'));
            }
        };
    }

    private static function summarizer(): Backend
    {
        $records = [];
        for ($i = 1; $i <= 12; $i++) {
            $records[] = "{$i}.\nasked: condensed exchange {$i}";
        }
        $reply = implode("\n", $records);

        return new class ($reply) implements Backend {
            public int $calls = 0;

            public function __construct(private readonly string $reply)
            {
            }

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                $this->calls++;

                return Message::assistant($this->reply);
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                $this->calls++;

                return \React\Promise\resolve(Message::assistant($this->reply));
            }
        };
    }

    private static function resolve(\Closure $cmd): mixed
    {
        $asyncCmd = $cmd();
        if (!$asyncCmd instanceof AsyncCmd) {
            return $asyncCmd;
        }
        $resolved = null;
        $asyncCmd->promise->then(static function ($msg) use (&$resolved): void {
            $resolved = $msg;
        });

        return $resolved;
    }

    private static function refills(Chat $chat): int
    {
        return (new \ReflectionProperty(Chat::class, 'consecutiveRefillCompactions'))->getValue($chat);
    }

    /** @param list<Message> $history */
    private static function saying(array $history, string $says): int
    {
        return \count(array_filter($history, static fn (Message $m): bool => str_contains((string) $m->content, $says)));
    }
}
