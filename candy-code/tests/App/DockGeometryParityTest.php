<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\App;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Code\Agents\AgentManager;
use SugarCraft\Code\App\App;
use SugarCraft\Code\App\DockGeometry;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Events\SubAgentActivity;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tests\Support\RosterAgent;
use SugarCraft\Code\Tui\Pane;
use SugarCraft\Code\Tui\Renderer as TuiRenderer;
use SugarCraft\Constraints\Dock\DockLayout;
use SugarCraft\Constraints\Dock\Side;

/**
 * The dock band's geometry, pinned equal on both sides of the paint seam.
 *
 * TUIARCH-02 phase 1 moved the model's five raw reads of the renderer's
 * published frame static behind one typed adapter, {@see App::dockGeometry()}
 * (private — driven here through reflection, the house idiom for the model's
 * gesture internals). The adapter is only honest while the frame it adapts
 * still says what {@see TuiRenderer::dockBandMeasure()} derives from the
 * model that painted it; this file pins exactly that equality across dock
 * states x terminal widths, so phase 2 — retiring the static and letting the
 * model compute the band itself — inherits a contract that already fails
 * loudly on any drift between derivation and publication.
 *
 * The pinned law is three-way: the frame must carry the width it was painted
 * at; the renderer's pure band measurement over the SAME model must answer
 * the frame's agentCols/bandCols; and every one of the adapter's seven fields
 * must byte-equal its frame key. A state whose split declines (a terminal too
 * narrow, or no live runs) is a parity leg too — agentCols 0 on both sides.
 */
final class DockGeometryParityTest extends TestCase
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

    /**
     * @return array<string, array{string, int}>
     */
    public static function bandStates(): array
    {
        $cases = [];

        foreach (['plain', 'stack', 'right', 'agents', 'agentsDragged'] as $state) {
            foreach ([60, 80, 100, 120, 140, 160] as $cols) {
                $cases[$state . '@' . $cols] = [$state, $cols];
            }
        }

        return $cases;
    }

    /**
     * @dataProvider bandStates
     */
    public function testThePublishedFrameCarriesExactlyWhatTheBandMeasurementDerives(string $state, int $cols): void
    {
        $app = $this->appInState($state);

        TuiRenderer::renderView($app, $cols, 40);

        $frame = TuiRenderer::lastDockFrame();
        self::assertIsArray($frame, $state . ' at ' . $cols . ' cells: a docked frame must publish its band');

        $measure = TuiRenderer::dockBandMeasure($app, $cols);

        self::assertSame($cols, $frame['cols'], $state . ': the frame states the width it was painted at');
        self::assertSame(
            $frame['agentCols'],
            $measure['agentCols'],
            $state . ' at ' . $cols . ': the published split width is the pure derivation over the same model',
        );
        self::assertSame(
            $frame['bandCols'],
            $measure['bandCols'],
            $state . ' at ' . $cols . ': the published band is the split width taken off the terminal, divider charged',
        );
    }

    /**
     * @dataProvider bandStates
     */
    public function testTheModelAdapterCarriesEveryFieldTheFramePublished(string $state, int $cols): void
    {
        $app = $this->appInState($state);

        TuiRenderer::renderView($app, $cols, 40);

        $frame = TuiRenderer::lastDockFrame();
        self::assertIsArray($frame);

        $geometry = (new ReflectionMethod(App::class, 'dockGeometry'))->invoke($app);
        self::assertInstanceOf(DockGeometry::class, $geometry);

        // The adapter reads the whole frame — key roster first, then every
        // field pair by name, so a renamed key fails with its own message.
        $keys = array_keys($frame);
        sort($keys);
        self::assertSame(['agentCols', 'bandCols', 'bandTop', 'centerFrom', 'centerTo', 'cols', 'paneRows'], $keys);

        self::assertSame($frame['cols'], $geometry->cols);
        self::assertSame($frame['bandTop'], $geometry->bandTop);
        self::assertSame($frame['bandCols'], $geometry->bandCols);
        self::assertSame($frame['paneRows'], $geometry->paneRows);
        self::assertSame($frame['centerFrom'], $geometry->centerFrom);
        self::assertSame($frame['centerTo'], $geometry->centerTo);
        self::assertSame($frame['agentCols'], $geometry->agentCols);
    }

    public function testTheAgentSplitStateActuallySplitsSomewhereInTheSweep(): void
    {
        // Parity of zeros would pass both sweeps vacuously at every width if
        // the live-run recipe silently stopped being live. Somewhere in the
        // sweep the split must engage, and somewhere a side band must be
        // painted — the discriminating floor under the equality legs.
        $splitSeen = false;
        $sidedSeen = false;

        foreach ([80, 100, 120, 140, 160] as $cols) {
            TuiRenderer::renderView($this->appInState('agents'), $cols, 40);
            $frame = TuiRenderer::lastDockFrame();
            self::assertIsArray($frame);
            $splitSeen = $splitSeen || $frame['agentCols'] > 0;
            $sidedSeen = $sidedSeen || $frame['bandCols'] < $cols;
        }

        self::assertTrue($splitSeen, 'a live agent run must widen the split at some swept width');
        self::assertTrue($sidedSeen, 'the right dock slot must cost the band some columns');
    }

    public function testTheAdapterPassesNullThroughAsNoMeasuredFrame(): void
    {
        self::assertNull(DockGeometry::fromFrame(null));
    }

    public function testTheAdapterRefusesAFrameMissingAGestureField(): void
    {
        $frame = [
            'cols' => 120, 'bandTop' => 3, 'bandCols' => 120, 'paneRows' => 34,
            'centerFrom' => 40, 'centerTo' => 80, // agentCols deliberately absent
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing the agentCols field');

        DockGeometry::fromFrame($frame);
    }

    private function appInState(string $state): App
    {
        $app = App::new($this->provider, 'test-model')->withChat(new Chat());

        if ($state === 'plain') {
            return $app;
        }

        if ($state === 'stack') {
            return $app
                ->withDock(App::defaultDock()->withSlotAdded(Side::Left, 'tools'))
                ->withPane(Pane::Chat);
        }

        if ($state === 'right') {
            return $app
                ->withDock(DockLayout::new('chat')->withSlotAdded(Side::Right, 'tools'))
                ->withPane(Pane::Chat);
        }

        $manager = new AgentManager($this->provider, new SkillRegistry());
        $manager->register(RosterAgent::named('reviewer')->withActive(false));
        $manager->projectRemoteSubAgent(new SubAgentActivity('started', 'r0', 'reviewer', 'Audit 0', 1, ''));
        // The split activates on runs that have PRODUCED text (the activation
        // policy in agentSplitWidth()'s docblock); a bare 'started' frame
        // leaves the run silent and the band full-width.
        $manager->projectRemoteSubAgent(new SubAgentActivity('progress', 'r0', 'reviewer', '', 2, '-> Grep(pattern: "TODO")'));
        $app = $app
            ->withChat(new Chat(agentManager: $manager))
            ->withDock(DockLayout::new('chat')->withSlotAdded(Side::Right, 'agents'))
            ->withPane(Pane::Chat);

        return $state === 'agentsDragged' ? $app->withAgentSplitCols(47) : $app;
    }
}
