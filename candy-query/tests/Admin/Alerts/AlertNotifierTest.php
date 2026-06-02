<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\Alerts;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\Alerts\Alert;
use SugarCraft\Query\Admin\Alerts\AlertNotifier;
use SugarCraft\Query\Admin\Alerts\Severity;
use SugarCraft\Toast\Toast;
use SugarCraft\Toast\Position;

/**
 * @covers \SugarCraft\Query\Admin\Alerts\AlertNotifier
 */
final class AlertNotifierTest extends TestCase
{
    public function testNewReturnsMutedNotifierWithNoFactory(): void
    {
        $notifier = AlertNotifier::new();

        $this->assertTrue($notifier->isMuted());
        $this->assertNull($notifier->toast());
        $this->assertFalse($notifier->hasActiveAlert());
    }

    public function testWithMutedReturnsNewInstanceWithMuteState(): void
    {
        $n1 = AlertNotifier::new();
        $n2 = $n1->withMuted(false);

        $this->assertTrue($n1->isMuted());
        $this->assertFalse($n2->isMuted());
    }

    public function testNotifyWithMutedDoesNothing(): void
    {
        $notifier = AlertNotifier::new()->withMuted(true);
        $alert = Alert::warning('test_metric', 'Test message', 0.75, 0.6);

        $result = $notifier->notify($alert);

        // Returns same instance since nothing changed
        $this->assertSame($notifier, $result);
        $this->assertFalse($result->hasActiveAlert());
    }

    public function testNotifyWithNoFactoryDoesNothing(): void
    {
        $notifier = AlertNotifier::new();
        $alert = Alert::warning('test_metric', 'Test message', 0.75, 0.6);

        $result = $notifier->notify($alert);

        // Returns same instance since no factory
        $this->assertSame($notifier, $result);
    }

    public function testNotifyWithFactoryCreatesToastAlert(): void
    {
        $toastFactory = fn(): Toast => Toast::new(50);
        $notifier = AlertNotifier::new($toastFactory)->withMuted(false);
        $alert = Alert::warning('connection_usage', 'Connection usage high', 0.75, 0.6);

        $result = $notifier->notify($alert);

        // Returns new instance
        $this->assertNotSame($notifier, $result);
        $this->assertTrue($result->hasActiveAlert());
        $this->assertNotNull($result->toast());
    }

    public function testNotifyWarningCreatesAlert(): void
    {
        $toastFactory = fn(): Toast => Toast::new(50);
        $notifier = AlertNotifier::new($toastFactory)->withMuted(false);

        $result = $notifier->notifyWarning('High memory usage');

        $this->assertNotSame($notifier, $result);
        $this->assertTrue($result->hasActiveAlert());
    }

    public function testNotifyCriticalCreatesAlert(): void
    {
        $toastFactory = fn(): Toast => Toast::new(50);
        $notifier = AlertNotifier::new($toastFactory)->withMuted(false);

        $result = $notifier->notifyCritical('Critical system error');

        $this->assertNotSame($notifier, $result);
        $this->assertTrue($result->hasActiveAlert());
    }

    public function testNotifyErrorCreatesAlert(): void
    {
        $toastFactory = fn(): Toast => Toast::new(50);
        $notifier = AlertNotifier::new($toastFactory)->withMuted(false);

        $result = $notifier->notifyError('Database connection failed');

        $this->assertNotSame($notifier, $result);
        $this->assertTrue($result->hasActiveAlert());
    }

    public function testNotifyInfoCreatesAlert(): void
    {
        $toastFactory = fn(): Toast => Toast::new(50);
        $notifier = AlertNotifier::new($toastFactory)->withMuted(false);

        $result = $notifier->notifyInfo('System update available');

        $this->assertNotSame($notifier, $result);
        $this->assertTrue($result->hasActiveAlert());
    }

    public function testMultipleAlertsAreChained(): void
    {
        $toastFactory = fn(): Toast => Toast::new(50);
        $notifier = AlertNotifier::new($toastFactory)->withMuted(false);

        $result = $notifier
            ->notifyWarning('First warning')
            ->notifyCritical('Second critical')
            ->notifyInfo('Third info');

        $this->assertNotSame($notifier, $result);
        $this->assertTrue($result->hasActiveAlert());
    }

    public function testWithToastFactoryReturnsNewInstance(): void
    {
        $n1 = AlertNotifier::new();
        $n2 = $n1->withToastFactory(fn(): Toast => Toast::new(50));

        $this->assertNotSame($n1, $n2);
        // Original still muted/no factory
        $this->assertTrue($n1->isMuted());
        $this->assertNull($n1->toast());
    }

    public function testViewReturnsBackgroundWhenNoToast(): void
    {
        $notifier = AlertNotifier::new();
        $background = "Some terminal output";

        $result = $notifier->view($background);

        $this->assertSame($background, $result);
    }

    public function testViewCompositesToastOverBackground(): void
    {
        $toastFactory = fn(): Toast => Toast::new(50)->withPosition(Position::TopLeft);
        $notifier = AlertNotifier::new($toastFactory)->withMuted(false);
        $notifier = $notifier->notifyWarning('Test alert');
        $background = "Some terminal output\nLine 2\nLine 3";

        $result = $notifier->view($background, 80, 24);

        // Result should contain toast content (the alert box)
        $this->assertStringContainsString('Test alert', $result);
        // When alerts are active, the toast renders over the viewport
        // which may or may not preserve the background depending on
        // buffer dimensions vs content dimensions
        $this->assertNotSame($background, $result);
    }

    public function testViewWithCustomViewportSize(): void
    {
        $toastFactory = fn(): Toast => Toast::new(50);
        $notifier = AlertNotifier::new($toastFactory)->withMuted(false);
        $notifier = $notifier->notifyInfo('Test');
        $background = "Small viewport";

        $result = $notifier->view($background, 40, 10);

        // Should not throw, should return composite
        $this->assertIsString($result);
    }

    public function testToastReturnsNullWhenNoAlertsSent(): void
    {
        $notifier = AlertNotifier::new(fn(): Toast => Toast::new(50));

        $this->assertNull($notifier->toast());
    }

    public function testToastReturnsToastAfterAlert(): void
    {
        $toastFactory = fn(): Toast => Toast::new(50);
        $notifier = AlertNotifier::new($toastFactory)->withMuted(false);
        $notifier = $notifier->notifyInfo('Test message');

        $this->assertNotNull($notifier->toast());
        $this->assertInstanceOf(Toast::class, $notifier->toast());
    }

    public function testChainingPreservesPreviousAlerts(): void
    {
        $toastFactory = fn(): Toast => Toast::new(50);
        $notifier = AlertNotifier::new($toastFactory)->withMuted(false);

        $step1 = $notifier->notifyWarning('First');
        $step2 = $step1->notifyCritical('Second');
        $step3 = $step2->notifyInfo('Third');

        // All steps should have alerts
        $this->assertTrue($step1->hasActiveAlert());
        $this->assertTrue($step2->hasActiveAlert());
        $this->assertTrue($step3->hasActiveAlert());
    }

    public function testWithDefaultsCreatesMutedNotifierByDefault(): void
    {
        $notifier = AlertNotifier::withDefaults();

        // withDefaults() should create a muted notifier by default for safety
        $this->assertTrue($notifier->isMuted());
        // toast() is null before any alert is sent
        $this->assertNull($notifier->toast());
    }

    public function testWithDefaultsCanBeUnmuted(): void
    {
        $notifier = AlertNotifier::withDefaults(muted: false);

        $this->assertFalse($notifier->isMuted());
        // toast() is null before any alert is sent
        $this->assertNull($notifier->toast());
    }

    public function testWithDefaultsNotifiesWhenUnmuted(): void
    {
        $notifier = AlertNotifier::withDefaults(muted: false);
        $result = $notifier->notifyWarning('Test alert');

        $this->assertNotSame($notifier, $result);
        $this->assertTrue($result->hasActiveAlert());
    }

    public function testWithDefaultsDoesNotNotifyWhenMuted(): void
    {
        $notifier = AlertNotifier::withDefaults(muted: true);
        $result = $notifier->notifyWarning('Test alert');

        // Returns same instance when muted
        $this->assertSame($notifier, $result);
        $this->assertFalse($result->hasActiveAlert());
    }
}
