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
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Backend\ReportsContextWindow;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Context\CompactorConfig;
use SugarCraft\Crush\HistoryCompactedMsg;
use SugarCraft\Crush\Message;

/**
 * Audit 15b-03-rem(a): compaction read the WHOLE transcript, UI-only rows
 * included, although those rows never reach the model.
 *
 * Three ways that leaked, each pinned below:
 *
 *  1. The summarization request a model is sent offered command echoes and
 *     their output (`/help`'s listing, `/permissions`) as exchanges to summarise.
 *  2. Whatever compaction condensed came back as NEW agent-visible rows - a
 *     `[summary] /help → …` line is not a UI-only row any more, so the next
 *     turn put the UI text on the wire after all.
 *  3. The compactor's 85% trigger counted UI-only bytes Chat's own estimate
 *     skips, so a large notice could rewrite a conversation that fits.
 *
 * The fix runs compaction over the agent-visible rows only, on BOTH sides of the
 * exchange-key alignment: the offered exchanges and the compaction that consumes
 * their summaries see the same list, so every key still lands. UI-only rows are
 * carried around the compaction untouched.
 */
final class UiOnlyCompactionInputTest extends TestCase
{
    /** Markers that exist only in UI-only rows of {@see history()}. */
    private const UI_MARKERS = ['LAUNCH-UI', '/help', 'HELP-UI', 'QUEUED-UI', '/permissions', 'PERM-UI'];

    /**
     * Five real exchanges with UI-only rows in every position the app writes
     * them: ahead of the first exchange (a launch notice), between exchanges (a
     * command echo and its output), and inside an exchange (a queued-prompt
     * notice between a prompt and its answer).
     *
     * @return list<Message>
     */
    private static function history(): array
    {
        $detail = str_repeat('detail ', 60);

        return [
            Message::notice('LAUNCH-UI launch notice'),
            Message::user('/help')->withUiOnly(),
            Message::assistant('HELP-UI listing of every command')->withUiOnly(),
            Message::user('question 1'),
            Message::notice('QUEUED-UI Queued (1 waiting)'),
            Message::assistant("answer 1 {$detail}"),
            Message::user('question 2'),
            Message::assistant("answer 2 {$detail}"),
            Message::user('/permissions')->withUiOnly(),
            Message::assistant('PERM-UI no permission gate is attached')->withUiOnly(),
            Message::user('question 3'),
            Message::assistant("answer 3 {$detail}"),
            Message::user('question 4'),
            Message::assistant("answer 4 {$detail}"),
            Message::user('question 5'),
            Message::assistant("answer 5 {$detail}"),
        ];
    }

    private static function chat(?Backend $summaryBackend, array $history, string $draft = '/compact'): Chat
    {
        return new Chat(
            history: $history,
            inputBuf: $draft,
            backend: new EchoBackend(),
            compactorConfig: CompactorConfig::new()->withRecentPreserveCount(2),
            summaryBackend: $summaryBackend,
        );
    }

    /** A summarization backend that records what it was sent and answers $reply. */
    private static function summarizer(string $reply, ?array &$seen = null): Backend
    {
        return new class ($reply, $seen) implements Backend {
            public function __construct(private readonly string $reply, private mixed &$seen) {}

            public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
            {
                $this->seen = $history;

                return Message::assistant($this->reply);
            }

            public function completeAsync(array $history, callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                $this->seen = $history;

                return \React\Promise\resolve(Message::assistant($this->reply));
            }
        };
    }

    private function resolve(\Closure $cmd): mixed
    {
        $asyncCmd = $cmd();
        $this->assertInstanceOf(AsyncCmd::class, $asyncCmd);
        $resolved = null;
        $asyncCmd->promise->then(function ($msg) use (&$resolved): void {
            $resolved = $msg;
        });

        return $resolved;
    }

    /** @param list<Message> $messages */
    private static function joined(array $messages): string
    {
        return implode("\n", array_map(static fn(Message $m): string => $m->content, $messages));
    }

    private function assertNoUiMarker(string $haystack, string $where): void
    {
        foreach (self::UI_MARKERS as $marker) {
            $this->assertStringNotContainsString($marker, $haystack, "{$where} carries the UI-only text `{$marker}`");
        }
    }

    /**
     * Every row the compaction was handed is still in the transcript, in its
     * order (roadmap 1.B-3: compaction hides, it does not delete), and every
     * UI-only one is still flagged. The rows it condensed are flagged too now -
     * hidden from the model - so the UI-only rows are told apart by content.
     *
     * @param list<Message> $history
     */
    private function assertUiRowsSurviveInOrder(array $history): void
    {
        $handed = self::history();
        $at = 0;
        foreach ($history as $message) {
            $expected = $handed[$at] ?? null;
            if ($expected !== null && $message->role === $expected->role && $message->content === $expected->content) {
                if ($expected->uiOnly) {
                    $this->assertTrue($message->uiOnly, "the UI-only row `{$expected->content}` is still flagged");
                }
                $at++;
            }
        }
        $this->assertSame(count($handed), $at, 'every row the compaction was handed is still in the transcript, in order');

        $uiText = array_map(
            static fn(Message $m): string => $m->content,
            array_values(array_filter($handed, static fn(Message $m): bool => $m->uiOnly)),
        );
        $ui = array_values(array_filter(
            $history,
            static fn(Message $m): bool => $m->uiOnly && in_array($m->content, $uiText, true),
        ));
        $contents = array_map(static fn(Message $m): string => $m->content, $ui);

        $this->assertSame(
            [
                'LAUNCH-UI launch notice',
                '/help',
                'HELP-UI listing of every command',
                'QUEUED-UI Queued (1 waiting)',
                '/permissions',
                'PERM-UI no permission gate is attached',
            ],
            array_slice($contents, 0, 6),
            'every UI-only row the compaction was handed is still in the transcript, verbatim, flagged, in its order',
        );
    }

    public function testTheSummarizerIsOfferedOnlyAgentVisibleExchangesAndEveryKeyStillLands(): void
    {
        $seen = null;
        $reply = "1.\nasked: topic one\n2.\nasked: topic two\n3.\nasked: topic three";
        $chat = self::chat(self::summarizer($reply, $seen), self::history());

        [$scheduled, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertNotNull($cmd, 'the summarizer is asked off the render loop');
        $this->assertStringContainsString(
            'Summarising 3 earlier exchanges',
            $scheduled->history[count($scheduled->history) - 1]->content,
            'five real exchanges with two preserved leave three to summarise - the /help and /permissions '
            . 'command pairs are not exchanges, and neither is the /compact echo',
        );

        $msg = $this->resolve($cmd);
        $this->assertInstanceOf(HistoryCompactedMsg::class, $msg);
        $this->assertIsArray($seen, 'the summarizer was called');
        $this->assertNoUiMarker(self::joined($seen), 'the summarization request');
        $this->assertStringContainsString('question 1', self::joined($seen));
        $this->assertStringContainsString('question 3', self::joined($seen));

        [$landed] = $scheduled->update($msg);

        $visible = Message::agentVisible($landed->history);
        $this->assertNoUiMarker(self::joined($visible), 'the agent-visible compacted history');

        // Alignment: each of the three offered exchanges got ITS model summary.
        // A key computed over a different list than the compaction consumed would
        // miss, and the exchange would fall back to the heuristic placeholder.
        $summaries = array_values(array_filter(
            $visible,
            static fn(Message $m): bool => str_starts_with($m->content, '[summary] '),
        ));
        // The state block every compaction leads with (roadmap 2.5), then the
        // three records.
        $this->assertCount(4, $summaries);
        $this->assertStringStartsWith('[summary] Session state (compacted):', $summaries[0]->content);
        $this->assertStringContainsString('topic one', $summaries[1]->content);
        $this->assertStringContainsString('topic two', $summaries[2]->content);
        $this->assertStringContainsString('topic three', $summaries[3]->content);
        $this->assertStringNotContainsString('[exchanged information]', self::joined($visible));

        $this->assertUiRowsSurviveInOrder($landed->history);

        // The preserved tail is the original objects, UI rows around it intact.
        $original = self::history();
        $q4 = array_values(array_filter($landed->history, static fn(Message $m): bool => $m->content === 'question 4'));
        $this->assertCount(1, $q4);
        $this->assertSame($original[12]->content, $q4[0]->content);
    }

    public function testTheHeuristicCompactionPutsNoUiTextBackOnTheWire(): void
    {
        $chat = self::chat(null, self::history());

        [$compacted, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertNull($cmd, 'no summarizer: /compact answers synchronously');

        $visible = Message::agentVisible($compacted->history);
        $this->assertNotSame([], array_filter(
            $visible,
            static fn(Message $m): bool => str_starts_with($m->content, '[summary] '),
        ), 'fixture: the compaction really condensed something');
        $this->assertNoUiMarker(self::joined($visible), 'the agent-visible compacted history');
        $this->assertUiRowsSurviveInOrder($compacted->history);
    }

    /**
     * Chat's estimate skips UI-only rows (they occupy none of the window), and
     * the compactor now reads the same rows, so a huge notice no longer trips
     * the 85% rewrite of a conversation that fits.
     */
    public function testAHugeUiOnlyRowDoesNotTripTheAutomaticCompactionTier(): void
    {
        $history = [];
        for ($i = 1; $i <= 6; $i++) {
            $history[] = Message::user("q{$i}");
            $history[] = Message::assistant("a{$i}");
        }
        // ~100,000 chars/4 tokens: over the 85% tier of the 100,000-token
        // fallback window EchoBackend gets, were it counted.
        $history[] = Message::notice(str_repeat('n', 400_000));

        $chat = self::chat(null, $history, 'next');
        [$next] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertSame([], array_values(array_filter(
            $next->history,
            static fn(Message $m): bool => str_starts_with($m->content, '[summary] '),
        )), 'nothing was condensed: the conversation the model sees is six short exchanges');
        $this->assertSame('q1', $next->history[0]->content);
    }

    /**
     * The 95% refusal says "each further attempt drops the oldest of them, so
     * re-sending ... will get through after a pass or two". That used to be true
     * by accident: the refusal's own UI-only echo/refusal pair counted as an
     * exchange and pushed a real one out of the preserved ten. With compaction
     * reading agent-visible rows only, the count is explicit
     * (`Chat::blockedAttempts()`); without it every retry would be refused
     * against the same estimate forever.
     *
     * 13 equal exchanges of ~10,000 estimated tokens against an 88,000-token
     * window: ten preserved is ~100,000, nine ~90,000 (both over the 83,600
     * blocking tier), eight ~80,000.
     */
    public function testRetryingPastTheBlockingTierGetsThroughAfterAPassOrTwo(): void
    {
        $backend = new class implements Backend, ReportsContextWindow {
            public int $calls = 0;

            public function contextWindow(): int
            {
                return 88_000;
            }

            public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
            {
                $this->calls++;

                return Message::assistant('ok');
            }

            public function completeAsync(array $history, callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                $this->calls++;

                return \React\Promise\resolve(Message::assistant('ok'));
            }
        };

        $history = [];
        for ($i = 0; $i < 13; $i++) {
            $history[] = Message::user(str_repeat(chr(97 + $i), 20_000));
            $history[] = Message::assistant(str_repeat(chr(110 + $i), 20_000));
        }
        $chat = new Chat(history: $history, inputBuf: 'hello', backend: $backend);

        $estimates = [];
        $sentOn = null;
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            if ($attempt > 1) {
                foreach (mb_str_split('hello') as $char) {
                    [$chat] = $chat->update(new KeyMsg(KeyType::Char, $char));
                }
            }
            [$chat, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
            if ($cmd !== null) {
                $sentOn = $attempt;
                break;
            }
            $refusal = $chat->history[count($chat->history) - 1]->content;
            $this->assertStringStartsWith('This turn was NOT sent', $refusal);
            preg_match('/~(\d+) estimated tokens/', $refusal, $m);
            $estimates[] = (int) $m[1];
        }

        $this->assertSame(3, $sentOn, 'two refusals, then the third attempt goes out with eight exchanges preserved');
        $this->assertCount(2, $estimates);
        $this->assertLessThan(
            $estimates[0] - 9_000,
            $estimates[1],
            'the second refusal is about one exchange smaller than the first: each attempt dropped one',
        );
    }
}
