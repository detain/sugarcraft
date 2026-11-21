<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Chat;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\TickRequest;

/**
 * The headless TickRequest arm in HandlesRunControl answers a paced hop
 * itself when no loop can arm it. A TickHandle promises its produce closure
 * never runs after cancel(); the arm must honour that the way Program and
 * ProgramSimulator already do, or a cancelled tick dispatches a stale Msg.
 */
final class HeadlessTickCancelTest extends TestCase
{
    public function testACancelledTickIsNotProducedByTheHeadlessArm(): void
    {
        $ran = false;
        $handle = Cmd::cancellableTick(0.0, static function () use (&$ran): Msg {
            $ran = true;
            return new QuitMsg();
        });
        $request = ($handle->cmd())();
        $this->assertInstanceOf(TickRequest::class, $request);

        $handle->cancel();
        [, $cmd] = (new Chat())->update($request);

        $this->assertFalse($ran, 'produce must not run after cancel()');
        $this->assertNull($cmd, 'a cancelled tick dispatches nothing');
    }

    public function testALiveTickIsProducedByTheHeadlessArmControl(): void
    {
        // Control: an uncancelled handle still produces, so the guard above
        // is the only reason the cancelled case stays silent.
        $handle = Cmd::cancellableTick(0.0, static fn (): Msg => new QuitMsg());
        $request = ($handle->cmd())();
        $this->assertInstanceOf(TickRequest::class, $request);

        [, $cmd] = (new Chat())->update($request);

        $this->assertIsCallable($cmd);
        $this->assertInstanceOf(QuitMsg::class, $cmd());
    }
}
