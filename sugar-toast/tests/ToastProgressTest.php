<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use SugarCraft\Toast\{Alert, Position, Toast, ToastType};
use PHPUnit\Framework\TestCase;

final class ToastProgressTest extends TestCase
{
    public function testProgressToastRendersProgressBar(): void
    {
        $t = Toast::new(50)
            ->withPosition(Position::TopLeft)
            ->progressToast(ToastType::Info, 'Uploading...', 0.5);

        $bg = \str_repeat("background line\n", 10);
        $result = $t->View($bg, 80, 10);

        $this->assertStringContainsString('Uploading...', $result);
        // Progress bar contains ▰ and █ / ░ characters
        $this->assertMatchesRegularExpression('/[▰░█]/', $result);
    }

    public function testProgressClampedToZero(): void
    {
        $t = Toast::new(50)
            ->withPosition(Position::TopLeft)
            ->progressToast(ToastType::Info, 'Test', -0.5);

        $bg = \str_repeat("x\n", 5);
        $result = $t->View($bg, 80, 10);
        $this->assertStringContainsString('Test', $result);
    }

    public function testProgressClampedToOne(): void
    {
        $t = Toast::new(50)
            ->withPosition(Position::TopLeft)
            ->progressToast(ToastType::Info, 'Test', 1.5);

        $bg = \str_repeat("x\n", 5);
        $result = $t->View($bg, 80, 10);
        $this->assertStringContainsString('Test', $result);
    }

    public function testAlertWithProgressProperty(): void
    {
        $alert = (new Alert(ToastType::Info, 'msg'))->withProgress(0.75);
        $this->assertSame(0.75, $alert->progress);
    }

    public function testAlertProgressDefaultIsNull(): void
    {
        $alert = new Alert(ToastType::Info, 'msg');
        $this->assertNull($alert->progress);
    }

    public function testProgressToastWithExpiry(): void
    {
        $t = Toast::new(50)
            ->withPosition(Position::TopLeft)
            ->progressToast(ToastType::Success, 'Done', 1.0, \microtime(true) + 10);

        $this->assertTrue($this->getQueue($t)[0]->expiresAt !== null);
    }

    public function testProgressToastFluent(): void
    {
        $t1 = Toast::new(50)->progressToast(ToastType::Info, 'a', 0.1);
        $t2 = $t1->progressToast(ToastType::Error, 'b', 0.2);
        $this->assertNotSame($t1, $t2);
        $this->assertCount(2, $this->getQueue($t2));
    }

    private function getQueue(Toast $t): array
    {
        $ref = (new \ReflectionClass($t))->getProperty('queue');
        $ref->setAccessible(true);
        return $ref->getValue($t);
    }
}
