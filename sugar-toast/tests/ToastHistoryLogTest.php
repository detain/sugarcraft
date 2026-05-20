<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use SugarCraft\Toast\{Alert, HistoryLog, Position, Toast, ToastType};
use PHPUnit\Framework\TestCase;

final class ToastHistoryLogTest extends TestCase
{
    public function testHistoryLogRecordsDismissedAlerts(): void
    {
        $t = Toast::new(50)
            ->withPosition(Position::TopLeft)
            ->success('First')
            ->error('Second');

        $dismissed = $t->dismiss();
        $history = $dismissed->getHistory();

        $this->assertCount(2, $history);
        $this->assertSame('First', $history[0]->message);
        $this->assertSame('Second', $history[1]->message);
    }

    public function testDismissReturnsNewInstance(): void
    {
        $t = Toast::new(50)->success('msg');
        $d = $t->dismiss();

        $this->assertNotSame($t, $d);
        $this->assertSame([], $t->getHistory());  // original unchanged
        $this->assertCount(1, $d->getHistory());
    }

    public function testHistoryLogImmutability(): void
    {
        $t1 = Toast::new(50)->success('a')->error('b');
        $t2 = $t1->dismiss();

        $this->assertSame([], $t1->getHistory());
        $this->assertCount(2, $t2->getHistory());
    }

    public function testExpiredAlertsNotRecorded(): void
    {
        // Create an already-expired alert
        $t = Toast::new(50)
            ->withPosition(Position::TopLeft)
            ->alert(ToastType::Success, 'keep')
            ->alert(ToastType::Error, 'expired', \microtime(true) - 1);

        $dismissed = $t->dismiss();
        $history = $dismissed->getHistory();

        // Only the non-expired alert should be recorded
        $this->assertCount(1, $history);
        $this->assertSame('keep', $history[0]->message);
    }

    public function testHistoryLogAll(): void
    {
        $log = new HistoryLog();
        $a1 = new Alert(ToastType::Info, 'one');
        $a2 = new Alert(ToastType::Error, 'two');

        $log2 = $log->push($a1);
        $log3 = $log2->push($a2);

        $this->assertSame([], $log->all());
        $this->assertSame([$a1], $log2->all());
        $this->assertSame([$a1, $a2], $log3->all());
    }

    public function testHistoryLogCount(): void
    {
        $log = new HistoryLog();
        $this->assertSame(0, $log->count());

        $log2 = $log->push(new Alert(ToastType::Info, 'a'));
        $this->assertSame(1, $log2->count());
    }

    public function testDismissedFlagStillWorksWithHistory(): void
    {
        $t = Toast::new(50)->success('msg')->dismiss();
        $bg = "background";

        $this->assertSame($bg, $t->View($bg));
    }
}
