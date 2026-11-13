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
        $history = $dismissed->history();

        $this->assertCount(2, $history);
        $this->assertSame('First', $history[0]->message);
        $this->assertSame('Second', $history[1]->message);
    }

    public function testDismissReturnsNewInstance(): void
    {
        $t = Toast::new(50)->success('msg');
        $d = $t->dismiss();

        $this->assertNotSame($t, $d);
        $this->assertSame([], $t->history());  // original unchanged
        $this->assertCount(1, $d->history());
    }

    public function testHistoryLogImmutability(): void
    {
        $t1 = Toast::new(50)->success('a')->error('b');
        $t2 = $t1->dismiss();

        $this->assertSame([], $t1->history());
        $this->assertCount(2, $t2->history());
    }

    public function testExpiredAlertsAreRecordedOnDismiss(): void
    {
        // In-step flip (WCAG 2.2.1 recovery accounting, 2026-11-03):
        // dismiss() used to SILENTLY DISCARD expired-but-unpruned alerts — a
        // transient error could expire unseen and leave no recovery trace.
        // Dismissal is an explicit host act, so every queued survivor —
        // expired or not — now rides the history log.
        $t = Toast::new(50)
            ->withPosition(Position::TopLeft)
            ->alert(ToastType::Success, 'keep')
            ->alert(ToastType::Error, 'expired', \microtime(true) - 1);

        $dismissed = $t->dismiss();
        $history = $dismissed->history();

        $this->assertCount(2, $history);
        $this->assertSame('keep', $history[0]->message);
        $this->assertSame('expired', $history[1]->message);
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

    public function testNewFactorySeedsThroughTheFailFastDoor(): void
    {
        // CONV-03: siblings all ship ::new(); HistoryLog had only the bare
        // ctor. The factory delegates, so the ingress law is identical —
        // an empty seed, a valid list, and a garbage payload each behave
        // exactly as `new self(...)` would.
        $this->assertSame([], HistoryLog::new()->all());
        $alert = new Alert(ToastType::Info, 'seeded');
        $log = HistoryLog::new([$alert]);
        $this->assertSame([$alert], $log->all());
        $this->assertSame(1, $log->count());

        $this->expectException(\InvalidArgumentException::class);
        HistoryLog::new(['not-an-alert']);
    }
}
