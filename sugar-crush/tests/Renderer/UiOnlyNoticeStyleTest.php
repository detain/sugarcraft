<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Renderer;

/**
 * Audit 15b-03-rem(b), decision N1: mid-turn notices (queued, refused,
 * runtime, background) stay INLINE where they happened - even between a
 * prompt and its answer - and render in a distinct dim style, so the user can
 * tell them from the system rows the model does see.
 *
 * Before the fix a UI-only notice and an agent-visible system row both
 * rendered as `system: …` in the same colour.
 */
final class UiOnlyNoticeStyleTest extends TestCase
{
    private const ITALIC = "\e[3m";

    /** @return list<string> the frame's rows */
    private static function rows(Chat $chat): array
    {
        return explode("\n", Renderer::render($chat));
    }

    private static function withoutSgr(string $row): string
    {
        return (string) preg_replace('/\e\[[0-9;]*m/', '', $row);
    }

    /** @param list<string> $rows */
    private static function rowContaining(array $rows, string $needle): ?string
    {
        foreach ($rows as $row) {
            if (str_contains(self::withoutSgr($row), $needle)) {
                return $row;
            }
        }

        return null;
    }

    public function testAUiOnlyNoticeRendersWithItsOwnLabelInItalicDim(): void
    {
        $rows = self::rows(new Chat(history: [
            Message::user('first question'),
            Message::notice('Queued (1 waiting)'),
            Message::system('hook context for the model'),
            Message::assistant('first answer'),
        ]));

        $notice = self::rowContaining($rows, 'Queued (1 waiting)');
        $this->assertNotNull($notice);
        $this->assertStringContainsString('notice: Queued (1 waiting)', self::withoutSgr($notice));
        $this->assertStringContainsString(self::ITALIC, $notice, 'the notice is italic');

        $system = self::rowContaining($rows, 'hook context for the model');
        $this->assertNotNull($system);
        $this->assertStringContainsString('system: hook context for the model', self::withoutSgr($system), 'an agent-visible system row keeps its label');
        $this->assertStringNotContainsString(self::ITALIC, $system, 'and is not italic: the two must look different');
    }

    public function testTheNoticeKeepsTheDimColourOfASystemRow(): void
    {
        $rows = self::rows(new Chat(history: [Message::notice('N-ROW'), Message::system('S-ROW')]));

        preg_match('/\e\[38;[0-9;]+m(?=(?:notice|system): )/', (string) self::rowContaining($rows, 'N-ROW'), $noticeColour);
        preg_match('/\e\[38;[0-9;]+m(?=(?:notice|system): )/', (string) self::rowContaining($rows, 'S-ROW'), $systemColour);

        $this->assertNotSame([], $noticeColour, 'the notice carries a foreground colour');
        $this->assertSame($systemColour, $noticeColour, 'the same dim colour - distinct by italics and label, not by brightness');
    }

    public function testNoticesStayInlineBetweenAPromptAndItsAnswer(): void
    {
        $plain = array_map(self::withoutSgr(...), self::rows(new Chat(history: [
            Message::user('PROMPT-ONE'),
            Message::notice('/budget is a command, and commands do not run while a turn is in flight'),
            Message::notice('Queued (1 waiting)'),
            Message::assistant('ANSWER-ONE'),
        ])));

        $at = static function (string $needle) use ($plain): int {
            foreach ($plain as $index => $row) {
                if (str_contains($row, $needle)) {
                    return $index;
                }
            }

            return -1;
        };

        $this->assertGreaterThan($at('PROMPT-ONE'), $at('notice: /budget'));
        $this->assertGreaterThan($at('notice: /budget'), $at('notice: Queued'));
        $this->assertGreaterThan($at('notice: Queued'), $at('ANSWER-ONE'), 'not reordered after the answer');
    }
}
