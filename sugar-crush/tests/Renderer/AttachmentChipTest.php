<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Theme;

/**
 * Audit 15b-15: the `📎` row under a user turn names what it attached - one
 * row, clipped to the pane with the R4 floor at 1, and sanitised, because
 * every name in it is a path the user (or a screenshot tool) chose.
 */
final class AttachmentChipTest extends TestCase
{
    public function testTheTranscriptNamesWhatAPromptAttached(): void
    {
        $prompt = Message::user('look')
            ->attachFile('src/Chat.php', 'x')
            ->attachImage('/tmp/pastes/shot.png', "\x89PNG\r\n\x1a\n", 'image/png');
        $chat = (new Chat(history: [$prompt], backend: new EchoBackend()))->withSize(100, 30);

        $plain = self::plain($chat->view());

        $this->assertStringContainsString('📎 Chat.php · 🖼 shot.png', $plain);
    }

    public function testAPromptWithoutAttachmentsHasNoChip(): void
    {
        $this->assertSame('', self::chip(Message::user('plain'), 80));
    }

    public function testTheChipClipsToEveryWidthWithAFloorOfOne(): void
    {
        $prompt = Message::user('x')
            ->attachFile(str_repeat('very-long-directory/', 10) . 'file-with-a-long-name.php', 'x')
            ->attachImage('screenshot-2026-10-02-at-12.34.56.png', "\x89PNG\r\n\x1a\n", 'image/png');

        foreach ([-3, 0, 1, 2, 5, 13, 40, 120] as $width) {
            $chip = self::chip($prompt, $width);

            $this->assertStringStartsWith("\n", $chip);
            $this->assertStringNotContainsString("\n", substr($chip, 1), "one row at width {$width}");
            $this->assertLessThanOrEqual(max(1, $width), Width::of(substr($chip, 1)), "clipped at width {$width}");
            $this->assertGreaterThanOrEqual(1, Width::of(substr($chip, 1)), "never empty at width {$width}");
        }
    }

    public function testAHostileFileNameIsFoldedToOneInertLine(): void
    {
        $prompt = Message::user('x')->attachFile("/tmp/evil\x1b]0;pwned\x07\nname.txt", 'x');

        $chip = self::chip($prompt, 80);
        $withoutStyling = (string) preg_replace('/\x1b\[[0-9;]*m/', '', $chip);

        $this->assertStringNotContainsString("\x1b", $withoutStyling, 'only the chip\'s own SGR survives');
        $this->assertStringNotContainsString("\x07", $withoutStyling);
        $this->assertStringNotContainsString("\n", substr($chip, 1));
        $this->assertStringContainsString('name.txt', $withoutStyling);
    }

    private static function chip(Message $message, int $width): string
    {
        return (string) (new \ReflectionMethod(Renderer::class, 'attachmentChip'))
            ->invoke(null, $message, Theme::default(), $width);
    }

    private static function plain(string $frame): string
    {
        return (string) preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $frame);
    }
}
