<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Renderer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\ToolResult;

/**
 * Audit 15b-28: a bidi override or zero-width character in model- or
 * tool-authored text must not reach the frame raw. U+202E makes the terminal
 * paint the rest of the row reversed ("Trojan Source"), so a tool row or a
 * permission prompt would read differently from what runs; a zero-width space
 * makes two different paths look identical.
 *
 * The rows go through candy-core's `Sanitize::untrustedForDisplay()` (tool
 * and transcript rows) and `Sanitize::visibleControls()` (the permission
 * modal), which render these codepoints as `<U+XXXX>` markers.
 */
final class BidiOverrideFrameTest extends TestCase
{
    private const PAYLOAD = "rm \u{202E}hs.txt\u{202C} pay\u{200B}load";

    /** @return array<string, array{\Closure(string): Chat}> */
    public static function rowKinds(): array
    {
        $chat = static fn (array $history): Chat => (new Chat(history: $history))->withSize(100, 40);

        return [
            'user' => [static fn (string $t): Chat => $chat([Message::user($t, 0)])],
            'assistant' => [static fn (string $t): Chat => $chat([Message::assistant($t)])],
            'tool description' => [static fn (string $t): Chat => $chat([
                Message::assistant('')->withToolResults([
                    ToolResult::ok('bash', 'ok', 'call_1')->withDescription($t),
                ]),
            ])],
            'expanded tool result' => [static fn (string $t): Chat => $chat([
                Message::assistant('')->withToolResults([ToolResult::ok('bash', $t, 'call_1')]),
            ])->toggleToolOutput('call_1')],
            'tool error' => [static fn (string $t): Chat => $chat([
                Message::assistant('')->withToolResults([ToolResult::error('bash', $t, 'call_1')]),
            ])],
        ];
    }

    /**
     * @param \Closure(string): Chat $build
     */
    #[DataProvider('rowKinds')]
    public function testNoRawOverrideOrZeroWidthSpaceReachesTheFrame(\Closure $build): void
    {
        $frame = Renderer::render($build(self::PAYLOAD));

        $this->assertStringNotContainsString("\u{202E}", $frame);
        $this->assertStringNotContainsString("\u{202C}", $frame);
        $this->assertStringNotContainsString("\u{200B}", $frame);
        // Guards the guard: the row was painted, with the markers in place.
        $plain = Ansi::strip($frame);
        $this->assertStringContainsString('<U+202E>hs.txt<U+202C>', $plain);
        $this->assertStringContainsString('pay<U+200B>load', $plain);
    }

    public function testThePermissionModalShowsTheOverride(): void
    {
        $method = new \ReflectionMethod(Renderer::class, 'wrapPermissionText');
        $out = (string) $method->invoke(null, self::PAYLOAD, 60);

        $this->assertStringNotContainsString("\u{202E}", $out);
        $this->assertStringContainsString('rm <U+202E>hs.txt<U+202C> pay<U+200B>load', $out);
    }

    public function testAnEmojiZwjSequenceStillRendersWhole(): void
    {
        $plain = Ansi::strip(Renderer::render(
            (new Chat(history: [Message::user("dev \u{1F469}\u{200D}\u{1F4BB} ok", 0)]))->withSize(100, 40),
        ));

        $this->assertStringContainsString("\u{1F469}\u{200D}\u{1F4BB}", $plain);
    }
}
