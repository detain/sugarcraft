<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Toast\{Action, Alert, Position, Toast, ToastType};

/**
 * Caps on hostile-length input.
 *
 * The security lane measured the old renderer at ~O(n^2) in message length
 * with NO size limit: 16KB took 3.7s, 32KB took 14.4s, and 262KB was killed
 * over 120s — from ORDINARY long messages, no malice required (the label
 * channel was already immune via truncation; the queue caveat covered alert
 * COUNT, never per-alert SIZE). The renderer is now a linear grid build AND
 * the content is bounded: the render surface carries at most
 * Toast::MAX_MESSAGE_CELLS cells with an explicit '…[truncated]' marker, and
 * at most MAX_ACTIONS_PER_ALERT painted buttons behind an expandable '[+N more]' fold row.
 * The full text still rides the Alert into history() — caps bound the
 * RENDER, never data loss.
 */
final class ToastMessageCapTest extends TestCase
{
    private const WORDS = 4000; // 4000 * 'word ' = 20 000 cells, ~2.4x the cap

    private function got(string $message, ?array $actions = null): Toast
    {
        return Toast::new(50)
            ->withPosition(Position::TopLeft)
            ->alert(ToastType::Info, $message, actions: $actions);
    }

    public function testOverlongMessageRendersBoundedFrameWithMarker(): void
    {
        $toast = $this->got(\str_repeat('word ', self::WORDS));
        $started = \microtime(true);
        $out = $toast->view('', 80, 24);
        $elapsed = \microtime(true) - $started;

        $this->assertStringContainsString('[truncated]', $out);
        // TST-HYGIENE (037): the fixed 5.0 wall-clock ceiling reddened under
        // loaded shared runners with no defect. Bracket instead: ~16x the
        // workload must not cost 200x the control render's time — the
        // linear-path contract stays enforced, CI load cannot.
        $controlToast = $this->got('word');
        $controlStart = \microtime(true);
        $controlToast->view('', 80, 24);
        $control = \microtime(true) - $controlStart;
        $this->assertLessThan(
            0.1 + $control * 200.0,
            $elapsed,
            'M6 perf pin: a 20K-cell message renders on the linear path (ratio-bracketed vs control)'
        );
        // Bounded frame: 8192 cells / ~46 inner cells ≈ 179 rows maximum.
        $rows = \explode("\n", $out);
        $this->assertLessThanOrEqual(200, \count($rows));
    }

    public function testFullTextStillRidesTheAlertIntoHistory(): void
    {
        $message = \str_repeat('word ', self::WORDS);
        $toast = $this->got($message)->dismiss();
        $history = $toast->history();
        $this->assertCount(1, $history);
        $this->assertSame(\strlen($message), \strlen($history[0]->message), 'caps bind the render, never the data');
    }

    public function testUnderCapMessagesAreUntouched(): void
    {
        $toast = $this->got('plain short news');
        $this->assertStringNotContainsString('[truncated]', $toast->view('', 80, 24));
    }

    private function manyDoors(int $n = 67): array
    {
        $actions = [];
        for ($i = 1; $i <= $n; $i++) {
            $actions[] = Action::new("A{$i}", static function () {});
        }
        return $actions;
    }

    public function testActionButtonsFoldPastTheCap(): void
    {
        // TST-FOLDROW gated leg (disclosed re-pin of the dim-prose fold): the
        // fold now paints as a pressable '[+3 more]' button because it IS
        // one — it is a rect in actionRects() carrying 'fold' = 3.
        $actions = $this->manyDoors();
        $toast = $this->got('many doors', $actions);
        $out = $toast->view('', 120, 240);

        $this->assertStringContainsString('[+3 more]', $out, 'the expandable fold wears the button shape');
        $this->assertStringContainsString('[A64]', $out);
        $this->assertStringNotContainsString('[A65]', $out);

        $rects = $toast->actionRects('', 120, 240);
        $this->assertCount(Toast::MAX_ACTIONS_PER_ALERT + 1, $rects, '64 buttons + the fold rect');
        $this->assertSame(0, $rects[63]['fold']);
        $fold = $rects[64];
        $this->assertSame(3, $fold['fold'], 'hidden-button count rides the rect');
        $this->assertSame(9, $fold['width'], "'[+3 more]' is 9 cells");

        // actionAt() answers only real buttons — the fold is its own door.
        $last = $rects[63];
        $this->assertSame($actions[63], $toast->actionAt($last['col'], $last['row'], '', 120, 240));
        $this->assertSame($last['row'] + 1, $fold['row']);
        $this->assertNull($toast->actionAt($fold['col'], $fold['row'], '', 120, 240), 'fold never dispatches as an Action');
    }

    public function testExpandAtOnTheFoldRowExpandsTheAlertAndOnlyThere(): void
    {
        $toast = $this->got('many doors', $this->manyDoors());
        $toast->view('', 120, 240);
        $fold = $toast->actionRects()[64];

        $this->assertNull($toast->expandAt(0, 0), 'off the fold: no expansion');
        $this->assertNull($toast->expandAt($fold['col'] + $fold['width'], $fold['row']), 'one cell past the label');

        $next = $toast->expandAt($fold['col'], $fold['row']);
        $this->assertNotNull($next);
        $this->assertNotSame($toast, $next, 'immutable: expansion is a new Toast');
        $out = $next->view('', 120, 240);
        $this->assertStringContainsString('[A67]', $out, 'every button now paints');
        $this->assertStringNotContainsString('more]', $out);
        $rects = $next->actionRects();
        $this->assertCount(67, $rects);
        $this->assertSame([0], \array_values(\array_unique(\array_column($rects, 'fold'))), 'no fold rect once expanded');
        // The original instance is untouched.
        $this->assertStringNotContainsString('[A67]', $toast->view('', 120, 240));
    }

    public function testWithActionsExpandedIsTheKeyboardTwinAndCollapses(): void
    {
        $toast = $this->got('many doors', $this->manyDoors());
        $this->assertSame($toast, $toast->withActionsExpanded(5), 'out of range is a no-op');
        $open = $toast->withActionsExpanded(0);
        $this->assertCount(67, $open->actionRects('', 120, 240));
        $closed = $open->withActionsExpanded(0, false);
        $this->assertCount(65, $closed->actionRects('', 120, 240));
    }

    public function testExpansionBeyondTheHardCeilingFallsBackToDimProse(): void
    {
        $toast = $this->got('many doors', $this->manyDoors(Toast::MAX_EXPANDED_ACTIONS + 5))
            ->withActionsExpanded(0);
        $out = $toast->view('', 120, 700);
        $this->assertStringContainsString("\x1b[0;2m+5 more", $out);
        $this->assertCount(Toast::MAX_EXPANDED_ACTIONS, $toast->actionRects());
    }

    public function testHiddenActionsAreStillReachableThroughActiveAlerts(): void
    {
        $actions = [];
        for ($i = 1; $i <= 67; $i++) {
            $actions[] = Action::new("A{$i}", static function () {});
        }
        $toast = $this->got('many doors', $actions);
        $this->assertCount(67, $toast->activeAlerts()[0]->actions, 'hiding is a render policy, not data loss');
    }

    public function testMarkerNeverInheritsHostileColour(): void
    {
        // A message styled to the very end must not paint the truncation
        // marker — boundMessage closes styling before it.
        $toast = $this->got(\str_repeat('word ', self::WORDS) . "\x1b[31mEND");
        $out = $toast->view('', 80, 24);
        $pos = \strpos($out, '[truncated]');
        $this->assertNotFalse($pos);
        $window = \substr($out, \max(0, $pos - 12), 12);
        $this->assertStringNotContainsString("\x1b[31", $window, 'reset must precede the marker');
    }

    public function testFoldAndTruncationMarkersFollowTheActiveLocale(): void
    {
        // TST-CHROME code leg: 'more' / 'truncated' are lang keys, not
        // hardcoded English; English bytes stay pinned by the tests above.
        $actions = [];
        for ($i = 1; $i <= 67; $i++) {
            $actions[] = Action::new("A{$i}", static function () {});
        }
        try {
            \SugarCraft\Core\I18n\T::setLocale('de');
            $out = $this->got('many doors', $actions)->view('', 120, 240);
            $this->assertStringContainsString('+3 weitere', $out);
            $this->assertStringNotContainsString('+3 more', $out);

            $cut = $this->got(\str_repeat('word ', self::WORDS))->view('', 80, 24);
            $this->assertStringContainsString('…[gekürzt]', $cut);
            $this->assertStringNotContainsString('[truncated]', $cut);
        } finally {
            \SugarCraft\Core\I18n\T::setLocale('en');
        }
    }
}
