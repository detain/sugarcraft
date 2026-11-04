<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseMotionMsg;
use SugarCraft\Core\Msg\MouseReleaseMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\RawMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Message;
use SugarCraft\Code\Renderer;
use SugarCraft\Code\Support\SystemClipboard;

/**
 * TUIARCH-03 (regression guard): the resize arm of the selection lifetime law.
 *
 * The documented law (trackTextSelection's header, bullet 3) says a settled
 * highlight "stays up ... until the next press, key, wheel notch or resize".
 * All four arms exist in production at their true homes: the press nulls (or
 * re-anchors) and a wheel notch drops only a SETTLED record inside
 * Chat::trackTextSelection() (an in-progress drag rides the wheel by design);
 * the key arm lives at the top of the Keys handler's message walk
 * (src/Chat/Handlers/HandlesKeys.php); and the resize arm lives in the
 * session-surface WindowSizeMsg leg (src/Chat/Handlers/HandlesSessionSurface.php)
 * — both traits feed Chat::update()'s dispatch. The resize arm predates this
 * file: a lane re-audit of TUIARCH-03 found the finding's premise stale at
 * this base and struck the duplicate arm it had briefly carried, leaving
 * these pins as the guard that keeps the law from silently regressing. They
 * drive the real message flow through Chat::update() and pin the three
 * shapes whose absence reddens them:
 *
 * - the settled record is dropped by a resize (the highlight, not the copy);
 * - an in-progress drag is aborted, and its trailing release copies nothing
 *   (the stale-extract arm can no longer fire against re-flowed lines);
 * - the resize choke is conditional: with no selection standing the frame
 *   paints through it byte-unchanged.
 *
 * Verified non-vacuous: neutering the HandlesSessionSurface drop reddens the
 * first two pins (cp+md5 mutation proof, lane G2-L2 finish).
 *
 * The press/drag/release driver idiom is copied from MouseTextSelectionTest so
 * the two files speak the same mouse-report vocabulary (1-based SGR cells
 * against the standalone frame at the terminal origin).
 */
final class SelectionResizeArmTest extends TestCase
{
    private const VARS = ['CANDY_CODE_DISABLE_MOUSE', 'CANDY_CODE_DISABLE_MOUSE_CLICKS'];

    /** A candidate that exists, so candidate discovery short-circuits on it. */
    private const STUB_CANDIDATE = ['/usr/bin/tmux', 'load-buffer', '-w', '-'];

    /** @var list<array{0: list<string>, 1: string}> */
    private array $nativeCopies = [];

    protected function setUp(): void
    {
        parent::setUp();

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

        parent::tearDown();
    }

    public function testAResizeDropsTheSettledRecord(): void
    {
        [$chat, $at] = $this->transcript();
        $cmd = $this->copiedSelection($chat, $at('beta'), $at('beta')[1]);
        self::assertNotEmpty($this->rawWrites($cmd), 'evaluating the release writes the OSC-52 copy sequence');

        self::assertCount(1, $this->nativeCopies, 'the copy itself reached the system clipboard');
        self::assertNotNull(Chat::textSelection(), 'the copy settles an explicit highlight to drop');

        $chat->update(new WindowSizeMsg(100, 30));

        self::assertNull(Chat::textSelection(), 'a resize drops the settled record — its frozen cells survive no reflow (TUIARCH-03)');
        self::assertCount(1, $this->nativeCopies, 'dropping the record is not re-copying it');
    }

    public function testAResizeAbortsAnInProgressDragAndItsTrailingReleaseCopiesNothing(): void
    {
        [$chat, $at] = $this->transcript();
        [$col, $row] = $at('beta');

        $this->body($chat);
        $chat->update($this->press($col, $row));
        $chat->update($this->motion($col + 6, $row));

        self::assertNotNull(Chat::textSelection(), 'the drag is in flight');
        self::assertTrue(Chat::textSelectionInProgress(), 'and nothing is settled yet');

        [, $cmd] = $chat->update(new WindowSizeMsg(100, 30));

        self::assertNull(Chat::textSelection(), 'the resize aborts the drag (TUIARCH-03)');
        self::assertSame([], $this->rawWrites($cmd), 'a resize is not a copy — the abort itself dispatches nothing');

        $motion = $chat->update($this->motion($col + 8, $row));
        $release = $chat->update($this->release($col + 8, $row));

        self::assertSame([], $this->rawWrites($motion[1]), 'the stale motion after the abort paints no selection');
        self::assertSame([], $this->rawWrites($release[1]), 'its trailing release dispatches no copy command');
        self::assertSame([], $this->nativeCopies, 'and nothing reaches the clipboard — at base the surviving drag re-extracts from the reflowed lines and copies the garbage');
    }

    public function testTheChokeIsConditionalWhenNoSelectionStands(): void
    {
        [$chat] = $this->transcript();

        $body = $this->body($chat);
        [, $cmd] = $chat->update(new WindowSizeMsg(100, 30));

        self::assertNull(Chat::textSelection(), 'nothing was ever selected');
        self::assertSame($body, $this->body($chat), 'the frame paints through the arm unchanged');
        self::assertSame([], $this->rawWrites($cmd), 'and no clipboard write rides the resize');
    }

    // ── driver ─────────────────────────────────────────────────────────────

    /**
     * @return array{0: Chat, 1: callable(string): array{0: int, 1: int}}
     */
    private function transcript(): array
    {
        $chat = (new Chat(history: [Message::user("alpha beta gamma\n    indented();\nomega")], backend: new EchoBackend()))
            ->withSize(80, 24);
        $lines = explode("\n", Ansi::strip($this->body($chat)));

        return [
            $chat,
            fn (string $word): array => $this->cellOf($lines, $word),
        ];
    }

    /**
     * Press, drag, release over the six cells starting at [$col, $row] — the
     * gesture that settles a copy — returning the release Cmd unevaluated.
     * The native copy and the OSC-52 write both fire only when a caller
     * evaluates it the way `Program` does ({@see rawWrites()}).
     */
    private function copiedSelection(Chat $chat, array $at, int $row): ?\Closure
    {
        [$col] = $at;
        $this->body($chat);

        [$chat] = $chat->update($this->press($col, $row));
        [$chat] = $chat->update($this->motion($col + 6, $row));
        [, $cmd] = $chat->update($this->release($col + 6, $row));

        self::assertNotNull($cmd, 'the release dispatches the copy');

        return $cmd;
    }

    /**
     * The frame painted for a standalone Chat sits at the terminal origin, so a
     * word's cell is its line number plus one and its display width plus one.
     *
     * @param list<string> $lines
     *
     * @return array{0: int, 1: int}
     */
    private function cellOf(array $lines, string $needle): array
    {
        foreach ($lines as $i => $line) {
            $at = mb_strpos($line, $needle);

            if ($at !== false) {
                return [mb_strwidth(mb_substr($line, 0, (int) $at)) + 1, $i + 1];
            }
        }

        self::fail("needle {$needle} missing from the painted transcript");
    }

    private function body(Chat $chat): string
    {
        return Renderer::render($chat);
    }

    private function press(int $col, int $row): MouseClickMsg
    {
        return new MouseClickMsg($col, $row, MouseButton::Left, MouseAction::Press);
    }

    private function motion(int $col, int $row): MouseMotionMsg
    {
        return new MouseMotionMsg($col, $row, MouseButton::Left, MouseAction::Motion);
    }

    private function release(int $col, int $row): MouseReleaseMsg
    {
        return new MouseReleaseMsg($col, $row, MouseButton::Left, MouseAction::Release);
    }

    /**
     * The raw-writer bytes a returned Cmd dispatches (mirrors how Program
     * evaluates one), flattened for a "did it write anything" assertion.
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

    private function resetMouseState(): void
    {
        Renderer::clearZones();
        Chat::clearTextSelection();
        (new \ReflectionProperty(Chat::class, 'clickTracker'))->setValue(null, null);
        (new \ReflectionProperty(Chat::class, 'pressGesture'))->setValue(null, null);
    }
}
