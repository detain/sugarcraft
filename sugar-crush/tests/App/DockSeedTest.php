<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Tui\Pane;
use SugarCraft\Crush\Tui\Renderer as TuiRenderer;
use SugarCraft\Layout\Dock\DockLayout;
use SugarCraft\Layout\Dock\Side;

/**
 * The seed hand-off: from the legacy quarter-measure to real, resizable shares.
 *
 * While the dock is the untouched default, the frame draws its side at
 * `max(20, floor(bandCols / 4))` (pinned by {@see DockDefaultIdentityTest}).
 * The first user dock mutation must not snap that frame to the library's
 * design thirds, nor freeze it at a quarter forever — so
 * {@see App::seedSharesFromFrame()} snapshots the drawn width as a RATIONAL
 * into the column shares exactly once, at the mutation boundary, and
 * {@see \SugarCraft\Crush\Tui\Renderer::sideWidth()} switches to
 * {@see DockLayout::resolve()} from then on. Resizes then scale the seeded
 * proportion instead of jumping.
 *
 * The seeding rules this file pins, one per behavior:
 *  - fires only while the dock is byte-identical to the default (one-time),
 *  - never fires without a measured width (no WindowSizeMsg yet => nothing
 *    was ever "drawn" to preserve),
 *  - seeds each side that will carry slots after the mutation — the already
 *    occupied ones and the `$docksInto` target — and only those,
 *  - writes through the manifest parse, so the pair-clamp of
 *    withColumnShare cannot squash a 1/4 snapshot against the 1/3 sibling,
 *  - is wired into setPaneSide() and BOTH arms of togglePaneDocking(), but
 *    NOT layoutReset(), which returns to the default BY DEFINITION.
 */
final class DockSeedTest extends TestCase
{
    private ProviderInterface $provider;

    protected function setUp(): void
    {
        parent::setUp();
        TuiRenderer::setSize(200, 60); // deterministic default (round-61 flake; see tests/bootstrap.php)
        $this->provider = $this->createMock(ProviderInterface::class);
        $this->provider->method('name')->willReturn('TestProvider');
    }

    protected function tearDown(): void
    {
        TuiRenderer::setSize(200, 60);
        parent::tearDown();
    }

    // ----- the pure helper ---------------------------------------------------

    public function testSeedIsANoOpIdentityWithoutAMeasuredWidth(): void
    {
        // No WindowSizeMsg has arrived => there is no drawn frame to preserve.
        $dock = App::defaultDock();

        self::assertSame($dock, App::seedSharesFromFrame($dock, 0));
        self::assertSame($dock, App::seedSharesFromFrame($dock, -5, Side::Left));
    }

    public function testSeedIsANoOpIdentityOnceTheDockIsNotTheDefault(): void
    {
        $seeded = App::seedSharesFromFrame(App::defaultDock(), 100);
        // Non-default => the guard returns the SAME instance, not a copy: the
        // hand-off fired once and future mutations never re-snapshot the frame.
        self::assertSame($seeded, App::seedSharesFromFrame($seeded, 200));
    }

    public function testSeedSnapshotsTheLegacyMeasureIntoEverySideThatWillCarrySlots(): void
    {
        // docksInto=Right: Files (non-empty left) and the Right target both
        // take the drawn quarter; a side that stays empty does not.
        $leftOnly = App::seedSharesFromFrame(App::defaultDock(), 100, Side::Left);
        self::assertSame([25, 100], $leftOnly->toArray()['columnShare']['left']);
        self::assertSame([1, 3], $leftOnly->toArray()['columnShare']['right'], 'an untouched, still-empty side keeps the design share');

        $intoRight = App::seedSharesFromFrame(App::defaultDock(), 100, Side::Right);
        self::assertSame([25, 100], $intoRight->toArray()['columnShare']['left']);
        self::assertSame([25, 100], $intoRight->toArray()['columnShare']['right']);
    }

    public function testSeedRespectsTheLegacyTwentyColumnFloor(): void
    {
        // bandCols 60: floor(60/4) = 15 < 20, so the frame drew 20 — the seed
        // snapshot is the DRAWN width, formula included, not the raw quarter.
        $seeded = App::seedSharesFromFrame(App::defaultDock(), 60);

        self::assertSame([20, 60], $seeded->toArray()['columnShare']['left']);
    }

    public function testSeedSurvivesTheManifestRoundTripWithoutThePairClamp(): void
    {
        // The squash this write path exists to dodge: withColumnShare(1/4 vs
        // the 1/3 sibling) clamps the pair and lands 1/6. The manifest parse
        // carries the measured rational exactly.
        $seeded = App::seedSharesFromFrame(App::defaultDock(), 100, Side::Left);
        $manifest = $seeded->toArray();

        self::assertSame([25, 100], $manifest['columnShare']['left']);
        self::assertSame($manifest, DockLayout::fromArray($manifest)->toArray());
        self::assertFalse(App::isUntouchedDefaultDock($seeded));
    }

    // ----- the entry points --------------------------------------------------

    public function testSetPaneSideSeedsTheFrameItJustDrew(): void
    {
        // The user focused Tools at a 100-col frame (quarter = 25), then docks
        // it: the seed must plant 25/100 so the frame does not jump.
        [$measured] = App::new($this->provider, 'test-model')->update(new WindowSizeMsg(100, 40));
        \assert($measured instanceof App);

        $after = $measured->setPaneSide(Pane::Tools, Side::Left);

        self::assertSame([25, 100], $after->dock()->toArray()['columnShare']['left']);
        self::assertContainsPaneId('tools', $after);
    }

    public function testTheSeedFiresOnceSoLaterResizesScaleInsteadOfReSnapshotting(): void
    {
        [$at100] = App::new($this->provider, 'test-model')->update(new WindowSizeMsg(100, 40));
        \assert($at100 instanceof App);
        $seededOnce = $at100->setPaneSide(Pane::Tools, Side::Left);

        // Grow the terminal, then dock again: the left share stays the 25/100
        // the eye last saw (it now renders 25/100 * 200 ≈ 49, not a re-frozen
        // 50/200), and the still-empty right side keeps its design thirds.
        [$at200] = $seededOnce->update(new WindowSizeMsg(200, 40));
        \assert($at200 instanceof App);
        $dockedRight = $at200->setPaneSide(Pane::Skills, Side::Right);

        self::assertSame([25, 100], $dockedRight->dock()->toArray()['columnShare']['left']);
        self::assertSame([1, 3], $dockedRight->dock()->toArray()['columnShare']['right']);
    }

    public function testToggleDockingArmSeedsBeforeAddingTheSlot(): void
    {
        [$measured] = App::new($this->provider, 'test-model')->update(new WindowSizeMsg(100, 40));
        \assert($measured instanceof App);

        $docked = $measured->withPane(Pane::Tools)->togglePaneDocking(Pane::Tools);

        self::assertSame([25, 100], $docked->dock()->toArray()['columnShare']['left']);
        self::assertTrue($docked->isDocked(Pane::Tools));
    }

    public function testToggleUndockingArmSeedsTheSideItIsStripping(): void
    {
        [$measured] = App::new($this->provider, 'test-model')->update(new WindowSizeMsg(100, 40));
        \assert($measured instanceof App);

        $undocked = $measured->withPane(Pane::Files)->togglePaneDocking(Pane::Files);

        // Files was the only left slot; the frame it leaves behind still drew
        // 25 columns, so the share is planted even as the side empties, and
        // focus falls back to Chat per the landed undock arm.
        self::assertSame([25, 100], $undocked->dock()->toArray()['columnShare']['left']);
        self::assertFalse($undocked->isDocked(Pane::Files));
        self::assertSame(Pane::Chat, $undocked->pane);
    }

    public function testLayoutResetReturnsToTheDefaultWithoutSeeding(): void
    {
        [$measured] = App::new($this->provider, 'test-model')->update(new WindowSizeMsg(100, 40));
        \assert($measured instanceof App);

        $reset = $measured->setPaneSide(Pane::Tools, Side::Left)->layoutReset();

        self::assertTrue(App::isUntouchedDefaultDock($reset->dock()));
        self::assertSame(App::defaultDock()->toArray(), $reset->dock()->toArray());
    }

    // ----- sideWidth regimes -------------------------------------------------

    public function testAMutatedDockTakesItsWidthFromResolveNotTheQuarter(): void
    {
        [$at100] = App::new($this->provider, 'test-model')->update(new WindowSizeMsg(100, 40));
        \assert($at100 instanceof App);
        $seeded = $at100->setPaneSide(Pane::Tools, Side::Left);

        // 25/100 of the usable band: 24 at 100 cols, 49 at 200 (the divider
        // column is carved off before the share applies) — while an untouched
        // default at the same 120 cols still answers the legacy 30.
        self::assertSame(24, self::sideWidth($seeded, Pane::Files, 100));
        self::assertSame(49, self::sideWidth($seeded, Pane::Files, 200));

        [$at120] = App::new($this->provider, 'test-model')->update(new WindowSizeMsg(120, 40));
        \assert($at120 instanceof App);
        self::assertSame(30, self::sideWidth($at120, Pane::Files, 120));
    }

    public function testASideResolveNeverPlacedFallsBackToTheLegacyMeasure(): void
    {
        // Hand-mutated (never seeded) dock: Skills is only TRANSIENTLY focused,
        // no region exists for it, so something has to width the box — and the
        // honest answer is the number the frame always used.
        $dock = App::defaultDock()->withSlotAdded(Side::Left, 'tools');
        $app = App::new($this->provider, 'test-model')->withDock($dock);

        self::assertSame(30, self::sideWidth($app, Pane::Skills, 120));
    }

    private static function sideWidth(App $app, Pane $pane, int $cols): int
    {
        return (new \ReflectionMethod(TuiRenderer::class, 'sideWidth'))->invoke(null, $app, $pane, $cols, 40);
    }

    private static function assertContainsPaneId(string $paneId, App $app): void
    {
        $ids = [];
        foreach (Side::cases() as $side) {
            foreach ($app->dock()->slots($side) as $slot) {
                $ids[] = $slot->paneId;
            }
        }

        self::assertContains($paneId, $ids, 'the mutation must have added the slot it seeded for');
    }
}
