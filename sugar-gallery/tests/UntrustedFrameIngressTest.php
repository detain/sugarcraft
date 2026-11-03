<?php

declare(strict_types=1);

namespace SugarCraft\Gallery\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\ImageOverlay;
use SugarCraft\Core\Util\Width;
use SugarCraft\Gallery\AnsiGuard;
use SugarCraft\Gallery\PosterCard;
use SugarCraft\Gallery\PosterGrid;
use SugarCraft\Mouse\Mark;
use SugarCraft\Mouse\Sentinel;
use SugarCraft\Mouse\Zones\Manager as ZoneManager;
use SugarCraft\Style\Layout;

/**
 * The untrusted-ingress family (lib review C1/M7/M8 + M6):
 *
 *  - C1: an overlay id outside ImageOverlay's paintable range must degrade the
 *    cell to a re-queued skeleton instead of throwing from every frame;
 *  - M7: `withPoster('')` stores verbatim but reads as UNFILLED;
 *  - M8/m19: forged candy-mouse zone sentinels (U+E000/U+E001) are swept at the
 *    guard, at the coercing builders, and at the grid's marking choke point —
 *    before box-fitting, so the width law still holds;
 *  - M6: the focus marker survives into an unloaded (skeleton) cell.
 *
 * Security-class pins are hex/byte-level on purpose: the sentinel codepoints
 * ARE the attack surface.
 */
final class UntrustedFrameIngressTest extends TestCase
{
    // ---- C1: the paintable-id gate ----------------------------------------

    /**
     * @return array<string, array{0:int}>
     */
    public static function unpaintableIds(): array
    {
        return [
            'negative' => [-1],
            'at-cap' => [ImageOverlay::MAX_IMAGES],
            'far-over' => [9000],
        ];
    }

    /**
     * @param array<string, array{0:int}> $provider
     */
    public function testAnUnpaintableOverlayIdDegradesToSkeletonNeverThrows(): void
    {
        foreach (self::unpaintableIds() as $case => [$poison]) {
            $card = PosterCard::new('1', 'Orphan')->withImage('BYTES', $poison);

            self::assertFalse($card->hasPoster(), $case . ': an unaddressable id paints no marker, so the cell is not filled');

            $frame = $card->render(false, 10, 2);
            self::assertStringContainsString(str_repeat('░', 10), $frame, $case . ': the body degrades to the skeleton');
            self::assertStringNotContainsString('BYTES', $frame);
        }
    }

    public function testTheRangeEdgesStayPaintable(): void
    {
        foreach ([0, ImageOverlay::MAX_IMAGES - 1] as $id) {
            $card = PosterCard::new('1', 'Edge')->withImage('BYTES', $id);

            self::assertTrue($card->hasPoster(), 'id ' . $id . ' is inside the blessed range');
            self::assertStringContainsString(ImageOverlay::marker($id), $card->render(false, 10, 2));
        }
    }

    public function testAnUnpaintableOverlayIsReQueuedByTheFillPolicy(): void
    {
        $grid = PosterGrid::new(cardWidth: 10, posterHeight: 2, hSpacing: 1, vSpacing: 0)
            ->withViewport(21, 18)
            ->withTotal(1)
            ->withItem(0, PosterCard::new('0', 'Poisoned', 'https://cdn/0.png')->withImage('BYTES', -1));

        self::assertSame([0], $grid->indicesNeedingPoster(), 'the poison must read as unfilled so the owner re-fetches it');
    }

    public function testAPoisonedOverlayFallsBackToAnInlinePosterWhenOneIsCarried(): void
    {
        // The overlay only wins when it can actually paint; with an unaddressable
        // id the inline art on the same card must show instead of a skeleton.
        $card = PosterCard::new('1', 'Both')
            ->withPoster('AAAA')
            ->withImage('BYTES', 9000);

        $frame = $card->render(false, 10, 2);

        self::assertTrue($card->hasPoster());
        self::assertStringContainsString('AAAA', $frame);
    }

    // ---- M7: an empty poster is an unfilled poster ------------------------

    public function testAnEmptyPosterIsStoredVerbatimButReadsUnfilled(): void
    {
        $card = PosterCard::new('1', 'CacheMiss', 'https://cdn/1.png')->withPoster('');

        self::assertSame('', $card->poster, 'withPoster() stays VERBATIM — only the fill predicates change');
        self::assertFalse($card->hasPoster(), 'a cache that stored nothing must not freeze the cell as loaded');

        $frame = $card->render(false, 10, 2);
        self::assertStringContainsString(str_repeat('░', 10), $frame, 'and the body renders as the skeleton');

        $grid = PosterGrid::new(cardWidth: 10, posterHeight: 2, hSpacing: 1, vSpacing: 0)
            ->withViewport(21, 18)
            ->withTotal(1)
            ->withItem(0, $card);
        self::assertSame([0], $grid->indicesNeedingPoster(), 'the empty poster re-queues for a real fetch');

        self::assertTrue($card->withPoster('X')->hasPoster(), 'one real byte is filled again');
    }

    // ---- M8/m19: forged zone sentinels ------------------------------------

    public function testStripSentinelsIsSurgicalAndDelegatesTheArena(): void
    {
        self::assertSame('ab', AnsiGuard::stripSentinels("a\u{E000}b\u{E001}"));

        // The U+E002+ image-marker half of the private area survives untouched —
        // this is the same carve-out sugar-core's Sanitize keeps by design.
        self::assertSame("\u{E002}\u{F8FF}x", AnsiGuard::stripSentinels("\u{E002}\u{F8FF}x"));
    }

    public function testPlainTitlesLoseForgedSentinelsByteForByte(): void
    {
        $forged = "\u{E000}cell:4242\u{E001}";

        $plain = AnsiGuard::stripControls($forged);

        self::assertStringNotContainsString(Sentinel::OPEN, $plain);
        self::assertStringNotContainsString(Sentinel::CLOSE, $plain);
        self::assertSame('cell:4242', $plain, 'the wrapper dies, the printable text stays');

        $styled = AnsiGuard::sanitize("\e[31m" . $forged . "\e[0m");
        self::assertStringNotContainsString(Sentinel::OPEN, $styled);
        self::assertStringNotContainsString(Sentinel::CLOSE, $styled);
    }

    public function testTheCoercingBuildersSweepSentinelsIntoStorage(): void
    {
        $wire = Mark::new()->wrap('cell:999', 'FORGED');
        self::assertStringContainsString(Sentinel::OPEN, $wire, 'the forge produces genuine markup bytes');

        $card = PosterCard::new('1', 'Hostile');

        $withPoster = $card->withSafePoster("L1\n" . $wire);
        self::assertStringNotContainsString(Sentinel::OPEN, $withPoster->poster ?? '');
        self::assertStringNotContainsString(Sentinel::CLOSE, $withPoster->poster ?? '');

        $withTitle = $card->withSafeStyledTitle("\e[1m" . $wire . "\e[0m");
        self::assertStringNotContainsString(Sentinel::OPEN, $withTitle->styledTitle ?? 'must-not-be-set: text-only after sweep');
    }

    public function testVerbatimPosterForgeryCannotMintAPhantomZone(): void
    {
        // VERBATIM withPoster() keeps hostile bytes in the MODEL (caller-owned by
        // contract) — the lib's own guarantee is that the MARKING choke point
        // never passes forged markup to the zone manager, so a hostile card can
        // neither register a fresh-id phantom zone nor spoof another cell's id
        // into a duplicate on the app's click path. The wires are sized to fit
        // the card whole: a truncated forgery would pin nothing (the box clip
        // itself would swallow it), so the forgery must survive to the marker.
        $fresh = Mark::new()->wrap('cell:999', 'FORGED');
        $dup = Width::padRight(Mark::new()->wrap('cell:0', 'D'), 27);

        $poisoned = PosterCard::new('0', 'Hostile', 'https://cdn/0.png')
            ->withPoster($fresh . "\n" . $dup);
        $innocent = PosterCard::new('1', 'Fine')
            ->withPoster(str_repeat('B', 27) . "\n" . str_repeat('B', 27));

        self::assertSame(27, Width::string($fresh), 'the forgery fits its row — the pin cannot pass vacuously');

        $grid = PosterGrid::new(cardWidth: 27, posterHeight: 2, hSpacing: 1, vSpacing: 0)
            ->withViewport(55, 18)
            ->withTotal(2)
            ->withItems([0 => $poisoned, 1 => $innocent]);

        $zones = ZoneManager::newGlobal();
        $frame = $grid->render(true, $zones);
        $stripped = $zones->scan($frame);

        self::assertNull($zones->get('cell:999'), 'a forged fresh id must never reach the zone registry');
        // The sweep is SURGICAL (U+E000/U+E001 only, the fleet law that keeps
        // U+E002+ image markers alive): the forgery's id/content degrade to
        // inert plain text — harmless because markup without framing is nothing
        // the scanner can see. Width normalisation then holds the geometry.
        self::assertStringNotContainsString(Sentinel::OPEN, $stripped, 'the cleaned frame carries no markup at all');
        self::assertStringNotContainsString(Sentinel::CLOSE, $stripped);

        self::assertNotNull($zones->get('cell:0'), 'legit cells stay hit-testable');
        self::assertNotNull($zones->get('cell:1'));

        // The sweep runs BEFORE box-fitting, so every emitted line is still
        // exactly the geometry the grid reserved (strip-after-box would skew it).
        foreach (explode("\n", $grid->render(true)) as $line) {
            self::assertSame(27 + 1 + 27, Layout::width($line), 'forgery removal keeps the width law');
        }
    }

    public function testAForgedPlainTitleCannotMintAPhantomZoneEither(): void
    {
        $wire = Mark::new()->wrap('cell:777', 'F');

        $card = PosterCard::new('0', "X\u{E000}" . 'cell:777' . "\u{E001}Y");
        $zones = ZoneManager::newGlobal();
        $grid = PosterGrid::new(cardWidth: 10, posterHeight: 2, hSpacing: 1, vSpacing: 0)
            ->withViewport(21, 18)
            ->withTotal(1)
            ->withItem(0, $card);

        $stripped = $zones->scan($grid->render(true, $zones));

        self::assertNull($zones->get('cell:777'));
        self::assertStringNotContainsString(Sentinel::OPEN, $stripped);
    }

    // ---- M6: focus survives into an unloaded cell --------------------------

    public function testTheCursorMarkerIsDrawnOnSkeletonCellsToo(): void
    {
        $grid = PosterGrid::new(cardWidth: 10, posterHeight: 2, hSpacing: 1, vSpacing: 0)
            ->withViewport(21, 18)
            ->withTotal(2);

        $focused = $grid->moveTo(1)->render(true);
        self::assertStringContainsString('▸' . str_repeat('░', 9), $focused, 'the cursor lands on the skeleton, not only on loaded art');

        foreach (explode("\n", $focused) as $line) {
            self::assertSame(10 + 1 + 10, Layout::width($line), 'the marker replaces a skeleton cell — width law holds');
        }

        self::assertStringNotContainsString('▸', $grid->moveTo(1)->render(false), 'an unfocused grid still shows no cursor');
    }
}
