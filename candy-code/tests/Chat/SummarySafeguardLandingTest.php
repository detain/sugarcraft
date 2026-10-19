<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Backend\ReportsContextWindow;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Context\CompactorConfig;
use SugarCraft\Code\Context\Compaction\StateSummaryTemplate;
use SugarCraft\Code\HistoryCompactedMsg;
use SugarCraft\Code\Message;
use SugarCraft\Code\ToolResult;

/**
 * CTX-4 (plan_comparison.md): what a HOLD verdict costs each landing route.
 *
 * The three-way ruling under test: a typed `/compact` REFUSES (nothing waits
 * on it; the full history beats a fold on collapsed text), the parked 85% tier
 * DEGRADES to the heuristic fold with an advisory (the prompt is already typed
 * — holding it would 400 a turn the user pressed Enter for), and the blocking
 * tier's refusal stays reachable UNDER the fold, which is the CTX-13 property:
 * a degraded fold is still just a fold, measured by the same 95% check as
 * every other one. PASS and FILL routes are pinned untouched by the existing
 * families ({@see CompactModelSummaryTest}, {@see AutomaticCompactionModelSummaryTest});
 * the fixture replies here are the collapsed shapes only.
 */
final class SummarySafeguardLandingTest extends TestCase
{
    /**
     * Six plain pairs plus one exchange carrying the tool rows whose files
     * become the audit's file needles. With the preserve-tail of two, four
     * exchanges are condensed; the Read rows sit inside them.
     *
     * @return list<Message>
     */
    private static function fileBearingPairs(): array
    {
        $out = [
            Message::user('fix the router'),
            Message::assistant('reading it')->withToolResults([
                new ToolResult('Read', 'contents', arguments: ['file_path' => 'src/Router.php']),
                new ToolResult('Read', 'contents', arguments: ['file_path' => 'src/Handler.php']),
            ]),
        ];
        for ($i = 1; $i <= 5; $i++) {
            $out[] = Message::user("question {$i}");
            $out[] = Message::assistant("answer {$i} " . str_repeat('detail ', 60));
        }

        return $out;
    }

    /**
     * A reply whose block writes three of the eight model sections and carries
     * none of the file references: structural collapse by the only definition
     * that is HOLD, with one sound-looking record line beside it so the
     * records channel alone is proven NOT to be what fires the verdict.
     */
    private static function collapsedReply(): string
    {
        return "1.\nasked: about routing\n"
            . "<session-state>\n## Goal\nwork happened\n## Progress\nsome exchanges\n"
            . "## Next step\ncontinue\n</session-state>";
    }

    /** The audit sentence every HOLD notice carries. */
    private const HOLD_MARK = 'discarded by the safeguard audit';

    private function compactChat(?Backend $summaryBackend, string $draft = '/compact'): Chat
    {
        return new Chat(
            history: self::fileBearingPairs(),
            inputBuf: $draft,
            backend: new SafeguardTurnBackend(88_000),
            compactorConfig: CompactorConfig::new()->withRecentPreserveCount(2),
            summaryBackend: $summaryBackend,
        );
    }

    private function safeguardBackend(string $reply): Backend
    {
        return new SafeguardSummaryBackend($reply);
    }

    private function submit(Chat $chat): array
    {
        return $chat->update(new KeyMsg(KeyType::Enter, ''));
    }

    /** Drive a Cmd built by Cmd::promise() and hand back the Msg it resolves to. */
    private function drive(\Closure $cmd): mixed
    {
        $asyncCmd = $cmd();
        $this->assertInstanceOf(AsyncCmd::class, $asyncCmd);
        $resolved = null;
        $asyncCmd->promise->then(function ($msg) use (&$resolved): void {
            $resolved = $msg;
        });

        return $resolved;
    }

    private function latchOf(Chat $chat): ?string
    {
        return (new \ReflectionProperty(Chat::class, 'pendingCompactionId'))->getValue($chat);
    }

    /** @param list<Message> $history */
    private static function textOf(array $history): string
    {
        return implode("\n", array_map(static fn (Message $m): string => $m->content, $history));
    }

    public function testTypedCompactWithACollapsedBlockRefusesAndPreservesTheHistory(): void
    {
        $chat = $this->compactChat($this->safeguardBackend(self::collapsedReply()));
        $before = Message::agentVisible($chat->history);

        [$pending, $cmd] = $this->submit($chat);
        $msg = $this->drive($cmd);
        $this->assertInstanceOf(HistoryCompactedMsg::class, $msg);
        $this->assertNotNull($msg->safeguardHold, 'the settle stamped the verdict');
        $this->assertStringContainsString('omitted 5 of 8 state sections', (string) $msg->safeguardHold);
        $this->assertStringContainsString('carried 0 of 2 file references', (string) $msg->safeguardHold);

        [$done] = $pending->update($msg);

        $text = self::textOf($done->history);
        $this->assertStringContainsString(self::HOLD_MARK, $text, 'the refusal names the audit');
        $this->assertStringContainsString('Compaction refused:', $text);
        $this->assertStringContainsString('Nothing was condensed', $text);
        $this->assertStringNotContainsString('[summary]', $text, 'nothing condensed: no compaction rows exist');
        $this->assertStringNotContainsString('asked: about routing', $text, 'and the model text did not land either');
        $this->assertSame(
            array_map(static fn (Message $m): string => $m->content, $before),
            array_map(
                static fn (Message $m): string => $m->content,
                array_values(array_filter(
                    Message::agentVisible($done->history),
                    static fn (Message $m): bool => !str_contains($m->content, self::HOLD_MARK)
                        && !str_contains($m->content, 'Summarising'),
                )),
            ),
            'the whole pre-command transcript survives, row for row',
        );
        $this->assertNull($this->latchOf($done), 'the compaction latch is released');
        $this->assertSame(1, $this->countSaying($done->history, 'Compaction refused:'), 'exactly one refusal row');
    }

    /** @param list<Message> $history */
    private function countSaying(array $history, string $text): int
    {
        return count(array_filter(
            $history,
            static fn (Message $m): bool => str_contains((string) $m->content, $text),
        ));
    }

    public function testParkedTurnWithACollapsedBlockFoldsOnTheHeuristicAndSaysWhy(): void
    {
        $chat = new Chat(
            history: self::parkablePairs(),
            inputBuf: 'what changed in the router?',
            backend: new SafeguardTurnBackend(88_000),
            summaryBackend: $this->safeguardBackend(self::collapsedReply()),
        );

        [$parked, $cmd] = $this->submit($chat);
        $this->assertNotNull($cmd, 'fixture: the 85% tier parked this');
        [$done, $turnCmd] = $parked->update($this->drive($cmd));

        $text = self::textOf($done->history);
        $this->assertStringContainsString(self::HOLD_MARK, $text, 'the fold owns its reason');
        $this->assertStringContainsString('compacted with the local heuristic instead', $text);
        $this->assertStringContainsString('[summary]', $text, 'the heuristic lines landed');
        $this->assertStringNotContainsString('asked: about routing', $text, 'the collapsed model record did not');
        $this->assertNotNull($turnCmd, 'the parked turn was dispatched, not refused');
        $this->assertSame(0, $this->countSaying($done->history, 'This turn was NOT sent'));
    }

    /**
     * The CTX-13 property: HOLD changes WHICH lines the tier writes, never
     * WHICH checks run. A heuristic fold over an unshrinkable history still
     * crosses the 95% blocking tier and still refuses the parked turn with the
     * ordinary blocking sentence — beside the advisory.
     */
    public function testTheHeuristicFoldUnderHoldStillReachesTheBlockingRefusal(): void
    {
        $history = [];
        for ($i = 0; $i < 13; $i++) {
            $user = Message::user(str_repeat(chr(97 + $i), 30_000));
            $assistant = Message::assistant(str_repeat(chr(110 + $i), 30_000));
            if ($i === 0) {
                $assistant = $assistant->withToolResults([
                    new ToolResult('Read', 'c', arguments: ['file_path' => 'src/Router.php']),
                ]);
            }
            $history[] = $user;
            $history[] = $assistant;
        }
        $chat = new Chat(
            history: $history,
            inputBuf: 'what changed in the router?',
            backend: new SafeguardTurnBackend(88_000),
            summaryBackend: $this->safeguardBackend(self::collapsedReply()),
        );

        [$parked, $cmd] = $this->submit($chat);
        $this->assertNotNull($cmd, 'fixture: the 85% tier must park this');
        [$refused] = $parked->update($this->drive($cmd));

        $text = self::textOf($refused->history);
        $this->assertStringContainsString('This turn was NOT sent', $text, 'the blocking tier is reachable under HOLD');
        $this->assertStringContainsString(self::HOLD_MARK, $text, 'and the refusal tells the fold was heuristic-by-audit');
        $this->assertNull($this->latchOf($refused));
    }

    public function testABackgroundHoldIsNeverHeldForSplice(): void
    {
        $chat = $this->compactChat(null);
        $slot = [
            'id' => 'cid-hold',
            'sessionId' => (new \ReflectionProperty(Chat::class, 'currentSessionId'))->getValue($chat),
            'fingerprint' => null,
            'summaries' => null,
        ];
        $armed = (new \ReflectionMethod(Chat::class, 'mutate'))->invoke($chat, ['backgroundSummary' => $slot]);
        $this->assertInstanceOf(Chat::class, $armed);

        [$done] = $armed->update(new HistoryCompactedMsg(
            'cid-hold',
            [StateSummaryTemplate::SUMMARY_KEY => 'collapsed model text'],
            background: true,
            safeguardHold: 'omitted 5 of 8 state sections; carried 0 of 2 file references',
        ));

        $held = (new \ReflectionProperty(Chat::class, 'backgroundSummary'))->getValue($done);
        $this->assertSame([], $held['summaries'], 'a HOLD slot holds nothing to splice');
    }

    /**
     * 3 heavy pairs + 10 trivial ones over the 88,000-token window: past the
     * 85% tier at submit, comfortably back under 95% once the heavy ones
     * condense — the fold itself must not be the refusal in this test.
     * Shape borrowed from {@see AutomaticCompactionModelSummaryTest}.
     *
     * @return list<Message>
     */
    private static function parkablePairs(): array
    {
        $history = [];
        for ($i = 0; $i < 3; $i++) {
            $user = Message::user(str_repeat(chr(97 + $i), 52_000));
            $assistant = Message::assistant(str_repeat(chr(110 + $i), 52_000));
            if ($i === 0) {
                $assistant = $assistant->withToolResults([
                    new ToolResult('Read', 'c', arguments: ['file_path' => 'src/Router.php']),
                ]);
            }
            $history[] = $user;
            $history[] = $assistant;
        }
        for ($i = 0; $i < 10; $i++) {
            $history[] = Message::user("q{$i}");
            $history[] = Message::assistant("r{$i}");
        }

        return $history;
    }
}

/** The turn backend: fixed window, one canned reply, counts its calls. */
final class SafeguardTurnBackend implements Backend, ReportsContextWindow
{
    public int $turnCalls = 0;

    public function __construct(private readonly int $window) {}

    public function contextWindow(): int
    {
        return $this->window;
    }

    public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
    {
        $this->turnCalls++;

        return Message::assistant('ok');
    }

    public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
    {
        $this->turnCalls++;

        return \React\Promise\resolve(Message::assistant('ok'));
    }
}

/** A summarization backend answering with a fixed reply, resolving immediately. */
final class SafeguardSummaryBackend implements Backend
{
    public function __construct(private readonly string $reply) {}

    public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
    {
        return Message::assistant($this->reply);
    }

    public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
    {
        return \React\Promise\resolve(Message::assistant($this->reply));
    }
}
