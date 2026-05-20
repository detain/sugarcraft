<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use SugarCraft\Toast\{Position, SymbolSet, Toast, ToastType};
use PHPUnit\Framework\TestCase;

final class ToastEscCloseTest extends TestCase
{
    public function testDefaultAllowEscToClose(): void
    {
        // Default value should be true
        $t = Toast::new(50);
        $this->assertTrue($this->getAllowEscToClose($t));
    }

    public function testWithAllowEscToCloseTrue(): void
    {
        $t = Toast::new(50)->withAllowEscToClose(true);
        $this->assertTrue($this->getAllowEscToClose($t));
    }

    public function testWithAllowEscToCloseFalse(): void
    {
        $t = Toast::new(50)->withAllowEscToClose(false);
        $this->assertFalse($this->getAllowEscToClose($t));
    }

    public function testWithAllowEscToCloseReturnsNewInstance(): void
    {
        $t = Toast::new(50);
        $t2 = $t->withAllowEscToClose(false);
        $this->assertNotSame($t, $t2);
        // Original unchanged
        $this->assertTrue($this->getAllowEscToClose($t));
    }

    public function testHasActiveAlertWhenEmpty(): void
    {
        $t = Toast::new(50);
        $this->assertFalse($t->hasActiveAlert());
    }

    public function testHasActiveAlertWithActiveAlert(): void
    {
        $t = Toast::new(50)->success('Hello');
        $this->assertTrue($t->hasActiveAlert());
    }

    public function testHasActiveAlertWithOnlyExpiredAlert(): void
    {
        // Create an already-expired alert
        $t = Toast::new(50)->alert(ToastType::Info, 'Expired', \microtime(true) - 1);
        $this->assertFalse($t->hasActiveAlert());
    }

    public function testHasActiveAlertWithMixedAlerts(): void
    {
        // One active, one expired
        $t = Toast::new(50)
            ->alert(ToastType::Success, 'Active')
            ->alert(ToastType::Error, 'Expired', \microtime(true) - 1);

        $this->assertTrue($t->hasActiveAlert());
    }

    public function testHasActiveAlertAfterClear(): void
    {
        $t = Toast::new(50)->success('Hello')->clear();
        $this->assertFalse($t->hasActiveAlert());
    }

    public function testHasActiveAlertAfterDismiss(): void
    {
        $t = Toast::new(50)->success('Hello')->dismiss();
        // dismiss() hides rendering but queue still has the alert
        // hasActiveAlert checks queue, not dismissed flag
        $this->assertTrue($t->hasActiveAlert());
    }

    public function testFluentWithAllowEscToClose(): void
    {
        $t = Toast::new(50)
            ->withMaxWidth(80)
            ->withMinWidth(20)
            ->withPosition(Position::BottomRight)
            ->withSymbolSet(SymbolSet::Ascii)
            ->withDuration(5.0)
            ->withAllowEscToClose(false);

        $this->assertInstanceOf(Toast::class, $t);
        $this->assertFalse($this->getAllowEscToClose($t));
    }

    // Helper to access private property
    private function getAllowEscToClose(Toast $t): bool
    {
        $ref = (new \ReflectionClass($t))->getProperty('allowEscToClose');
        $ref->setAccessible(true);
        return $ref->getValue($t);
    }
}
