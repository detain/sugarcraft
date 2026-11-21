<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Tests\Bridge;

use PHPUnit\Framework\TestCase;
use SugarCraft\Plugins\Bridge\ConversationKey;

final class ConversationKeyTest extends TestCase
{
    public function testParseSplitsPlatformAccountAndRestOnTheFirstTwoColons(): void
    {
        $this->assertSame(
            ['platform' => 'msteams', 'account' => 'acc', 'rest' => '19:topic@thread.v2:peer'],
            ConversationKey::parse('msteams:acc:19:topic@thread.v2:peer'),
        );
    }

    public function testParseOfAShortKeyYieldsEmptySegmentsAndNeverThrows(): void
    {
        $this->assertSame(['platform' => 'solo', 'account' => '', 'rest' => ''], ConversationKey::parse('solo'));
        $this->assertSame(['platform' => 'discord', 'account' => 'a', 'rest' => ''], ConversationKey::parse('discord:a'));
        $this->assertSame(['platform' => '', 'account' => '', 'rest' => ''], ConversationKey::parse(''));
    }
}
