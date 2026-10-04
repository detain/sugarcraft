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
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Context\Compaction\StateSummaryTemplate;
use SugarCraft\Crush\Context\CompactorConfig;
use SugarCraft\Crush\HistoryCompactedMsg;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\ToolResult;

/**
 * Roadmap 2.5, driven through the real `/compact` model route: a reply that is
 * not smaller than what it summarises, or that the provider cut at its length
 * limit, is thrown away whole; a usable reply's state block is audited (missing
 * headings filled, derived ones taken from the transcript) and merged with the
 * previous compaction's; and the block hands the model the user's latest
 * request verbatim with the instruction to continue.
 */
final class SummaryRejectedWhenNotSmallerTest extends TestCase
{
    public function testAReplyNotSmallerThanItsSourceIsRejectedAndTheHeuristicCompacts(): void
    {
        $chat = $this->chat($this->summarizer(Message::assistant("1.\nasked: " . str_repeat('padding ', 2000))));

        $landed = $this->compactThrough($chat);

        $text = $this->contentsOf($landed->history);
        $this->assertMatchesRegularExpression(
            '/Model summarisation failed \(the summary \(\d+ chars\) was not smaller than what it summarised \(\d+ chars\)\) — compacted with the local heuristic instead\./',
            $text,
        );
        $this->assertStringNotContainsString('padding padding', $this->modelReads($landed->history), 'nothing of the rejected reply reaches the model');
        $this->assertStringContainsString('[exchanged information]', $this->modelReads($landed->history));
    }

    public function testAReplyCutShortByTheLengthLimitIsRejected(): void
    {
        $chat = $this->chat($this->summarizer(Message::assistant("1.\nasked: half a rec")->withLengthStopped(true)));

        $landed = $this->compactThrough($chat);

        $this->assertStringContainsString(
            'Model summarisation failed (the summary was cut short by the length limit) — compacted with the local heuristic instead.',
            $this->contentsOf($landed->history),
        );
        $this->assertStringNotContainsString('half a rec', $this->modelReads($landed->history));
    }

    public function testTheModelsStateBlockIsAuditedAndItsDerivedSectionsComeFromTheTranscript(): void
    {
        $reply = "1.\nasked: set up\n2.\nasked: wire it\n"
            . StateSummaryTemplate::OPEN_TAG . "\n"
            . "## Goal\nport the parser\n"
            . "## Next step\nwrite the lexer tests\n"
            . "## Files modified\n- invented/by/model.php\n"
            . StateSummaryTemplate::CLOSE_TAG;
        $chat = $this->chat($this->summarizer(Message::assistant($reply)), withTools: true);

        $landed = $this->compactThrough($chat);

        $state = $this->stateRow($landed->history);
        $this->assertSame('port the parser', $state->section('Goal'));
        $this->assertSame('write the lexer tests', $state->section('Next step'));
        $this->assertSame('- src/Lexer.php', $state->section('Files modified'), 'the model never writes the file list');
        $this->assertSame('- src/Grammar.php', $state->section('Files read'));
        $this->assertSame('question 6', $state->section('Latest unresolved user request'), 'the latest request, verbatim');
        $this->assertNotSame('', $state->section('Progress'), 'a heading the model left out is filled from the heuristic');
        $this->assertStringContainsString('asked: wire it', $this->modelReads($landed->history), 'the records still parse once the block is cut out');
        $this->assertStringEndsWith(StateSummaryTemplate::CONTINUE_LINE, $this->stateRowContent($landed->history));
    }

    public function testASecondCompactionMergesThePreviousStateBlockRatherThanStackingIt(): void
    {
        $first = $this->compactThrough($this->chat($this->summarizer(Message::assistant(
            "1.\nasked: a\n" . StateSummaryTemplate::OPEN_TAG . "\n## Goal\nthe first goal\n## Key decisions\nuse sqlite\n" . StateSummaryTemplate::CLOSE_TAG,
        ))));

        // More conversation, then a second /compact whose model block omits the
        // decision the first one recorded.
        $history = $first->history;
        for ($i = 7; $i <= 12; $i++) {
            $history[] = Message::user("question {$i}");
            $history[] = Message::assistant("answer {$i} " . str_repeat('detail ', 60));
        }
        $seen = null;
        $second = new Chat(
            history: $history,
            inputBuf: '/compact',
            backend: new EchoBackend(),
            compactorConfig: CompactorConfig::new()->withRecentPreserveCount(2),
            summaryBackend: $this->summarizer(Message::assistant(
                "1.\nasked: b\n" . StateSummaryTemplate::OPEN_TAG . "\n## Goal\nthe second goal\n" . StateSummaryTemplate::CLOSE_TAG,
            ), $seen),
        );

        $landed = $this->compactThrough($second);

        $this->assertStringContainsString('the first goal', implode("\n", array_map(static fn (Message $m): string => $m->content, $seen)), 'the previous block was carried to the summariser to merge');
        $states = array_values(array_filter(
            Message::agentVisible($landed->history),
            static fn (Message $m): bool => StateSummaryTemplate::isStateRow($m->content),
        ));
        $this->assertCount(1, $states, 'one state block for the model, never a stack of them');
        $state = StateSummaryTemplate::fromRow($states[0]->content);
        $this->assertSame('the second goal', $state?->section('Goal'));
        $this->assertSame('use sqlite', $state?->section('Key decisions'), 'what the new block left empty keeps the previous text');
    }

    // =====================================================================
    // helpers
    // =====================================================================

    private function chat(Backend $summarizer, bool $withTools = false): Chat
    {
        $history = [];
        for ($i = 1; $i <= 6; $i++) {
            $history[] = Message::user("question {$i}");
            $answer = Message::assistant("answer {$i} " . str_repeat('detail ', 60));
            if ($withTools && $i === 1) {
                $answer = $answer->withToolResults([new ToolResult('Read', 'grammar', arguments: ['file_path' => 'src/Grammar.php'])]);
            }
            if ($withTools && $i === 2) {
                $answer = $answer->withToolResults([new ToolResult('Edit', 'ok', arguments: ['file_path' => 'src/Lexer.php'])]);
            }
            $history[] = $answer;
        }

        return new Chat(
            history: $history,
            inputBuf: '/compact',
            backend: new EchoBackend(),
            compactorConfig: CompactorConfig::new()->withRecentPreserveCount(2),
            summaryBackend: $summarizer,
        );
    }

    /** @param ?list<Message> $seen */
    private function summarizer(Message $reply, ?array &$seen = null): Backend
    {
        return new class ($reply, $seen) implements Backend {
            public function __construct(private readonly Message $reply, private mixed &$seen) {}

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                $this->seen = $history;

                return $this->reply;
            }

            public function completeAsync(
                array $history,
                ?callable $onToken = null,
                ?CancellationToken $cancellation = null,
                ?callable $onEvent = null,
            ): PromiseInterface {
                $this->seen = $history;

                return \React\Promise\resolve($this->reply);
            }
        };
    }

    private function compactThrough(Chat $chat): Chat
    {
        [$pending, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertNotNull($cmd, 'the model route schedules a Cmd');
        $async = $cmd();
        $this->assertInstanceOf(AsyncCmd::class, $async);
        $msg = null;
        $async->promise->then(static function ($landed) use (&$msg): void {
            $msg = $landed;
        });
        $this->assertInstanceOf(HistoryCompactedMsg::class, $msg);

        [$landed] = $pending->update($msg);

        return $landed;
    }

    /** @param list<Message> $history */
    private function stateRowContent(array $history): string
    {
        foreach (Message::agentVisible($history) as $message) {
            if (StateSummaryTemplate::isStateRow($message->content)) {
                return $message->content;
            }
        }
        $this->fail('the compaction wrote no state block');
    }

    /** @param list<Message> $history */
    private function stateRow(array $history): StateSummaryTemplate
    {
        $state = StateSummaryTemplate::fromRow($this->stateRowContent($history));
        $this->assertNotNull($state);

        return $state;
    }

    /** @param list<Message> $history */
    private function contentsOf(array $history): string
    {
        return implode("\n", array_map(static fn (Message $m): string => $m->content, $history));
    }

    /** @param list<Message> $history */
    private function modelReads(array $history): string
    {
        return $this->contentsOf(Message::agentVisible($history));
    }
}
