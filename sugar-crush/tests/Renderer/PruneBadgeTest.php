<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Mosaic\ImageLayer;
use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\Context\Pruning\PruneAuthor;
use SugarCraft\Crush\Context\Pruning\PruneEntry;
use SugarCraft\Crush\Context\Pruning\PruneKind;
use SugarCraft\Crush\Context\Pruning\PruneReason;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Theme;
use SugarCraft\Crush\ToolResult;

/**
 * Roadmap 3.B-3: a tool row whose output the model is no longer sent wears a
 * badge in its status — `⊟ pruned` for a placeholder, `⊟ distilled` for the
 * model's own stand-in — and stays inside the pane like every other row. A
 * row whose call only lost its INPUT, and every row without a ledger, wear
 * none.
 */
final class PruneBadgeTest extends TestCase
{
    public function testEachKindOfPruneWearsItsBadge(): void
    {
        $ledger = ContextLedger::new()
            ->withPrune(new PruneEntry('p', PruneKind::Output, PruneReason::Done, PruneAuthor::Model, 10))
            ->withPrune(new PruneEntry('d', PruneKind::Distilled, PruneReason::Done, PruneAuthor::Model, 10, 'short'))
            ->withPrune(new PruneEntry('i', PruneKind::WriteContent, PruneReason::Superseded, PruneAuthor::Strategy, 10));

        $this->assertStringEndsWith('✓ ok ⊟ pruned', self::head('p', $ledger, 80));
        $this->assertStringEndsWith('✓ ok ⊟ distilled', self::head('d', $ledger, 80));
        $this->assertStringEndsWith('✓ ok', self::head('i', $ledger, 80), 'an elided input leaves the output sent');
        $this->assertStringEndsWith('✓ ok', self::head('x', $ledger, 80), 'a row the ledger leaves alone');
        $this->assertStringEndsWith('✓ ok', self::head('p', null, 80), 'no ledger, no badge');
    }

    public function testABadgedRowStillFitsThePane(): void
    {
        $ledger = ContextLedger::new()->withPrune(new PruneEntry('p', PruneKind::Distilled, PruneReason::Done, PruneAuthor::Model, 10, 'short'));

        foreach ([30, 40, 80] as $width) {
            $this->assertLessThanOrEqual($width, Width::of(self::head('p', $ledger, $width)), "width {$width}");
        }
    }

    /** The first row of one tool result, its styling stripped. */
    private static function head(string $id, ?ContextLedger $ledger, int $width): string
    {
        $render = new \ReflectionMethod(Renderer::class, 'renderToolResults');
        $block = (string) $render->invoke(
            null,
            Message::assistant('')->withToolResults([new ToolResult(name: 'Read', result: 'done', id: $id)]),
            Theme::default(),
            $width,
            [],
            new ImageLayer(),
            null,
            0,
            null,
            $ledger,
        );

        return rtrim((string) preg_replace('/\e\[[0-9;]*m/', '', explode("\n", $block)[0]));
    }
}
