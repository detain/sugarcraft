<?php

declare(strict_types=1);

namespace SugarCraft\QuickPick\Tests;

use SugarCraft\QuickPick\Lang;
use PHPUnit\Framework\TestCase;

/**
 * Pins for the chrome translation surface (S-15/-013).
 *
 * The geometry/composite suites already prove the rendered frame carries
 * these exact bytes; this file pins the catalogue itself: every key the
 * chrome seats ask for exists, interpolates numerically, and keeps the
 * two-space indent that aligns it inside the frame.
 */
final class LangTest extends TestCase
{
    public function testShowingRangeRendersTheHistoricBytes(): void
    {
        // The pre-i18n seat was sprintf('  ↕ showing %d-%d of %d', …); the
        // catalogue swap must be byte-invisible (goldens depend on it).
        $this->assertSame(
            '  ↕ showing 10-12 of 12',
            Lang::t('showing_range', ['from' => 10, 'to' => 12, 'total' => 12])
        );
    }

    public function testEmptyStatesKeepIndentAndWording(): void
    {
        $this->assertSame('  no matches', Lang::t('no_matches'));
        $this->assertSame('  no items', Lang::t('no_items'));
    }

    public function testAnchorHintInterpolatesTheCount(): void
    {
        $this->assertSame(
            '  7 hidden by anchor bias',
            Lang::t('anchor_hidden', ['count' => 7])
        );
    }

    public function testFilterCaretPairIsCatalogued(): void
    {
        // Single-cell caret; caret+ellipsis overflow pair. Both are measured
        // with Width::of() by the header clip, so a locale swap must keep
        // the cell maths in mind — the EN bytes themselves are pinned here.
        $this->assertSame('▏', Lang::t('filter_caret'));
        $this->assertSame('▏⋯', Lang::t('filter_overflow'));
    }

    public function testUnknownKeyFallsBackToTheRawKey(): void
    {
        // House lookup law (exact → base → en → raw): a missing key must
        // surface visibly, never as an empty chrome row. The raw answer is
        // the fully-qualified key.
        $this->assertSame('quickpick.no_such_chrome_key', Lang::t('no_such_chrome_key'));
    }
}
