<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\LaunchContext;
use SugarCraft\Constraints\Dock\DockLayout;
use SugarCraft\Constraints\Dock\Side;
use SugarCraft\Constraints\Region;

/**
 * candy-code-once-007: a layout saved at one size must restore sanely at
 * another — shares renormalized, undockable ids dropped, narrow terminals
 * degraded at render by resolve() rather than by rewriting the saved panes.
 */
final class PersistedLayoutRestoreTest extends TestCase
{
    private function manifest(array $left, array $right, array $lShare, array $rShare): DockLayout
    {
        $m = DockLayout::new('chat')->toArray();
        $m['sides']['left'] = array_map(static fn(string $id) => ['id' => $id, 'weight' => [1, 1]], $left);
        $m['sides']['right'] = array_map(static fn(string $id) => ['id' => $id, 'weight' => [1, 1]], $right);
        $m['columnShare'] = ['left' => $lShare, 'right' => $rShare];

        return DockLayout::fromArray($m);
    }

    public function testOversizedSharesRenormalizeToHalf(): void
    {
        $dock = LaunchContext::renormalizeDock(
            $this->manifest(['sessions'], ['agents'], [60, 78], [60, 78]),
        );
        $l = $dock->columnShare(Side::Left);
        $r = $dock->columnShare(Side::Right);
        // l + r == 1/2 exactly
        $this->assertSame($dock->columnShare(Side::Left)['denom'], $r['denom']);
        $this->assertSame(1, 2 * ($l['num'] + $r['num']) / $l['denom']);
    }

    public function testSaneSharesAreUntouched(): void
    {
        $in = $this->manifest(['sessions'], ['agents'], [15, 78], [15, 78]);
        $out = LaunchContext::renormalizeDock($in);
        $this->assertSame($in->toArray(), $out->toArray());
    }

    public function testUndockableAndCenterIdsAreDropped(): void
    {
        $out = LaunchContext::renormalizeDock(
            $this->manifest(['not-a-pane'], ['sessions'], [1, 4], [1, 4]),
        );
        $this->assertSame([], $out->slots(Side::Left));
        $this->assertCount(1, $out->slots(Side::Right));
    }

    public function testRestoredAtNarrowWidthStillRendersTiledWithoutGhost(): void
    {
        $out = LaunchContext::renormalizeDock(
            $this->manifest(['sessions'], ['agents'], [15, 78], [15, 78]),
        );
        $geo = $out->resolve(new Region(0, 0, 40, 24));
        $total = 0;
        foreach (['chat'] as $id) {
            $total += $geo->regionFor($id)?->width ?? 0;
        }
        $this->assertGreaterThanOrEqual($out->centerMinCols, $total);
        // The saved panes remain persisted: nothing was rewritten by width.
        $this->assertCount(1, $out->slots(Side::Left));
        $this->assertCount(1, $out->slots(Side::Right));
    }
}
