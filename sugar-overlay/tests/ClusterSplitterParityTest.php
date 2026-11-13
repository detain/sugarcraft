<?php

declare(strict_types=1);

namespace SugarCraft\Overlay\Tests;

use SugarCraft\Core\Util\Width;
use SugarCraft\Overlay\Cluster;
use SugarCraft\Overlay\MarkedFrame;
use SugarCraft\Overlay\Position;
use SugarCraft\Overlay\Veil;
use PHPUnit\Framework\TestCase;

/**
 * SO-02 (sugar-overlay-002): the paint side and the diff model must share
 * ONE cluster splitter. Before this step Veil::paintRow walked a byte
 * cascade while MarkedFrame's text arm walked core Width::nextCluster
 * (UAX#29 via grapheme_extract when ext-intl is loaded) — 👨‍👩‍👧 measured
 * 2 cells but painted 5 clusters, so background suffixes spliced at the
 * wrong column. These pins make a re-divergence impossible: whatever the
 * two halves believe, they must believe it together.
 */
final class ClusterSplitterParityTest extends TestCase
{
    /**
     * Fixture corpus: ZWJ family, skin-tone modifier, regional-indicator
     * flag, combining accent, variation selector, plain CJK + ASCII.
     *
     * @return array<string, array{0: string}>
     */
    public static function walkerFixtures(): array
    {
        return [
            'zwj-family' => ['👨‍👩‍👧'],
            'skin-tone' => ['👍🏿'],
            'flag' => ['🇺🇸'],
            'combining' => ['éx'],
            'variation-selector' => ['❤️'],
            'cjk' => ['日本語'],
            'ascii' => ['plain'],
            'mixed-row' => ["x👨‍👩‍👧y👍🏿z"],
        ];
    }

    /**
     * Cross-walk property pin: MarkedFrame::measure() must equal a walk of
     * the shared Cluster splitter summing Width::of per cluster — the very
     * arithmetic the paint side performs. Under the split brain the Marked
     * side returned the grapheme count (family = 2), the paint side the
     * byte-walk count (family = 6): this assertion was RED pre-fix on any
     * host with ext-intl.
     */
    public function testMeasureAgreesWithTheSharedSplitterWalk(): void
    {
        foreach (self::walkerFixtures() as $name => [$line]) {
            $byteWalkWidth = 0;
            $pos = 0;
            $len = \strlen($line);
            while ($pos < $len) {
                $cluster = Cluster::next($line, $pos);
                $byteWalkWidth += Width::of($cluster);
                $pos += \strlen($cluster);
            }

            $this->assertSame(
                $byteWalkWidth,
                MarkedFrame::measure($line),
                "measure() and the paint-side byte walk disagree on fixture '{$name}'",
            );
        }
    }

    /**
     * Row-width pin from the finding: a ZWJ-family + skin-tone line painted
     * over a fixed-width background fills EXACTLY that width — no suffix
     * spliced at the wrong column, no row past the canvas. Under the split
     * brain the clip arithmetic spliced 16 dots after a family the byte walk
     * lays in 6 cells: the emitted row measured 19 (grapheme view) or 26
     * (byte view), never 20 — this assertion was RED pre-fix.
     */
    public function testZwjFamilyRowPaintsExactlyTheBackgroundWidth(): void
    {
        $bg = \str_repeat('.', 20);
        $fg = "👨‍👩‍👧👍🏿.";

        $frame = Veil::new()->composite($fg, $bg, Position::TOP, Position::LEFT);

        $this->assertSame(20, MarkedFrame::measure($frame));
        // The family bytes ride the row exactly once, unsplit.
        $this->assertSame(1, \substr_count($frame, "👨‍👩‍👧"));
    }

    /**
     * The splitter lives in ONE home: neither walker side carries a second
     * private tokenizer (no `Width::nextCluster` in this lib's src, no
     * private nextCluster on Veil).
     */
    public function testSplitterHasASingleHome(): void
    {
        $srcDir = \dirname(__DIR__) . '/src';
        $hits = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcDir)) as $file) {
            if ($file->isFile() && \str_ends_with($file->getFilename(), '.php') && $file->getFilename() !== 'Cluster.php') {
                if (\preg_match('/nextCluster/', (string) \file_get_contents($file->getPathname())) === 1) {
                    $hits[] = $file->getFilename();
                }
            }
        }

        $this->assertSame([], $hits, 'Cluster::next is the only splitter call site in src/');
    }
}
