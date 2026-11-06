<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\{Ansi, Width};
use SugarCraft\Toast\{Action, Alert, Position, Toast, ToastType};

/**
 * Pins the action hit-test surface. The law it holds: "an overlay either
 * marks its own controls or must not claim them clickable" — resolved by
 * exporting real geometry, zone-neutral.
 *
 * The rectangles must describe the ACTUAL composited frame: a rect is only
 * worth its pin if walking the rendered row at [col, col+width) lands on
 * the label's own cells. view(), actionRects() and actionAt() share the one
 * private layout() pass, which is what keeps pixels and hit-map honest.
 */
final class ToastActionGeometryTest extends TestCase
{
    private const BG = "bg\n";

    /** @param list<Action> $actions */
    private function toastWith(?Position $position = null, array $actions = ['Retry']): Toast
    {
        $actions = \array_map(
            static fn(string $l): Action => Action::new($l, static function () {}),
            $actions
        );
        $toast = Toast::new(60)->withPosition($position ?? Position::TopLeft);
        return $toast->alert(ToastType::Error, 'Connection lost', actions: $actions);
    }

    /**
     * Cell-indexed substring of a frame row: walk runes, accumulating
     * display width, so multi-byte content maps the way the terminal sees it.
     */
    private static function cells(string $row, int $col, int $width): string
    {
        $out = '';
        $cursor = 0;
        foreach (\preg_split('//u', Ansi::strip($row), -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            $w = \max(1, Width::string($ch));
            // Collect runes whose cell span intersects [col, col+width).
            if ($cursor + $w > $col && $cursor < $col + $width) {
                $out .= $ch;
            }
            $cursor += $w;
            if ($cursor >= $col + $width) {
                break;
            }
        }
        return $out;
    }

    public function testRectCoordinatesLandOnTheRenderedLabel(): void
    {
        $toast = $this->toastWith();
        $rects = $toast->actionRects(self::BG, 80, 24);
        $this->assertCount(1, $rects);
        $rect = $rects[0];

        $frame = \explode("\n", $toast->view(self::BG, 80, 24));
        $row = $frame[$rect['row']] ?? '(missing row)';
        // 7 columns starting at col must read exactly "[Retry]" on the wire.
        $this->assertSame('[Retry]', self::cells($row, $rect['col'], 7));
        $this->assertSame(7, $rect['width']);
        $this->assertSame(0, $rect['alertIndex']);
        $this->assertSame(0, $rect['actionIndex']);
    }

    public function testActionAtDispatchesTheButtonUnderTheCursor(): void
    {
        $toast = $this->toastWith(actions: ['Retry', 'Ignore']);
        $rects = $toast->actionRects(self::BG, 80, 24);
        $this->assertCount(2, $rects);
        $this->assertSame(0, $rects[0]['actionIndex']);
        $this->assertSame(1, $rects[1]['actionIndex']);

        foreach ($rects as $rect) {
            $mid = $rect['col'] + \intdiv($rect['width'], 2);
            $hit = $toast->actionAt($mid, $rect['row'], self::BG, 80, 24);
            $this->assertSame($rect['action'], $hit, 'center of every rect must hit its own action');
        }

        // First and last cell of each rect are inside; one outside each edge is not.
        $r = $rects[0];
        $this->assertSame($r['action'], $toast->actionAt($r['col'], $r['row'], self::BG, 80, 24));
        $this->assertSame($r['action'], $toast->actionAt($r['col'] + $r['width'] - 1, $r['row'], self::BG, 80, 24));
        $this->assertNull($toast->actionAt($r['col'] - 1, $r['row'], self::BG, 80, 24));
        $this->assertNull($toast->actionAt($r['col'] + $r['width'], $r['row'], self::BG, 80, 24));
    }

    public function testHeaderAndBackgroundPositionsAreNotClickable(): void
    {
        $toast = $this->toastWith();
        $rects = $toast->actionRects(self::BG, 80, 24);
        $row = $rects[0]['row'];
        // The box header sits above every action row.
        $this->assertNull($toast->actionAt(10, $row - 2, self::BG, 80, 24));
        // Far right of the frame (60-wide box at TopLeft): nothing there.
        $this->assertNull($toast->actionAt(79, $row, self::BG, 80, 24));
        // Row zero of the frame is background/chrome top border, never a button.
        $this->assertNull($toast->actionAt(0, 0, self::BG, 80, 24));
    }

    public function testBottomRightPlacementIsHonoured(): void
    {
        $toast = $this->toastWith(position: Position::BottomRight);
        $rects = $toast->actionRects(self::BG, 80, 24);
        $this->assertCount(1, $rects);
        $frame = \explode("\n", $toast->view(self::BG, 80, 24));
        $this->assertSame('[Retry]', self::cells($frame[$rects[0]['row']], $rects[0]['col'], 7));
        // Bottom family hugs the (24-row) viewport: row must sit deep.
        $this->assertGreaterThanOrEqual(20, $rects[0]['row']);
    }

    public function testTwoAlertsCarryDistinctIndicesAndRows(): void
    {
        $toast = Toast::new(60)
            ->withPosition(Position::TopLeft)
            ->alert(ToastType::Info, 'first', actions: [Action::new('Open', static function () {})])
            ->alert(ToastType::Warning, 'second', actions: [
                Action::new('Undo', static function () {}),
                Action::new('Keep', static function () {}),
            ]);

        $rects = $toast->actionRects(self::BG, 80, 24);
        $this->assertCount(3, $rects);
        $this->assertSame([0, 1, 1], \array_column($rects, 'alertIndex'));
        $this->assertSame([0, 0, 1], \array_column($rects, 'actionIndex'));
        $this->assertSame(
            \array_values(\array_unique(\array_column($rects, 'row'))),
            \array_column($rects, 'row'),
            'three buttons must occupy three distinct rows'
        );

        $frame = \explode("\n", $toast->view(self::BG, 80, 24));
        foreach ($rects as $rect) {
            $this->assertSame(
                '[' . $rect['action']->label . ']',
                self::cells($frame[$rect['row']], $rect['col'], $rect['width'])
            );
        }
    }

    public function testExpiredAlertsLeaveBothTheFrameAndTheHitMap(): void
    {
        $toast = Toast::new(60)
            ->withPosition(Position::TopLeft)
            // Explicit past epoch => already expired, unpruned (a11y P5 shape).
            ->push(new Alert(ToastType::Error, 'stale', \microtime(true) - 10.0, actions: [
                Action::new('Dead', static function () {}),
            ]))
            ->alert(ToastType::Info, 'live', actions: [Action::new('Live', static function () {})]);

        $this->assertCount(1, $toast->activeAlerts());
        $this->assertSame('live', $toast->activeAlerts()[0]->message);

        $rects = $toast->actionRects(self::BG, 80, 24);
        $this->assertCount(1, $rects, 'the expired button must not stay clickable');
        $this->assertSame(0, $rects[0]['alertIndex'], 'alertIndex is into activeAlerts(), not the raw queue');
        $this->assertSame('Live', $rects[0]['action']->label);
    }

    public function testDismissedAndEmptySurfacesAreEmpty(): void
    {
        $toast = $this->toastWith();
        $this->assertSame([], $toast->dismiss()->actionRects(self::BG, 80, 24));
        $this->assertNull($toast->dismiss()->actionAt(5, 5, self::BG, 80, 24));
        $this->assertSame([], Toast::new()->actionRects(self::BG, 80, 24));
    }

    public function testGeometryMatchesTheFrameByteForByteAcrossPositions(): void
    {
        // The rect walk must reproduce on every position family that fits
        // (boxes are 48 cells at maxWidth 50 — 9 x {40,60,80} x 24 frames).
        foreach (Position::cases() as $position) {
            foreach ([40, 60, 80] as $cols) {
                $toast = Toast::new(50)
                    ->withPosition($position)
                    ->alert(ToastType::Error, 'geom probe', actions: [Action::new('Go', static function () {})]);
                $rects = $toast->actionRects('', $cols, 24);
                $this->assertCount(1, $rects, $position->name . '@' . $cols);
                $frame = \explode("\n", $toast->view('', $cols, 24));
                $this->assertSame(
                    '[Go]',
                    self::cells($frame[$rects[0]['row']], $rects[0]['col'], $rects[0]['width']),
                    $position->name . '@' . $cols . ' rect missed its own label'
                );
                $this->assertNotNull(
                    $toast->actionAt($rects[0]['col'] + 1, $rects[0]['row'], '', $cols, 24),
                    $position->name . '@' . $cols . ' actionAt disagreeing with actionRects'
                );
            }
        }
    }
}
