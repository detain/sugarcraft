<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * E5 — the input caret may not be spliced INSIDE a grapheme cluster.
 *
 * `Renderer::renderInput()` composes prompt + `mb_substr(draft, 0, offset)` +
 * `█` + `mb_substr(draft, offset)` from `Chat::inputCursorOffset()`, a FLAT
 * CODE-POINT number — and the draft widget walks the same flat codepoints, so
 * `e` + U+0301 (combining acute) typed and stepped over parks the caret
 * BETWEEN a base and its mark. `mb_substr` then hands the orphaned mark to the
 * block glyph: the accent stops accenting the `e` and rides the `█` instead,
 * on every frame from the offending offset on. (Measured on the pre-fix tree
 * with exactly the fixture below: `e █` then the stray mark, `Width` counting
 * one more cell than the user typed.)
 *
 * The fix is in the paint, not the widget: `snapToClusterStart()` floors the
 * offset to the cluster boundary strictly before it, both ends short-circuit
 * byte-identically, and E3 gave the palette query the same guard at the same
 * choke point. The DRAFT caret itself still walks codepoints — that is the
 * forms widget's contract, out of this file's authority — which is precisely
 * why the renderer must not trust it for a splice.
 *
 * Assertions are adjacency on the plain (SGR-stripped) input-box rows: the
 * defect is the caret sitting right of a base whose mark continues left of
 * it, and "the mark follows its base" is the whole fix. The box is located by
 * its `└`/`┌` `Border::normal()` corners walked up from the bottom, the same
 * predicate {@see InputWrapTest::inputBox()} uses and for the same reason —
 * the frame's row count moves with everything else in it.
 */
final class InputCaretGraphemeTest extends TestCase
{
    use HomeSandboxTrait;

    private string $homeSandbox = '';

    protected function setUp(): void
    {
        // Constructing a Chat walks the skill trees under HOME; sandbox both
        // spellings so nothing here depends on what this machine has installed.
        $this->homeSandbox = $this->useHomeSandbox(
            sys_get_temp_dir() . '/crush_input_grapheme_home_' . bin2hex(random_bytes(6)),
        );
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        @rmdir($this->homeSandbox);

        parent::tearDown();
    }

    /**
     * The reported shape, driven by keystrokes: `e` + combining acute + `x`,
     * caret stepped back twice. The widget's flat walk parks the offset at 1,
     * one codepoint INTO the cluster - fixture-proved before any paint claim -
     * and the frame still paints the caret BEFORE the whole cluster, with the
     * mark glued to its base.
     */
    public function testTheCaretIsNeverPaintedBetweenABaseAndItsCombiningMark(): void
    {
        $chat = $this->chat("e\u{0301}x", 60);
        [$chat] = $chat->update(new KeyMsg(KeyType::Left));
        [$chat] = $chat->update(new KeyMsg(KeyType::Left));
        self::assertSame(1, $chat->inputCursorOffset(), 'fixture: the draft walk parked the caret inside the cluster');

        $box = implode('', $this->inputBox($chat));

        self::assertSame(1, substr_count($box, "\u{2588}"), 'one caret');
        self::assertStringContainsString("\u{2588}e\u{0301}", $box, 'the snapped caret leads the whole cluster');
        self::assertStringNotContainsString("e\u{2588}", $box, 'the pre-fix splice - the frame may not split the cluster');
    }

    /**
     * Every exact boundary paints byte-identically to what it painted before
     * the snap existed: end (the shape all older captures hold), Home, and a
     * step to the boundary AFTER the cluster.
     */
    public function testBoundaryOffsetsPaintUnmoved(): void
    {
        $atEnd = implode('', $this->inputBox($this->chat("e\u{0301}x", 60)));
        self::assertStringContainsString("e\u{0301}x\u{2588}", $atEnd);

        $chat = $this->chat("e\u{0301}x", 60);
        [$chat] = $chat->update(new KeyMsg(KeyType::Home));
        self::assertSame(0, $chat->inputCursorOffset(), 'fixture: Home parked the caret on the left boundary');
        $atHome = implode('', $this->inputBox($chat));
        self::assertStringContainsString("\u{2588}e\u{0301}x", $atHome);

        [$chat] = $chat->update(new KeyMsg(KeyType::Right));
        [$chat] = $chat->update(new KeyMsg(KeyType::Right));
        [$chat] = $chat->update(new KeyMsg(KeyType::Right));
        [$chat] = $chat->update(new KeyMsg(KeyType::Left));
        self::assertSame(2, $chat->inputCursorOffset(), 'fixture: the caret sits on the boundary after the cluster');
        $afterCluster = implode('', $this->inputBox($chat));
        self::assertStringContainsString("e\u{0301}\u{2588}x", $afterCluster, 'a legal offset keeps its exact bytes');
    }

    /**
     * The same defence for the widest cluster this project renders: a ZWJ
     * emoji couple is three codepoints, and the widget's walk can park the
     * caret after the first of them. The paint must show the couple whole -
     * a caret inside it would paint half a man and a divorced zero-width
     * joiner.
     */
    public function testTheCaretIsNeverPaintedInsideAZwjEmojiCluster(): void
    {
        $chat = $this->chat("a\u{1F468}\u{200D}\u{1F469}b", 60);
        [$chat] = $chat->update(new KeyMsg(KeyType::Home));
        [$chat] = $chat->update(new KeyMsg(KeyType::Right));
        [$chat] = $chat->update(new KeyMsg(KeyType::Right));
        self::assertSame(2, $chat->inputCursorOffset(), 'fixture: the draft walk parked the caret after the first codepoint of the couple');

        $box = implode('', $this->inputBox($chat));

        self::assertStringContainsString("a\u{2588}\u{1F468}\u{200D}", $box, 'the snapped caret sits before the whole couple');
        self::assertStringNotContainsString("\u{1F468}\u{2588}", $box);
        self::assertStringNotContainsString("\u{200D}\u{2588}", $box);
    }

    // =====================================================================
    // fixtures
    // =====================================================================

    private function chat(string $draft, int $cols): Chat
    {
        return (new Chat(inputBuf: $draft, backend: new EchoBackend()))->withSize($cols, 24);
    }

    /**
     * The CONTENT rows of the input box, border rows dropped - the same
     * bottom-up `└`/`┌` walk {@see InputWrapTest::inputBox()} documents.
     *
     * @return list<string>
     */
    private function inputBox(Chat $chat): array
    {
        $rows = array_map(
            static fn (string $r): string => preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $r) ?? $r,
            explode("\n", $chat->view()),
        );

        $close = -1;
        for ($i = count($rows) - 1; $i >= 0; $i--) {
            if (str_starts_with($rows[$i], '└')) {
                $close = $i;
                break;
            }
        }
        self::assertGreaterThan(0, $close, 'no input-box closing border in the frame');

        $open = -1;
        for ($i = $close - 1; $i >= 0; $i--) {
            if (str_starts_with($rows[$i], '┌')) {
                $open = $i;
                break;
            }
        }
        self::assertGreaterThanOrEqual(0, $open, 'no input-box opening border in the frame');

        return array_values(array_slice($rows, $open + 1, $close - $open - 1));
    }
}
