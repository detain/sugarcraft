<?php

declare(strict_types=1);

namespace SugarCraft\Calendar\Tests;

use SugarCraft\Calendar\DatePicker;
use SugarCraft\Calendar\EventStore;
use SugarCraft\Calendar\Model;
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

        $this->assertSame(0, $nextModel->picker()->CursorIndex());
        $this->assertNull($cmd);
    }

    public function testUpdateWithEndSetsCursorToFortyOne(): void
    {
        $model = Model::new(new \DateTimeImmutable('2026-05-01'));

        $keyMsg = new KeyMsg(KeyType::End);
        [$nextModel, $cmd] = $model->update($keyMsg);

        $this->assertSame(41, $nextModel->picker()->CursorIndex());
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
        $this->assertSame('cursor_moved', $events[0]['type']);
    }

    public function testModelRecordsDateSelectedInRangeMode(): void
    {
        $store = new EventStore();
        // Build a model with a picker that has range mode enabled and cursor on a valid day.
        // For May 2026, the first day (Friday May 1) is at grid index 5.
        $picker = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->withRangeMode(true);

        // Use GoToPreviousMonth/GoToNextMonth to find a month where the 1st is at index 0.
        // Actually, let's just use SetTime to navigate to a known state.
        // Alternatively, directly test the underlying picker to ensure our assumptions are correct.
        $testPicker = $picker->handleKey(DatePicker::KEY_HOME);
        for ($i = 0; $i < 5; $i++) {
            $testPicker = $testPicker->MoveCursorRight();
        }
        // Now testPicker is at index 5 (May 1) with range mode

        $model = new Model($testPicker, $store);

        // First Enter: sets rangeStart (first date in range)
        $model = $model->update(new KeyMsg(KeyType::Enter))[0];
        $this->assertFalse($store->hasEvents(), 'No event on first Enter (rangeStart set, no date_selected yet)');
        $this->assertNotNull($model->picker()->rangeStart(), 'rangeStart should be set');

        // Move to a new day and press Enter again to set rangeEnd
        $model = new Model($model->picker()->MoveCursorRight(), $store);
        $model = $model->update(new KeyMsg(KeyType::Enter))[0];

        $this->assertTrue($store->hasEvents(), 'date_selected should be recorded after rangeEnd is set');
        $events = $store->release();
        $this->assertCount(1, $events);
        $this->assertSame('date_selected', $events[0]['type']);
    }

    public function testModelDoesNotRecordEventWhenNoStore(): void
    {
        $model = Model::new(new \DateTimeImmutable('2026-05-01'));

        $keyMsg = new KeyMsg(KeyType::Right);
        [$nextModel, $cmd] = $model->update($keyMsg);

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
}
