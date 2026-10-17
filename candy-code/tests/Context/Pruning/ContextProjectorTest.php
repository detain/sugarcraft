<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Context\Pruning;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Context\Pruning\ContextLedger;
use SugarCraft\Code\Context\Pruning\ContextProjector;
use SugarCraft\Code\Context\Pruning\PruneAuthor;
use SugarCraft\Code\Context\Pruning\PruneEntry;
use SugarCraft\Code\Context\Pruning\PruneKind;
use SugarCraft\Code\Context\Pruning\PruneReason;
use SugarCraft\Code\Context\Pruning\PrunedOutputPlaceholder;
use SugarCraft\Code\Context\TurnContextBlock;
use SugarCraft\Code\Messages\AssistantMessage;
use SugarCraft\Code\Messages\ToolResultMessage;
use SugarCraft\Code\Messages\UserMessage;
use SugarCraft\Code\Tools\ToolCall;

/**
 * Roadmap 2.2-1: a request is the rows projected through the ledger — the
 * rows themselves never change, and the same input always projects to the
 * same bytes (the prompt-cache contract).
 */
final class ContextProjectorTest extends TestCase
{
    public function testAnEmptyLedgerProjectsTheRowsUnchanged(): void
    {
        $rows = self::conversation();

        $this->assertSame($rows, ContextProjector::new()->project($rows, ContextLedger::new())->messages);
    }

    public function testAPrunedResultBecomesAPlaceholderNamingItsCall(): void
    {
        $rows = self::conversation();
        $ledger = ContextLedger::new()->withPrune(self::entry('c1'))->withPrune(self::entry('c2'));

        $projected = ContextProjector::new()->project($rows, $ledger)->messages;

        $this->assertCount(count($rows), $projected, 'no row is removed');
        $this->assertSame(
            '[Read src/Tools/Bash.php — output pruned to save context; re-run the tool if you need it]',
            $projected[2]->content(),
        );
        $this->assertInstanceOf(ToolResultMessage::class, $projected[2]);
        $this->assertSame('c1', $projected[2]->toolCallId(), 'the result still answers its call');
        $this->assertSame('[Bash ls -la src — output pruned to save context; re-run the tool if you need it]', $projected[4]->content());
        $this->assertTrue($projected[4]->isError(), 'an error stays an error');
        $this->assertSame($rows[6], $projected[6], 'an unpruned result is the same object');
        $this->assertStringStartsWith('<<<the whole file>>>', $rows[2]->content(), 'the rows are never rewritten');
    }

    public function testTwoProjectionsOfTheSameInputAreByteIdentical(): void
    {
        $rows = self::conversation();
        $ledger = ContextLedger::new()->withPrune(self::entry('c1'))
            ->withDroppedContextRow(ContextLedger::contextRowKey($rows[7]->content()), 10);

        $wire = static fn (): string => (string) json_encode(array_map(
            static fn ($message): array => $message->toArray(),
            ContextProjector::new()->project($rows, $ledger)->messages,
        ));

        $this->assertSame($wire(), $wire());
    }

    public function testASupersededTurnContextRowIsLeftOutButNeverTheLast(): void
    {
        $rows = self::conversation();
        $older = $rows[7]->content();
        $newest = $rows[9]->content();
        $ledger = ContextLedger::new()
            ->withDroppedContextRow(ContextLedger::contextRowKey($older), 10)
            ->withDroppedContextRow(ContextLedger::contextRowKey($newest), 10);

        $projected = ContextProjector::new()->project($rows, $ledger)->messages;
        $contents = array_map(static fn ($message): string => $message->content(), $projected);

        $this->assertNotContains($older, $contents);
        $this->assertContains($newest, $contents, 'the newest state is the one the model must read');
        $this->assertCount(count($rows) - 1, $projected);
    }

    public function testAResultWhoseCallIsGoneIsNamedGenerically(): void
    {
        $rows = [new UserMessage('go'), new ToolResultMessage('lost', 'output')];

        $projected = ContextProjector::new()->project($rows, ContextLedger::new()->withPrune(self::entry('lost')))->messages;

        $this->assertSame('[tool — output pruned to save context; re-run the tool if you need it]', $projected[1]->content());
    }

    public function testThePlaceholderKeepsTheMainArgumentOnOneBoundedLine(): void
    {
        $this->assertSame(
            '[Grep TODO — output pruned to save context; re-run the tool if you need it]',
            PrunedOutputPlaceholder::for('Grep', ['glob' => '*.php', 'pattern' => 'TODO']),
        );
        $this->assertSame('a b', PrunedOutputPlaceholder::mainArgument(['command' => "a\n  b"]));
        $this->assertSame('first', PrunedOutputPlaceholder::mainArgument(['limit' => 3, 'other' => 'first']));
        $this->assertNull(PrunedOutputPlaceholder::mainArgument(['limit' => 3]));
        $long = PrunedOutputPlaceholder::mainArgument(['file_path' => str_repeat('x', 500)]);
        $this->assertSame(PrunedOutputPlaceholder::MAX_ARGUMENT_CHARS, mb_strlen((string) $long));
        $this->assertStringEndsWith('…', (string) $long);
    }

    /** @return list<\SugarCraft\Code\Messages\Message> */
    private static function conversation(): array
    {
        return [
            new UserMessage('look around'),
            new AssistantMessage('', [new ToolCall('c1', 'Read', ['file_path' => 'src/Tools/Bash.php'])]),
            new ToolResultMessage('c1', '<<<the whole file>>>' . str_repeat('x', 400)),
            new AssistantMessage('', [new ToolCall('c2', 'Bash', ['command' => 'ls -la src'])]),
            new ToolResultMessage('c2', 'permission denied', isError: true),
            new AssistantMessage('', [new ToolCall('c3', 'Read', ['file_path' => 'README.md'])]),
            new ToolResultMessage('c3', '# readme'),
            new UserMessage(TurnContextBlock::FENCE . "\nstate one\n</turn-context>"),
            new AssistantMessage('noted'),
            new UserMessage(TurnContextBlock::FENCE . "\nstate two\n</turn-context>"),
        ];
    }

    private static function entry(string $id): PruneEntry
    {
        return new PruneEntry($id, PruneKind::Output, PruneReason::Aged, PruneAuthor::Strategy, 100);
    }
}
