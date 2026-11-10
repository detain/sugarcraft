<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Tests\Support;

use CandyCode\Bundles\Discord\Support\Keys;
use PHPUnit\Framework\TestCase;

/**
 * §9.1 grammar: <platform>:<account>:<rest> with the dmScope ladder.
 */
final class KeysTest extends TestCase
{
    public function testChannelKeysCarryPlatformAndAccount(): void
    {
        self::assertSame('discord:work:c-42', Keys::channelKey('discord', 'work', 'c-42'));
    }

    public function testDmScopeLadder(): void
    {
        self::assertSame(
            'discord:work:dm:main',
            Keys::dmKey('discord', 'work', 'u-1', 'c-9', Keys::DM_MAIN),
        );
        self::assertSame(
            'discord:work:dm:u-1',
            Keys::dmKey('discord', 'work', 'u-1', 'c-9', Keys::DM_PER_PEER),
        );
        self::assertSame(
            'discord:work:dm:c-9:u-1',
            Keys::dmKey('discord', 'work', 'u-1', 'c-9', Keys::DM_PER_CHANNEL_PEER),
        );
        self::assertSame(
            'discord:work:dm:acc:c-9:u-1',
            Keys::dmKey('discord', 'work', 'u-1', 'c-9', Keys::DM_PER_ACCOUNT_CHANNEL_PEER),
        );
    }

    public function testParseSplitsExactlyThreeSegments(): void
    {
        $parts = Keys::parse('discord:work:dm:c-9:u-1');
        self::assertSame('discord', $parts['platform']);
        self::assertSame('work', $parts['account']);
        self::assertSame('dm:c-9:u-1', $parts['rest']);
    }

    public function testAuthorIgnoranceFloor(): void
    {
        self::assertTrue(Keys::ignoreAuthor(true, false, true), 'our own messages never echo back');
        self::assertTrue(Keys::ignoreAuthor(false, true, false), 'bots are refused when allowBots is off');
        self::assertFalse(Keys::ignoreAuthor(false, true, true), 'bots may pass when explicitly allowed');
        self::assertFalse(Keys::ignoreAuthor(false, false, false), 'humans always pass');
    }
}
