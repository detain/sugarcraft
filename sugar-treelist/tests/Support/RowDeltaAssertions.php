<?php

declare(strict_types=1);

namespace SugarCraft\TreeList\Tests\Support;

use PHPUnit\Framework\Assert;
use SugarCraft\TreeList\Model;

/**
 * Structural pins for the row-granular delta emitted by Model::view().
 *
 * Shared by ModelTest/IntegrationTest/RowDeltaTest (2026-11-03 CRIT fix
 * wave). The old suite asserted "delta is shorter than full", which is not
 * the correctness property — a two-row list whose every row changed is a
 * legitimately larger delta, and that heuristic pinned nothing about WHERE
 * bytes land. What IS load-bearing: replaying a delta over the previous
 * frame reconstructs, byte for byte, what a fresh instance renders as its
 * full first frame.
 */
trait RowDeltaAssertions
{
    /**
     * Assert that $delta, applied over $frame, yields exactly the full frame
     * a freshly built (memo-less) $rendered model paints. Pass a model that
     * has never been viewed: view() on a memo-carrying lineage returns the
     * next delta, not the full frame.
     */
    protected function assertDeltaRebuildsFullFrame(string $frame, string $delta, Model $rendered): void
    {
        Assert::assertSame($rendered->view(), $this->applyRowDelta($frame, $delta),
            'delta applied over the previous frame must equal the fresh full frame');
    }

    /** Assert the delta rewrites exactly $writes rows (erase-marked writes). */
    protected function assertDeltaWritesRows(string $delta, int $writes): void
    {
        Assert::assertSame($writes, \substr_count($delta, "\x1b[K"),
            'delta must carry exactly one erase-terminated write per changed row');
        // Styled rows carry their SGR sequences INSIDE the write, so a bare
        // "\x1b[" count would over-count; CUP is the only sequence ending in H
        // (sgrCodes() restricts styles to digits/semicolons + m).
        Assert::assertSame($writes, \preg_match_all('/\x1b\[\d+;1H/', $delta),
            'every write must open with exactly one absolute CUP');
    }

    /**
     * Replay a row delta over a frame's text: each write is
     * CUP <row>;1H content EL newline and replaces that screen row.
     * Fails loudly if the delta carries bytes outside row writes.
     */
    private function applyRowDelta(string $frame, string $delta): string
    {
        $rows = \explode("\n", \rtrim($frame, "\n"));
        $matched = \preg_match_all('/\x1b\[(\d+);1H(.*?)\x1b\[K\n/s', $delta, $writes, PREG_SET_ORDER);
        Assert::assertGreaterThan(0, $matched, 'delta carried no row writes');
        foreach ($writes as $write) {
            $row = (int) $write[1] - 1;
            Assert::assertLessThan(count($rows), $row, 'delta wrote outside the frame');
            $rows[$row] = $write[2];
        }
        $stripped = \preg_replace('/\x1b\[(\d+);1H(.*?)\x1b\[K\n/s', '', $delta);
        Assert::assertSame('', $stripped, 'delta carried bytes outside row writes');
        return \implode("\n", $rows) . "\n";
    }
}
