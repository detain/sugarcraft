<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use SugarCraft\Toast\{Alert, Toast, ToastType};
use PHPUnit\Framework\TestCase;

final class ToastPersistentTest extends TestCase
{
    public function testAlertWithNullExpiryIsNotExpired(): void
    {
        // An alert with no expiry time set should never be considered expired
        $alert = new Alert(ToastType::Info, 'persistent message', null);
        $this->assertFalse($alert->isExpired());
    }

    public function testPersistentToastNeverExpires(): void
    {
        // When both expiresAt is null and duration is null,
        // the alert has no expiry (persistent)
        $t = Toast::new(50)
            ->withDuration(null)  // explicitly no auto-dismiss
            ->alert(ToastType::Info, 'I stay forever');

        $queue = $this->getQueue($t);
        $this->assertCount(1, $queue);

        // The alert in the queue should not be expired
        $this->assertFalse($queue[0]->isExpired());
    }

    public function testPersistentAlertSurvivesPrune(): void
    {
        $t = Toast::new(50)
            ->withDuration(null)
            ->alert(ToastType::Success, 'always here')
            ->alert(ToastType::Error, 'expired', \microtime(true) - 1);

        $pruned = $t->pruneExpired();
        $queue = $this->getQueue($pruned);

        // Only the expired alert should be removed
        $this->assertCount(1, $queue);
        $this->assertSame('always here', $queue[0]->message);
    }

    public function testHasActiveAlertWithPersistent(): void
    {
        $t = Toast::new(50)
            ->withDuration(null)
            ->alert(ToastType::Info, 'persistent');

        $this->assertTrue($t->hasActiveAlert());
    }

    public function testViewRendersPersistentAlert(): void
    {
        $t = Toast::new(50)
            ->withPosition(\SugarCraft\Toast\Position::TopLeft)
            ->withDuration(null)
            ->alert(ToastType::Success, 'never goes away');

        $bg = \str_repeat("background\n", 10);
        $result = $t->View($bg);

        $this->assertStringContainsString('never goes away', $result);
        $this->assertStringContainsString('background', $result);
    }

    public function testNullExpiresAtInheritsConfiguredDuration(): void
    {
        // A null $expiresAt means "use the default", NOT "never expire":
        // with a duration configured, the queued alert must carry an expiry.
        $t = Toast::new(50)
            ->withDuration(5.0)
            ->alert(ToastType::Info, 'inherits five seconds', null);

        $queue = $this->getQueue($t);
        $this->assertNotNull($queue[0]->expiresAt);
        $this->assertGreaterThan(\microtime(true), $queue[0]->expiresAt ?? 0.0);
    }

    public function testDurationlessToastLeavesAlertWithoutExpiry(): void
    {
        // The genuine persistent path: no duration configured and no
        // per-call expiry — the alert carries nothing to expire on.
        $t = Toast::new(50)
            ->withDuration(null)
            ->alert(ToastType::Info, 'stays until dismissed', null);

        $queue = $this->getQueue($t);
        $this->assertNull($queue[0]->expiresAt);
    }

    // Helper to access private queue
    private function getQueue(Toast $t): array
    {
        $ref = (new \ReflectionClass($t))->getProperty('queue');
        $ref->setAccessible(true);
        return $ref->getValue($t);
    }
}
