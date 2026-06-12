<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\Dashboard;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\Alerts\Alert;
use SugarCraft\Query\Admin\Alerts\AlertNotifierInterface;
use SugarCraft\Query\Admin\Dashboard\DashboardPage;
use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\Db\Version;
use SugarCraft\Toast\Toast;

/**
 * Test double that records how many alerts were dispatched.
 *
 * AlertNotifier is final, so before AlertNotifierInterface existed there was
 * no way to count notify() calls — which is exactly why the dedup test below
 * was deferred in the candy-query audit.
 */
final class CountingAlertNotifier implements AlertNotifierInterface
{
    public int $notifyCount = 0;

    public function notify(Alert $alert): self
    {
        $this->notifyCount++;
        return $this;
    }

    public function notifyWarning(string $message): self
    {
        return $this;
    }

    public function notifyCritical(string $message): self
    {
        return $this;
    }

    public function notifyError(string $message): self
    {
        return $this;
    }

    public function notifyInfo(string $message): self
    {
        return $this;
    }

    public function isMuted(): bool
    {
        return false;
    }

    public function withMuted(bool $muted): self
    {
        return $this;
    }

    public function view(string $background, int $viewportWidth = 80, int $viewportHeight = 24): string
    {
        return $background;
    }

    public function toast(): ?Toast
    {
        return null;
    }

    public function hasActiveAlert(): bool
    {
        return $this->notifyCount > 0;
    }
}

/**
 * Regression cover for DashboardPage::checkAlerts() dedup.
 *
 * A breach that persists across poll ticks must fire exactly one toast,
 * not one per tick (the alert-storm bug the dedup tracking prevents).
 */
final class DashboardAlertDedupTest extends TestCase
{
    private function makePage(CountingAlertNotifier $notifier): DashboardPage
    {
        $context = $this->createMock(ServerContextInterface::class);
        $context->method('flavor')->willReturn(Flavor::MySQL);
        $context->method('statusVariables')->willReturn([]);
        $context->method('serverVariables')->willReturn([]);
        $context->method('statusVariablesTs')->willReturn(0.0);
        $context->method('version')->willReturn(Version::parse('8.0.36'));
        $context->method('versionString')->willReturn('8.0.36');

        return (new DashboardPage($context))->withAlertNotifier($notifier);
    }

    /**
     * @param array<string, string> $status
     * @param array<string, string> $server
     */
    private function tick(DashboardPage $page, array $status, array $server): void
    {
        $method = new \ReflectionMethod($page, 'checkAlerts');
        $method->setAccessible(true);
        $method->invoke($page, $status, $server);
    }

    public function testPersistentBreachNotifiesOnlyOnce(): void
    {
        $notifier = new CountingAlertNotifier();
        $page = $this->makePage($notifier);

        // Threads_connected at 100% of max_connections → fires max_connections_usage.
        $status = ['Threads_connected' => '50'];
        $server = ['max_connections' => '50'];

        $this->tick($page, $status, $server);
        $this->assertSame(1, $notifier->notifyCount, 'first breach should notify once');

        $this->tick($page, $status, $server);
        $this->assertSame(1, $notifier->notifyCount, 'persistent breach must not re-notify');
    }

    public function testClearedBreachReNotifiesWhenItRecurs(): void
    {
        $notifier = new CountingAlertNotifier();
        $page = $this->makePage($notifier);

        $this->tick($page, ['Threads_connected' => '50'], ['max_connections' => '50']);
        $this->assertSame(1, $notifier->notifyCount);

        // Breach clears (ratio 0) — no notify, and the key is forgotten.
        $this->tick($page, ['Threads_connected' => '0'], ['max_connections' => '50']);
        $this->assertSame(1, $notifier->notifyCount, 'a cleared breach does not notify');

        // Same breach recurs — newly breached again, so it re-notifies.
        $this->tick($page, ['Threads_connected' => '50'], ['max_connections' => '50']);
        $this->assertSame(2, $notifier->notifyCount, 'a recurring breach re-notifies');
    }
}
