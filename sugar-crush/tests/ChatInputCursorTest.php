<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\InputReader;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Commands\KeyBindingRegistry;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Session\SessionStore;

/**
 * The draft's cursor (crush_code.md Phase 3 item 1).
 *
 * `Chat::$inputBuf` used to be an append-only string with no cursor at all:
 * there was no `KeyType::Left` or `KeyType::Right` arm anywhere in `Chat`, so
 * a typo three characters back could only be fixed by backspacing over
 * everything after it. The draft now lives in `candy-forms`'
 * {@see \SugarCraft\Forms\TextArea\TextArea}, and `$inputBuf` is that widget's
 * `value()` re-derived on every clone.
 *
 * Everything here is driven through `Chat::update()` with real `KeyMsg`s —
 * never by calling the widget directly — because the delegation and its
 * ORDER against `Chat`'s own arms is the part that can break. The widget's own
 * editing is already tested in `candy-forms`.
 *
 * @see Chat::inputCursorOffset()
 * @see Chat::delegateToInput()
 */
final class ChatInputCursorTest extends TestCase
{
    /** Feed a list of KeyMsgs through update(), returning the settled Chat. */
    private function drive(Chat $chat, KeyMsg ...$keys): Chat
    {
        foreach ($keys as $key) {
            [$chat] = $chat->update($key);
        }

        return $chat;
    }

    private function type(string $text): array
    {
        $keys = [];
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            $keys[] = $char === ' '
                ? new KeyMsg(KeyType::Space)
                : new KeyMsg(KeyType::Char, $char);
        }

        return $keys;
    }

    // -------------------------------------------------------------------------
    // 1. Cursor motion, mid-line insert, mid-line delete
    // -------------------------------------------------------------------------

    /**
     * The whole point of the item: arrow back into a draft and fix a typo
     * without destroying everything after it. Typed from empty rather than
     * seeded, so the cursor's position is one the keystrokes established.
     */
    public function testLeftArrowThenTypingInsertsMidDraftInsteadOfAppending(): void
    {
        $chat = $this->drive(new Chat(), ...$this->type('helo world'));
        $this->assertSame(10, $chat->inputCursorOffset(), 'fixture: typing leaves the cursor at the end');

        // Back over " world" and the second "l" of "helo".
        $chat = $this->drive($chat, ...array_fill(0, 7, new KeyMsg(KeyType::Left)));
        $this->assertSame(3, $chat->inputCursorOffset());

        $chat = $this->drive($chat, new KeyMsg(KeyType::Char, 'l'));
        $this->assertSame('hello world', $chat->inputBuf);
        $this->assertSame(4, $chat->inputCursorOffset(), 'the cursor advances past what was inserted');
    }

    public function testRightArrowMovesForwardAndStopsAtTheEnd(): void
    {
        $chat = $this->drive(new Chat(inputBuf: 'abc'), new KeyMsg(KeyType::Home));
        $this->assertSame(0, $chat->inputCursorOffset());

        $chat = $this->drive($chat, new KeyMsg(KeyType::Right), new KeyMsg(KeyType::Right));
        $this->assertSame(2, $chat->inputCursorOffset());

        // Past the end is a clamp, not a wrap onto some other row.
        $chat = $this->drive($chat, ...array_fill(0, 5, new KeyMsg(KeyType::Right)));
        $this->assertSame(3, $chat->inputCursorOffset());
        $this->assertSame('abc', $chat->inputBuf, 'motion must never edit');
    }

    public function testLeftArrowStopsAtTheStart(): void
    {
        $chat = $this->drive(new Chat(inputBuf: 'ab'), ...array_fill(0, 6, new KeyMsg(KeyType::Left)));

        $this->assertSame(0, $chat->inputCursorOffset());
        $this->assertSame('ab', $chat->inputBuf);
    }

    public function testHomeAndEndJumpToTheEndsOfTheLine(): void
    {
        $chat = new Chat(inputBuf: 'hello world');

        $this->assertSame(0, $this->drive($chat, new KeyMsg(KeyType::Home))->inputCursorOffset());
        $this->assertSame(
            11,
            $this->drive($chat, new KeyMsg(KeyType::Home), new KeyMsg(KeyType::End))->inputCursorOffset(),
        );
    }

    public function testBackspaceDeletesBehindTheCursorAndDeleteDeletesInFrontOfIt(): void
    {
        $chat = $this->drive(new Chat(inputBuf: 'hello world'), ...array_fill(0, 4, new KeyMsg(KeyType::Left)));
        $this->assertSame(7, $chat->inputCursorOffset(), 'fixture: the cursor sits between "w" and "o"');

        $this->assertSame(
            'hello orld',
            $this->drive($chat, new KeyMsg(KeyType::Backspace))->inputBuf,
            'Backspace takes the "w" BEHIND the cursor, not the "d" at the end of the draft',
        );
        $this->assertSame(
            'hello wrld',
            $this->drive($chat, new KeyMsg(KeyType::Delete))->inputBuf,
            'Delete takes the "o" in FRONT of it — a key this box had no arm for at all before',
        );
    }

    public function testBackspaceAtTheEndStillTrimsAWholeMultiByteCharacter(): void
    {
        // The behaviour the hand-rolled dropLast() existed for, now the
        // widget's: a backspace after an emoji must not leave half of it.
        $this->assertSame(
            'hi ',
            $this->drive(new Chat(inputBuf: 'hi 🚀'), new KeyMsg(KeyType::Backspace))->inputBuf,
        );
    }

    /**
     * Word motion, both modifier spellings. Alt+arrow is what macOS terminals
     * send; Ctrl+arrow is the xterm-family spelling of the same intent.
     */
    public function testWordMotionMovesAWordAtATimeUnderEitherModifier(): void
    {
        foreach ([['alt', true, false], ['ctrl', false, true]] as [$label, $alt, $ctrl]) {
            $chat = new Chat(inputBuf: 'hello there world');

            $once = $this->drive($chat, new KeyMsg(KeyType::Left, alt: $alt, ctrl: $ctrl));
            $this->assertSame(12, $once->inputCursorOffset(), "{$label}+Left: to the start of \"world\"");

            $twice = $this->drive($once, new KeyMsg(KeyType::Left, alt: $alt, ctrl: $ctrl));
            $this->assertSame(6, $twice->inputCursorOffset(), "{$label}+Left again: start of \"there\"");

            $back = $this->drive($twice, new KeyMsg(KeyType::Right, alt: $alt, ctrl: $ctrl));
            $this->assertSame(11, $back->inputCursorOffset(), "{$label}+Right: to the end of \"there\"");

            $this->assertSame('hello there world', $back->inputBuf, "{$label}: word motion must never edit");
        }
    }

    /**
     * Word motion and Ctrl+W agree on where a word starts, because both read
     * the same `dropLastWord()` boundary. If one of them ever grows its own
     * notion of a word, this is the assertion that catches it.
     *
     * The equality is the ONLY thing pinned, and that is deliberate: an earlier
     * form of this test also asserted the two literals (`11` and
     * `'alpha beta '`), which made the equality `11 === 11` — unfailable, since
     * both sides were already nailed to the same constant by the assertions
     * above it. Table-driven over drafts with different word shapes instead, so
     * every row's expectation is computed from the two mechanisms under test and
     * from nothing else.
     *
     * The drafts vary what the boundary has to decide: a plain run of words, a
     * multi-space gap, punctuation, a trailing space (`dropLastWord()` eats the
     * gap AND the word before it), and a multi-byte word (where a byte-wise
     * boundary would disagree with a codepoint-wise one).
     */
    public function testWordLeftLandsExactlyWhereCtrlWWouldHaveCut(): void
    {
        foreach ([
            'plain words' => 'alpha beta gamma',
            'multi-space gap' => 'alpha beta   gamma',
            'punctuation' => 'alpha beta, gamma;',
            'trailing space' => 'alpha beta gamma ',
            'trailing run of spaces' => 'alpha beta   ',
            'multi-byte word' => 'alpha βῆτα γάμμα',
            'single word' => 'gamma',
            'leading space only' => '  gamma',
        ] as $label => $draft) {
            $chat = new Chat(inputBuf: $draft);
            $this->assertSame(
                mb_strlen($draft),
                $chat->inputCursorOffset(),
                "fixture ({$label}): the seed must leave the cursor at the end, or the two "
                . 'mechanisms are being asked about different positions',
            );

            $moved = $this->drive($chat, new KeyMsg(KeyType::Left, alt: true));
            $cut = $this->drive($chat, new KeyMsg(KeyType::Char, 'w', ctrl: true));

            $this->assertSame(
                mb_strlen($cut->inputBuf),
                $moved->inputCursorOffset(),
                "{$label}: Alt+Left must stop at the column Ctrl+W cuts back to",
            );
            // And the cut is real — without this the equality would also be
            // satisfied by a Ctrl+W that deleted nothing and an Alt+Left that
            // moved nowhere. It is a bound rather than a literal: how MUCH
            // comes off is what the row above is asking about.
            $this->assertLessThan(
                mb_strlen($draft),
                mb_strlen($cut->inputBuf),
                "{$label}: fixture chosen so Ctrl+W has a word to take",
            );
        }
    }

    // -------------------------------------------------------------------------
    // 2. Ctrl+W is now cursor-relative, and still identical at the end
    // -------------------------------------------------------------------------

    public function testCtrlWAtTheEndOfTheDraftIsUnchangedFromTheHandRolledForm(): void
    {
        // The exact pair ChatTest pins, restated here so a regression shows up
        // in the file that owns the delegation too.
        foreach ([new KeyMsg(KeyType::Char, 'w', ctrl: true), new KeyMsg(KeyType::Backspace, alt: true)] as $key) {
            $this->assertSame(
                'hello there ',
                $this->drive(new Chat(inputBuf: 'hello there world'), $key)->inputBuf,
            );
        }
    }

    public function testCtrlWMidDraftKeepsEverythingAfterTheCursor(): void
    {
        $chat = $this->drive(new Chat(inputBuf: 'foo bar baz'), ...array_fill(0, 4, new KeyMsg(KeyType::Left)));
        $this->assertSame(7, $chat->inputCursorOffset(), 'fixture: cursor after "bar"');

        $cut = $this->drive($chat, new KeyMsg(KeyType::Char, 'w', ctrl: true));

        $this->assertSame('foo  baz', $cut->inputBuf, '"bar" goes, " baz" stays');
        $this->assertSame(4, $cut->inputCursorOffset(), 'and the cursor sits where the deleted word started');
    }

    // -------------------------------------------------------------------------
    // 3. Multi-line drafts — why TextArea and not TextInput
    // -------------------------------------------------------------------------

    /**
     * The measurement the TextArea-over-TextInput choice rests on: a draft
     * with a newline in it is painted as a real two-row box, so multi-line
     * drafts are a live visible feature rather than a latent one.
     *
     * @see Chat::freshInput()
     */
    public function testATwoLineDraftIsPaintedAsTwoRowsInsideTheInputBox(): void
    {
        $chat = $this->drive(
            (new Chat(backend: new EchoBackend()))->withSize(60, 20),
            ...$this->type('ab'),
            ...[new KeyMsg(KeyType::Enter, alt: true)],
            ...$this->type('cd'),
        );
        $this->assertSame("ab\ncd", $chat->inputBuf);

        $rows = [];
        foreach (explode("\n", $chat->view()) as $row) {
            $plain = preg_replace('/\e\[[0-9;]*m/', '', $row) ?? $row;
            if (str_contains($plain, '> ab') || str_contains($plain, 'cd')) {
                $rows[] = trim($plain, "│ ");
            }
        }

        $this->assertSame(['> ab', 'cd█'], $rows, 'the two draft lines occupy two physical rows');
    }

    public function testAltEnterSplitsTheLineAtTheCursorRatherThanAppending(): void
    {
        $chat = $this->drive(
            new Chat(inputBuf: 'abcd'),
            new KeyMsg(KeyType::Left),
            new KeyMsg(KeyType::Left),
            new KeyMsg(KeyType::Enter, alt: true),
        );

        $this->assertSame("ab\ncd", $chat->inputBuf, 'the newline lands AT the cursor');
        $this->assertSame(3, $chat->inputCursorOffset(), 'and the cursor follows it onto the new row');
    }

    /**
     * Every one of the three modifier spellings still appends a newline on a
     * single-line draft with the cursor at the end — the case ChatTest and
     * KeyBindingDriftTest pin — because that is where the cursor already is.
     */
    public function testAllThreeNewlineProducersStillAppendOnAnEndOfDraftCursor(): void
    {
        foreach (['alt', 'shift', 'ctrl'] as $modifier) {
            $key = new KeyMsg(
                KeyType::Enter,
                alt: $modifier === 'alt',
                shift: $modifier === 'shift',
                ctrl: $modifier === 'ctrl',
            );

            $this->assertSame(
                "line one\n",
                $this->drive(new Chat(inputBuf: 'line one'), $key)->inputBuf,
                "{$modifier}+Enter",
            );
        }
    }

    public function testUpAndDownMoveBetweenRowsOfAMultiLineDraft(): void
    {
        $chat = new Chat(inputBuf: "alpha\nbeta");
        $this->assertSame(10, $chat->inputCursorOffset(), 'fixture: seeded at the end of the last row');

        $up = $this->drive($chat, new KeyMsg(KeyType::Up));
        $this->assertSame(4, $up->inputCursorOffset(), 'Up keeps the column and changes the row');

        $typed = $this->drive($up, new KeyMsg(KeyType::Char, '!'));
        $this->assertSame("alph!a\nbeta", $typed->inputBuf, 'and typing lands on the row the cursor moved to');

        $down = $this->drive($up, new KeyMsg(KeyType::Down));
        $this->assertSame(10, $down->inputCursorOffset(), 'Down comes back');
    }

    /**
     * Vertical motion is gated on the draft actually HAVING a second row, so
     * that the two Up arms above it in `update()` keep the whole single-line
     * keyboard they already own. Both are re-driven here against the
     * delegation, because a gate written the other way round would silently
     * steal them.
     */
    public function testUpOnASingleLineDraftIsStillRecallOrANoOpAndNeverRowMotion(): void
    {
        // Empty draft: Chat's own shell-history recall, not the widget's (the
        // widget has no history at all — that is why TextArea was chosen).
        $recalled = $this->drive(
            new Chat(history: [Message::user('earlier')]),
            new KeyMsg(KeyType::Up),
        );
        $this->assertSame('earlier', $recalled->inputBuf);
        $this->assertSame(7, $recalled->inputCursorOffset(), 'a recalled draft is ready to keep typing on');

        // Non-empty single-line draft: still the no-op default arm.
        $chat = new Chat(inputBuf: 'draft');
        [$up] = $chat->update(new KeyMsg(KeyType::Up));
        $this->assertSame($chat, $up, 'Up on a one-line draft must not even clone');

        // And the "/" popup still claims Up ahead of everything.
        $menu = new Chat(inputBuf: '/th');
        [$navigated] = $menu->update(new KeyMsg(KeyType::Up));
        $this->assertSame('/th', $navigated->inputBuf, 'the popup claimed it; the draft is untouched');
    }

    // -------------------------------------------------------------------------
    // 4. The two write routes into the draft cannot disagree
    // -------------------------------------------------------------------------

    /**
     * `inputBuf` is derived from the widget, so a write through the string key
     * has to reseed the widget or it would be silently ignored. Every "replace
     * the draft" route in `Chat` goes through that key.
     */
    public function testAStringWriteReplacesTheDraftAndParksTheCursorAtItsEnd(): void
    {
        // Slash completion (Enter on an ambiguous prefix) writes the string.
        $completed = $this->drive(new Chat(inputBuf: '/re'), new KeyMsg(KeyType::Enter));
        $this->assertSame('/rename ', $completed->inputBuf);
        $this->assertSame(8, $completed->inputCursorOffset(), 'ready to type the argument');

        // Up-recall writes the string too, over a cursor that had been moved.
        $recalled = $this->drive(
            new Chat(history: [Message::user('second message')]),
            new KeyMsg(KeyType::Up),
        );
        $this->assertSame('second message', $recalled->inputBuf);
        $this->assertSame(14, $recalled->inputCursorOffset());

        // Submit clears it.
        $sent = $this->drive(new Chat(backend: new EchoBackend(), inputBuf: 'hi'), new KeyMsg(KeyType::Enter));
        $this->assertSame('', $sent->inputBuf);
        $this->assertSame(0, $sent->inputCursorOffset());
    }

    /**
     * `mutate()` rebuilds `Chat` from its constructorProps map on every
     * transition; a widget missing from that map would reset the cursor (and
     * the draft) on the next unrelated state change.
     */
    public function testTheCursorSurvivesAnUnrelatedStateChange(): void
    {
        $chat = $this->drive(
            new Chat(inputBuf: 'hello world'),
            new KeyMsg(KeyType::Left),
            new KeyMsg(KeyType::Left),
        );
        $this->assertSame(9, $chat->inputCursorOffset());

        [$resized] = $chat->update(new WindowSizeMsg(80, 24));

        $this->assertSame('hello world', $resized->inputBuf);
        $this->assertSame(9, $resized->inputCursorOffset(), 'a resize must not move the cursor');
    }

    /**
     * A revived checkpoint row can be arbitrarily long, so the draft editor is
     * built with `withCharLimit(0)`. TextArea's 65536 default would silently
     * truncate one — a loss the hand-rolled string did not have.
     */
    public function testALongDraftIsNotTruncatedByTheWidgetsDefaultCharLimit(): void
    {
        $long = str_repeat('x', 70_000);
        $chat = new Chat(inputBuf: $long);

        $this->assertSame(70_000, mb_strlen($chat->inputBuf));
        $this->assertSame(70_000, $chat->inputCursorOffset());
    }

    // -------------------------------------------------------------------------
    // 5. Modal precedence — typing stays inert under every keyboard owner
    // -------------------------------------------------------------------------

    /**
     * The delegation sits BELOW every modal arm in `update()`. Each of these
     * states owns the keyboard, and a Char/Left/Backspace reaching the widget
     * would type into (or edit behind) a box the user cannot see.
     *
     * A live PERMISSION prompt is the fourth such owner and is deliberately
     * NOT in this list. Its cursor-key case is driven in
     * `Renderer\KeyHelpTest::testCursorMotionAndEditingAreSwallowedByALivePromptToo()`
     * instead, because raising a prompt means constructing a
     * `PermissionRequestMsg`, and the set of files that do so is itself pinned
     * as the domain `Chat::requestPermission()`'s mutation table was measured
     * over — a fourth constructing file would stale every figure in it. That
     * test's docblock carries the full reasoning.
     *
     * The CURSOR is asserted alongside the text, and without that half this
     * test has no power over most of its table: motion can never change
     * `inputBuf`, so `Left`/`Right`/`Home`/`End` were inert assertions here.
     * Proven blind rather than argued — mutating
     * `Chat::handlePaletteKey()`'s `default => [$this, null]` to
     * `default => $this->delegateToInput($msg)` leaks motion into the
     * invisible widget (`Home` under an open palette moves the cursor 5 → 0)
     * and left the whole suite green. Its `Renderer\KeyHelpTest` sibling
     * already asserted both halves; this is the same table brought up to it.
     *
     * Only the palette and picker rows carry that power, and by construction:
     * `?` opens the reference ONLY on a blank draft, so that row's cursor is at
     * offset 0 with nothing to the left and no tail to delete. The other two
     * are seeded with `'draft'` and a cursor at 5.
     *
     * @dataProvider modalStates
     */
    public function testTypingAndCursorMotionAreInertWhileAModalOwnsTheKeyboard(callable $factory): void
    {
        $chat = $factory();
        $before = $chat->inputBuf;
        $at = $chat->inputCursorOffset();

        foreach ([
            new KeyMsg(KeyType::Char, 'z'),
            new KeyMsg(KeyType::Space),
            new KeyMsg(KeyType::Left),
            new KeyMsg(KeyType::Right),
            new KeyMsg(KeyType::Home),
            new KeyMsg(KeyType::End),
            new KeyMsg(KeyType::Delete),
            new KeyMsg(KeyType::Backspace),
            // The three ctrl forms Chat claims explicitly instead of
            // delegating; they must be just as inert behind a modal.
            new KeyMsg(KeyType::Space, ' ', ctrl: true),
            new KeyMsg(KeyType::Backspace, ctrl: true),
            new KeyMsg(KeyType::Delete, ctrl: true),
        ] as $key) {
            [$next] = $chat->update($key);
            $this->assertSame($before, $next->inputBuf, 'a hidden input box must not collect keystrokes');
            $this->assertSame(
                $at,
                $next->inputCursorOffset(),
                'nor move a cursor the user cannot see',
            );
        }
    }

    /** @return array<string, array{0: callable(): Chat}> */
    public static function modalStates(): array
    {
        return [
            'keybinding reference' => [static function (): Chat {
                [$open] = (new Chat())->update(new KeyMsg(KeyType::Char, '?'));
                self::assertNotNull($open->keyHelp(), 'fixture: the reference did not open');

                return $open;
            }],
            'command palette' => [static function (): Chat {
                [$open] = (new Chat(inputBuf: 'draft'))->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
                self::assertNotNull($open->palette(), 'fixture: the palette did not open');

                return $open;
            }],
            'session picker' => [static function (): Chat {
                $store = new SessionStore(':memory:');
                $store->createSession('sess-a', 'sugarcrush', 'test-model', null, 'Alpha');
                $store->createSession('sess-b', 'sugarcrush', 'test-model', null, 'Beta');

                [$open] = (new Chat(inputBuf: 'draft', sessionStore: $store, currentSessionId: 'sess-a'))
                    ->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));
                self::assertNotNull($open->sessionPicker(), 'fixture: the picker did not open');

                return $open;
            }],
        ];
    }

    public function testWhileATurnIsInFlightNothingReachesTheDraft(): void
    {
        $chat = new Chat(inputBuf: 'sent', inFlight: true);

        foreach ([new KeyMsg(KeyType::Char, 'z'), new KeyMsg(KeyType::Left), new KeyMsg(KeyType::Delete)] as $key) {
            [$next] = $chat->update($key);
            $this->assertSame($chat, $next, 'in-flight keystrokes are dropped, object identity and all');
        }
    }

    // -------------------------------------------------------------------------
    // 6. Keys the delegation must NOT claim
    // -------------------------------------------------------------------------

    /**
     * `TextArea::update()` reserves ctrl+a/e/u/k/o for its own line edits, and
     * `Chat` binds ctrl+a/o/p/r/w to commands of its own. Handing a
     * ctrl-flagged Char to the widget's `update()` would therefore swallow
     * Ctrl+L and Ctrl+K, which `Renderer\KeyHelpTest`'s byte map asserts type
     * the LETTER — the measurement its "a form feed is not typeable" claim
     * rests on. So they go to `insertRune()` instead.
     */
    public function testAnUnclaimedCtrlCharStillTypesItsLetterAtTheCursor(): void
    {
        foreach (['l', 'k', 'e', 'u'] as $rune) {
            $this->assertSame(
                'why' . $rune,
                $this->drive(new Chat(inputBuf: 'why'), new KeyMsg(KeyType::Char, $rune, ctrl: true))->inputBuf,
                "Ctrl+" . strtoupper($rune),
            );
        }

        // At the cursor, not at the end — the one thing that DID change.
        $mid = $this->drive(
            new Chat(inputBuf: 'why'),
            new KeyMsg(KeyType::Home),
            new KeyMsg(KeyType::Char, 'l', ctrl: true),
        );
        $this->assertSame('lwhy', $mid->inputBuf);
    }

    /**
     * Chat's own chords stay Chat's: TextArea binds Ctrl+O to "open the draft
     * in $EDITOR" and Ctrl+A/Ctrl+E to line motion, all three of which Chat
     * claims first. Ctrl+O reaching the widget would spawn an editor out of a
     * chord that is documented to expand tool output.
     */
    public function testChatsOwnCtrlChordsAreNotTypedAndDoNotReachTheWidget(): void
    {
        foreach (['p', 'o', 'r'] as $rune) {
            $this->assertSame(
                '',
                $this->drive(new Chat(), new KeyMsg(KeyType::Char, $rune, ctrl: true))->inputBuf,
                "Ctrl+" . strtoupper($rune) . " must not type a literal letter",
            );
        }
    }

    /**
     * Plain Tab puts nothing in the box — TextArea's own Tab arm inserts four
     * spaces, so Tab must stay off the delegation list. `KeyHelpTest`'s
     * "a tab draft is untypeable" byte map depends on this.
     */
    public function testPlainTabStillPutsNothingInTheDraft(): void
    {
        foreach (['', 'why'] as $draft) {
            $this->assertSame(
                $draft,
                $this->drive(new Chat(inputBuf: $draft), new KeyMsg(KeyType::Tab))->inputBuf,
            );
        }
    }

    /**
     * The three ctrl-flagged draft keys that must NOT be delegated, driven as
     * the real bytes a terminal sends.
     *
     * `TextArea::update()` opens with `if ($msg->ctrl)` and answers from a
     * five-entry rune table (a/e/u/k/o), dropping every other ctrl-flagged key
     * whatever its TYPE. So Ctrl+Space and Ctrl+Backspace died the moment the
     * delegation replaced `Chat`'s hand-rolled Space/Backspace arms — measured
     * against HEAD, `CSI 32;5u` on the draft `ab cd` gave `ab cd ` there and
     * `ab cd` after; `CSI 127;5u` gave `ab c` there and `ab cd` after. Nothing
     * in the suite noticed, which is what this test is for.
     *
     * The three expectations are NOT all restorations, and the difference is
     * the point:
     *
     *   * Ctrl+Space types a space — exactly HEAD's behaviour;
     *   * Ctrl+Backspace deletes the word before the cursor, where HEAD deleted
     *     one character. A deliberate upgrade, justified by word motion
     *     (Alt/Ctrl+←/→) now existing, and it shares `Ctrl+W`'s boundary helper
     *     so the two can never disagree;
     *   * Ctrl+Delete deletes the word after the cursor, which was a no-op at
     *     HEAD — a pure addition, mirroring the above through the forward
     *     boundary.
     *
     * Ctrl+Home / Ctrl+End stay no-ops, as they were at HEAD, and are asserted
     * as such so the omission is recorded rather than assumed.
     */
    public function testTheCtrlFlaggedDraftKeysDoWhatTheirOwnArmsSayRatherThanDyingInTheWidget(): void
    {
        foreach ([
            'Ctrl+Space' => ["\x1b[32;5u", 'ab cd ', 6],
            'Ctrl+Backspace' => ["\x1b[127;5u", 'ab ', 3],
            'Ctrl+Delete' => ["\x1b[3;5~", 'ab cd', 5],
            'Ctrl+Home' => ["\x1b[1;5H", 'ab cd', 5],
            'Ctrl+End' => ["\x1b[1;5F", 'ab cd', 5],
        ] as $label => [$bytes, $expectedBuf, $expectedAt]) {
            $decoded = (new InputReader())->parse($bytes);
            $this->assertCount(1, $decoded, "fixture ({$label}): one message per chord");
            $this->assertInstanceOf(KeyMsg::class, $decoded[0], "fixture ({$label})");
            $this->assertTrue($decoded[0]->ctrl, "fixture ({$label}): the decoder must set the ctrl flag");

            $next = $this->drive(new Chat(inputBuf: 'ab cd'), $decoded[0]);
            $this->assertSame($expectedBuf, $next->inputBuf, $label);
            $this->assertSame($expectedAt, $next->inputCursorOffset(), $label . ': cursor');
        }

        // Mid-draft, where "before" and "after" are two different words rather
        // than one word and nothing.
        $mid = $this->drive(new Chat(inputBuf: 'alpha beta gamma'), new KeyMsg(KeyType::Left, alt: true));
        $this->assertSame(11, $mid->inputCursorOffset(), 'fixture: parked at the start of "gamma"');

        $back = $this->drive($mid, new KeyMsg(KeyType::Backspace, ctrl: true));
        $this->assertSame('alpha gamma', $back->inputBuf, 'Ctrl+Backspace takes the word BEFORE');
        $this->assertSame(6, $back->inputCursorOffset());

        $forward = $this->drive($mid, new KeyMsg(KeyType::Delete, ctrl: true));
        $this->assertSame('alpha beta ', $forward->inputBuf, 'Ctrl+Delete takes the word AFTER');
        $this->assertSame(11, $forward->inputCursorOffset(), 'and leaves the cursor where it was');
    }

    /**
     * Ctrl+Backspace and Ctrl+W cut back to the same column, and Ctrl+Delete
     * stops where Alt+Right would have moved to — the forward mirror of
     * {@see testWordLeftLandsExactlyWhereCtrlWWouldHaveCut()}.
     *
     * Derived on both sides: nothing here states an offset or a string, so the
     * only thing pinned is that the four mechanisms read one boundary.
     */
    public function testTheCtrlWordDeletesShareTheirBoundaryWithWordMotion(): void
    {
        foreach ([
            'plain words' => 'alpha beta gamma',
            'multi-space gap' => 'alpha  beta   gamma',
            'punctuation' => 'alpha beta, gamma;',
            'multi-byte word' => 'alpha βῆτα γάμμα',
        ] as $label => $draft) {
            // Parked one word in from the right, so both directions have a
            // whole word to work on.
            $parked = $this->drive(new Chat(inputBuf: $draft), new KeyMsg(KeyType::Left, alt: true));

            $ctrlW = $this->drive($parked, new KeyMsg(KeyType::Char, 'w', ctrl: true));
            $ctrlBs = $this->drive($parked, new KeyMsg(KeyType::Backspace, ctrl: true));
            $this->assertSame($ctrlW->inputBuf, $ctrlBs->inputBuf, "{$label}: same text");
            $this->assertSame(
                $ctrlW->inputCursorOffset(),
                $ctrlBs->inputCursorOffset(),
                "{$label}: same cursor",
            );

            $ctrlDel = $this->drive($parked, new KeyMsg(KeyType::Delete, ctrl: true));
            $altRight = $this->drive($parked, new KeyMsg(KeyType::Right, alt: true));
            $this->assertSame(
                mb_substr($draft, 0, $parked->inputCursorOffset())
                    . mb_substr($draft, $altRight->inputCursorOffset()),
                $ctrlDel->inputBuf,
                "{$label}: Ctrl+Delete must take exactly what Alt+Right skips",
            );
        }
    }

    /**
     * Every key type the draft newly answers is disclosed in the in-app `?`
     * reference — the instrument that was missing when seven of them shipped
     * live and undocumented.
     *
     * Read back from `Chat::DRAFT_KEYS` (the delegation list itself, via
     * reflection) rather than from a copy of it, so a KeyType added to that
     * list is a keystroke this test immediately demands a row for.
     *
     * Labels are compared TOKEN BY TOKEN, not by substring, and that is what
     * gives the test its power. Measured: with a substring test, deleting the
     * `chat.cursor` row (`← / →`) left this green, because `chat.word-motion`'s
     * `Alt+← / Alt+→` still CONTAINS both glyphs. Splitting each label on the
     * same `A / B` boundary the reference's own drift test uses makes `←` a
     * token only the bare-arrow row provides.
     *
     * DOMAIN, stated because a green run here is not a claim about the whole
     * keyboard: the delegation list plus the five chords `Chat::update()` claims
     * by hand for the draft, and it checks only that some live chat-context row
     * NAMES the key. It does not check that the row describes the key
     * correctly — `Commands\KeyBindingDriftTest` is what drives each row through
     * the real handler and asserts the described effect.
     *
     * Two holes, both closed on purpose rather than left silent:
     *
     *   * `Char` and `Space` are in the delegation list and have no row.
     *     "Typing a character puts it in the box" is not a binding and a
     *     reference that listed it would be noise, so they map to null here.
     *   * `↑`/`↓` are ambiguous — three live chat rows carry that exact token
     *     (the "/" popup, Up-recall, and the multi-line draft), so a token test
     *     cannot tell which one is missing. The multi-line-draft row is
     *     therefore pinned by ID instead, which is the only key here whose
     *     disclosure this test asserts by name rather than by label.
     */
    public function testEveryDelegatedKeyTypeIsDisclosedInTheReference(): void
    {
        /** @var list<KeyType> $delegated */
        $delegated = (new \ReflectionClass(Chat::class))->getConstant('DRAFT_KEYS');
        $this->assertNotSame([], $delegated, 'fixture: DRAFT_KEYS must be readable');

        // The exact label token each delegated key must appear as. Null =
        // ordinary typing, which the reference deliberately does not list.
        $labelToken = [
            KeyType::Char->name => null,
            KeyType::Space->name => null,
            KeyType::Backspace->name => 'Backspace',
            KeyType::Delete->name => 'Delete',
            KeyType::Left->name => '←',
            KeyType::Right->name => '→',
            KeyType::Home->name => 'Home',
            KeyType::End->name => 'End',
        ];

        $tokens = [];
        $labels = [];
        foreach (KeyBindingRegistry::live() as $binding) {
            if ($binding->context !== KeyBindingRegistry::CONTEXT_CHAT) {
                continue;
            }
            $labels[] = $binding->keys;
            foreach (preg_split('/\s+(?:\/\s+)?/u', trim($binding->keys)) ?: [] as $token) {
                if ($token !== '') {
                    $tokens[] = $token;
                }
            }
        }
        $shown = implode(' | ', $labels);

        // Plus the chords Chat claims by hand instead of delegating — they
        // reach the draft too, so the same disclosure rule applies.
        $required = ['Ctrl+Space', 'Ctrl+Backspace', 'Ctrl+Delete', 'Alt+←', 'Alt+→'];

        foreach ($delegated as $type) {
            $this->assertArrayHasKey(
                $type->name,
                $labelToken,
                'DRAFT_KEYS grew a member this test has no disclosure rule for — decide whether it '
                . 'is ordinary typing or a binding the reference must name.',
            );
            if ($labelToken[$type->name] !== null) {
                $required[] = $labelToken[$type->name];
            }
        }

        foreach (array_unique($required) as $token) {
            $this->assertContains(
                $token,
                $tokens,
                "'{$token}' reaches the draft but no live Chat row names it as a chord of its own, so "
                . "the in-app reference does not disclose it. Chat rows are: {$shown}",
            );
        }

        $rows = KeyBindingRegistry::byId('chat.draft-rows');
        $this->assertNotNull($rows, '↑/↓ move between the rows of a multi-line draft, undisclosed');
        $this->assertTrue($rows->isLive(), 'and the row must be listed, not dormant');
        $this->assertSame(KeyBindingRegistry::CONTEXT_CHAT, $rows->context);
    }

    /**
     * The `?`-on-a-blank-line guard is unchanged, and cursor motion gives the
     * message that STARTS with "?" a second, ordinary way to be composed —
     * the one the arm's own comment used to say could not exist.
     */
    public function testHomeThenAQuestionMarkComposesALeadingQuestionMark(): void
    {
        $chat = $this->drive(new Chat(), ...$this->type('why'));
        $composed = $this->drive($chat, new KeyMsg(KeyType::Home), new KeyMsg(KeyType::Char, '?'));

        $this->assertSame('?why', $composed->inputBuf);
        $this->assertNull($composed->keyHelp(), 'the reference must not open on a non-blank draft');

        // And on a genuinely empty line the guard still fires, so "??" is
        // still the hatch there.
        [$opened] = (new Chat())->update(new KeyMsg(KeyType::Char, '?'));
        $this->assertNotNull($opened->keyHelp());
        $this->assertSame('', $opened->inputBuf);
    }

    // -------------------------------------------------------------------------
    // 7. Rendering
    // -------------------------------------------------------------------------

    /**
     * The block cursor is painted where the widget says it is. With the cursor
     * at the end — where every seed, recall and completion leaves it — the box
     * is byte-identical to the pre-widget form, which is why the existing
     * `RendererTest` golden output needed no edit.
     */
    public function testTheBlockCursorIsPaintedAtTheCursorNotAtTheEnd(): void
    {
        $chat = (new Chat(inputBuf: 'abcd', backend: new EchoBackend()))->withSize(40, 10);

        $this->assertStringContainsString('> abcd█', $this->inputRow($chat), 'end-of-draft cursor');

        $moved = $this->drive($chat, new KeyMsg(KeyType::Left), new KeyMsg(KeyType::Left));
        $this->assertStringContainsString('> ab█cd', $this->inputRow($moved), 'mid-draft cursor');
    }

    /**
     * The frame must never grow a row wider than the terminal or taller than
     * it: candy-core's renderer repaints with an absolute `cursorTo()` and
     * paints one logical line per physical row.
     *
     * Measured over a multi-row draft, which is the shape this change makes
     * easier to reach: six Alt+Enters build a seven-row input box inside a
     * twelve-row terminal, so the box alone is more than half the frame.
     *
     * The two halves are measured at DIFFERENT widths, and deliberately:
     *
     *   * the HEIGHT clip is what this change could plausibly break, so it is
     *     driven at a deliberately cramped 40x12;
     *   * the WIDTH bound is asserted at 100 columns because at 40 the frame
     *     is ALREADY over-wide with no draft at all — measured, an empty
     *     `Chat` at 40x12 paints a 62-column row 0 (the transcript border box,
     *     which does not shrink below its own minimum). That is a pre-existing
     *     narrow-terminal renderer gap with nothing to do with the draft, and
     *     asserting against it here would pin someone else's bug to this file.
     *     Also unasserted, and also pre-existing: a single-line draft longer
     *     than the terminal over-widens the input box, because the box does
     *     not wrap. Neither is made better or worse by this change.
     */
    public function testAMultiRowDraftKeepsTheFrameInsideTheTerminal(): void
    {
        $build = function (int $cols, int $rows): Chat {
            $chat = (new Chat(history: [Message::user('hi')], backend: new EchoBackend()))->withSize($cols, $rows);
            foreach (range(1, 6) as $i) {
                $chat = $this->drive($chat, ...$this->type("row{$i}"));
                $chat = $this->drive($chat, new KeyMsg(KeyType::Enter, alt: true));
            }
            self::assertSame(6, substr_count($chat->inputBuf, "\n"), 'fixture: a seven-row draft');

            return $chat;
        };

        $lines = explode("\n", $build(40, 12)->view());
        $this->assertCount(12, $lines, 'the frame is clipped to the terminal height, not merely padded');

        foreach (explode("\n", $build(100, 24)->view()) as $i => $line) {
            $this->assertLessThanOrEqual(100, Width::string($line), "row {$i} is wider than the terminal");
        }
    }

    private function inputRow(Chat $chat): string
    {
        foreach (explode("\n", $chat->view()) as $row) {
            $plain = preg_replace('/\e\[[0-9;]*m/', '', $row) ?? $row;
            if (str_contains($plain, '> ')) {
                return $plain;
            }
        }

        return '';
    }
}
