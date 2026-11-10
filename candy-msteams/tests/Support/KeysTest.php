<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\Tests\Support;

use CandyCode\Bundles\MsTeams\Support\Keys;
use PHPUnit\Framework\TestCase;

/**
 * §9.1 grammar: <platform>:<account>:<rest> with the dmScope ladder.
 * Teams conversation ids themselves carry colons ("19:..."), which the
 * three-way-limited parse absorbs into <rest> — pinned below, because the
 * whole sidecar keys on this split surviving an adversarial id.
 */
final class KeysTest extends TestCase
{
    public function testChannelKeysCarryPlatformAndAccount(): void
    {
        self::assertSame(
            'msteams:work:19:c-42@thread.tacv2',
            Keys::channelKey('msteams', 'work', '19:c-42@thread.tacv2'),
        );
    }

    public function testDmScopeLadder(): void
    {
        self::assertSame(
            'msteams:work:dm:main',
            Keys::dmKey('msteams', 'work', 'aad-1', '19:chat-9@thread.v2', Keys::DM_MAIN),
        );
        self::assertSame(
            'msteams:work:dm:aad-1',
            Keys::dmKey('msteams', 'work', 'aad-1', '19:chat-9@thread.v2', Keys::DM_PER_PEER),
        );
        self::assertSame(
            'msteams:work:dm:19:chat-9@thread.v2:aad-1',
            Keys::dmKey('msteams', 'work', 'aad-1', '19:chat-9@thread.v2', Keys::DM_PER_CHANNEL_PEER),
        );
        self::assertSame(
            'msteams:work:dm:acc:19:chat-9@thread.v2:aad-1',
            Keys::dmKey('msteams', 'work', 'aad-1', '19:chat-9@thread.v2', Keys::DM_PER_ACCOUNT_CHANNEL_PEER),
        );
    }

    public function testParseSplitsExactlyThreeSegmentsAndTheRestAbsorbsColons(): void
    {
        $parts = Keys::parse('msteams:work:dm:19:chat-9@thread.v2:aad-1');
        self::assertSame('msteams', $parts['platform']);
        self::assertSame('work', $parts['account']);
        self::assertSame('dm:19:chat-9@thread.v2:aad-1', $parts['rest']);
    }

    public function testAuthorIgnoranceFloor(): void
    {
        self::assertTrue(Keys::ignoreAuthor(true, false, true), 'our own messages never echo back');
        self::assertTrue(Keys::ignoreAuthor(false, true, false), 'bots are refused when allowBots is off');
        self::assertFalse(Keys::ignoreAuthor(false, true, true), 'bots may pass when explicitly allowed');
        self::assertFalse(Keys::ignoreAuthor(false, false, false), 'humans always pass');
    }
}
