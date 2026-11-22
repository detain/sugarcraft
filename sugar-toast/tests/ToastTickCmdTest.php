<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\TickRequest;
use SugarCraft\Toast\Alert;
use SugarCraft\Toast\Toast;
use SugarCraft\Toast\ToastTickMsg;
use SugarCraft\Toast\ToastType;

/**
 * Toast::tickCmd(): a Cmd::tick() for the soonest live expiry while one is
 * pending, null when nothing is waiting to expire.
 */
final class ToastTickCmdTest extends TestCase
{
    private static function request(\Closure $cmd): TickRequest
    {
        $request = $cmd();
        self::assertInstanceOf(TickRequest::class, $request);

        return $request;
    }

    public function testALiveTimedAlertYieldsATickForItsExpiry(): void
    {
        $toast = Toast::new()->push(Alert::new(ToastType::Error, 'Disk full')->withTtl(3.0));

        $cmd = $toast->tickCmd();
        $this->assertInstanceOf(\Closure::class, $cmd);

        $request = self::request($cmd);
        $this->assertGreaterThan(2.5, $request->seconds);
        $this->assertLessThanOrEqual(3.0, $request->seconds);

        $msg = ($request->produce)();
        $this->assertInstanceOf(ToastTickMsg::class, $msg);
        $this->assertSame($toast->nextExpiry(), $msg->at);
    }

    public function testTheTickTargetsTheSoonestOfSeveralAlerts(): void
    {
        $toast = Toast::new()
            ->push(Alert::new(ToastType::Info, 'late')->withTtl(30.0))
            ->push(Alert::new(ToastType::Info, 'soon')->withTtl(2.0));

        $this->assertLessThanOrEqual(2.0, self::request($toast->tickCmd() ?? self::fail('no tick'))->seconds);
    }

    public function testNoTickOnceEveryTimedAlertHasExpired(): void
    {
        $toast = Toast::new()->push(Alert::new(ToastType::Info, 'gone')->withTtl(0.01));
        \usleep(30_000);

        $this->assertFalse($toast->hasActiveAlert());
        $this->assertNull($toast->tickCmd());
        $this->assertNull($toast->pruneExpired()->tickCmd());
    }

    public function testNoTickForAnEmptyPersistentOrDismissedToast(): void
    {
        $this->assertNull(Toast::new()->tickCmd());
        $this->assertNull(Toast::new()->alert(ToastType::Info, 'no duration set')->tickCmd());
        $this->assertNull(Toast::new()->withDuration(5.0)->alert(ToastType::Info, 'x')->cancelAlert(0)->tickCmd());
        $this->assertNull(Toast::new()->withDuration(5.0)->alert(ToastType::Info, 'x')->dismiss()->tickCmd());
    }

    public function testTheInheritedDurationIsTicked(): void
    {
        $toast = Toast::new()->withDuration(5.0)->alert(ToastType::Info, 'x');

        $this->assertGreaterThan(4.0, self::request($toast->tickCmd() ?? self::fail('no tick'))->seconds);
    }
}
