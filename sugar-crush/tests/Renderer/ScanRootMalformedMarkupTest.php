<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Renderer;
use SugarCraft\Mouse\Mark;

/**
 * Pins the candy-mouse malformed-markup matrix as {@see Renderer::scanRoot()}
 * experiences it, which is what the Renderer's zone comments
 * (`markPaneHeader()`, `fitStatusBar()`, the status-bar fit loop,
 * `DIVIDER_ZONE_PREFIX`) describe.
 *
 * Those comments used to say an unmatched sentinel makes `Scan::parse()`
 * throw and costs the WHOLE frame its zones. It never did: an orphan close or
 * an unclosed open is skipped and registers no zone (the viewport clip is
 * exactly how one arises), and only a duplicate id throws — which scanRoot()
 * answers by clearing the registry. The single-line-zone invariant still
 * matters, for a narrower reason: a zone clipped in half silently stops being
 * clickable.
 */
final class ScanRootMalformedMarkupTest extends TestCase
{
    private const VARS = ['SUGARCRUSH_DISABLE_MOUSE', 'SUGARCRUSH_DISABLE_MOUSE_CLICKS'];

    protected function setUp(): void
    {
        foreach (self::VARS as $var) {
            putenv($var);
        }
        Renderer::scanner()->clear();
    }

    protected function tearDown(): void
    {
        Renderer::scanner()->clear();
    }

    public function testAnOrphanCloseCostsOnlyItsOwnZoneNotTheFrame(): void
    {
        // A multi-line zone whose first line (and so its open tag) was clipped
        // off the top: only its last line - carrying the close tag - survives.
        $clipped = explode("\n", Mark::zone('pane:clipped', "head\nbody\ntail"));
        $frame = end($clipped) . "\n" . Mark::zone('tab:kept', 'tab');

        $painted = Renderer::scanRoot($frame, 80);

        self::assertNull(Renderer::scanner()->get('pane:clipped'), 'the half-clipped zone registers nothing');
        $kept = Renderer::scanner()->get('tab:kept');
        self::assertNotNull($kept, 'an orphan close does not take the rest of the frame down');
        self::assertSame([1, 2, 3, 2], [$kept->startCol, $kept->startRow, $kept->endCol, $kept->endRow]);
        self::assertSame("tail\ntab", $painted, 'the orphan sentinel is stripped from what reaches the terminal');
    }

    public function testAnUnclosedOpenCostsOnlyItsOwnZoneNotTheFrame(): void
    {
        // Same zone clipped off the BOTTOM: its open tag survives, its close does not.
        $clipped = explode("\n", Mark::zone('pane:clipped', "head\nbody\ntail"));
        $frame = Mark::zone('tab:kept', 'tab') . "\n" . $clipped[0];

        $painted = Renderer::scanRoot($frame, 80);

        self::assertNull(Renderer::scanner()->get('pane:clipped'));
        self::assertNotNull(Renderer::scanner()->get('tab:kept'));
        self::assertSame("tab\nhead", $painted);
    }

    public function testADuplicateIdIsTheOneCaseThatClearsTheWholeRegistry(): void
    {
        $frame = Mark::zone('tab:kept', 'tab') . "\n" . Mark::zone('dup', 'a') . Mark::zone('dup', 'b');

        $painted = Renderer::scanRoot($frame, 80);

        self::assertSame([], Renderer::scanner()->all(), 'the throw is degraded to "no zones this frame", never a crash');
        self::assertSame("tab\nab", $painted);
    }
}
