<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context\Pruning;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\Context\Pruning\ContextProjector;
use SugarCraft\Crush\Context\Pruning\PrunedInputPlaceholder;
use SugarCraft\Crush\Context\Pruning\PrunedOutputPlaceholder;
use SugarCraft\Crush\Context\Pruning\PruneAuthor;
use SugarCraft\Crush\Context\Pruning\PruneEntry;
use SugarCraft\Crush\Context\Pruning\PruneKind;
use SugarCraft\Crush\Context\Pruning\PruneReason;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * Roadmap 3.B-3: a distilled prune — the model's own text in place of an
 * output — is an OUTPUT prune that keeps its text through the ledger's
 * persisted form and projects as a one-line header naming the call, then the
 * distillation; the entries every earlier step wrote keep their stored shape.
 */
final class DistilledPruneTest extends TestCase
{
    public function testADistilledEntryRoundTripsAndOtherKindsKeepTheirShape(): void
    {
        $distilled = new PruneEntry('c1', PruneKind::Distilled, PruneReason::Done, PruneAuthor::Model, 120, "sig: f(int): int\nerr: none");
        $this->assertEquals($distilled, PruneEntry::fromArray($distilled->toArray()));
        $this->assertFalse(PruneKind::Distilled->rewritesInput());
        $this->assertSame(['x' => 1], PrunedInputPlaceholder::apply(PruneKind::Distilled, ['x' => 1]));

        $plain = new PruneEntry('c2', PruneKind::Output, PruneReason::Aged, PruneAuthor::Strategy, 9);
        $this->assertSame(['toolCallId', 'kind', 'reason', 'by', 'tokens'], array_keys($plain->toArray()), 'no distillation key on any other kind');
        $this->assertNull(PruneEntry::fromArray([...$plain->toArray(), 'distillation' => 'ignored'])?->distillation);

        $ledger = ContextLedger::fromArray(ContextLedger::new()->withPrune($distilled)->toArray());
        $this->assertSame("sig: f(int): int\nerr: none", $ledger->prune('c1')?->distillation);
    }

    public function testTheProjectionShowsTheDistillationUnderTheCallsName(): void
    {
        $rows = [
            new AssistantMessage('', [new ToolCall('c1', 'Read', ['file_path' => 'src/A.php'])]),
            new ToolResultMessage('c1', str_repeat('source ', 300)),
        ];
        $ledger = ContextLedger::new()->withPrune(new PruneEntry('c1', PruneKind::Distilled, PruneReason::Done, PruneAuthor::Model, 100, 'class A { f(): int }'));

        $sent = ContextProjector::new()->project($rows, $ledger)->messages[1];

        $this->assertInstanceOf(ToolResultMessage::class, $sent);
        $this->assertSame(
            "[Read src/A.php — distilled by the model; re-run the tool for the full output]\nclass A { f(): int }",
            $sent->content(),
        );
        $this->assertSame(PrunedOutputPlaceholder::distilled('Read', ['file_path' => 'src/A.php'], 'class A { f(): int }'), $sent->content());
    }
}
