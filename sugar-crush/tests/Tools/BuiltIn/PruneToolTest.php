<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\Context\Pruning\ContextProjector;
use SugarCraft\Crush\Context\Pruning\LedgerDelta;
use SugarCraft\Crush\Context\Pruning\PrunedOutputPlaceholder;
use SugarCraft\Crush\Context\Pruning\PruneAuthor;
use SugarCraft\Crush\Context\Pruning\PruneEntry;
use SugarCraft\Crush\Context\Pruning\PruneKind;
use SugarCraft\Crush\Context\Pruning\PruneReason;
use SugarCraft\Crush\Context\Pruning\PruningMode;
use SugarCraft\Crush\Context\Pruning\RefTag;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\Message as TypedMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Tools\BuiltIn\Prune;
use SugarCraft\Crush\Tools\Catalog\ToolCatalog;
use SugarCraft\Crush\Tools\Catalog\ToolPermissionClass;
use SugarCraft\Crush\Tools\MutatesContextLedger;
use SugarCraft\Crush\Tools\ParallelSafe;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * Roadmap 3.B-3: the model's `Prune` resolves each target by the ref the
 * model read, writes one {@see PruneEntry} by the model per output it may
 * prune, skips — and names — every target it may not, and fails only when
 * nothing applied.
 */
final class PruneToolTest extends TestCase
{
    private ContextLedger $ledger;

    /** @var list<LedgerDelta> */
    private array $applied = [];

    protected function setUp(): void
    {
        $this->ledger = ContextLedger::new()->withDefaultMode(PruningMode::Auto);
        $this->applied = [];
    }

    public function testItIsACatalogBuiltNoAskToolThatMutatesTheLedgerAndNeverRunsConcurrently(): void
    {
        $this->assertSame(ToolPermissionClass::NoAsk, ToolCatalog::permissionOf('Prune'));
        $this->assertContains('Prune', array_map(static fn ($e): string => $e->name, ToolCatalog::built()));
        $this->assertInstanceOf(MutatesContextLedger::class, Prune::new());
        $this->assertNotInstanceOf(ParallelSafe::class, Prune::new(), 'the ledger lives in the turn\'s own process');

        $schema = Prune::new()->inputSchema();
        $this->assertSame(['targets', 'reason'], $schema['required']);
        $this->assertSame(['noise', 'superseded', 'done'], $schema['properties']['reason']['enum']);
        $this->assertSame(['ref'], $schema['properties']['targets']['items']['required']);
    }

    public function testAnUnboundToolRefusesEveryCall(): void
    {
        $result = Prune::new()->execute(['targets' => [['ref' => 'r1']], 'reason' => 'done']);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('not available in this run', $result->content());
    }

    public function testItPrunesAndDistillsByRefAndTheProjectionShowsBoth(): void
    {
        $result = $this->bound()->execute([
            'reason' => 'done',
            'targets' => [
                ['ref' => 'r1'],
                ['ref' => '<ctx-ref r="2"/>', 'distillation' => 'b.php: class B { f(): int }'],
            ],
        ]);

        $this->assertFalse($result->isError(), $result->content());
        $this->assertMatchesRegularExpression('/^Pruned 2 outputs \(~[\d.]+K? tokens\): Read ×2\. Distilled: r2\.$/u', $result->content());

        $this->assertCount(1, $this->applied, 'one delta per call');
        $first = $this->ledger->prune('c1');
        $this->assertNotNull($first);
        $this->assertSame(PruneKind::Output, $first->kind);
        $this->assertSame(PruneAuthor::Model, $first->by);
        $this->assertSame(PruneReason::Done, $first->reason);
        $this->assertGreaterThan(0, $first->tokens);
        $second = $this->ledger->prune('c2');
        $this->assertSame(PruneKind::Distilled, $second?->kind);
        $this->assertSame('b.php: class B { f(): int }', $second->distillation);

        $sent = [];
        foreach (ContextProjector::new()->withRefTags()->project(self::rows(), $this->ledger)->messages as $message) {
            if ($message instanceof ToolResultMessage) {
                $sent[$message->toolCallId()] = $message->content();
            }
        }
        $this->assertSame(RefTag::appendTo(PrunedOutputPlaceholder::for('Read', ['file_path' => 'a.php']), 1), $sent['c1']);
        $this->assertSame(
            RefTag::appendTo(PrunedOutputPlaceholder::distilled('Read', ['file_path' => 'b.php'], 'b.php: class B { f(): int }'), 2),
            $sent['c2'],
        );
        $this->assertSame(RefTag::appendTo(self::toolOutput('t'), 3), $sent['c3'], 'a protected output stays as it was');
    }

    public function testEveryTargetItMayNotPruneIsSkippedByName(): void
    {
        $this->ledger = $this->ledger->withPrune(new PruneEntry('c1', PruneKind::Output, PruneReason::Aged, PruneAuthor::Strategy, 10));

        $result = $this->bound()->execute([
            'reason' => 'noise',
            'targets' => [
                ['ref' => 'r1'],
                ['ref' => 'r2', 'distillation' => str_repeat('x', 5000)],
                ['ref' => 'r3'],
                ['ref' => 'r9'],
                ['ref' => 'banana'],
                ['ref' => 'r4'],
                ['ref' => 'r4'],
            ],
        ]);

        $this->assertFalse($result->isError(), 'r4 still applied');
        $this->assertStringStartsWith('Pruned 1 output (~', $result->content());
        foreach ([
            'r1 (already pruned)',
            'r2 (the distillation is no shorter than the output)',
            'r3 (protected: Task)',
            'r9 (no tool result you have read has this ref)',
            '"banana" (not a ref)',
            'r4 (named twice)',
        ] as $reason) {
            $this->assertStringContainsString($reason, $result->content());
        }
        $this->assertSame(['c1', 'c4'], array_keys($this->ledger->prunes));
    }

    public function testACallWhereNothingAppliedFailsAndAppliesNothing(): void
    {
        $result = $this->bound()->execute(['reason' => 'done', 'targets' => [['ref' => 'r3'], ['ref' => 'r5']]]);

        $this->assertTrue($result->isError());
        $this->assertStringStartsWith('Error: nothing was pruned. Skipped: r3 (protected: Task); r5 (too small to be worth a placeholder).', $result->content());
        $this->assertSame([], $this->applied);
    }

    public function testMalformedArgumentsAreRefused(): void
    {
        $this->assertStringContainsString('reason must be one of noise, superseded, done', $this->bound()->execute(['targets' => [['ref' => 'r1']], 'reason' => 'aged'])->content());
        $this->assertStringContainsString('targets must be a non-empty list', $this->bound()->execute(['targets' => [], 'reason' => 'done'])->content());
        $this->assertStringContainsString('targets must be a non-empty list', $this->bound()->execute(['targets' => ['a' => ['ref' => 'r1']], 'reason' => 'done'])->content());
        $this->assertSame([], $this->applied);
    }

    public function testASessionThatDoesNotLetTheModelPruneRefuses(): void
    {
        $this->ledger = $this->ledger->withMode(PruningMode::Manual);

        $result = $this->bound()->execute(['reason' => 'done', 'targets' => [['ref' => 'r1']]]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('context pruning is `manual` for this session', $result->content());
        $this->assertSame([], $this->applied);
    }

    public function testALedgerOutOfReachIsAFailureNotASilentNoOp(): void
    {
        $tool = Prune::new()->withLedger(fn (): array => [$this->ledger, self::rows()], static fn (LedgerDelta $delta): ?ContextLedger => null);

        $result = $tool->execute(['reason' => 'done', 'targets' => [['ref' => 'r1']]]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('could not be reached', $result->content());
    }

    // ── harness ─────────────────────────────────────────────────────────

    private function bound(): Prune
    {
        $tool = Prune::new()->withLedger(
            fn (): array => [$this->ledger, self::rows()],
            function (LedgerDelta $delta): ContextLedger {
                $this->applied[] = $delta;

                return $this->ledger = $this->ledger->apply($delta);
            },
        );
        $this->assertInstanceOf(Prune::class, $tool);

        return $tool;
    }

    private static function toolOutput(string $seed): string
    {
        return str_repeat($seed . ' line of output ', 60);
    }

    /**
     * Four results the model has read: two Reads, a Task (protected), and a
     * Grep too small for a placeholder to save anything, and a fourth Read.
     *
     * @return list<TypedMessage>
     */
    private static function rows(): array
    {
        return [
            new UserMessage('look around'),
            new AssistantMessage('', [
                new ToolCall('c1', 'Read', ['file_path' => 'a.php']),
                new ToolCall('c2', 'Read', ['file_path' => 'b.php']),
                new ToolCall('c3', 'Task', ['description' => 'explore']),
                new ToolCall('c4', 'Read', ['file_path' => 'c.php']),
                new ToolCall('c5', 'Grep', ['pattern' => 'x']),
            ]),
            new ToolResultMessage('c1', self::toolOutput('a')),
            new ToolResultMessage('c2', self::toolOutput('b')),
            new ToolResultMessage('c3', self::toolOutput('t')),
            new ToolResultMessage('c4', self::toolOutput('c')),
            new ToolResultMessage('c5', 'x'),
        ];
    }
}
