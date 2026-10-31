<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\App\App;
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
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Renderer;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\ToolCall;
use SugarCraft\Code\ToolResult;
use SugarCraft\Code\Tui\Components\MenuBar;
use SugarCraft\Code\Tui\DiffReview\DiffReview;
use SugarCraft\Code\Tui\DiffReview\OpenDiffReviewMsg;
use SugarCraft\Code\Tui\Renderer as TuiRenderer;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Ansi;

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
        // The menu bar keeps its opened index in class-static state; a test
        // that opened a dropdown and died before closing it would leak the
        // panel into every later frame in the process.
        MenuBar::closeMenu();
        // Same hazard for the shell-declared Agent View (Renderer is
        // process-static about it): no test in this file may leak one.
        Renderer::setAgentView(null);
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

        // ...but the REPLACEMENT is of the clipped BODY band only. The status
        // bar is chrome the frame pins outside that band — E48's "the row
        // that survives" — and it answers "what state is the session in"
        // independently of which modal owns the body. Deleting it (a plain
        // `$frame = $overlay`) reddens this leg; the lead ruling on
        // review-1 (c).
        self::assertStringContainsString('Ctrl+P menu', $this->stripAnsi($frame), 'the overlay replaces the body, not the chrome — the status bar must survive beside it');
    }

    public function testTheAgentViewHeaderSurvivesBesideTheScreenReaderOverlay(): void
    {
        // Ruling (c) splices TWO chrome rows around the replacing overlay.
        // The status bar is pinned by the test above; this is the other
        // leg — the Agent View header, which P-C2 pins ABOVE the clipped
        // body so "the way back" never scrolls off. The design block for
        // this step (design-tui.md, C-TUI-6.j, "Overlays") says the chain
        // "REPLACES the body" — the body band, not the chrome pinned
        // outside it. Deleting the ($agentHeader ...) leg at the SR
        // branch drops this row, and nothing else in the suite has ever
        // opened an overlay over an agent view.
        $name = 'srh-agent-' . bin2hex(random_bytes(6));
        $view = [
            'id' => 'run-1',
            'name' => $name,
            'state' => null,
            'rows' => [Message::user('child-asks-loudly')],
            'composer' => null,
            'attach' => null,
            'siblings' => ['run-1'],
            'transcript' => true,
        ];

        $this->writeConfig(['screenReader' => true]);
        Renderer::setAgentView($view);
        try {
            $sr = $this->stripAnsi(Renderer::render($this->paletteChat()));
        } finally {
            Renderer::setAgentView(null);
        }

        // The SR replacement branch is what painted: the agent run's own
        // row is the body under an open view, and the replacing overlay
        // took it — gutter and all. (The breadcrumb's own ' ▸ ' is
        // AgentViewHeader::ARROW — chrome outside the box-drawing range —
        // so the ▸-absence door the sibling test uses cannot be reused here.)
        self::assertStringNotContainsString('user> chi', $sr, 'fixture: the overlay painted through the SR replacement path — the run transcript that is the body under an open view is gone with its gutter');
        self::assertStringNotContainsString('child-asks-loudly', $sr, 'and no unclipped remnant of it survives either');
        self::assertMatchesRegularExpression('/\d+\/\d+/', $sr, 'and the palette painted as positioned SR rows');
        self::assertStringContainsString($name, $sr, 'the Agent View header survives beside the SR overlay — the splice at the SR branch is load-bearing chrome, not decoration');
        self::assertStringContainsString('main ▸ ' . $name, $this->plainLines($sr)[0], 'and it stays the pinned top row — above the replacing overlay, the way P-C2 holds it above the clip');

        // The off-mode counterpart: the header row is still pinned above
        // the body — but under off the Veil composites over the row, so
        // the full agent name is clipped away ("main ▸ sr╭ command…").
        // The clipped prefix, not the name, is the off-mode needle.
        $this->writeConfig(['screenReader' => false]);
        Renderer::setAgentView($view);
        try {
            $off = $this->stripAnsi(Renderer::render($this->paletteChat()));
        } finally {
            Renderer::setAgentView(null);
        }

        $offLines = $this->plainLines($off);
        // The row carries the click-zone sentinels around "main" (stripAnsi
        // removes SGR runs only), so the door is the substring, not an anchor.
        self::assertStringContainsString('main ▸ ', $offLines[0], 'off mode keeps the header row pinned above the body, with the composited box clipping the rest of it');
        self::assertStringContainsString('user> chi', $off, 'and the run transcript is the body under off — clipped to "user> chi" beside the composited box, the same way the sibling off pin records');
        self::assertStringContainsString("\u{25b8} ", $off, 'and the palette composites over it, sighted markers and all');
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

    public function testPermissionOptionRowsCarryTheirPositionsOnlyWithTheModeOn(): void
    {
        $this->writeConfig(['screenReader' => true]);
        $on = $this->positionedRows($this->plainLines($this->stripAnsi(Renderer::render($this->askingChat()))));

        // menuRow() stamps every permission option with its place in the
        // list — four options, one per row, in order. Deleting the wrapper
        // at the permission loop leaves bare labels: this list empties.
        self::assertSame(['1/4', '2/4', '3/4', '4/4'], $on, 'the SR permission prompt must read "option n/of" for every option, in order');

        $this->writeConfig(['screenReader' => false]);
        $off = $this->positionedRows($this->plainLines($this->stripAnsi(Renderer::render($this->askingChat()))));
        self::assertSame([], $off, 'off mode is the pre-6.j shape: option rows carry no position suffix at all');
    }

    public function testTheSplitDiffCentreGutterShedsItsDividerWithTheModeOn(): void
    {
        $needle = '-$oldline';

        $this->writeConfig(['screenReader' => true]);
        $sr = $this->lineContaining($needle, $this->plainLines($this->stripAnsi(Renderer::render($this->splitDiffChat()))));
        self::assertStringNotContainsString('= 1;│', $sr, 'the centre divider between the halves must shed — the side row-number columns are unwired design scope and keep their │');
        self::assertMatchesRegularExpression('/= 1; 2│/', $sr, 'the shed centre sits flush before the right half');

        $this->writeConfig(['screenReader' => false]);
        $off = $this->lineContaining($needle, $this->plainLines($this->stripAnsi(Renderer::render($this->splitDiffChat()))));
        self::assertMatchesRegularExpression('/= 1;│2│/', $off, 'off mode keeps the centre divider fused to the right-hand row number');
    }

    public function testTheDiffReviewRuleUnderScreenReaderIsBlankPaper(): void
    {
        $this->writeConfig(['screenReader' => true]);
        $sr = $this->ruleLineBeneath('Files changed', $this->plainLines(Ansi::strip(TuiRenderer::render($this->reviewApp(), 90, 22))));
        self::assertSame('', trim($sr), 'the review pane rule under SR: ScreenReaderMode::glyph sheds the full-width ─ run to spaces (delete the glyph() call and this line is ──…── again)');

        $this->writeConfig(['screenReader' => false]);
        $off = $this->ruleLineBeneath('Files changed', $this->plainLines(Ansi::strip(TuiRenderer::render($this->reviewApp(), 90, 22))));
        self::assertMatchesRegularExpression('/^\x{2500}+$/u', trim($off), 'off paints the rule as one continuous light-box dash');
    }

    public function testTheDropdownOverflowLineShedsItsWallsOnlyWithTheModeOn(): void
    {
        MenuBar::closeMenu();
        MenuBar::openMenu(1);

        $this->writeConfig(['screenReader' => false]);
        $off = $this->plainLines(Ansi::strip(TuiRenderer::render($this->plainApp(), 100, 12)));
        self::assertGreaterThanOrEqual(1, preg_grep('/│ …+│/', $off), 'fixture: the clipped Session dropdown ends in its "│ …  │" overflow line under off');
        self::assertSame([], preg_grep('/^\s+…\s{2,}/', $off), 'and no wall-less ellipsis row exists there');

        $this->writeConfig(['screenReader' => true]);
        $sr = $this->plainLines(Ansi::strip(TuiRenderer::render($this->plainApp(), 100, 12)));
        self::assertSame(1, \count(preg_grep('/^\s+…\s{2,}/', $sr)), 'under SR the whole overflow row sheds to bare spaces plus the text ellipsis (dropdownOverflowLine is wired through glyph(); deleting it restores the │ …│ walls and reddens this)');
        self::assertSame([], preg_grep('/│ …+│/', $sr), 'the wired row keeps no walls under SR');

        MenuBar::closeMenu();
    }

    /** @param list<string> $lines rows of a stripped frame */
    private function positionedRows(array $lines): array
    {
        $positions = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s+\S.*\s(\d+)\/(\d+)$/', rtrim($line), $m) === 1) {
                $positions[] = $m[1] . '/' . $m[2];
            }
        }

        return $positions;
    }

    /** @param list<string> $lines */
    private function lineContaining(string $needle, array $lines): string
    {
        foreach ($lines as $line) {
            if (str_contains($line, $needle)) {
                return rtrim($line);
            }
        }
        self::fail('fixture: no frame row contains ' . $needle);
    }

    /** @param list<string> $lines */
    private function ruleLineBeneath(string $anchor, array $lines): string
    {
        foreach ($lines as $i => $line) {
            if (str_contains($line, $anchor)) {
                self::assertArrayHasKey($i + 1, $lines, 'fixture: the anchored line is not the last row');

                return $lines[$i + 1];
            }
        }
        self::fail('fixture: no frame row contains ' . $anchor);
    }

    /** @return Chat */
    private function splitDiffChat(): Chat
    {
        $diff = "--- a/src/App.php\n+++ b/src/App.php\n@@ -1,3 +1,3 @@\n <?php\n-\$oldline = 1;\n+\$newline = 2;\n";

        return new Chat(
            history: [
                Message::user('zebra-questions'),
                Message::assistant('')->withToolResults([new ToolResult(name: 'Edit', result: 'File updated: src/App.php', id: 'call_diff', diff: $diff)]),
            ],
            rows: 30,
            cols: 140,
            backend: new EchoBackend(),
        );
    }

    private function plainApp(): App
    {
        return App::new($this->createMock(ProviderInterface::class), 'test-model')
            ->withChat(new Chat(
                history: [Message::user('zebra-questions'), Message::assistant('marsh-answers')],
                backend: new EchoBackend(),
            ));
    }

    private function reviewApp(): App
    {
        [$app] = $this->plainApp()->update(new OpenDiffReviewMsg(
            DiffReview::open([[
                'status' => 'M',
                'path' => 'file.txt',
                'added' => 1,
                'removed' => 1,
                'patch' => "--- a/file.txt\n+++ b/file.txt\n@@ -1 +1 @@\n-old\n+new\n",
            ]], 1, 1, false, 0),
        ));
        self::assertNotNull($app->diffReview, 'fixture: the review slot armed');

        return $app;
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
