<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Cli\LaunchContext;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Tui\Renderer as TuiRenderer;
use SugarCraft\Constraints\Dock\DockLayout;
use SugarCraft\Constraints\Dock\Side;

/**
 * candy-code-once-007 / G-06, the render half: a dock saved at a wide terminal
 * and restored at a narrow one keeps its persisted panes, but a side the band
 * cannot host at its own minimum is not painted at all — no few-column ghost
 * slot, no orphan border — and returns when the terminal is wide enough.
 */
final class NarrowRestoredDockRenderTest extends TestCase
{
    private ProviderInterface $provider;

    protected function setUp(): void
    {
        parent::setUp();
        TuiRenderer::setSize(200, 60);
        $this->provider = $this->createMock(ProviderInterface::class);
        $this->provider->method('name')->willReturn('TestProvider');
    }

    protected function tearDown(): void
    {
        TuiRenderer::setSize(200, 60);
        parent::tearDown();
    }

    public function testASideTheNarrowBandCannotHostAtItsMinimumIsNotPainted(): void
    {
        $app = $this->restored();

        // 60 columns resolves Left=20 (the side minimum) and Right=11: only the
        // sliver is dropped.
        self::assertNotSame('', self::side($app, Side::Left, 60), 'the left side still meets its minimum and paints');
        self::assertSame('', self::side($app, Side::Right, 60), 'the 11-column sliver is not a pane');

        // 40 columns resolves both to 7: neither can host a box.
        self::assertSame('', self::side($app, Side::Left, 40));
        self::assertSame('', self::side($app, Side::Right, 40));
    }

    public function testTheSavedPanesAreStillThereAndComeBackWhenWideEnough(): void
    {
        $app = $this->restored();
        self::side($app, Side::Left, 40);

        self::assertCount(1, $app->dock()->slots(Side::Left), 'rendering never rewrites the persisted layout');
        self::assertCount(1, $app->dock()->slots(Side::Right));
        self::assertNotSame('', self::side($app, Side::Left, 200));
        self::assertNotSame('', self::side($app, Side::Right, 200));
    }

    private function restored(): App
    {
        $m = DockLayout::new('chat')->toArray();
        $m['sides']['left'] = [['id' => 'sessions', 'weight' => [1, 1]]];
        $m['sides']['right'] = [['id' => 'agents', 'weight' => [1, 1]]];
        $m['columnShare'] = ['left' => [15, 78], 'right' => [15, 78]];
        $dock = LaunchContext::renormalizeDock(DockLayout::fromArray($m));

        return App::new($this->provider, 'test-model')->withDock($dock);
    }

    private static function side(App $app, Side $side, int $cols): string
    {
        $out = (new \ReflectionMethod(TuiRenderer::class, 'renderSide'))->invoke(null, $app, $side, $cols, 24);

        return $out[0];
    }
}
