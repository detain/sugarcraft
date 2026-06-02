<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\Alerts;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\Alerts\Alert;
use SugarCraft\Query\Admin\Alerts\Severity;

/**
 * @covers \SugarCraft\Query\Admin\Alerts\Alert
 */
final class AlertTest extends TestCase
{
    public function testWarningFactoryCreatesWarningSeverityAlert(): void
    {
        $alert = Alert::warning('connection_usage', 'Connection usage high', 0.75, 0.6);

        $this->assertSame(Severity::Warning, $alert->severity);
        $this->assertSame('connection_usage', $alert->metric);
        $this->assertSame('Connection usage high', $alert->message);
        $this->assertSame(0.75, $alert->value);
        $this->assertSame(0.6, $alert->threshold);
        $this->assertInstanceOf(\DateTimeImmutable::class, $alert->firedAt);
    }

    public function testCriticalFactoryCreatesCriticalSeverityAlert(): void
    {
        $alert = Alert::critical('connection_usage', 'Connection usage critical', 0.9, 0.8);

        $this->assertSame(Severity::Critical, $alert->severity);
        $this->assertSame('connection_usage', $alert->metric);
        $this->assertSame('Connection usage critical', $alert->message);
        $this->assertSame(0.9, $alert->value);
        $this->assertSame(0.8, $alert->threshold);
    }

    public function testInfoFactoryCreatesInfoSeverityAlert(): void
    {
        $alert = Alert::info('slow_query', 'Slow query detected', 10.5, 5.0);

        $this->assertSame(Severity::Info, $alert->severity);
        $this->assertSame('slow_query', $alert->metric);
        $this->assertSame('Slow query detected', $alert->message);
        $this->assertSame(10.5, $alert->value);
        $this->assertSame(5.0, $alert->threshold);
    }

    public function testIsCriticalReturnsTrueOnlyForCriticalSeverity(): void
    {
        $critical = Alert::critical('m', 'm', 0.0, 0.0);
        $warning = Alert::warning('m', 'm', 0.0, 0.0);
        $info = Alert::info('m', 'm', 0.0, 0.0);

        $this->assertTrue($critical->isCritical());
        $this->assertFalse($warning->isCritical());
        $this->assertFalse($info->isCritical());
    }

    public function testIsWarningReturnsTrueOnlyForWarningSeverity(): void
    {
        $critical = Alert::critical('m', 'm', 0.0, 0.0);
        $warning = Alert::warning('m', 'm', 0.0, 0.0);
        $info = Alert::info('m', 'm', 0.0, 0.0);

        $this->assertFalse($critical->isWarning());
        $this->assertTrue($warning->isWarning());
        $this->assertFalse($info->isWarning());
    }

    public function testIsInfoReturnsTrueOnlyForInfoSeverity(): void
    {
        $critical = Alert::critical('m', 'm', 0.0, 0.0);
        $warning = Alert::warning('m', 'm', 0.0, 0.0);
        $info = Alert::info('m', 'm', 0.0, 0.0);

        $this->assertFalse($critical->isInfo());
        $this->assertFalse($warning->isInfo());
        $this->assertTrue($info->isInfo());
    }

    public function testToToastMessageFormatsAsExpected(): void
    {
        $alert = Alert::warning('connection_usage', 'Connection usage high', 0.75, 0.6);

        $msg = $alert->toToastMessage();

        $this->assertStringContainsString('connection_usage', $msg);
        $this->assertStringContainsString('Connection usage high', $msg);
        $this->assertStringContainsString('75.0%', $msg);
        $this->assertStringContainsString('60.0%', $msg);
    }

    public function testAlertIsImmutable(): void
    {
        $alert = Alert::warning('connection_usage', 'Connection usage high', 0.75, 0.6);

        // Public readonly properties cannot be modified
        $this->expectException(\Error::class);
        $alert->severity = Severity::Critical;
    }

    public function testFiredAtDefaultsToNow(): void
    {
        $before = new \DateTimeImmutable();
        $alert = Alert::warning('m', 'm', 0.0, 0.0);
        $after = new \DateTimeImmutable();

        $this->assertGreaterThanOrEqual($before, $alert->firedAt);
        $this->assertLessThanOrEqual($after, $alert->firedAt);
    }

    public function testFiredAtCanBeSpecified(): void
    {
        $customTime = new \DateTimeImmutable('2024-01-01 12:00:00');
        $alert = new Alert(
            Severity::Warning,
            'connection_usage',
            'Connection usage high',
            0.75,
            0.6,
            $customTime,
        );

        $this->assertSame($customTime, $alert->firedAt);
    }

    public function testFactoryMethodsProduceDistinctInstances(): void
    {
        $a1 = Alert::warning('m', 'm', 0.0, 0.0);
        $a2 = Alert::warning('m', 'm', 0.0, 0.0);

        $this->assertNotSame($a1, $a2);
    }
}
