<?php

declare(strict_types=1);

namespace SugarCraft\DatePicker\Tests;

use SugarCraft\DatePicker\DatePicker;
use SugarCraft\DatePicker\EventStore;
use SugarCraft\DatePicker\Model;
use SugarCraft\DatePicker\PickerEvent;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use PHPUnit\Framework\TestCase;

final class ModelTest extends TestCase
{
    public function testNewReturnsModelInstance(): void
    {
        $model = Model::new(new \DateTimeImmutable('2026-05-01'));
        $this->assertInstanceOf(Model::class, $model);
        $this->assertSame(5, $model->picker()->ViewMonth());
        $this->assertSame(2026, $model->picker()->ViewYear());
    }

    public function testInitReturnsNull(): void
    {
        $model = Model::new();
        $this->assertNull($model->init());
    }

    public function testViewDelegatesToDatePickerView(): void
    {
        $model = Model::new(new \DateTimeImmutable('2026-05-01'));
        $view = $model->view();
        $this->assertIsString($view);
        $this->assertStringContainsString('May', $view);
    }

    public function testSubscriptionsReturnsNull(): void
    {
        $model = Model::new();
        $this->assertNull($model->subscriptions());
    }

    public function testUpdateWithRightMovesCursorRight(): void
    {
        $model = Model::new(new \DateTimeImmutable('2026-05-01'));
        $initial = $model->picker()->CursorIndex();

        $keyMsg = new KeyMsg(KeyType::Right);
        [$nextModel, $cmd] = $model->update($keyMsg);

        $this->assertSame($initial + 1, $nextModel->picker()->CursorIndex());
        $this->assertNull($cmd);
    }

    public function testUpdateWithLeftMovesCursorLeft(): void
    {
        $model = Model::new(new \DateTimeImmutable('2026-05-01'));

        // Move right first to get off zero
        $keyMsg = new KeyMsg(KeyType::Right);
        [$model, ] = $model->update($keyMsg);

        $initial = $model->picker()->CursorIndex();
        $keyMsg = new KeyMsg(KeyType::Left);
        [$nextModel, $cmd] = $model->update($keyMsg);

        $this->assertSame($initial - 1, $nextModel->picker()->CursorIndex());
        $this->assertNull($cmd);
    }

    public function testUpdateWithDownMovesCursorDown(): void
    {
        $model = Model::new(new \DateTimeImmutable('2026-05-01'));
        $initial = $model->picker()->CursorIndex();

        $keyMsg = new KeyMsg(KeyType::Down);
        [$nextModel, $cmd] = $model->update($keyMsg);

        $this->assertSame($initial + 7, $nextModel->picker()->CursorIndex());
        $this->assertNull($cmd);
    }

    public function testUpdateWithUpMovesCursorUp(): void
    {
        $model = Model::new(new \DateTimeImmutable('2026-05-01'));

        // Move down first to ensure we're not at top boundary
        $keyMsg = new KeyMsg(KeyType::Down);
        [$model, ] = $model->update($keyMsg);
        $initial = $model->picker()->CursorIndex();

        $keyMsg = new KeyMsg(KeyType::Up);
        [$nextModel, $cmd] = $model->update($keyMsg);

        $this->assertSame($initial - 7, $nextModel->picker()->CursorIndex());
        $this->assertNull($cmd);
    }

    public function testUpdateWithHomeResetsCursorToZero(): void
    {
        $model = Model::new(new \DateTimeImmutable('2026-05-01'));

        // Move right a few times to get off zero
        $keyMsg = new KeyMsg(KeyType::Right);
        [$model, ] = $model->update($keyMsg);
        [$model, ] = $model->update($keyMsg);

        $keyMsg = new KeyMsg(KeyType::Home);
        [$nextModel, $cmd] = $model->update($keyMsg);

        // Home jumps to the first valid day (May 1 at index 5 — May 2026 opens
        // on Friday, so the Sunday lead-in cell 0 is never landed on).
        $this->assertSame(5, $nextModel->picker()->CursorIndex());
        $this->assertNull($cmd);
    }

    public function testUpdateWithEndSetsCursorToLastValidDay(): void
    {
        $model = Model::new(new \DateTimeImmutable('2026-05-01'));

        $keyMsg = new KeyMsg(KeyType::End);
        [$nextModel, $cmd] = $model->update($keyMsg);

        // End lands on the last valid day (May 31 at index 35), not the raw
        // grid position 41 that may be a blank trailing cell.
        $this->assertSame(35, $nextModel->picker()->CursorIndex());
        $this->assertNull($cmd);
    }

    public function testUpdateWithNonKeyMsgReturnsSamePicker(): void
    {
        $model = Model::new(new \DateTimeImmutable('2026-05-01'));

        $msg = new \SugarCraft\Core\Msg\QuitMsg();
        [$nextModel, $cmd] = $model->update($msg);

        $this->assertSame(
            $model->picker()->CursorIndex(),
            $nextModel->picker()->CursorIndex()
        );
        $this->assertNull($cmd);
    }

    public function testModelRecordsCursorMovedEventToStore(): void
    {
        $store = new EventStore();
        $model = Model::new(new \DateTimeImmutable('2026-05-01'), $store);

        $model->update(new KeyMsg(KeyType::Right));

        $this->assertTrue($store->hasEvents());
        $events = $store->release();
        $this->assertCount(1, $events);
        $this->assertSame(PickerEvent::CURSOR_MOVED, $events[0]['type']);
    }

    public function testModelRecordsRangeStartedThenDateSelectedInRangeMode(): void
    {
        // D4/MT4: arming the range start is its own observable event (the old
        // wrapper was event-silent until BOTH ends were set, so a host could
        // not see the anchor). The constructor seeds the cursor on May 1 (the
        // viewed date), so the first Enter lands there directly.
        $store = new EventStore();
        $picker = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->withRangeMode(true);
        $model = new Model($picker, $store);

        // First Enter: arms rangeStart and records range_started.
        $model = $model->update(new KeyMsg(KeyType::Enter))[0];
        $armed = $store->release();
        $this->assertNotEmpty($armed, 'arming the range must record an event');
        $this->assertSame(PickerEvent::RANGE_STARTED, $armed[0]['type']);
        $this->assertNotNull($model->picker()->rangeStart(), 'rangeStart should be set');

        // Second Enter on a later day: completes the range, records date_selected
        // carrying BOTH endpoints.
        $model = new Model($model->picker()->MoveCursorRight(), $store);
        $model = $model->update(new KeyMsg(KeyType::Enter))[0];
        $events = $store->release();
        $selected = array_values(array_filter($events, static fn(array $e): bool => $e['type'] === PickerEvent::DATE_SELECTED));
        $this->assertCount(1, $selected, 'date_selected should be recorded after rangeEnd is set');
        $this->assertSame('2026-05-01', $selected[0]['payload']['rangeStart'],
            'the completed-range payload must carry the start');
        $this->assertSame('2026-05-02', $selected[0]['payload']['rangeEnd']);
    }

    public function testPageDownAndPageUpStepTheMonth(): void
    {
        // D5: month navigation was keyboard-dead on the TEA path (only eight
        // KEY_* were mapped); PageUp/PageDown now bridge to the picker's
        // GoTo{Previous,Next}Month so a README-only embedder can leave the
        // launch month.
        $model = Model::new(new \DateTimeImmutable('2026-05-01'));
        $this->assertSame(5, $model->picker()->ViewMonth());

        [$next, $cmd] = $model->update(new KeyMsg(KeyType::PageDown));
        $this->assertSame(6, $next->picker()->ViewMonth());
        $this->assertNull($cmd);

        [$prev, ] = $model->update(new KeyMsg(KeyType::PageUp));
        $this->assertSame(4, $prev->picker()->ViewMonth());
    }

    /**
     * dp-07 successor to the two-case testChordedEnterIsIgnored: pins the
     * WHOLE settled chord matrix — every mapped arm under every modifier —
     * as contract. Navigation arms (arrows, Home, End) act bare AND chorded
     * (terminal-compatibility exception); action arms (PageUp, PageDown,
     * Enter, Escape) act ONLY bare and are host-reserved under Ctrl, Alt or
     * Shift. Flipping the polarity of any single arm in applyKey reddens a
     * row here.
     */
    public static function chordMatrixProvider(): array
    {
        $cases = [];
        $modifiers = ['none', 'ctrl', 'alt', 'shift'];
        $navigation = ['Left', 'Right', 'Up', 'Down', 'Home', 'End'];
        $actions = ['PageUp', 'PageDown', 'Enter', 'Escape'];
        foreach ([...$navigation, ...$actions] as $arm) {
            foreach ($modifiers as $chord) {
                $acts = in_array($arm, $navigation, true) || $chord === 'none';
                $cases["{$arm}+{$chord}"] = [$arm, $chord, $acts];
            }
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('chordMatrixProvider')]
    public function testChordMatrix(string $arm, string $chord, bool $acts): void
    {
        $types = [
            'Left' => KeyType::Left,
            'Right' => KeyType::Right,
            'Up' => KeyType::Up,
            'Down' => KeyType::Down,
            'Home' => KeyType::Home,
            'End' => KeyType::End,
            'PageUp' => KeyType::PageUp,
            'PageDown' => KeyType::PageDown,
            'Enter' => KeyType::Enter,
            'Escape' => KeyType::Escape,
        ];

        $model = Model::new(new \DateTimeImmutable('2026-05-15'));
        if ($arm === 'Escape') {
            // Seed a selection so Escape has an observable effect to pin.
            $model = $model->update(new KeyMsg(KeyType::Enter))[0];
            $this->assertNotNull($model->picker()->selectedDate());
        }

        $before = self::stateFingerprint($model->picker());
        [$next, $cmd] = $model->update(new KeyMsg(
            $types[$arm],
            alt: $chord === 'alt',
            ctrl: $chord === 'ctrl',
            shift: $chord === 'shift',
        ));
        $after = self::stateFingerprint($next->picker());
        $this->assertNull($cmd, 'the command is permanently null');

        if ($acts) {
            $this->assertNotSame(
                $before,
                $after,
                "the {$arm} arm must act under '{$chord}' (terminal-compat exception or bare key)",
            );
        } else {
            $this->assertSame(
                $before,
                $after,
                "{$arm} held with '{$chord}' belongs to the host and must be ignored",
            );
        }
    }

    /** @return array{0: ?string, 1: bool, 2: int, 3: int, 4: int} */
    private static function stateFingerprint(DatePicker $picker): array
    {
        return [
            $picker->selectedDate()?->format('Y-m-d'),
            $picker->isSelecting(),
            $picker->cursorIndex(),
            $picker->viewMonth(),
            $picker->viewYear(),
        ];
    }

    public function testEscapeInSingleModeRecordsDateCleared(): void
    {
        // MT4: clearing a selection was event-silent; a host watching the store
        // could not tell "cleared" from "never picked".
        $store = new EventStore();
        $model = Model::new(new \DateTimeImmutable('2026-05-01'), $store);
        $model = $model->update(new KeyMsg(KeyType::Enter))[0];   // select
        $store->release();                                        // discard the date_selected
        $model = $model->update(new KeyMsg(KeyType::Escape))[0];  // clear

        $cleared = array_values(array_filter(
            $store->release(),
            static fn(array $e): bool => $e['type'] === PickerEvent::DATE_CLEARED,
        ));
        $this->assertCount(1, $cleared);
        $this->assertNull($model->picker()->SelectedDate());
    }

    public function testSelectingADifferentDateRecordsAgain(): void
    {
        // D4: date_selected fires on a VALUE change, not the false→true edge of
        // IsSelecting. Re-picking a DIFFERENT date emits a second event (the old
        // flag-edge logic dropped it, persisting a stale first pick); re-picking
        // the SAME date stays a single event.
        $store = new EventStore();
        $model = Model::new(new \DateTimeImmutable('2026-05-01'), $store);
        $model = $model->update(new KeyMsg(KeyType::Enter))[0];           // May 1
        $model = new Model($model->picker()->MoveCursorRight(), $store);
        $model = $model->update(new KeyMsg(KeyType::Enter))[0];           // May 2 (different)

        $selected = array_values(array_filter(
            $store->release(),
            static fn(array $e): bool => $e['type'] === PickerEvent::DATE_SELECTED,
        ));
        $this->assertCount(2, $selected, 'a changed selection must re-record');
        $this->assertSame('2026-05-01', $selected[0]['payload']['date']);
        $this->assertSame('2026-05-02', $selected[1]['payload']['date']);

        // Re-pressing Enter on the same date adds nothing.
        $store2 = new EventStore();
        $same = Model::new(new \DateTimeImmutable('2026-05-01'), $store2);
        $same = $same->update(new KeyMsg(KeyType::Enter))[0];
        $same = $same->update(new KeyMsg(KeyType::Enter))[0];
        $this->assertCount(1, array_filter(
            $store2->release(),
            static fn(array $e): bool => $e['type'] === PickerEvent::DATE_SELECTED,
        ), 're-selecting the same date must not duplicate');
    }

    public function testModelDoesNotRecordEventWhenNoStore(): void
    {
        $model = Model::new(new \DateTimeImmutable('2026-05-01'));

        $keyMsg = new KeyMsg(KeyType::Right);
        [$nextModel] = $model->update($keyMsg);

        // No store, no error - model still works
        $this->assertSame(1, $nextModel->picker()->CursorIndex() - $model->picker()->CursorIndex());
    }

    public function testModelPreservesStoreAcrossUpdates(): void
    {
        $store = new EventStore();
        $model = Model::new(new \DateTimeImmutable('2026-05-01'), $store);

        $model->update(new KeyMsg(KeyType::Right));
        $model->update(new KeyMsg(KeyType::Right));

        $events = $store->release();
        $this->assertCount(2, $events);
    }

    public function testPickerAccessorReturnsDatePicker(): void
    {
        $picker = DatePicker::new(new \DateTimeImmutable('2026-05-01'));
        $model = new Model($picker);

        $this->assertSame($picker, $model->picker());
    }

    public function testUpdateWithNonKeyMsgIsStable(): void
    {
        // Non-KeyMsg returns same picker unchanged (no-op branch)
        $model = Model::new(new \DateTimeImmutable('2026-05-01'));
        $initialIndex = $model->picker()->CursorIndex();

        $msg = new class implements \SugarCraft\Core\Msg {};
        [$nextModel, $cmd] = $model->update($msg);

        $this->assertSame($initialIndex, $nextModel->picker()->CursorIndex());
        $this->assertNull($cmd);
    }

    public function testModelEscapeKeyClearsRangeInRangeMode(): void
    {
        // Build picker with range mode and a range already set
        $firstDow = (int) (new \DateTimeImmutable('2026-05-01'))->format('w');
        $picker = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->withRangeMode(true);

        // Navigate to May 1
        for ($i = 0; $i < $firstDow; $i++) {
            $picker = $picker->MoveCursorRight();
        }
        $picker = $picker->handleKey(DatePicker::KEY_ENTER); // rangeStart set

        // Move to May 5 and set rangeEnd
        for ($i = 0; $i < 4; $i++) {
            $picker = $picker->MoveCursorRight();
        }
        $picker = $picker->handleKey(DatePicker::KEY_ENTER); // rangeEnd set

        $this->assertNotNull($picker->rangeStart());
        $this->assertNotNull($picker->rangeEnd());

        // Now wrap in a Model and press Escape
        $store = new EventStore();
        $model = new Model($picker, $store);

        $model = $model->update(new KeyMsg(KeyType::Escape))[0];

        $this->assertNull($model->picker()->rangeStart(),
            'Escape must clear rangeStart in range mode');
        $this->assertNull($model->picker()->rangeEnd(),
            'Escape must clear rangeEnd in range mode');
    }

    public function testModelUpdateWithCharKeyIsNoOp(): void
    {
        // Char keys (default case) should return same picker unchanged
        $model = Model::new(new \DateTimeImmutable('2026-05-01'));
        $initialIndex = $model->picker()->CursorIndex();

        $keyMsg = new KeyMsg(KeyType::Char, 'a');
        [$nextModel, $cmd] = $model->update($keyMsg);

        $this->assertSame($initialIndex, $nextModel->picker()->CursorIndex());
        $this->assertNull($cmd);
    }

    // -------------------------------------------------------------------------
    // Single-mode selection end-to-end (docs-fix-1023 E2 / M1)
    //
    // These pins drive REAL key bytes through update() — the whole point of
    // the TEA wrapper. Before the fix, Enter in single mode no-oped inside
    // handleKey(), the 'date_selected' branch below was dead code, and the
    // docblock promise "Enter to select, Esc to close" was unreachable.
    // -------------------------------------------------------------------------

    public function testEnterInSingleModeSelectsThroughTheTeaLoop(): void
    {
        $store = new EventStore();
        // The constructor now seeds the cursor on the viewed date (May 1, 2026
        // sits at grid index 5), so Enter on a fresh picker selects it directly
        // — the previous edit-existing-value path had no cursor aim.
        $model = Model::new(new \DateTimeImmutable('2026-05-01'), $store);

        $model = $model->update(new KeyMsg(KeyType::Enter))[0];

        $this->assertTrue($model->picker()->IsSelecting(),
            'Enter in single mode must enter selection mode through update()');
        $this->assertSame('2026-05-01', $model->picker()->SelectedDate()?->format('Y-m-d'));

        $events = $store->release();
        $selected = array_values(array_filter($events, static fn(array $e): bool => $e['type'] === PickerEvent::DATE_SELECTED));
        $this->assertCount(1, $selected);
        $this->assertSame('2026-05-01', $selected[0]['payload']['date'],
            'The date transition must be recorded once with the selected value');
    }

    public function testSecondEnterInSingleModeDoesNotDuplicateTheRecord(): void
    {
        $store = new EventStore();
        $model = Model::new(new \DateTimeImmutable('2026-05-01'), $store);
        $model = $model->update(new KeyMsg(KeyType::End))[0];  // index 41
        $model = $model->update(new KeyMsg(KeyType::Home))[0]; // index 0 (empty cell → May 1 fallback)
        $model = $model->update(new KeyMsg(KeyType::Enter))[0];
        $this->assertTrue($model->picker()->IsSelecting());

        $model = $model->update(new KeyMsg(KeyType::Enter))[0];

        $events = $store->release();
        $selected = array_values(array_filter($events, static fn(array $e): bool => $e['type'] === PickerEvent::DATE_SELECTED));
        $this->assertCount(1, $selected,
            'Re-entering the same selecting state must not re-record');
        $this->assertSame('2026-05-01', $model->picker()->SelectedDate()?->format('Y-m-d'));
    }

    public function testEscapeInSingleModeClearsThroughTheTeaLoop(): void
    {
        $model = Model::new(new \DateTimeImmutable('2026-05-01'));
        $model = $model->update(new KeyMsg(KeyType::Home))[0];
        $model = $model->update(new KeyMsg(KeyType::Enter))[0];
        $this->assertTrue($model->picker()->IsSelecting());

        $model = $model->update(new KeyMsg(KeyType::Escape))[0];

        $this->assertFalse($model->picker()->IsSelecting());
        $this->assertNull($model->picker()->SelectedDate());
    }

    public function testEnterOnOutOfBoundDateRecordsNothing(): void
    {
        // Selection refusal (bound) must not fabricate a date_selected event.
        $store = new EventStore();
        $model = Model::new(new \DateTimeImmutable('2026-05-01'), $store);
        $picker = $model->picker()->withMinDate(new \DateTimeImmutable('2026-05-10'));
        $model = new Model($picker, $store);
        $model = $model->update(new KeyMsg(KeyType::Home))[0];
        $model = $model->update(new KeyMsg(KeyType::Enter))[0]; // May 1 fallback < min

        $this->assertFalse($model->picker()->IsSelecting());
        $this->assertFalse($store->hasEvents(),
            'A refused selection must record nothing');
    }

    public function testModelViewDelegatesLayoutArguments(): void
    {
        $model = Model::new(new \DateTimeImmutable('2026-05-01'));

        $this->assertSame(
            $model->picker()->View(40, true),
            $model->view(40, true),
            'view() must forward width/week-number options'
        );
        $this->assertSame($model->picker()->View(), $model->view());
        $this->assertNotSame($model->view(40, true), $model->view());
    }

    public function testArglessModelViewHonoursPickerRenderState(): void
    {
        $picker = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->withWidth(30)
            ->withShowWeekNumbers(true);
        $model = new Model($picker);

        // The argless Core\Model contract renders the CONFIGURED grid.
        $this->assertSame($picker->view(30, true), $model->view());
        // Explicit arguments through Model::view() still win per call.
        $this->assertSame($picker->view(21, false), $model->view(21, false));
    }

    public function testNewAcceptsConfiguredPicker(): void
    {
        $picker = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->withRangeMode(true)
            ->withWidth(30);

        $model = Model::new(picker: $picker);

        $this->assertSame($picker, $model->picker());
        $this->assertTrue($model->picker()->isRangeMode());
        // The configured render state survives construction: argless view
        // renders the 30-wide grid, not a re-defaulted 21.
        $this->assertSame($picker->view(30), $model->view());
    }

    public function testNewRejectsTimeAndPickerTogether(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Model::new(
            new \DateTimeImmutable('2026-05-01'),
            picker: DatePicker::new(new \DateTimeImmutable('2026-06-01')),
        );
    }

    public function testWithPickerCallableFormSeesCurrentPickerAndKeepsStore(): void
    {
        $store  = new EventStore(clock: static fn (): int => 1_777_636_800);
        $model  = Model::new(new \DateTimeImmutable('2026-05-01'), $store);

        $seen = null;
        $next = $model->withPicker(static function (DatePicker $p) use (&$seen): DatePicker {
            $seen = $p;

            return $p->withRangeMode(true);
        });

        $this->assertSame($model->picker(), $seen, 'the callable receives the current picker');
        $this->assertTrue($next->picker()->isRangeMode());

        // Store identity proven behaviourally: the event raised through the
        // rebound model lands in the ORIGINAL store instance.
        $next->update(new KeyMsg(KeyType::Enter));
        $this->assertNotSame([], $store->release(), 'withPicker must keep the same EventStore');
    }

    public function testWithPickerInstanceForm(): void
    {
        $store   = new EventStore(clock: static fn (): int => 1_777_636_800);
        $model   = Model::new(new \DateTimeImmutable('2026-05-01'), $store);
        $replacement = DatePicker::new(new \DateTimeImmutable('2026-07-01'));

        $next = $model->withPicker($replacement);

        $this->assertSame($replacement, $next->picker());
        $this->assertSame(7, $next->picker()->viewMonth());
    }

    public function testWithPickerRejectsNonPickerReturn(): void
    {
        $model = Model::new(new \DateTimeImmutable('2026-05-01'));

        $this->expectException(\TypeError::class);

        /** @phpstan-ignore-next-line callable must return DatePicker */
        $model->withPicker(static fn (DatePicker $p) => 'nope');
    }
}
