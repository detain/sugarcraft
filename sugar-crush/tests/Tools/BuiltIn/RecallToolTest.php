<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\Pruning\CompressionBlock;
use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\Context\Pruning\LedgerDelta;
use SugarCraft\Crush\Context\Pruning\PruneAuthor;
use SugarCraft\Crush\Context\Pruning\PruneEntry;
use SugarCraft\Crush\Context\Pruning\PruneKind;
use SugarCraft\Crush\Context\Pruning\PruneReason;
use SugarCraft\Crush\Context\Pruning\PruningMode;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\Message as TypedMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Tools\BuiltIn\Recall;
use SugarCraft\Crush\Tools\Catalog\ToolCatalog;
use SugarCraft\Crush\Tools\Catalog\ToolPermissionClass;
use SugarCraft\Crush\Tools\MutatesContextLedger;
use SugarCraft\Crush\Tools\ParallelSafe;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * Roadmap 3.B-5 (DCP §13.2 P2-12): `Recall` returns, word for word, what
 * pruning took out of the model's view — a pruned output, an elided input, a
 * row or a whole compressed section — from the turn's raw rows, refuses what
 * is still in view, never changes the ledger, and is capped per turn and per
 * call.
 */
final class RecallToolTest extends TestCase
{
    private ContextLedger $ledger;

    private int $applied = 0;

    protected function setUp(): void
    {
        $this->ledger = ContextLedger::new()->withDefaultMode(PruningMode::Auto);
        $this->applied = 0;
    }

    public function testItIsACatalogBuiltNoAskLedgerToolThatNeverRunsConcurrently(): void
    {
        $this->assertSame(ToolPermissionClass::NoAsk, ToolCatalog::permissionOf('Recall'));
        $this->assertContains('Recall', array_map(static fn ($e): string => $e->name, ToolCatalog::built()));
        $this->assertInstanceOf(MutatesContextLedger::class, Recall::new());
        $this->assertNotInstanceOf(ParallelSafe::class, Recall::new());
        $this->assertSame(['ref'], Recall::new()->inputSchema()['required']);
        $gated = Recall::new()->withCompactionGate(static fn (): string => 'refused');
        $this->assertInstanceOf(Recall::class, $gated, 'a recall is no compaction: the gate is not bound');
    }

    public function testAnUnboundToolRefusesEveryCall(): void
    {
        $result = Recall::new()->execute(['ref' => 'r2']);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('not available in this run', $result->content());
    }

    public function testItReturnsAPrunedOutputWordForWordAndLeavesTheLedgerAlone(): void
    {
        $this->ledger = $this->ledger->withPrune(new PruneEntry('c1', PruneKind::Output, PruneReason::Done, PruneAuthor::Model, 100));
        $before = $this->ledger;

        $result = $this->bound()->execute(['ref' => 'r2']);

        $this->assertFalse($result->isError(), $result->content());
        $this->assertSame("[r2 Read a.php — recalled: the original output, as the tool returned it]\n" . self::toolOutput('a'), $result->content());
        $this->assertSame($before, $this->ledger);
        $this->assertSame(0, $this->applied, 'nothing is applied: the row stays pruned');
    }

    public function testItReturnsAnElidedInput(): void
    {
        $this->ledger = $this->ledger->withPrune(new PruneEntry('c2', PruneKind::WriteContent, PruneReason::Superseded, PruneAuthor::Strategy, 100));

        $result = $this->bound()->execute(['ref' => 'r3']);

        $this->assertFalse($result->isError(), $result->content());
        $this->assertStringStartsWith("[r3 Write b.php — recalled: the call's original input", $result->content());
        $this->assertStringContainsString('"content": "<?php class B {}"', $result->content());
    }

    public function testARowStillInViewIsRefused(): void
    {
        $result = $this->bound()->execute(['ref' => 'r2']);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('r2 is not pruned or compressed', $result->content());

        $prompt = $this->bound()->execute(['ref' => 'r1']);
        $this->assertTrue($prompt->isError());
        $this->assertStringContainsString('r1 is a prompt that is already in your context in full', $prompt->content());
    }

    public function testUnknownAndMalformedRefsAreRefused(): void
    {
        $this->assertStringContainsString('r40 names no row of this conversation', $this->bound()->execute(['ref' => 'r40'])->content());
        $this->assertStringContainsString('"banana" is not a ref', $this->bound()->execute(['ref' => 'banana'])->content());
        $this->assertStringContainsString('b7 is not a compressed section', $this->bound()->execute(['ref' => 'b7'])->content());
        $this->assertStringContainsString('ref must be a ref', $this->bound()->execute(['ref' => ['r2']])->content());
    }

    public function testARowInsideACompressedSectionIsRecalledAndSoIsTheWholeSection(): void
    {
        $refs = $this->ledger->refsFor(self::rows());
        $this->ledger = $this->ledger->withRefsAssigned(self::rows())->withRangeBlock(CompressionBlock::range(
            $this->ledger->nextBlockId,
            'Reading the two files',
            ContextLedger::userRowKey('look around', 1),
            ContextLedger::stepKey('c1'),
            $refs[ContextLedger::userRowKey('look around', 1)],
            $refs['c2'],
            'read a.php and wrote b.php',
            900,
            10,
        ));

        $prompt = $this->bound()->execute(['ref' => 'r1']);
        $this->assertFalse($prompt->isError(), $prompt->content());
        $this->assertSame("[r1 — recalled: a prompt from compressed section b1, word for word]\nlook around", $prompt->content());

        $row = $this->bound()->execute(['ref' => 'r2']);
        $this->assertStringStartsWith('[r2 Read a.php — recalled: the original output, as the tool returned it, from compressed section b1]', $row->content());

        $section = $this->bound()->execute(['ref' => 'b1']);
        $this->assertFalse($section->isError(), $section->content());
        $text = $section->content();
        $this->assertStringStartsWith('[b1 "Reading the two files" — recalled: the 4 rows it replaced, word for word; files touched: code ×2]', $text);
        $this->assertStringContainsString("### r1 · user\nlook around", $text);
        $this->assertStringContainsString("### assistant\nlet me look\n→ Read a.php\n→ Write b.php", $text);
        $this->assertStringContainsString("### r2 · Read result\n" . self::toolOutput('a'), $text);
        $this->assertStringContainsString("### r3 · Write result\nwrote b.php", $text);
        $this->assertStringNotContainsString('keep going', $text, 'only the section\'s own rows');
        $this->assertSame(0, $this->applied);
    }

    public function testASectionTakenBackIsRefused(): void
    {
        $refs = $this->ledger->refsFor(self::rows());
        $this->ledger = $this->ledger->withRangeBlock(CompressionBlock::range(1, 't', ContextLedger::userRowKey('look around', 1), ContextLedger::stepKey('c1'), $refs[ContextLedger::userRowKey('look around', 1)], $refs['c2'], 's', 900, 10))
            ->withBlockDecompressed(1);

        $result = $this->bound()->execute(['ref' => 'b1']);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('b1 is not compressed now', $result->content());
    }

    public function testATurnMayRecallAtMostFiveTimesAndRefusalsCostNothing(): void
    {
        $this->ledger = $this->ledger->withPrune(new PruneEntry('c1', PruneKind::Output, PruneReason::Done, PruneAuthor::Model, 100));
        $tool = $this->bound();

        $this->assertTrue($tool->execute(['ref' => 'r40'])->isError(), 'a refused call…');
        for ($i = 0; $i < Recall::MAX_CALLS_PER_TURN; $i++) {
            $this->assertFalse($tool->execute(['ref' => 'r2'])->isError(), 'call ' . ($i + 1));
        }
        $sixth = $tool->execute(['ref' => 'r2']);
        $this->assertTrue($sixth->isError());
        $this->assertStringContainsString('at most 5 times per turn', $sixth->content());

        $this->assertFalse($this->bound()->execute(['ref' => 'r2'])->isError(), 'a new turn binds a new allowance');
    }

    public function testALargeRecallIsCutAndSaysSo(): void
    {
        $big = str_repeat('é', Recall::MAX_BYTES);
        $rows = [
            new UserMessage('go'),
            new AssistantMessage('', [new ToolCall('c1', 'Bash', ['command' => 'cat big'])]),
            new ToolResultMessage('c1', $big),
            new UserMessage('next'),
        ];
        $this->ledger = $this->ledger->withPrune(new PruneEntry('c1', PruneKind::Output, PruneReason::Done, PruneAuthor::Model, 100));

        $result = Recall::new()->withLedger(fn (): array => [$this->ledger, $rows], static fn (LedgerDelta $d): ?ContextLedger => null)->execute(['ref' => 'r2']);

        $this->assertFalse($result->isError());
        $this->assertStringContainsString('[… cut at 40000 bytes of ', $result->content());
        $this->assertTrue(mb_check_encoding($result->content(), 'UTF-8'), 'cut on a character boundary');
        $this->assertLessThan(Recall::MAX_BYTES + 200, \strlen($result->content()));
    }

    // ── harness ─────────────────────────────────────────────────────────

    private function bound(): Recall
    {
        $tool = Recall::new()->withLedger(
            fn (): array => [$this->ledger, self::rows()],
            function (LedgerDelta $delta): ContextLedger {
                $this->applied++;

                return $this->ledger = $this->ledger->apply($delta);
            },
        );
        $this->assertInstanceOf(Recall::class, $tool);

        return $tool;
    }

    private static function toolOutput(string $seed): string
    {
        return str_repeat($seed . ' line of output ', 60);
    }

    /**
     * A prompt (r1), one step reading a.php (r2) and writing b.php (r3), and
     * the next prompt.
     *
     * @return list<TypedMessage>
     */
    private static function rows(): array
    {
        return [
            new UserMessage('look around'),
            new AssistantMessage('let me look', [
                new ToolCall('c1', 'Read', ['file_path' => 'a.php']),
                new ToolCall('c2', 'Write', ['file_path' => 'b.php', 'content' => '<?php class B {}']),
            ]),
            new ToolResultMessage('c1', self::toolOutput('a')),
            new ToolResultMessage('c2', 'wrote b.php'),
            new UserMessage('keep going'),
            new AssistantMessage('ok'),
        ];
    }
}
