<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Message;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Renderer;
use SugarCraft\Code\ToolCall;
use SugarCraft\Code\ToolResult;
use SugarCraft\Code\Tui\Components\ToolsPane;
use SugarCraft\Style\Style;

/**
 * W7-B (a11y-F1 follow-through): the settled `Ctrl+F` row cursor PAINTS.
 * The unit's rows wear the theme's active style — shellPrimary + bold, the
 * menu-bar dropdown's selection vocabulary — on their EXISTING head spans.
 * Zero added cells (the stripped frame is byte-identical focused or not),
 * the zone contract intact (the click registry records the very string this
 * paints), and the highlight survives a colour downgrade: under
 * {@see ColorProfile::Ascii} colour is dropped per-colour while text
 * attributes survive, so the mark degrades to BOLD — no '>' glyph, which
 * would have cost the row a cell and the click zone a prefix.
 *
 * @internal
 */
final class FocusedToolRowHighlightTest extends TestCase
{
    protected function tearDown(): void
    {
        Renderer::setFocusedToolRowKeys([]);
        Style::setDefaultProfile(null);
        parent::tearDown();
    }

    /** @param list<Message> $history */
    private function chat(array $history, string $themeName = 'dark'): Chat
    {
        return (new Chat(history: $history, backend: new EchoBackend(), cols: 120, rows: 50))->withThemeName($themeName);
    }

    /** The two-row fixture: an older call and a newer one, each one row. */
    private function twoRows(): Chat
    {
        return $this->chat([
            Message::assistant('a')->withToolResults([ToolResult::ok('grep', 'a', 'call_old')]),
            Message::assistant('b')->withToolResults([ToolResult::ok('grep', 'b', 'call_new')]),
        ]);
    }

    private function headText(Chat $chat): string
    {
        return Renderer::toolRowPrefix() . 'grep';
    }

    /**
     * @param list<string> $keys
     */
    private function render(Chat $chat, array $keys): string
    {
        Renderer::setFocusedToolRowKeys($keys);

        return Renderer::render($chat);
    }

    public function testTheFocusedHeadWearsTheActiveStyleAndTheOthersStayDim(): void
    {
        $chat = $this->twoRows();
        $theme = $chat->theme();
        $text = $this->headText($chat);

        $active = Style::new()->foreground($theme->shellPrimary)->bold()->render($text);
        $dim = Style::new()->foreground($theme->systemLabel)->render($text);

        $base = $this->render($chat, []);
        $lit = $this->render($chat, ['call_old']);

        self::assertSame(2, substr_count($base, $dim), 'both heads are the dim label style un-armed');
        self::assertStringNotContainsString($active, $base, 'nothing is lit until the cursor settles');
        self::assertSame(1, substr_count($lit, $active), 'exactly the named unit lights');
        self::assertSame(1, substr_count($lit, $dim), 'and its sibling keeps its own style');
    }

    public function testThePaintCostsZeroCells(): void
    {
        $chat = $this->twoRows();

        $base = $this->render($chat, []);
        $lit = $this->render($chat, ['call_old']);

        self::assertSame(
            explode("\n", Ansi::strip($base)),
            explode("\n", Ansi::strip($lit)),
            'line for line the stripped frame is the SAME text — the highlight rides existing spans',
        );
        self::assertNotSame($base, $lit, 'precondition: the paint did change the bytes');
    }

    public function testUnArmedRendersStayByteIdentical(): void
    {
        $chat = $this->twoRows();

        $first = $this->render($chat, []);
        $lit = $this->render($chat, ['call_old']);
        $after = $this->render($chat, []);

        self::assertSame($first, $after, 'the empty default self-heals — every non-hosted path keeps its bytes');
        self::assertNotSame($first, $lit);
    }

    public function testTheThoughtRowOfItsUnitLightsToo(): void
    {
        $reasoning = 'weighing the options';
        $chat = $this->chat([
            Message::assistant('x')->withReasoning($reasoning)
                ->withToolResults([ToolResult::ok('grep', 'a', 'call_b')]),
            Message::assistant('y')->withToolResults([ToolResult::ok('grep', 'd', 'call_new')]),
        ]);
        $theme = $chat->theme();
        $thoughtHead = Style::new()->foreground($theme->shellPrimary)->bold()->italic()
            ->render('💭 ' . Lang::t('tui.chat.thought'));

        $base = $this->render($chat, []);
        $lit = $this->render($chat, [Renderer::thoughtKey($reasoning), 'call_b']);

        self::assertStringNotContainsString($thoughtHead, $base);
        self::assertStringContainsString($thoughtHead, $lit, 'the whole named unit lights, thought lead included');
        self::assertSame(
            explode("\n", Ansi::strip($base)),
            explode("\n", Ansi::strip($lit)),
            'zero cells here too',
        );
    }

    public function testAPendingRowLights(): void
    {
        $chat = $this->chat([
            Message::toolRunning(new ToolCall('bash', ['description' => 'watching'], 'call_9')),
        ]);

        $base = $this->render($chat, []);
        $lit = $this->render($chat, ['call_9']);
        $theme = $chat->theme();

        // The running text is the dim style un-armed; armed it carries the
        // active style (bold + shellPrimary) over the SAME text.
        $running = Style::new()->foreground($theme->shellPrimary)->bold()
            ->render(Lang::t('tui.tool.running', ['call' => 'watching']));
        self::assertStringNotContainsString($running, $base, 'un-armed the running text stays dim');
        self::assertStringContainsString($running, $lit, 'the pending row wears the active style');
        self::assertSame(
            explode("\n", Ansi::strip($base)),
            explode("\n", Ansi::strip($lit)),
        );
    }

    public function testTheAnsiThemeResolvesTheActiveStyleToPaletteColour(): void
    {
        $ansiChat = $this->chat([
            Message::assistant('a')->withToolResults([ToolResult::ok('grep', 'a', 'call_old')]),
            Message::assistant('b')->withToolResults([ToolResult::ok('grep', 'b', 'call_new')]),
        ], 'ansi');
        $theme = $ansiChat->theme();

        // The ansi palette's primary is still a palette colour, so the SAME
        // active style resolves to the 16-colour form — never to added
        // cells, never to direct RGB.
        $active = Style::new()->foreground($theme->shellPrimary)->bold()->render($this->headText($ansiChat));
        $lit = $this->render($ansiChat, ['call_old']);
        $base = $this->render($ansiChat, []);

        self::assertStringContainsString($active, $lit);
        self::assertStringNotContainsString("\x1b[38;2;", $active, 'the ansi theme resolves palette colours only');
        self::assertSame(explode("\n", Ansi::strip($base)), explode("\n", Ansi::strip($lit)));
    }

    public function testTheAsciiProfileKeepsBoldAndDropsColourWithNoMarker(): void
    {
        Style::setDefaultProfile(ColorProfile::Ascii);
        $chat = $this->twoRows();
        $text = $this->headText($chat);

        $base = $this->render($chat, []);
        $lit = $this->render($chat, ['call_old']);

        // Colour is dropped per-colour down to Ascii, so the dim head is
        // BARE text; the active head keeps only its BOLD — the highlight is
        // visible as an attribute, and no '>' glyph is needed.
        self::assertStringContainsString($text, $base, 'un-armed the ASCII head is plain text');
        self::assertStringNotContainsString("\x1b[1m" . $text, $base);
        self::assertStringContainsString("\x1b[1m" . $text, $lit, 'ASCII keeps the bold and ONLY the bold');
        // The composer prompt already wears a '>' of its own — the law is
        // that the paint ADDS none: same marker count, focused or not.
        self::assertSame(substr_count(Ansi::strip($base), '>'), substr_count(Ansi::strip($lit), '>'), 'no marker cells anywhere new');
        self::assertSame(explode("\n", Ansi::strip($base)), explode("\n", Ansi::strip($lit)), 'zero cells under ASCII too');
    }

    /** The hosted shape: an App whose chat holds the same two settled rows. */
    private function dockApp(array $history): App
    {
        [$app] = App::new($this->createMock(ProviderInterface::class), 'gpt-4')
            ->withChat($this->chat($history))
            ->update(new WindowSizeMsg(100, 30));

        return $app;
    }

    public function testTheDockedToolsPaneLightsTheSameRow(): void
    {
        // Distinct names so the diff pins WHICH row gained the attribute.
        $history = [
            Message::assistant('a')->withToolResults([ToolResult::ok('grep', 'a', 'call_old')]),
            Message::assistant('b')->withToolResults([ToolResult::ok('sed', 'b', 'call_new')]),
        ];
        $app = $this->dockApp($history);
        [$afterF] = $app->update(new KeyMsg(KeyType::Char, 'f', ctrl: true));
        self::assertSame(['call_old'], $afterF->focusedToolRowKeys(), 'precondition: the cursor settled on the grep row');

        $base = ToolsPane::layout($app, 40, 20)[0];
        $lit = ToolsPane::layout($afterF, 40, 20)[0];

        self::assertSame(explode("\n", Ansi::strip($base)), explode("\n", Ansi::strip($lit)), 'the pane paints bold, never extra cells');

        $baseLines = explode("\n", $base);
        $diffs = [];
        foreach (explode("\n", $lit) as $i => $line) {
            if ($line !== ($baseLines[$i] ?? null)) {
                $diffs[] = $line;
            }
        }
        self::assertCount(1, $diffs, 'exactly one row changes bytes — the focused one');
        self::assertStringContainsString("\x1b[1m", $diffs[0], 'the delta is the active attribute');
        self::assertStringContainsString('grep', $diffs[0], 'and it landed on the grep row, not its sibling');
    }

    public function testWidthLawsHoldOnTheLitFrame(): void
    {
        $chat = $this->twoRows();

        $lit = $this->render($chat, ['call_old']);

        foreach (explode("\n", $lit) as $line) {
            self::assertLessThanOrEqual(120, Width::of($line), 'a lit row never outgrows the pane: ' . Ansi::strip($line));
        }
    }

    public function testThePublishedFrameCarriesTheKeysIntoThePaint(): void
    {
        Renderer::setFocusedToolRowKeys(['call_old']);

        self::assertSame(['call_old'], Renderer::publishedFrame()->focusedToolRowKeys());

        // renderView swaps frames through beginNext(); the input must ride.
        $lit = $this->render($this->twoRows(), ['call_old']);
        self::assertStringContainsString(
            Style::new()->foreground($this->twoRows()->theme()->shellPrimary)->bold()->render($this->headText($this->twoRows())),
            $lit,
            'the armed keys survived the frame swap',
        );
    }
}
