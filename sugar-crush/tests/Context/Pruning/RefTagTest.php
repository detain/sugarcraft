<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context\Pruning;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\Pruning\CompressionBlock;
use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\Context\Pruning\ContextProjector;
use SugarCraft\Crush\Context\Pruning\PrunedOutputPlaceholder;
use SugarCraft\Crush\Context\Pruning\PruneAuthor;
use SugarCraft\Crush\Context\Pruning\PruneEntry;
use SugarCraft\Crush\Context\Pruning\PruneKind;
use SugarCraft\Crush\Context\Pruning\PruneReason;
use SugarCraft\Crush\Context\Pruning\RefTag;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * Roadmap 3.B-2: every tool result a request sends ends with its ref tag,
 * `<ctx-ref r="N"/>`, so it can be named — a pure function of the ledger's
 * ref for it, byte-identical on every request (DCP #614), numbered before a
 * block drops any row, and stripped from the model's own text when echoed.
 */
final class RefTagTest extends TestCase
{
    public function testTheTagAndLabelAreFixedBytes(): void
    {
        $this->assertSame('<ctx-ref r="17"/>', RefTag::render(17));
        $this->assertSame('r17', RefTag::label(17));
        $this->assertSame("out\n<ctx-ref r=\"3\"/>", RefTag::appendTo('out', 3));
        $this->assertSame('<ctx-ref r="3"/>', RefTag::appendTo('', 3));
        $this->assertSame(RefTag::appendTo('out', 3), RefTag::appendTo(RefTag::appendTo('out', 3), 3), 'appending twice is appending once');
    }

    public function testParseReadsWhatAPersonOrTheModelWrites(): void
    {
        foreach (['r17', 'R17', '17', ' r17 ', '<ctx-ref r="17"/>', '<ctx-ref r="17" />', 'r017'] as $written) {
            $this->assertSame(17, RefTag::parse($written), $written);
        }
        foreach (['', 'r', 'r0', '0', '-3', 'b3', 'r1.5', '17 18', '99999999999999999999999'] as $written) {
            $this->assertNull(RefTag::parse($written), $written);
        }
    }

    public function testStripRemovesEveryTagWithItsNewline(): void
    {
        $this->assertSame('see the file', RefTag::stripFrom("see the file\n<ctx-ref r=\"4\"/>"));
        $this->assertSame('a b', RefTag::stripFrom('a <ctx-ref r="4"/>b'));
        $this->assertSame('no tags here', RefTag::stripFrom('no tags here'));
    }

    public function testATaggedProjectionTagsEveryResultPrunedOrNotAndIsByteStable(): void
    {
        $messages = [new UserMessage('go'), ...self::step('a'), ...self::step('b')];
        $ledger = ContextLedger::new()->withPrune(new PruneEntry('a', PruneKind::Output, PruneReason::Aged, PruneAuthor::Strategy, 10));
        $projector = ContextProjector::new()->withRefTags();

        $first = $projector->project($messages, $ledger)->messages;
        $results = self::results($first);

        $this->assertSame(RefTag::appendTo(PrunedOutputPlaceholder::for('Read', ['file_path' => 'a.php']), 1), $results['a'], 'a placeholder keeps its ref');
        $this->assertSame(RefTag::appendTo('contents of b', 2), $results['b']);
        $this->assertSame(serialize($first), serialize($projector->project($messages, $ledger)->messages), 'two projections are the same bytes');
        $this->assertSame('go', $first[0]->content(), 'a user row carries no tag');

        $grown = [...$messages, ...self::step('c')];
        $this->assertSame(
            serialize(\array_slice($first, 0, 5)),
            serialize(\array_slice($projector->project($grown, $ledger)->messages, 0, 5)),
            'rows added later never change the bytes before them',
        );
    }

    public function testAFixedRefIsWhatTheTagShowsAndUntaggedProjectionsAreUnchanged(): void
    {
        $messages = [new UserMessage('go'), ...self::step('a')];
        $ledger = ContextLedger::new()->withRefsAssigned([new ToolResultMessage('earlier', 'x'), new ToolResultMessage('a', 'y')]);

        $this->assertSame(RefTag::appendTo('contents of a', 2), self::results(ContextProjector::new()->withRefTags()->project($messages, $ledger)->messages)['a']);
        $this->assertSame($messages, ContextProjector::new()->project($messages, ContextLedger::new())->messages, 'no ledger entries and no tags: the rows themselves');
        $this->assertSame('contents of a', self::results(ContextProjector::new()->project($messages, $ledger)->messages)['a'], 'tags only when asked for');
    }

    public function testRefsAreNumberedBeforeABlockDropsRows(): void
    {
        $messages = [new UserMessage('go'), ...self::step('a'), ...self::step('b')];
        $ledger = ContextLedger::new()->withBlock(new CompressionBlock(1, 'b', 'what a found', 100, 10, PruneAuthor::Harness));

        $projected = ContextProjector::new()->withRefTags()->project($messages, $ledger)->messages;

        $this->assertTrue(CompressionBlock::isSummaryRow($projected[0]));
        $this->assertSame(RefTag::appendTo('contents of b', 2), self::results($projected)['b'], 'b keeps the ref it had before a was summarised away');
    }

    public function testAnEchoedTagIsStrippedFromTheModelsTextOnly(): void
    {
        $messages = [
            new UserMessage('go'),
            new AssistantMessage("I read it <ctx-ref r=\"1\"/>", [new ToolCall('a', 'Read', ['file_path' => 'a.php'])], 'thinking'),
            new ToolResultMessage('a', 'contents of a'),
        ];

        $projected = ContextProjector::new()->withRefTags()->project($messages, ContextLedger::new())->messages;

        $this->assertInstanceOf(AssistantMessage::class, $projected[1]);
        $this->assertSame('I read it ', $projected[1]->content());
        $this->assertSame($messages[1]->toolCalls(), $projected[1]->toolCalls(), 'the calls and reasoning stay');
        $this->assertSame('thinking', $projected[1]->reasoning());
        $this->assertSame(RefTag::appendTo('contents of a', 1), $projected[2]->content(), 'the harness\'s own tag stays');
    }

    /** @return list<Message> */
    private static function step(string $id): array
    {
        return [
            new AssistantMessage('', [new \SugarCraft\Crush\Tools\ToolCall($id, 'Read', ['file_path' => "{$id}.php"])]),
            new ToolResultMessage($id, "contents of {$id}"),
        ];
    }

    /**
     * @param list<Message> $messages
     * @return array<string, string>
     */
    private static function results(array $messages): array
    {
        $out = [];
        foreach ($messages as $message) {
            if ($message instanceof ToolResultMessage) {
                $out[$message->toolCallId()] = $message->content();
            }
        }

        return $out;
    }
}
