<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use SugarCraft\Toast\{Action, Alert, Position, Toast, ToastType};
use PHPUnit\Framework\TestCase;

/**
 * Pins that action buttons render inside the toast box: an Alert carrying
 * actions is injected into the live queue (reflection seam mirroring what
 * push()/alert(actions:) install) and view() must paint each `[Label]`.
 *
 * The upper-case `View()` spellings here are deliberate: they double as
 * coverage for PHP's case-insensitive method dispatch, which keeps the
 * legacy sugar-dashboard/candy-sql call sites resolving to `view()`.
 * There is no animation surface in this lib — the file is named for what
 * it actually pins.
 */
final class ToastActionButtonRenderTest extends TestCase
{
    public function testActionButtonsRenderedInToast(): void
    {
        $action = Action::new('OK', static function () {});
        $alert = (new Alert(ToastType::Info, 'msg'))->withActions([$action]);

        $t = Toast::new(50)
            ->withPosition(Position::TopLeft)
            ->alert(ToastType::Info, 'msg');

        // Replace the queue entry with the action-carrying twin.
        $queueProperty = (new \ReflectionClass($t))->getProperty('queue');
        $queueProperty->setAccessible(true);
        $queueProperty->setValue($t, [$alert]);

        $bg = \str_repeat("line\n", 10);
        $result = $t->View($bg, 80, 10);

        $this->assertStringContainsString('[OK]', $result);
    }

    public function testMultipleActionButtons(): void
    {
        $a1 = Action::new('Yes', static function () {});
        $a2 = Action::new('No', static function () {});
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
