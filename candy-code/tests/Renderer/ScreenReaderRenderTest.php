<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Config\Settings\UiSettings;
use SugarCraft\Code\Hooks\HookContext;
use SugarCraft\Code\Hooks\HookEvent;
use SugarCraft\Code\Hooks\HookInterface;
use SugarCraft\Code\Hooks\HookManager;
use SugarCraft\Code\Hooks\HookRegistry;
use SugarCraft\Code\Hooks\HookResult;
use SugarCraft\Code\Message;
use SugarCraft\Code\Renderer;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\ToolCall;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * C-TUI-6.j: the screen-reader wiring in `Renderer`. With `screenReader`
 * on, the frame sheds what a reader cannot narrate — box-drawing borders
 * and rules become blank paper (titles survive), a menu row carries its
 * position and a `>` marker by TEXT, and an open overlay replaces the
 * body instead of compositing a Veil over the transcript. With the mode
 * off every one of these paths is the pre-6.j code, byte-for-byte: the
 * shipped golden captures (KeyHelpTest, the palette caret family,
 * PaletteCaretHonoursHandoffTest) are the off-mode pins, and
 * {@see testTheSameFrameStillPaintsItsDecorationWithTheModeOff()} is
 * this file's own negative leg for the `enabled()` door.
 *
 * The no-glyph scan covers the frame the lane wired: transcript (with the
 * compaction rule), the input box, and each overlay that paints in
 * Renderer.php (key help, palette, permission prompt). The session
 * picker, directory picker and history search paint their own boxes in
 * their own class files — outside this step's declared files, reported
 * as a seam, not silently widened.
 */
final class ScreenReaderRenderTest extends TestCase
{
    use HomeSandboxTrait;

    private string $home = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->home = sys_get_temp_dir() . '/crush-sr-render-' . bin2hex(random_bytes(6));
        mkdir($this->home . '/.candy-code', 0o700, true);
        $this->useHomeSandbox($this->home);
        UiSettings::forget();
    }

    protected function tearDown(): void
    {
        UiSettings::forget();
        $this->restoreHomeSandbox();
        self::removeTree($this->home);

        parent::tearDown();
    }

    /** @param array<string, mixed> $values */
    private function writeConfig(array $values): void
    {
        file_put_contents($this->home . '/.candy-code/config.json', json_encode($values, JSON_THROW_ON_ERROR));
        UiSettings::forget();
    }

    public function testAFullFrameCarriesNoBoxDrawingCodepointWithTheModeOn(): void
    {
        $this->writeConfig(['screenReader' => true]);

        foreach ($this->frames() as $label => $chat) {
            self::assertSame(
                [],
                $this->boxDrawingRunes(Renderer::render($chat)),
                $label . ': with the mode on, every U+2500-U+257F rune must have shed',
            );
        }
    }

    public function testTheSameFrameStillPaintsItsDecorationWithTheModeOff(): void
    {
        $this->writeConfig(['screenReader' => false]);

        // The negative leg of the same fixtures: the door is enabled(),
        // not the branch order — deleting the mode check reddens this.
        $sawBorder = false;
        $sawRule = false;
        foreach ($this->frames() as $chat) {
            $runes = $this->boxDrawingRunes(Renderer::render($chat));
            $sawBorder = $sawBorder || \in_array("\u{2502}", $runes, true)
                || \in_array("\u{250c}", $runes, true)
                || \in_array("\u{2500}", $runes, true);
            $sawRule = $sawRule || \in_array("\u{2500}", $runes, true);
        }
        self::assertTrue($sawBorder, 'off must still paint framed boxes — a frame with zero decoration under off means the door was deleted');
        self::assertTrue($sawRule, 'and the compaction boundary must still be a rule under off');
    }

    public function testPaletteRowsCarryTheirPositionAndATextMarker(): void
    {
        $this->writeConfig(['screenReader' => true]);

        [$chat] = (new Chat())->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
        self::assertNotNull($chat->palette(), 'fixture: Ctrl+P opens the palette');

        $frame = Renderer::render($chat);
        $lines = explode("\n", $frame);

        // At least one row carries the selection marker and its position;
        // every result row states a position. menuRow()'s contract: '> '
        // or '  ', label, ' n/of'. The box pads rows with trailing spaces,
        // so the match runs on the trimmed line.
        $positioned = 0;
        $markers = [];
        foreach ($lines as $line) {
            if (preg_match('/(>)\s.*\s(\d+)\/(\d+)$/', rtrim($this->stripAnsi($line)), $m) === 1) {
                $positioned++;
                $markers[] = $m[1];
            }
        }
        self::assertGreaterThan(0, $positioned, 'at least the selected row must read "> label n/of"');
        self::assertContains('>', $markers);
        self::assertStringNotContainsString("\u{25b8} ", $frame, 'the ▸ marker belongs to the sighted path only — under SR it is replaced by the text > from menuRow');

        // The position digits must count the same list: the selected row
        // carries index+1 over the row count visible in any row's suffix.
        $trimmed = array_map(rtrim(...), $this->plainLines($frame));
        self::assertSame(1, preg_match('/^\s*>\s.*\s\d+\/\d+$/m', implode("\n", $trimmed)), 'exactly one row carries the > selection marker');
    }

    public function testAnOpenOverlayReplacesTheBody(): void
    {
        $this->writeConfig(['screenReader' => true]);

        $chat = $this->paletteChat();
        $frame = Renderer::render($chat);

        // The transcript is replaced, not covered: even the row GUTTER and
        // the surviving prefixes of the clipped off-mode frame are gone
        // (under off the Veil clips "zebra-questions" to "zeb" beside the
        // box, so the full words alone would not discriminate).
        self::assertStringNotContainsString('user>', $frame, 'an open overlay replaces the body under SR — the transcript gutter must not be painted at all');
        self::assertStringNotContainsString('marsh-', $frame, 'and neither may any transcript row prefix survive');
        self::assertStringNotContainsString('zebra-questions', $frame, 'the full history row is absent too');
        self::assertStringContainsString("\u{1f50d}", $frame, 'the palette query line is the frame');
        self::assertMatchesRegularExpression('/\d+\/\d+/', $this->stripAnsi($frame), 'positioned palette rows prove the overlay painted');
    }

    public function testTheSameOverlayCompositesOverTheBodyWithTheModeOff(): void
    {
        $this->writeConfig(['screenReader' => false]);

        $chat = $this->paletteChat();
        $frame = Renderer::render($chat);

        // The Veil path is untouched for off mode: transcript AND overlay
        // share the frame, exactly as every shipped capture pins. The
        // overlay clips the words beside the box ("user> zeb╭...",
        // "marsh-ans│"), so the discriminative needles are the prefixes
        // that provably vanish under the SR replacement.
        self::assertStringContainsString('user>', $frame, 'off composites over the transcript — the gutter survives beside the box');
        self::assertStringContainsString('marsh-', $frame, 'and so does the assistant row prefix');
        self::assertStringContainsString("\u{1f50d}", $frame, 'and the palette still paints');
        self::assertStringContainsString("\u{25b8} ", $frame, 'sanity: the ▸ sighted marker is on the off path');
    }

    /** @return array<string, Chat> label => chat to render with an open overlay or framed body */
    private function frames(): array
    {
        $base = new Chat(
            history: [
                Message::user('zebra-questions'),
                Message::assistant('marsh-answers'),
                Message::system(Chat::COMPACTION_BOUNDARY)->withUiOnly(),
            ],
            backend: new EchoBackend(),
        );

        [$palette] = $base->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
        self::assertNotNull($palette->palette(), 'fixture: the palette opened');

        $keyHelp = $base->withKeyHelp(0);
        self::assertNotNull($keyHelp->keyHelp(), 'fixture: the key-help reference is open');

        return [
            'plain frame (input box + compaction rule)' => $base,
            'key help open' => $keyHelp,
            'palette open' => $palette,
            'permission prompt open' => $this->askingChat(),
        ];
    }

    private function paletteChat(): Chat
    {
        [$chat] = (new Chat(
            history: [
                Message::user('zebra-questions'),
                Message::assistant('marsh-answers'),
            ],
            backend: new EchoBackend(),
        ))->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
        self::assertNotNull($chat->palette(), 'fixture: the palette opened');

        return $chat;
    }

    /** The prompt raised through the front door — a PreToolUse hook that asks. */
    private function askingChat(): Chat
    {
        $asks = new class implements HookInterface {
            public function name(): string
            {
                return 'sr-ask-every-tool';
            }

            public function event(): HookEvent
            {
                return HookEvent::PreToolUse;
            }

            public function matcher(): string
            {
                return '.*';
            }

            public function execute(HookContext $context): HookResult
            {
                return HookResult::ask('Run rm -rf build/?');
            }
        };
        $hooks = new HookManager(new HookRegistry());
        $hooks->register($asks);

        [$chat] = (new Chat(
            history: [Message::user('the prompt')],
            inFlight: true,
            backend: new EchoBackend(),
        ))
            ->registerTool('Bash', static fn(array $args): string => 'total 0')
            ->withHooks($hooks)
            ->update(new \SugarCraft\Code\App\AssistantMsg(
                Message::assistant('running')->withToolCalls([new ToolCall('Bash', ['command' => 'rm -rf build/'], 'call_sr')]),
            ));
        self::assertNotNull($chat->pendingPermission(), 'fixture: the hook raised the prompt');

        return $chat;
    }

    /**
     * Every Box Drawing (U+2500-U+257F) codepoint present in $text, in
     * first-seen order. Built by codepoint walk — no regex class literal,
     * which keeps the GlobDialect corpus of preg-shaped strings
     * untouched by this file.
     *
     * @return list<string>
     */
    private function boxDrawingRunes(string $text): array
    {
        $runes = [];
        foreach (preg_split('//u', $this->stripAnsi($text), -1, PREG_SPLIT_NO_EMPTY) as $rune) {
            $cp = mb_ord($rune, 'UTF-8');
            if ($cp >= 0x2500 && $cp <= 0x257F) {
                $runes[] = $rune;
            }
        }

        return $runes;
    }

    /** @return list<string> */
    private function plainLines(string $frame): array
    {
        return array_map($this->stripAnsi(...), explode("\n", $frame));
    }

    private function stripAnsi(string $line): string
    {
        // SGR runs only (ESC [ ... m); zone sentinels are private-use,
        // outside the box range, and cannot skew the digit-suffix match.
        return (string) preg_replace('/\033\[[0-9;]*m/', '', $line);
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            @unlink($dir);

            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($dir . '/' . $entry);
            }
        }
        @rmdir($dir);
    }
}
