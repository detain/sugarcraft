<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use SugarCraft\Toast\Action;
use PHPUnit\Framework\TestCase;

final class ActionTest extends TestCase
{
    public function testConstruction(): void
    {
        $called = false;
        $callback = static function () use (&$called) { $called = true; };

        $action = new Action('Confirm', $callback);

        $this->assertSame('Confirm', $action->label);
        $this->assertSame($callback, $action->callback);
    }

    public function testMakeFactory(): void
    {
        $action = Action::make('Cancel', static function () {});
        $this->assertSame('Cancel', $action->label);
    }

    public function testCallbackIsInvoked(): void
    {
        $invoked = false;
        $action = new Action('DoIt', static function () use (&$invoked) { $invoked = true; });

        ($action->callback)();
        $this->assertTrue($invoked);
    }
}
