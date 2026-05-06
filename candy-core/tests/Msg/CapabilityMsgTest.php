<?php

declare(strict_types=1);

namespace CandyCore\Core\Tests\Msg;

use CandyCore\Core\Msg;
use CandyCore\Core\Msg\CapabilityMsg;
use CandyCore\Core\Msg\ModeReportMsg;
use CandyCore\Core\Msg\TerminalVersionMsg;
use PHPUnit\Framework\TestCase;

final class CapabilityMsgTest extends TestCase
{
    public function testImplementsMsg(): void
    {
        $m = new CapabilityMsg('kitty', "\x1b[?7u");
        $this->assertInstanceOf(Msg::class, $m);
    }

    public function testCarriesCapabilityAndResponse(): void
    {
        $m = new CapabilityMsg('xtversion', 'WezTerm 20240203-110809-5046fc22');
        $this->assertSame('xtversion', $m->capability);
        $this->assertStringContainsString('WezTerm', $m->response);
    }

    public function testReadonlyProperties(): void
    {
        $m = new CapabilityMsg('da', 'CSI ?64;1c');
        $this->expectException(\Error::class);
        // @phpstan-ignore-next-line — intentional readonly violation.
        $m->capability = 'mutated';
    }

    public function testDistinctFromSpecialisedReplies(): void
    {
        $cap = new CapabilityMsg('mode', "\x1b[?2026;1\$y");
        // Same wire data, three different message types — models match by
        // class to route correctly.
        $this->assertNotInstanceOf(TerminalVersionMsg::class, $cap);
        $this->assertNotInstanceOf(ModeReportMsg::class,      $cap);
    }
}
