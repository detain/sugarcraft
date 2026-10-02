<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Context\ContextCompactor;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Util\TokenEstimate;

/**
 * Audit 15b-13-rem: Chat's estimate became script-weighted
 * ({@see TokenEstimate}), but {@see ContextCompactor} kept counting
 * codepoints/4, so its tiers - the 70% reminder, the 85% rewrite, the 95%
 * block and the intra-exchange rescue - read a CJK or emoji session at a
 * quarter of what Chat (and the provider) count. The 70% reminder fired late
 * for exactly the users the Chat-side fix was for.
 */
final class CompactorScriptWeightTest extends TestCase
{
    /** 10,000 ideographs: ~10,000 tokens script-weighted, 2,500 on codepoints/4. */
    private static function cjk(int $chars = 10_000): string
    {
        return str_repeat('漢字', intdiv($chars, 2));
    }

    public function testTheReminderTierCountsCjkAtOneTokenPerIdeograph(): void
    {
        $messages = [['role' => 'user', 'content' => self::cjk()]];

        // 10,010 of a 13,000-token window is 77%: past the 70% reminder. On
        // codepoints/4 it read 2,510 - 19%.
        $this->assertTrue(ContextCompactor::new()->shouldSendReminder($messages, 13_000));
    }

    public function testTheCompactionAndBlockingTiersAgreeWithChatsEstimate(): void
    {
        $compactor = ContextCompactor::new();
        $messages = [['role' => 'user', 'content' => self::cjk()]];

        $this->assertTrue($compactor->shouldCompact($messages, 11_000), '10,010 of 11,000 is past 85%');
        $this->assertTrue($compactor->shouldCompactForeground($messages, 10_500), 'and past 95% of 10,500');
    }

    public function testAsciiTiersAreUnchanged(): void
    {
        $compactor = ContextCompactor::new();
        // 4,000 ASCII chars: 1,000 + 10, the old figure exactly.
        $messages = [['role' => 'user', 'content' => str_repeat('a', 4_000)]];

        $this->assertTrue($compactor->shouldSendReminder($messages, 1_440), '1,010 >= 70% of 1,440 (1,008)');
        $this->assertFalse($compactor->shouldSendReminder($messages, 1_445), '1,010 < 70% of 1,445 (1,011)');
    }

    /**
     * The rescue trims an oversized message to a share of the window. Its
     * budget was characters at four per token, so with a script-weighted count
     * a CJK giant would come back at four times its share - still over the
     * tier, and the rescue would refuse. The share is now held in tokens.
     */
    public function testTheRescueTrimsACjkGiantUnderTheBlockingTier(): void
    {
        $compactor = ContextCompactor::new();
        $limit = 10_000;
        $messages = [
            ['role' => 'user', 'content' => 'please read this'],
            ['role' => 'assistant', 'content' => self::cjk(20_000)],
        ];

        $truncated = $compactor->truncateOversizedExchange($messages, $limit);

        $this->assertNotSame($messages, $truncated, 'a 20,000-token message alone is past the blocking tier');
        $this->assertFalse($compactor->shouldCompactForeground($truncated, $limit), 'and the rescue gets it under');
        $this->assertLessThan(9_500, TokenEstimate::ofText($truncated[1]['content']) + 10);
        $this->assertStringContainsString('characters truncated to fit the context window', $truncated[1]['content']);
        $this->assertStringStartsWith('漢字漢字', $truncated[1]['content'], 'the head is kept');
        $this->assertSame('please read this', $truncated[0]['content'], 'the small message is untouched');
    }

    /**
     * Driven through Chat: a CJK session at ~77% of its window gets the 70%
     * reminder beside its next prompt.
     */
    public function testAChatOverTheReminderTierInCjkGetsTheReminder(): void
    {
        $chat = new Chat(
            history: [Message::user(self::cjk(78_000)), Message::assistant('了解')],
            inputBuf: 'next',
            backend: new EchoBackend(),
        );

        [$next] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $text = implode("\n", array_map(static fn(Message $m): string => $m->content, $next->history));
        $this->assertStringContainsString('Heads up: this conversation has grown to ~', $text);
    }
}
