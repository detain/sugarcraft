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
        $this->assertSame(5, $model->picker()->viewMonth());
        $this->assertSame(2026, $model->picker()->viewYear());
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
        $initial = $model->picker()->cursorIndex();

        $keyMsg = new KeyMsg(KeyType::Right);
        [$nextModel, $cmd] = $model->update($keyMsg);

        $this->assertSame($initial + 1, $nextModel->picker()->cursorIndex());
        $this->assertNull($cmd);
    }

    public function testUpdateWithLeftMovesCursorLeft(): void
    {
        $model = Model::new(new \DateTimeImmutable('2026-05-01'));

        // Move right first to get off zero
        $keyMsg = new KeyMsg(KeyType::Right);
        [$model, ] = $model->update($keyMsg);

        $initial = $model->picker()->cursorIndex();
        $keyMsg = new KeyMsg(KeyType::Left);
        [$nextModel, $cmd] = $model->update($keyMsg);

        $this->assertSame($initial - 1, $nextModel->picker()->cursorIndex());
        $this->assertNull($cmd);
    }

    public function testUpdateWithDownMovesCursorDown(): void
    {
        $model = Model::new(new \DateTimeImmutable('2026-05-01'));
        $initial = $model->picker()->cursorIndex();

        $keyMsg = new KeyMsg(KeyType::Down);
        [$nextModel, $cmd] = $model->update($keyMsg);

        $this->assertSame($initial + 7, $nextModel->picker()->cursorIndex());
        $this->assertNull($cmd);
    }

    public function testUpdateWithUpMovesCursorUp(): void
    {
        $model = Model::new(new \DateTimeImmutable('2026-05-01'));

        // Move down first to ensure we're not at top boundary
        $keyMsg = new KeyMsg(KeyType::Down);
        [$model, ] = $model->update($keyMsg);
        $initial = $model->picker()->cursorIndex();

        $keyMsg = new KeyMsg(KeyType::Up);
        [$nextModel, $cmd] = $model->update($keyMsg);

        $this->assertSame($initial - 7, $nextModel->picker()->cursorIndex());
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
        $this->assertSame(5, $nextModel->picker()->cursorIndex());
        $this->assertNull($cmd);
    }

    public function testUpdateWithEndSetsCursorToLastValidDay(): void
    {
        $model = Model::new(new \DateTimeImmutable('2026-05-01'));

        $keyMsg = new KeyMsg(KeyType::End);
        [$nextModel, $cmd] = $model->update($keyMsg);

        // End lands on the last valid day (May 31 at index 35), not the raw
        // grid position 41 that may be a blank trailing cell.
        $this->assertSame(35, $nextModel->picker()->cursorIndex());
        $this->assertNull($cmd);
    }

    public function testUpdateWithNonKeyMsgReturnsSamePicker(): void
    {
        $model = Model::new(new \DateTimeImmutable('2026-05-01'));

        $msg = new \SugarCraft\Core\Msg\QuitMsg();
        [$nextModel, $cmd] = $model->update($msg);

        $this->assertSame(
            $model->picker()->cursorIndex(),
            $nextModel->picker()->cursorIndex()
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
        $model = new Model($model->picker()->moveCursorRight(), $store);
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
        $this->assertSame(5, $model->picker()->viewMonth());

        [$next, $cmd] = $model->update(new KeyMsg(KeyType::PageDown));
        $this->assertSame(6, $next->picker()->viewMonth());
        $this->assertNull($cmd);

        [$prev, ] = $model->update(new KeyMsg(KeyType::PageUp));
        $this->assertSame(4, $prev->picker()->viewMonth());
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
        $this->assertNull($model->picker()->selectedDate());
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
        $model = new Model($model->picker()->moveCursorRight(), $store);
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
        $this->assertSame(1, $nextModel->picker()->cursorIndex() - $model->picker()->cursorIndex());
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
        $initialIndex = $model->picker()->cursorIndex();

        $msg = new class implements \SugarCraft\Core\Msg {};
        [$nextModel, $cmd] = $model->update($msg);

        $this->assertSame($initialIndex, $nextModel->picker()->cursorIndex());
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
            $picker = $picker->moveCursorRight();
        }
        $picker = $picker->handleKey(DatePicker::KEY_ENTER); // rangeStart set

        // Move to May 5 and set rangeEnd
        for ($i = 0; $i < 4; $i++) {
            $picker = $picker->moveCursorRight();
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
        $initialIndex = $model->picker()->cursorIndex();

        $keyMsg = new KeyMsg(KeyType::Char, 'a');
        [$nextModel, $cmd] = $model->update($keyMsg);

        $this->assertSame($initialIndex, $nextModel->picker()->cursorIndex());
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

        $this->assertTrue($model->picker()->isSelecting(),
            'Enter in single mode must enter selection mode through update()');
        $this->assertSame('2026-05-01', $model->picker()->selectedDate()?->format('Y-m-d'));

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
        $model = $model->update(new KeyMsg(KeyType::End))[0];  // May 31 (index 35)
        $model = $model->update(new KeyMsg(KeyType::Home))[0]; // May 1 (index 5 — first selectable day unbounded)
        $model = $model->update(new KeyMsg(KeyType::Enter))[0];
        $this->assertTrue($model->picker()->isSelecting());

        $model = $model->update(new KeyMsg(KeyType::Enter))[0];

        $events = $store->release();
        $selected = array_values(array_filter($events, static fn(array $e): bool => $e['type'] === PickerEvent::DATE_SELECTED));
        $this->assertCount(1, $selected,
            'Re-entering the same selecting state must not re-record');
        $this->assertSame('2026-05-01', $model->picker()->selectedDate()?->format('Y-m-d'));
    }

    public function testEscapeInSingleModeClearsThroughTheTeaLoop(): void
    {
        $model = Model::new(new \DateTimeImmutable('2026-05-01'));
        $model = $model->update(new KeyMsg(KeyType::Home))[0];
        $model = $model->update(new KeyMsg(KeyType::Enter))[0];
        $this->assertTrue($model->picker()->isSelecting());

        $model = $model->update(new KeyMsg(KeyType::Escape))[0];

        $this->assertFalse($model->picker()->isSelecting());
        $this->assertNull($model->picker()->selectedDate());
    }

    public function testEnterOnOutOfBoundDateAnnouncesRefusal(): void
    {
        // dp-09 flip of the old silent-refusal pin: a refused Enter must not
        // fabricate a date_selected, but the Model channel now announces the
        // refusal as an event (state stays the authoritative channel).
        $store = new EventStore();
        $model = Model::new(new \DateTimeImmutable('2026-05-01'), $store);
        $picker = $model->picker()->withMinDate(new \DateTimeImmutable('2026-05-10'));
        $model = new Model($picker, $store);
        // dp-11: Home clamps to May 10 (the bound); the refusal leg reaches the
        // dimmed May 9 the way arrows allow it — one left. Drain the legitimate
        // cursor_moved transitions so the store shows exactly the refused Enter.
        $model = $model->update(new KeyMsg(KeyType::Home))[0];
        $model = $model->update(new KeyMsg(KeyType::Left))[0];
        $store->release();
        $model = $model->update(new KeyMsg(KeyType::Enter))[0]; // May 9 < min

        $this->assertFalse($model->picker()->isSelecting(),
            'Refused selection must still change no state');
        $events = $store->release();
        $this->assertCount(1, $events);
        $this->assertSame(PickerEvent::SELECTION_REFUSED, $events[0]['type']);
        $this->assertSame('2026-05-09', $events[0]['payload']['date']);
        $this->assertSame('out_of_bounds', $events[0]['payload']['reason']);
    }

    public function testChordedEnterCannotBeRefused(): void
    {
        // Chorded Enter never acts (host-reserved, dp-07), so the refusal
        // announcement must stay silent — deliberate-negative pin.
        $store = new EventStore();
        $model = Model::new(new \DateTimeImmutable('2026-05-01'), $store);
        $model = new Model($model->picker()->withMinDate(new \DateTimeImmutable('2026-05-10')), $store);
        $model = $model->update(new KeyMsg(KeyType::Home))[0];
        $model = $model->update(new KeyMsg(KeyType::Left))[0];
        $store->release();

        $model->update(new KeyMsg(KeyType::Enter, ctrl: true));

        $this->assertFalse($store->hasEvents(),
            'A chorded Enter is ignored, not refused — no event either way');
    }

    public function testEmptyCellFallbackBeyondBoundRefusesWithReason(): void
    {
        // Single mode on a lead-in blank: the fallback is the first of the
        // month, May 1 — before the bound, so the refusal carries that date
        // with reason empty_cell.
        $store = new EventStore();
        $model = Model::new(new \DateTimeImmutable('2026-05-01'), $store);
        $model = new Model($model->picker()->withMinDate(new \DateTimeImmutable('2026-05-10')), $store);
        // Park on the empty lead-in: Home clamps to May 10 (index 14), then
        // walk left past May 1 (index 5) into the blanks (indices 0-4).
        $model = $model->update(new KeyMsg(KeyType::Home))[0];
        for ($i = 0; $i < 14; $i++) {
            $model = $model->update(new KeyMsg(KeyType::Left))[0];
        }
        $this->assertNull($model->picker()->dateAtCursor());
        $store->release();

        $model = $model->update(new KeyMsg(KeyType::Enter))[0];

        $events = $store->release();
        $this->assertCount(1, $events);
        $this->assertSame(PickerEvent::SELECTION_REFUSED, $events[0]['type']);
        $this->assertSame('2026-05-01', $events[0]['payload']['date'],
            'The refused attempt is the fallback date, not a phantom');
        $this->assertSame('empty_cell', $events[0]['payload']['reason']);
        $this->assertNull($model->picker()->selectedDate());
    }

    public function testEmptyCellWithInBoundsFallbackSelectsQuietly(): void
    {
        // Unbounded May: Enter on the lead-in blank falls back to May 1 and
        // SUCCEEDS — empty_cell is a refusal only when the fallback is also
        // out of bounds. Deliberate-negative pin.
        $store = new EventStore();
        $model = Model::new(new \DateTimeImmutable('2026-05-01'), $store);
        $model = $model->update(new KeyMsg(KeyType::Left))[0];
        $this->assertNull($model->picker()->dateAtCursor());
        $store->release();

        $model = $model->update(new KeyMsg(KeyType::Enter))[0];

        $this->assertTrue($model->picker()->isSelecting());
        $this->assertSame('2026-05-01', $model->picker()->selectedDate()?->format('Y-m-d'));
        $events = $store->release();
        $types = array_column($events, 'type');
        $this->assertNotContains(PickerEvent::SELECTION_REFUSED, $types);
        $this->assertContains(PickerEvent::DATE_SELECTED, $types);
    }

    public function testMonthStepRecordsMonthChanged(): void
    {
        $store = new EventStore();
        $model = Model::new(new \DateTimeImmutable('2026-05-01'), $store);

        $model = $model->update(new KeyMsg(KeyType::PageDown))[0];

        $events = $store->release();
        $changed = array_values(array_filter($events, static fn(array $e): bool => $e['type'] === PickerEvent::MONTH_CHANGED));
        $this->assertCount(1, $changed);
        $this->assertSame(['year' => 2026, 'month' => 6], $changed[0]['payload']);

        // Clamped at the bound month the view cannot step — silence pin.
        $bounded = new Model($model->picker()->withMaxDate(new \DateTimeImmutable('2026-06-20')), $store);
        $bounded->update(new KeyMsg(KeyType::PageDown));
        $this->assertFalse($store->hasEvents(),
            'A month step that cannot happen records nothing');
    }

    public function testRangeRestartAndClearAreVisible(): void
    {
        // The third Enter (restart) and Escape (clear) used to be invisible
        // flag-edge blind spots (dp-09).
        $store = new EventStore();
        $model = Model::new(new \DateTimeImmutable('2026-05-01'), $store);
        $model = new Model($model->picker()->withRangeMode(true), $store);

        $model = $model->update(new KeyMsg(KeyType::Enter))[0];  // arm May 1

        // Completed range May 1 - May 31. The cursor hop goes through
        // moveToDate on the picker (a constructor swap, not an update), so no
        // cursor_moved is recorded for it — events below are Enter-channel only.
        $end = $model->picker()->moveToDate(new \DateTimeImmutable('2026-05-31'));
        $model = new Model($end, $store);
        $model = $model->update(new KeyMsg(KeyType::Enter))[0];  // completes
        $events = $store->release();
        $this->assertSame([PickerEvent::RANGE_STARTED, PickerEvent::DATE_SELECTED], array_column($events, 'type'));

        // Third Enter on May 20 restarts: end drops, start moves.
        $restart = $model->picker()->moveToDate(new \DateTimeImmutable('2026-05-20'));
        $model = new Model($restart, $store);
        $model = $model->update(new KeyMsg(KeyType::Enter))[0];
        $events = $store->release();
        $restarted = array_values(array_filter($events, static fn(array $e): bool => $e['type'] === PickerEvent::RANGE_RESTARTED));
        $this->assertCount(1, $restarted);
        $this->assertSame(['from' => '2026-05-01', 'to' => '2026-05-20'], $restarted[0]['payload']);

        // Escape clears the pending restart — announced, never silent.
        $model = $model->update(new KeyMsg(KeyType::Escape))[0];
        $events = $store->release();
        $cleared = array_values(array_filter($events, static fn(array $e): bool => $e['type'] === PickerEvent::RANGE_CLEARED));
        $this->assertCount(1, $cleared);
        $this->assertSame(['from' => '2026-05-20'], $cleared[0]['payload']);
        $this->assertNull($model->picker()->rangeStart());
    }

    public function testCursorMovedCarriesDatedPayload(): void
    {
        $store = new EventStore();
        $model = Model::new(new \DateTimeImmutable('2026-05-01'), $store);

        $model = $model->update(new KeyMsg(KeyType::Right))[0];
        $model = $model->update(new KeyMsg(KeyType::Left))[0]; // back onto May 1
        $model = $model->update(new KeyMsg(KeyType::Left))[0]; // into the empty lead-in

        $events = $store->release();
        $moved = array_values(array_filter($events, static fn(array $e): bool => $e['type'] === PickerEvent::CURSOR_MOVED));
        $this->assertCount(3, $moved);
        $this->assertSame(['index' => 6, 'date' => '2026-05-02'], $moved[0]['payload'],
            'index kept, date added (dp-09)');
        $this->assertSame(['index' => 5, 'date' => '2026-05-01'], $moved[1]['payload']);
        $this->assertSame(['index' => 4, 'date' => null], $moved[2]['payload'],
            'an empty cell moves the cursor with a null date');
    }

    public function testEscapeWithNothingSelectedRecordsNothing(): void
    {
        // Deliberate-negative pin: clearDate() on an already-clear picker is
        // a no-op transition and stays silent (docblock law).
        $store = new EventStore();
        $model = Model::new(new \DateTimeImmutable('2026-05-01'), $store);

        $model->update(new KeyMsg(KeyType::Escape));

        $this->assertFalse($store->hasEvents());
    }

    public function testModelViewDelegatesLayoutArguments(): void
    {
        $model = Model::new(new \DateTimeImmutable('2026-05-01'));

        $this->assertSame(
            $model->picker()->view(40, true),
            $model->view(40, true),
            'view() must forward width/week-number options'
        );
        $this->assertSame($model->picker()->view(), $model->view());
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
