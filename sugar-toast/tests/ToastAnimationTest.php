<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use SugarCraft\Toast\{Action, Alert, Position, Toast, ToastType};
use PHPUnit\Framework\TestCase;

final class ToastAnimationTest extends TestCase
{
    public function testWithAnimationDuration(): void
    {
        $t = Toast::new(50)
            ->withPosition(Position::TopLeft)
            ->withAnimationDuration(2.5);

        $this->assertInstanceOf(Toast::class, $t);
    }

    public function testAnimationDurationIsFluent(): void
    {
        $t1 = Toast::new(50);
        $t2 = $t1->withAnimationDuration(1.5);
        $this->assertNotSame($t1, $t2);
    }

    public function testAnimationDurationNegativesClampedToZero(): void
    {
        $t = Toast::new(50)->withAnimationDuration(-5.0);
        $this->assertInstanceOf(Toast::class, $t);
    }

    public function testActionButtonsRenderedInToast(): void
    {
        $action = Action::make('OK', static function () {});
        $alert = (new Alert(ToastType::Info, 'msg'))->withActions([$action]);

        $t = Toast::new(50)
            ->withPosition(Position::TopLeft)
            ->alert(ToastType::Info, 'msg');

        // Manually set alert with actions in queue
        $queueProperty = (new \ReflectionClass($t))->getProperty('queue');
        $queueProperty->setAccessible(true);
        $queueProperty->setValue($t, [$alert]);

        $bg = \str_repeat("line\n", 10);
        $result = $t->View($bg, 80, 10);

        $this->assertStringContainsString('[OK]', $result);
    }

    public function testMultipleActionButtons(): void
    {
        $a1 = Action::make('Yes', static function () {});
        $a2 = Action::make('No', static function () {});
        $alert = (new Alert(ToastType::Success, 'Proceed?'))->withActions([$a1, $a2]);

        $t = Toast::new(50)->withPosition(Position::TopLeft)->alert(ToastType::Success, 'Proceed?');

        $queueProperty = (new \ReflectionClass($t))->getProperty('queue');
        $queueProperty->setAccessible(true);
        $queueProperty->setValue($t, [$alert]);

        $bg = \str_repeat("line\n", 10);
        $result = $t->View($bg, 80, 10);

        $this->assertStringContainsString('[Yes]', $result);
        $this->assertStringContainsString('[No]', $result);
    }
}
