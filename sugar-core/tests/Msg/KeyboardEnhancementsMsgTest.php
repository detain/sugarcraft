<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Msg;

use SugarCraft\Core\Msg\KeyboardEnhancementsMsg;
use PHPUnit\Framework\TestCase;

final class KeyboardEnhancementsMsgTest extends TestCase
{
    public function testFlagsAreReadable(): void
    {
        $msg = new KeyboardEnhancementsMsg(KeyboardEnhancementsMsg::DISAMBIGUATE);
        $this->assertSame(KeyboardEnhancementsMsg::DISAMBIGUATE, $msg->flags);
    }

    public function testHasReturnsTrueWhenSingleFlagSet(): void
    {
        $msg = new KeyboardEnhancementsMsg(KeyboardEnhancementsMsg::REPORT_EVENT_TYPES);
        $this->assertTrue($msg->has(KeyboardEnhancementsMsg::REPORT_EVENT_TYPES));
        $this->assertFalse($msg->has(KeyboardEnhancementsMsg::DISAMBIGUATE));
    }

    public function testHasMatchesAllFlagsInMask(): void
    {
        $combined = KeyboardEnhancementsMsg::DISAMBIGUATE
            | KeyboardEnhancementsMsg::REPORT_EVENT_TYPES
            | KeyboardEnhancementsMsg::REPORT_ASSOCIATED;
        $msg = new KeyboardEnhancementsMsg($combined);

        $this->assertTrue($msg->has(KeyboardEnhancementsMsg::DISAMBIGUATE));
        $this->assertTrue($msg->has(KeyboardEnhancementsMsg::REPORT_EVENT_TYPES));
        $this->assertTrue($msg->has(KeyboardEnhancementsMsg::REPORT_ASSOCIATED));
        $this->assertTrue($msg->has(
            KeyboardEnhancementsMsg::DISAMBIGUATE | KeyboardEnhancementsMsg::REPORT_ASSOCIATED
        ));
        $this->assertFalse($msg->has(KeyboardEnhancementsMsg::REPORT_ALTERNATES));
    }

    public function testHasReturnsFalseWhenSubsetMissing(): void
    {
        $msg = new KeyboardEnhancementsMsg(KeyboardEnhancementsMsg::DISAMBIGUATE);
        $this->assertFalse($msg->has(
            KeyboardEnhancementsMsg::DISAMBIGUATE | KeyboardEnhancementsMsg::REPORT_EVENT_TYPES
        ));
    }

    public function testZeroFlagsHasNothing(): void
    {
        $msg = new KeyboardEnhancementsMsg(0);
        $this->assertFalse($msg->has(KeyboardEnhancementsMsg::DISAMBIGUATE));
    }

    public function testConstantsHaveExpectedValues(): void
    {
        $this->assertSame(1, KeyboardEnhancementsMsg::DISAMBIGUATE);
        $this->assertSame(2, KeyboardEnhancementsMsg::REPORT_EVENT_TYPES);
        $this->assertSame(4, KeyboardEnhancementsMsg::REPORT_ALTERNATES);
        $this->assertSame(8, KeyboardEnhancementsMsg::REPORT_ALL_AS_ESC);
        $this->assertSame(16, KeyboardEnhancementsMsg::REPORT_ASSOCIATED);
    }
}
