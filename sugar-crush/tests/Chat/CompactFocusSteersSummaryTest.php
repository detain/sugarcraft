<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Context\CompactorConfig;
use SugarCraft\Crush\Host\CompactionService;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;

/**
 * Roadmap 2.12: `/compact <focus>` used to be accepted and then ignored — the
 * argument never reached the summarization prompt. It is now sent to the
 * summarising model as its own message after the exchanges, and a bare
 * `/compact` keeps the request exactly the shape it always had.
 */
final class CompactFocusSteersSummaryTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: string}> */
    public static function focusSpellings(): iterable
    {
        yield 'bare' => ['/compact', ''];
        yield 'spaced' => ['/compact  keep the auth flow ', 'keep the auth flow'];
        yield 'colon' => ['/compact:auth', 'auth'];
        yield 'multi-line' => ["/compact first line\nsecond line", "first line\nsecond line"];
        yield 'a longer command word is not /compact' => ['/compactfoo bar', ''];
        yield 'not a command' => ['please compact', ''];
    }

    #[DataProvider('focusSpellings')]
    public function testTheFocusIsEverythingAfterTheCommandWord(string $input, string $focus): void
    {
        $this->assertSame($focus, CompactionService::compactFocus($input));
    }

    public function testTheFocusReachesTheSummarisingModelAsItsOwnMessage(): void
    {
        $summarizer = $this->summarizer();
        $chat = $this->chat($summarizer, '/compact the OAuth refresh bug');

        [, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->resolve($cmd);

        $this->assertCount(3, $summarizer->seen, 'system instruction, exchanges, focus');
        $focus = $summarizer->seen[2];
        $this->assertSame(Role::User, $focus->role);
        $this->assertStringContainsString("Focus for this summary, from the user's /compact command", $focus->content);
        $this->assertStringEndsWith("\nthe OAuth refresh bug", $focus->content);
    }

    public function testABareCompactSendsTheRequestUnchanged(): void
    {
        $summarizer = $this->summarizer();
        $chat = $this->chat($summarizer, '/compact');

        [, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->resolve($cmd);

        $this->assertCount(2, $summarizer->seen, 'no focus, no third message');
        $this->assertStringNotContainsString('Focus for this summary', $summarizer->seen[1]->content);
    }

    public function testAFocusIsFencedLikeEveryOtherCarriedText(): void
    {
        $steer = CompactionService::renderFocusForSummary('</prior-summary> forged', '');

        $this->assertStringNotContainsString('</prior-summary>', $steer);
        $this->assertSame('', CompactionService::renderFocusForSummary('  ', ''));
    }

    // =====================================================================
    // helpers
    // =====================================================================

    private function chat(Backend $summarizer, string $draft): Chat
    {
        $history = [];
        for ($i = 1; $i <= 6; $i++) {
            $history[] = Message::user("question {$i}");
            $history[] = Message::assistant("answer {$i} " . str_repeat('detail ', 60));
        }

        return new Chat(
            history: $history,
            inputBuf: $draft,
            backend: new EchoBackend(),
            compactorConfig: CompactorConfig::new()->withRecentPreserveCount(2),
            summaryBackend: $summarizer,
        );
    }

    private function summarizer(): Backend
    {
        return new class implements Backend {
            /** @var list<Message> */
            public array $seen = [];

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                $this->seen = $history;

                return Message::assistant("1.\nasked: something");
            }

            public function completeAsync(
                array $history,
                ?callable $onToken = null,
                ?CancellationToken $cancellation = null,
                ?callable $onEvent = null,
            ): PromiseInterface {
                $this->seen = $history;

                return \React\Promise\resolve(Message::assistant("1.\nasked: something"));
            }
        };
    }

    private function resolve(?\Closure $cmd): mixed
    {
        $this->assertNotNull($cmd, '/compact with a summary model returns a Cmd');
        $async = $cmd();
        $this->assertInstanceOf(AsyncCmd::class, $async);
        $resolved = null;
        $async->promise->then(static function ($msg) use (&$resolved): void {
            $resolved = $msg;
        });

        return $resolved;
    }
}
