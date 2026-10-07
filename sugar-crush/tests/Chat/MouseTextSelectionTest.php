<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseMotionMsg;
use SugarCraft\Core\Msg\MouseReleaseMsg;
use SugarCraft\Core\Msg\MouseWheelMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\RawMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Support\SystemClipboard;
use SugarCraft\Crush\Tui\Renderer as TuiRenderer;

/**
 * Drag-to-select and copy-on-release over the chat transcript.
 *
 * With SGR mouse tracking on, the terminal runs no selection of its own, so
 * before this the §8 E8 drag guard declined to treat a sweep as a click and
 * then nothing happened at all. Behaviour style: drive `update()` with the
 * mouse reports a terminal sends, assert on the highlight in `view()` and on
 * what the release's Cmd writes.
 *
 * Coordinates are the SGR report's 1-based cells; a standalone Chat's frame
 * sits at the terminal origin, so frame line `i`, cell `c` is reported as
 * `(c + 1, i + 1)`.
 */
final class MouseTextSelectionTest extends TestCase
{
    private const VARS = ['SUGARCRUSH_DISABLE_MOUSE', 'SUGARCRUSH_DISABLE_MOUSE_CLICKS'];

    /**
     * The candidate the discovery seam reports for every copy here. CI runs
     * headless — no TMUX, no DISPLAY, no WAYLAND_DISPLAY — so the real
     * {@see SystemClipboard::candidates()} is legitimately empty there and
     * the runner seam alone would never fire; discovery is stubbed alongside
     * it. The argv's contents are pinned as-passed, not asserted to be real:
     * what the copy flow must prove is that whatever discovery found reaches
     * the spawn seam with the copied text. The env-gated REAL discovery (and
     * the headless host finding nothing) is pinned in SystemClipboardTest.
     */
    private const STUB_CANDIDATE = ['/usr/bin/tmux', 'load-buffer', '-w', '-'];

    /** @var list<array{0:list<string>,1:string}> */
    private array $nativeCopies = [];

    protected function setUp(): void
    {
        foreach (self::VARS as $var) {
            putenv($var);
        }
        $this->resetMouseState();
        $this->nativeCopies = [];
        SystemClipboard::useCandidatesForTesting([self::STUB_CANDIDATE]);
        SystemClipboard::useRunnerForTesting(function (array $argv, string $text): bool {
            $this->nativeCopies[] = [$argv, $text];

            return true;
        });
    }

    protected function tearDown(): void
    {
        foreach (self::VARS as $var) {
            putenv($var);
        }
        $this->resetMouseState();
        SystemClipboard::useRunnerForTesting(static fn (): bool => false);
        SystemClipboard::useCandidatesForTesting(null);
        App::resetPaneDragController();
    }

    public function testADragHighlightsTheCoveredTextWhileTheButtonIsHeld(): void
    {
        [$chat, $at] = $this->transcript();
        [$col, $row] = $at('beta');

        [$chat] = $chat->update($this->press($col, $row));
        [$chat] = $chat->update($this->motion($col + 3, $row));

        $selection = Chat::textSelection();
        self::assertNotNull($selection);
        self::assertTrue($selection->dragging);
        self::assertFalse($selection->settled);
        self::assertStringContainsString("\e[7mbeta\e[", $this->body($chat), 'the four swept cells are painted reversed');
    }

    public function testAPressWithoutADragPaintsNothing(): void
    {
        [$chat, $at] = $this->transcript();
        [$col, $row] = $at('beta');

        [$chat] = $chat->update($this->press($col, $row));

        self::assertStringNotContainsString("\e[7m", $this->body($chat));
    }

    public function testReleaseCopiesTheSelectionThroughOsc52AndTheHostTool(): void
    {
        [$chat, $at] = $this->transcript();
        [$fromCol, $fromRow] = $at('beta');
        [$toCol, $toRow] = $at('omega');

        [$chat] = $chat->update($this->press($fromCol, $fromRow));
        [$chat] = $chat->update($this->motion($toCol, $toRow));
        [$chat, $cmd] = $chat->update($this->release($toCol + 4, $toRow));

        $expected = "beta gamma\n    indented();\nomega";
        self::assertSame([Ansi::setClipboard($expected)], $this->rawWrites($cmd), 'one OSC 52 write, the exact text');
        self::assertCount(1, $this->nativeCopies);
        self::assertSame($expected, $this->nativeCopies[0][1]);
        self::assertSame(self::STUB_CANDIDATE, $this->nativeCopies[0][0], 'the stubbed candidate rides through to the spawn seam');

        $selection = Chat::textSelection();
        self::assertNotNull($selection);
        self::assertTrue($selection->settled, 'the highlight stays up as the record of what was copied');
        self::assertSame(mb_strlen($expected), $selection->copiedChars);
        self::assertStringContainsString('✓ copied ' . mb_strlen($expected) . ' chars', Ansi::strip($this->body($chat)));
    }

    public function testAClickWithOneCellOfJitterCopiesNothing(): void
    {
        [$chat, $at] = $this->transcript();
        [$col, $row] = $at('beta');

        [$chat] = $chat->update($this->press($col, $row));
        [$chat, $cmd] = $chat->update($this->release($col + 1, $row));

        self::assertNull($cmd);
        self::assertNull(Chat::textSelection());
        self::assertSame([], $this->nativeCopies);
    }

    public function testAPressOutsideTheTranscriptTextSelectsNothing(): void
    {
        [$chat] = $this->transcript();

        // Column 40 sits right of the region's last column (the widest
        // transcript row is 22 cells) — since CL-3 there is no shell border to
        // press, so the dead space beyond the text is the off-region cell.
        [$chat] = $chat->update($this->press(40, 3));
        [$chat] = $chat->update($this->motion(40, 3));
        [, $cmd] = $chat->update($this->release(40, 3));

        self::assertNull($cmd);
        self::assertNull(Chat::textSelection());
    }

    public function testTheNextPressKeyWheelOrResizeDismissesTheCopiedHighlight(): void
    {
        $dismissals = [
            // Row 4 is the input box's top border — below the bare
            // transcript's last row (CL-3; it used to be the shell's border).
            'press' => $this->press(1, 4),
            'key' => new KeyMsg(KeyType::Char, 'x'),
            'wheel' => new MouseWheelMsg(5, 5, MouseButton::WheelUp, MouseAction::Press),
            'resize' => new WindowSizeMsg(80, 24),
        ];

        foreach ($dismissals as $name => $msg) {
            $chat = $this->copiedSelection();
            self::assertNotNull(Chat::textSelection(), $name . ': fixture');

            [$chat] = $chat->update($msg);

            self::assertNull(Chat::textSelection(), $name . ' dismisses the highlight');
            self::assertStringNotContainsString("\e[7m", $this->body($chat), $name);
        }
    }

    public function testCtrlCOverACopiedSelectionDismissesInsteadOfQuitting(): void
    {
        $chat = $this->copiedSelection();

        [, $cmd] = $chat->update(new KeyMsg(KeyType::Char, 'c', ctrl: true));

        self::assertNull($cmd, 'the text is already on the clipboard; quitting out from under the copy would be the surprise');
        self::assertNull(Chat::textSelection());

        [, $quit] = $chat->update(new KeyMsg(KeyType::Char, 'c', ctrl: true));
        self::assertNotNull($quit, 'with no selection up, Ctrl+C is the quit chord again');
    }

    public function testNoSelectionWhenMouseClicksAreDisabled(): void
    {
        putenv('SUGARCRUSH_DISABLE_MOUSE_CLICKS=1');
        [$chat, $at] = $this->transcript();
        [$col, $row] = $at('beta');

        [$chat] = $chat->update($this->press($col, $row));
        [$chat] = $chat->update($this->motion($col + 5, $row));
        [, $cmd] = $chat->update($this->release($col + 5, $row));

        self::assertNull($cmd);
        self::assertNull(Chat::textSelection());
    }

    public function testAHostedDragReleasedOverTheMenuBarStillCopies(): void
    {
        $chat = (new Chat(history: [Message::user("alpha beta gamma\n    indented();\nomega")], backend: new EchoBackend()))
            ->withSize(100, 30);
        $app = App::new($this->createStub(ProviderInterface::class), 'm')
            ->withChat($chat);
        [$app] = $app->update(new WindowSizeMsg(140, 40));

        $frame = explode("\n", Ansi::strip(TuiRenderer::render($app, 140, 40)));
        [$col, $row] = $this->cellOf($frame, 'beta');

        [$app] = $app->update($this->press($col, $row));
        [$app] = $app->update($this->motion($col, 1));
        [$app, $cmd] = $app->update($this->release($col, 1));

        self::assertSame(
            [Ansi::setClipboard('user> alpha b')],
            $this->rawWrites($cmd),
            'dragging up off the transcript clamps to its first cell (the anchor cell is inclusive), and the release over the menu bar '
            . 'reaches the chat instead of being swallowed as a cancelled chrome click',
        );
        self::assertInstanceOf(App::class, $app);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * A standalone chat with a known three-line user turn, rendered once so
     * the selectable region is measured, plus a locator for a word's cell.
     *
     * @return array{0:Chat,1:\Closure(string):array{0:int,1:int}}
     */
    private function transcript(): array
    {
        $chat = (new Chat(
            history: [Message::user("alpha beta gamma\n    indented();\nomega")],
            backend: new EchoBackend(),
        ))->withSize(80, 24);

        $lines = explode("\n", Ansi::strip($this->body($chat)));

        return [$chat, fn (string $word): array => $this->cellOf($lines, $word)];
    }

    /**
     * The SGR (1-based) cell of $needle's first character in $lines.
     *
     * @param list<string> $lines
     * @return array{0:int,1:int}
     */
    private function cellOf(array $lines, string $needle): array
    {
        foreach ($lines as $i => $line) {
            $at = mb_strpos($line, $needle);
            if ($at !== false) {
                return [mb_strwidth(mb_substr($line, 0, $at)) + 1, $i + 1];
            }
        }

        self::fail("'{$needle}' is not on the frame");
    }

    /** A chat whose last drag has been released and copied. */
    private function copiedSelection(): Chat
    {
        [$chat, $at] = $this->transcript();
        [$col, $row] = $at('beta');

        [$chat] = $chat->update($this->press($col, $row));
        [$chat] = $chat->update($this->motion($col + 6, $row));
        [$chat, $cmd] = $chat->update($this->release($col + 6, $row));
        self::assertNotNull($cmd);

        return $chat;
    }

    private function body(Chat $chat): string
    {
        return Renderer::render($chat);
    }

    /**
     * Evaluate a Cmd the way `Program` does — batches fanned out — and
     * collect the raw terminal writes it produces.
     *
     * @return list<string>
     */
    private function rawWrites(?\Closure $cmd): array
    {
        if ($cmd === null) {
            return [];
        }

        $msg = $cmd();
        if ($msg instanceof RawMsg) {
            return [$msg->bytes];
        }
        if ($msg instanceof BatchMsg) {
            $writes = [];
            foreach ($msg->cmds as $inner) {
                $writes = [...$writes, ...$this->rawWrites($inner)];
            }

            return $writes;
        }

        return [];
    }

    private function press(int $col, int $row): MouseClickMsg
    {
        return new MouseClickMsg($col, $row, MouseButton::Left, MouseAction::Press);
    }

    private function release(int $col, int $row): MouseReleaseMsg
    {
        return new MouseReleaseMsg($col, $row, MouseButton::Left, MouseAction::Release);
    }

    private function motion(int $col, int $row): MouseMotionMsg
    {
        return new MouseMotionMsg($col, $row, MouseButton::Left, MouseAction::Motion);
    }

    private function resetMouseState(): void
    {
        Renderer::clearZones();
        Chat::clearTextSelection();
        (new \ReflectionProperty(Chat::class, 'clickTracker'))->setValue(null, null);
        (new \ReflectionProperty(Chat::class, 'pressGesture'))->setValue(null, null);
    }
}
