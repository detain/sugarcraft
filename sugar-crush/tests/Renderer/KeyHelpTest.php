<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\InputReader;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseReleaseMsg;
use SugarCraft\Core\Msg\MouseWheelMsg;
use SugarCraft\Core\Msg\PasteMsg;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Commands\CommandRegistry;
use SugarCraft\Crush\Commands\KeyBindingRegistry;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Permissions\PermissionPromptStage;
use SugarCraft\Crush\Permissions\PermissionReply;
use SugarCraft\Crush\PermissionReplyMsg;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Tui\SessionPicker;

/**
 * The in-app keybinding reference (crush_code.md Phase 8 item 2): how it is
 * opened, how it is dismissed, and the two render invariants a frame in this
 * app may never break — no line wider than the terminal, no frame taller than
 * it, because candy-core's renderer repaints with an absolute cursorTo() and
 * paints exactly one logical line per physical row.
 *
 * @see Renderer::renderKeyHelp()
 * @see Chat::keyHelp()
 */
final class KeyHelpTest extends TestCase
{
    private const TITLE = 'keyboard shortcuts';

    private function chat(string $input = '', int $cols = 100, int $rows = 30): Chat
    {
        return (new Chat(
            history: [Message::user('hello'), Message::assistant('hi')],
            inputBuf: $input,
            backend: new EchoBackend(),
        ))->withSize($cols, $rows);
    }

    private function body(Chat $chat): string
    {
        $view = $chat->view();

        return is_string($view) ? $view : $view->body;
    }

    // ── opening ──────────────────────────────────────────────────────────

    public function testTheReferenceIsNotDrawnUntilItIsAskedFor(): void
    {
        $this->assertNull($this->chat()->keyHelp());
        $this->assertStringNotContainsString(self::TITLE, $this->body($this->chat()));
    }

    public function testQuestionMarkOnAnEmptyInputLineOpensIt(): void
    {
        [$next] = $this->chat()->update(new KeyMsg(KeyType::Char, '?'));

        $this->assertSame(0, $next->keyHelp());
        $this->assertStringContainsString(self::TITLE, $this->body($next));
    }

    /**
     * The whole reason "?" is guarded rather than bound outright: it is a
     * printable character, and a user typing a question must still get one.
     */
    public function testQuestionMarkIsTypedIntoANonEmptyInputLine(): void
    {
        [$next] = $this->chat('why')->update(new KeyMsg(KeyType::Char, '?'));

        $this->assertNull($next->keyHelp());
        $this->assertSame('why?', $next->inputBuf);
    }

    public function testSlashKeysOpensIt(): void
    {
        [$next] = $this->chat('/keys')->update(new KeyMsg(KeyType::Enter));

        $this->assertSame(0, $next->keyHelp());
        $this->assertSame('', $next->inputBuf, 'the command must not be left in the draft');
        $this->assertSame([], array_slice($next->history, 2), 'and must not be sent to the model');
    }

    /**
     * `/help` was a SECOND SPELLING of `/keys` — same arm, same reference —
     * until crush_code.md Phase 4 item 2 gave it the meaning it has in every
     * other CLI: the command list. This is the pin on the split, and it is
     * asserted in both directions, because "no longer an alias" is satisfiable
     * by `/help` doing nothing at all.
     *
     * The keyboard reference keeps `/keys` and `?`, and the popup keeps a row
     * for both spellings — see {@see testTheSlashPopupListsHelpToo()}.
     */
    public function testSlashHelpNoLongerOpensTheKeyboardReference(): void
    {
        [$next] = $this->chat('/help')->update(new KeyMsg(KeyType::Enter));

        $this->assertNull($next->keyHelp(), '/help must not open the keybinding reference any more');
        $this->assertSame('', $next->inputBuf, 'but it IS a command, so it clears the draft');

        $added = array_slice($next->history, 2);
        $this->assertCount(1, $added, 'and answers locally rather than going to the model');
        $this->assertSame(Role::Assistant, $added[0]->role);

        // The command LIST, and specifically the registry's own rows with their
        // argument hints — not a hand-written blurb that happens to mention a
        // command or two.
        foreach (CommandRegistry::slashCommands() as $spec) {
            $this->assertStringContainsString('/' . $spec->name, $added[0]->content, "/{$spec->name} must be listed");
            if ($spec->argumentHint !== null) {
                $this->assertStringContainsString(
                    $spec->argumentHint,
                    $added[0]->content,
                    "/{$spec->name}'s argument hint must be listed",
                );
            }
        }

        // And it points at the screen it used to BE, so the split does not lose
        // the discoverability the alias was added for.
        $this->assertStringContainsString('/keys', $added[0]->content);
    }

    /**
     * "/help me name this variable" is a prompt, not a request for the command
     * list — which is why `/help` matches exactly rather than by prefix like
     * the argument-taking commands. Held over from when `/help` opened the
     * keyboard reference; the guard it pins is the same one.
     */
    public function testAPromptThatMerelyStartsWithHelpIsStillAPrompt(): void
    {
        [$next] = $this->chat('/help me name this variable')->update(new KeyMsg(KeyType::Enter));

        $this->assertNull($next->keyHelp());
        $this->assertContains(
            '/help me name this variable',
            self::contents($next),
            'it goes to the model as a prompt',
        );
    }

    /**
     * `/keys` is a discoverable NAME for the reference, never a way to reach it
     * past a half-typed draft — and both the README and `Chat::submit()`'s own
     * comment claimed the second until this test existed.
     *
     * `submit()` trims the WHOLE buffer and compares it exactly, so with a draft
     * in the box the command is just more prose. Driven one keystroke per
     * character, exactly as a user types them, because that is the difference
     * between this and reasoning about `submit()`: the earlier false claim was
     * written by reading the method rather than typing into it.
     *
     * The consequence is the one worth pinning — following the old README
     * silently SENT `why/keys` to the backend as a prompt.
     */
    public function testSlashKeysInAHalfTypedDraftIsSentAsAPromptNotAsACommand(): void
    {
        foreach (['/keys', '/help'] as $command) {
            $chat = $this->chat();
            foreach (preg_split('//u', 'why' . $command, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $rune) {
                [$chat] = $chat->update(new KeyMsg(KeyType::Char, $rune));
            }

            $this->assertSame('why' . $command, $chat->inputBuf);
            $this->assertSame([], $chat->slashMenuMatches(), 'the popup needs a leading "/", not an inner one');

            [$sent] = $chat->update(new KeyMsg(KeyType::Enter));
            $this->assertNull($sent->keyHelp(), "'why{$command}' must not open the reference");
            $this->assertContains(
                'why' . $command,
                self::contents($sent),
                'it goes to the model as a prompt, which is exactly why this is not an escape hatch',
            );
        }

        // And the route that DOES work from a draft: clear it, then "?".
        $chat = $this->chat('why');
        for ($i = 0; $i < 3; $i++) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Backspace));
        }
        $this->assertSame('', $chat->inputBuf, 'fixture: the draft is cleared by backspacing');

        [$open] = $chat->update(new KeyMsg(KeyType::Char, '?'));
        $this->assertSame(0, $open->keyHelp());
    }

    /**
     * Half of the claim that makes "`/keys` is a name, not an escape hatch" a
     * property rather than an anecdote: in each of these states, `?` opens the
     * reference exactly when `/keys` + `Enter` does. If a state existed where the
     * command worked and `?` did not, the command would be an escape hatch after
     * all and the README row above would be wrong again.
     *
     * Domain: the six states in {@see openRouteStates()}, each driven and each
     * asserted to paint a distinct frame. Note the in-flight one, where NEITHER
     * route opens the reference — the point is the agreement, not that both
     * always succeed.
     *
     * **This corpus is not the whole claim, and reading it as one is how a false
     * universal shipped.** Its six members were chosen to be FRAME-DISTINCT, a
     * criterion orthogonal to the predicate the agreement actually turns on —
     * `trim($inputBuf) === ''` versus `$inputBuf === ''`. A one-space draft is
     * frame-distinct from idle (measured: 7 bytes longer, so the distinctness rule
     * would have ADMITTED it) and it was the counterexample; it was simply never
     * tried. The predicate-shaped corpus is
     * {@see testTheTwoRoutesAgreeOnEveryBlankAndNonBlankDraft()}, and the two
     * together are what earns the sentence in `Chat::submit()`.
     */
    public function testTheCommandAndTheShortcutOpenTheReferenceInExactlyTheSameStates(): void
    {
        foreach ($this->openRouteStates() as $label => $chat) {
            [$viaShortcut] = $chat->update(new KeyMsg(KeyType::Char, '?'));

            $typed = $chat;
            foreach (['/', 'k', 'e', 'y', 's'] as $rune) {
                [$typed] = $typed->update(new KeyMsg(KeyType::Char, $rune));
            }
            [$viaCommand] = $typed->update(new KeyMsg(KeyType::Enter));

            $this->assertSame(
                $viaShortcut->keyHelp() !== null,
                $viaCommand->keyHelp() !== null,
                "'?' and '/keys' must agree about whether the reference opens ({$label}) — a state where "
                . 'only the command worked would make it the escape hatch the docs must not claim',
            );
        }
    }

    /**
     * The other half, and the corpus that actually earns the universal: drafts
     * chosen either side of the PREDICATE the two routes disagreed on, rather than
     * states chosen to render differently.
     *
     * `Chat::submit()` matches against `trim($inputBuf)`; the `?` arm in
     * `update()` used to test the raw `$inputBuf`. Everything trimmed-empty but
     * not empty fell between them, and there `/keys` + `Enter` opened the reference
     * while `?` typed a literal `?` into the whitespace — i.e. `/keys` WAS the
     * escape hatch the docs say it is not, reachable by one Space press. The `?`
     * arm now trims too.
     *
     * THREE routes are driven per draft `D`, and the third is here because the
     * first two cannot disagree:
     *
     * 1. `?` pressed on `D` — opens iff `trim(D) === ''`;
     * 2. `/keys` TYPED ONTO `D`, then `Enter` — opens iff
     *    `trim(D . '/keys') === '/keys'`, i.e. iff `trim(D) === ''`. That is the
     *    escape-hatch question ("can the command get me past a half-typed
     *    draft?"), and the answer is no on every draft below. It is also the
     *    SAME predicate as route 1, so asserting that routes 1 and 2 agree is a
     *    restatement of the two guards and not evidence the corpus supplies —
     *    said plainly because a previous round counted it as the latter;
     * 3. `D` SUBMITTED as it stands — opens iff `trim(D)` is exactly `/keys`. This
     *    route CAN disagree with route 1, and does: the blank drafts, where `?`
     *    opens and `Enter` sends nothing, and the ones that ARE the command
     *    modulo whitespace (`'/keys'`, `' /keys'`, `'/keys '`, `"\t/keys"`,
     *    `"\n/keys"`, `"\0/keys"`, `"/keys\n"`), where `Enter` opens the
     *    reference and `?` types a character. The `/help`-shaped drafts were in
     *    that list until Phase 4 item 2 split `/help` off `/keys`; they are still
     *    driven, and now assert the reference does not open while the COMMAND
     *    still runs.
     *
     * Routes 1 and 3 are COMPLEMENTARY, not equivalent — and that is FORCED by the
     * two guards rather than measured here, which is the correction this revision
     * owes the previous one's subject line ("the corpus can finally say so"). `?`
     * opens iff `trim(D) === ''`; a submitted draft opens iff
     * `trim(D) === '/keys'`; those sets are disjoint by the definition of
     * `trim`. So NO draft can exhibit "both open", and no corpus — this one
     * included — could ever exhibit one. The set asserted at the foot of this
     * method is therefore a CLOSED-WORLD RECORD of which of ITS OWN members
     * disagree, not evidence of complementarity: it makes a corpus change re-state
     * itself, and nothing more. Every mutation of `Chat` tried against it reds one
     * of the per-draft assertions above it first (reverting the `?` arm to
     * `=== ''` reds the blank loop; prefix-matching `/keys` in `submit()` reds the
     * command loop), which is exactly what a derived set does.
     *
     * Pinned in BOTH directions, because either half alone is satisfiable by a
     * mistake:
     *
     * 1. the ten blank drafts (`''`, `' '`, `'  '`, `"\0"`, `"\0\0"`, `" \0 "`,
     *    `"\n"`, `"\n\n"`, `"\t"`, `" \t "`) open the reference by routes 1 and 2,
     *    and `?` leaves the draft ALONE — reverting the arm to `=== ''` reds this;
     * 2. the non-blank drafts (`' x '`, `'why'`, `'why '`, `' why'`, `'  x  '`, the
     *    `/`-shaped ones a lazy widening might swallow, and the two `?`-leading
     *    ones) open it by neither of those routes, and `?` is typed as a
     *    character — widening the arm to unconditional, or to `str_contains`-style
     *    sloppiness, reds this;
     * 3. and route 3's opening set is exactly `trim(D) === '/keys'`, with the
     *    disagreement against route 1 spelled out rather than denied — and the
     *    `/help`-shaped drafts assert the OTHER command they now reach, so the
     *    set shrinking cannot be mistaken for the drafts being dropped.
     *
     * Four things about the corpus itself, every one of which used to be asserted
     * by nothing:
     *
     * - Space is driven twice, as `KeyType::Space` and as `KeyType::Char ' '`.
     *   Measured, `InputReader::parse(' ')` yields only the first (asserted
     *   below); the second is driven because the `Char` arm accepts it, i.e. the
     *   corpus is stricter than the decoder rather than matching it.
     * - REACHABILITY, by the three routes a draft actually gets into the box. The
     *   previous revision called `"\t"`, `" \t "` and `"\t/keys"` "SYNTHETIC
     *   drafts: no keystroke can produce them", and that was wrong in BOTH
     *   directions — it over-claimed for those three and under-claimed by omitting
     *   two reachable blank drafts entirely. What holds is the narrower verb:
     *   nothing can TYPE them.
     *   * TYPED — the `Char` arm. `''`, `' '`, `'  '`, `"\0"`, `"\0\0"` and
     *     `" \0 "`: measured, `parse("\x00")` yields `KeyType::Char "\0"`
     *     (Ctrl+Space / Ctrl+@ on most terminals), so `NUL` — the byte the
     *     README's "blank without being whitespace" sentence rests on — is typed
     *     like any other rune, and repeats and mixes with spaces like one.
     *   * COMPOSED — an arm that is not the `Char` arm. `"\n"` and `"\n\n"`:
     *     measured, `parse("\x1b\r")` yields `KeyType::Enter` with `alt`, and
     *     `update()`'s Alt/Shift/Ctrl+Enter arm appends `"\n"` to the buffer.
     *   * TYPED AFTER COMPOSED, or composed after typed — no new route, just the
     *     two above in sequence, and the reason the COMMAND-SHAPED half of the
     *     disagreement class is no longer one recalled-only member. `"\n/keys"`,
     *     `"\0/keys"`, `"/keys\n"` and `"\n/help"` are pure keystrokes; the
     *     growth rule this docblock states ("the corpus grows into it whenever a
     *     further member of that class is found reachable") was applied to the
     *     blank half in an earlier round and not to this one. The `/help` members
     *     remain reachable and remain driven; they simply reach a different
     *     command now.
     *   * RECALLED — `update()`'s Up arm, which copies
     *     `lastUserMessageContent()` in verbatim and types no rune at all. This is
     *     the route that reaches `"\t"`, `" \t "`, `"\t/keys"` and `"\u{000C}"`,
     *     none of which any keystroke types: `parse("\t")` yields `KeyType::Tab`
     *     and Chat's Tab arm leaves the buffer alone, and `parse("\x0C")` yields
     *     `KeyType::Char 'l'` with `ctrl`, so Ctrl+L types the letter `l` and
     *     never a form feed (all three asserted below). A tab-bearing USER message
     *     is not typeable either — `submit()` trims — but
     *     `Chat::reviveCheckpointMessage()` maps every non-`assistant` checkpoint
     *     row to a `user` message with its content unchanged, and a `tool` row's
     *     content is full of tabs. Driven end to end below.
     *   There is still no paste route in. The decoder DOES emit
     *   `PasteMsg{content: "a\tb"}` for bracketed paste and `Chat::update()`
     *   returns the very same object when handed one — both asserted below, which
     *   replaces the instrument the previous revision cited: `grep -rn PasteMsg
     *   src/` is no longer empty, and every hit it returns is prose in `Chat.php`
     *   claiming it is.
     * - `'?'` and `'?why'` ARE reachable, contrary to an earlier revision of this
     *   comment which excluded them as impossible: `?` on a blank line opens the
     *   reference and a second `?` closes it and lands the character, which is
     *   the documented hatch — so {@see typeDraft()} performs it, and the last
     *   loop of this method is the hatch's own pin. Measured from that draft, a
     *   third `?` gives `'??'` and typing `/keys` onto it does not open the
     *   reference, i.e. `'?'` behaves as the ordinary non-blank draft it is
     *   listed as.
     * - `"\u{00A0}"`, `"\u{3000}"` and `"\u{000C}"` are in the NON-blank set, which
     *   is what makes "blank" mean `trim()`-empty rather than "whitespace-only" in
     *   the README row this file backs: `trim()`'s charlist is the ASCII
     *   `" \t\n\r\0\x0B"`, so a line of non-breaking spaces looks empty and `?`
     *   types into it. The first two decode as ordinary `Char` runes (measured), so
     *   a compose key reaches them; `"\u{000C}"` is RECALLED-only, per the bullet
     *   above, and is labelled so here rather than left standing beside them.
     */
    public function testTheTwoRoutesAgreeOnEveryBlankAndNonBlankDraft(): void
    {
        // The decoding facts the reachability bullet above rests on, driven rather
        // than reasoned about — candy-core's decoder and Chat's own arms.
        $this->assertSame(
            [[KeyType::Tab->name, '']],
            self::decode("\t"),
            'fixture: a tab byte decodes as KeyType::Tab, so no keystroke carries "\t" as a Char rune',
        );
        $this->assertSame(
            [[KeyType::Space->name, ' ']],
            self::decode(' '),
            'fixture: and a space byte decodes only as KeyType::Space, so driving Char " " too is strictness',
        );
        $this->assertSame(
            [[KeyType::Char->name, "\0"]],
            self::decode("\x00"),
            'fixture: NUL decodes as an ordinary Char rune (Ctrl+Space / Ctrl+@), so the one byte the '
            . "README's \"blank without being whitespace\" sentence rests on is TYPED, not synthetic",
        );
        $this->assertSame(
            [[KeyType::Char->name, 'l']],
            self::decode("\x0C"),
            'fixture: a form-feed byte decodes as Ctrl+L and Chat types the LETTER, so "\u{000C}" is not '
            . 'typeable either — the bullet above lists it as recalled-only on the strength of this',
        );
        $this->assertSame(['ctrl'], self::decodeMods("\x0C"), 'fixture: and it carries ctrl, not alt');
        $this->assertSame(
            [[KeyType::Enter->name, '']],
            self::decode("\x1b\r"),
            'fixture: ESC+CR decodes as Enter…',
        );
        $this->assertSame(
            ['alt'],
            self::decodeMods("\x1b\r"),
            'fixture: …with alt — which is the arm that appends "\n", so a newline draft is COMPOSED, not '
            . 'synthetic',
        );
        foreach (['', 'why'] as $before) {
            [$tabbed] = $this->chat($before)->update(new KeyMsg(KeyType::Tab));
            $this->assertSame(
                $before,
                $tabbed->inputBuf,
                'fixture: KeyType::Tab puts nothing in the box, which is what makes a tab draft untypeable',
            );
            [$ctrlL] = $this->chat($before)->update(new KeyMsg(KeyType::Char, 'l', ctrl: true));
            $this->assertSame(
                $before . 'l',
                $ctrlL->inputBuf,
                'fixture: and Ctrl+L types the letter, so no keystroke puts a form feed in the box either',
            );
            [$altEnter] = $this->chat($before)->update(new KeyMsg(KeyType::Enter, alt: true));
            $this->assertSame(
                $before . "\n",
                $altEnter->inputBuf,
                'fixture: Alt+Enter appends a newline, which is how a "\n" draft is composed',
            );
        }

        // Every byte `trim()` strips, put through the decoder and then through
        // Chat, because the README's "?" row rests on exactly this list and used to
        // recite it. Typeable means the byte ends up in the box; measured, only two
        // of the six are, and one of the four that are not (vertical tab) does not
        // even land silently — Ctrl+K types the letter.
        $landed = [];
        foreach ([' ', "\t", "\n", "\r", "\0", "\x0B"] as $byte) {
            $chat = $this->chat();
            foreach ((new InputReader())->parse($byte) as $decoded) {
                if ($decoded instanceof KeyMsg) {
                    [$chat] = $chat->update($decoded);
                }
            }
            $landed[bin2hex($byte)] = $chat->inputBuf;
        }
        $this->assertSame(
            ['20' => ' ', '09' => '', '0a' => '', '0d' => '', '00' => "\0", '0b' => 'k'],
            $landed,
            'of the six bytes trim() strips, only space and NUL are TYPEABLE: Tab puts nothing in the box, '
            . 'CR and LF submit, and Ctrl+K types a letter. The other four reach a draft only through the '
            . 'Up arm, which is what the README row now says',
        );

        // No paste route in, asserted at both ends rather than left to a grep. The
        // decoder DOES produce a paste message; Chat drops it, identity and all.
        $pastes = array_values(array_filter(
            (new InputReader())->parse("\x1b[200~a\tb\x1b[201~"),
            static fn(object $msg): bool => $msg instanceof PasteMsg,
        ));
        $this->assertCount(1, $pastes, 'fixture: bracketed paste really does decode to a PasteMsg');
        $this->assertSame("a\tb", $pastes[0]->content, 'fixture: carrying the tab verbatim');
        $pasted = $this->chat('why');
        [$afterPaste] = $pasted->update($pastes[0]);
        $this->assertSame(
            $pasted,
            $afterPaste,
            'Chat::update() drops a PasteMsg — the SAME object back, so there is no paste route into the '
            . 'box. This replaces "grep -rn PasteMsg src/ is empty", whose every hit today is prose in '
            . 'Chat.php claiming there are none',
        );

        // The three routes a draft reaches the box by. Kept apart, and named, so
        // that "no keystroke can produce them" cannot be written again over drafts
        // a user can really hold; the reachability bullet above measures each.
        $typedBlank = ['', ' ', '  ', "\0", "\0\0", " \0 "];
        $composedBlank = ["\n", "\n\n"];
        $recalledBlank = ["\t", " \t "];
        $blank = [...$typedBlank, ...$composedBlank, ...$recalledBlank];
        $nonBlank = [
            ' x ', 'why', 'why ', ' why', '  x  ', 'x', '/', '/k',
            '/keys', '/KEYS', ' /keys', '/keys ', "\t/keys", '/help', '  /help  ',
            // The COMMAND-SHAPED half of the disagreement class, grown by the
            // same rule that grew the blank half — see the reachability bullet.
            // Before this round it held exactly one non-space member ("\t/keys")
            // and that one is RECALLED-only, while these four are pure
            // keystrokes: Alt+Enter or Ctrl+Space, then the command (or the
            // command, then Alt+Enter). "\n/keys" is strictly more reachable
            // than the member the corpus already carried.
            "\n/keys", "\0/keys", "/keys\n", "\n/help",
            // Reachable only through the two-press hatch, which typeDraft() types.
            '?', '?why',
            // Visually blank and NOT blank, which is the README's wording made
            // checkable: trim()'s charlist is ASCII " \t\n\r\0\x0B", so a
            // non-breaking space, an ideographic space and a form feed are drafts
            // "?" types into. The first two decode as ordinary Char runes
            // (measured), so a compose key really reaches them; the form feed is
            // recalled-only, per the bullet above.
            "\u{00A0}", "\u{3000}", "\u{000C}",
        ];

        /** @var array<string, true> $disagreesOnSubmit */
        $disagreesOnSubmit = [];

        foreach ([...$blank, ...$nonBlank] as $draft) {
            $shouldOpen = in_array($draft, $blank, true);
            // `/keys` ALONE since Phase 4 item 2 split `/help` off it. The
            // `/help`-shaped drafts stayed in the corpus rather than being
            // deleted with the alias: they are now the members that prove the
            // reference does NOT open on a draft that is still a command, which
            // is a stronger row than the one they used to be. What `/help` does
            // instead is asserted below, in the same loop.
            $isCommand = trim($draft) === '/keys';
            $isHelpCommand = trim($draft) === '/help';
            $show = var_export($draft, true);

            foreach ([false, true] as $spaceAsSpaceKey) {
                $chat = $this->typeDraft($draft, $spaceAsSpaceKey);
                $this->assertSame($draft, $chat->inputBuf, "fixture: {$show} must reach the box verbatim");

                [$viaShortcut] = $chat->update(new KeyMsg(KeyType::Char, '?'));
                [$viaAppendedCommand] = $this->typeAndEnter($chat, '/keys');
                [$viaSubmittedDraft] = $chat->update(new KeyMsg(KeyType::Enter));

                $this->assertSame(
                    $shouldOpen,
                    $viaShortcut->keyHelp() !== null,
                    "'?' must open the reference on a BLANK line and type a character otherwise ({$show})",
                );
                $this->assertSame(
                    $shouldOpen,
                    $viaAppendedCommand->keyHelp() !== null,
                    "typing '/keys' onto this draft and pressing Enter must open the reference exactly on a "
                    . "blank line too ({$show}) — this is the escape-hatch question",
                );
                $this->assertSame(
                    $viaShortcut->keyHelp() !== null,
                    $viaAppendedCommand->keyHelp() !== null,
                    "'?' and an APPENDED '/keys' must agree ({$show}) — a draft where only the command worked "
                    . 'would make it the escape hatch the docs must not claim. Both guards reduce to '
                    . "trim(draft) === '', so this restates them rather than measuring something further",
                );

                // Route 3, the one that can and does disagree.
                $this->assertSame(
                    $isCommand,
                    $viaSubmittedDraft->keyHelp() !== null,
                    "submitting this draft as it stands must open the reference exactly when it IS the "
                    . "command modulo whitespace ({$show})",
                );
                if (($viaShortcut->keyHelp() !== null) !== ($viaSubmittedDraft->keyHelp() !== null)) {
                    $disagreesOnSubmit[$draft] = true;
                }

                // The other side of the split: a `/help`-shaped draft is still
                // a COMMAND, it just answers with the command list instead of
                // the keyboard reference. Without this, "the reference does not
                // open" would be equally satisfied by `/help` having been
                // deleted, or by it being sent to the model as prose.
                if ($isHelpCommand) {
                    $added = array_slice($viaSubmittedDraft->history, count($chat->history));
                    $this->assertCount(1, $added, "{$show} must answer locally, not go to the model");
                    $this->assertSame(Role::Assistant, $added[0]->role, "{$show} answers in the transcript");
                    $this->assertStringContainsString(
                        'Slash commands',
                        $added[0]->content,
                        "{$show} lists the commands",
                    );
                }

                // The cost of the widened guard, stated as an assertion: on a blank
                // draft "?" opens the reference and KEEPS the draft, so nothing the
                // user typed is discarded; on a non-blank one it is typed.
                $this->assertSame(
                    $shouldOpen ? $draft : $draft . '?',
                    $viaShortcut->inputBuf,
                    "'?' must leave a blank draft untouched and append to a non-blank one ({$show})",
                );
            }
        }

        // The disagreement between "?" and a SUBMITTED draft, as a closed-world
        // record of THIS corpus rather than as evidence of anything about Chat —
        // see the docblock: complementarity is forced by the two guards, and by the
        // same construction no member can be in both halves, so this set cannot be
        // what establishes it. What it is for is that a corpus change has to
        // re-state itself here. SORTED for the reason the floor set one method away
        // is (`sort($atFloor)`): keyed by insertion, the ONLY mutation that reddened
        // this line was reordering the literals above it, with every behavioural
        // assertion still green — an order artifact wearing an exhaustiveness label.
        //
        // GROWTH RULE, applied to BOTH halves. This set is not a boundary, so it
        // grows whenever a further member of the disagreement class turns out to
        // be reachable. An earlier round found two ("\0" and "\n") and grew only
        // the BLANK half with them, leaving the command-shaped half holding
        // exactly one non-space member ("\t/keys") that is RECALLED-only. Driven
        // since, keystrokes only: "\n/keys", "\0/keys" and "/keys\n" are
        // command-shaped and typeable, and "\n\n", "\0\0" and " \0 " are blank
        // and typeable. A rule stated on one half is a rule that has not been
        // applied.
        //
        // It SHRANK in Phase 4 item 2, and by the same rule: '/help',
        // '  /help  ' and "\n/help" left this set because `/help` stopped
        // opening the reference, so they no longer disagree with "?" about it.
        // They are still in the corpus above and are still driven — the loop
        // asserts they answer with the command list instead — which is the
        // difference between a set that shrank because the behaviour moved and
        // one that shrank because the drafts were deleted to make it pass.
        ksort($disagreesOnSubmit, SORT_STRING);
        $this->assertSame(
            [
                '', "\0", "\0\0", "\0/keys", "\t", "\t/keys", "\n", "\n\n", "\n/keys",
                ' ', " \0 ", " \t ", '  ', ' /keys', '/keys', "/keys\n", '/keys ',
            ],
            array_keys($disagreesOnSubmit),
            'the drafts on which "?" and a submitted draft differ: every blank one (only "?" opens) and '
            . 'every one that is the command modulo whitespace (only Enter opens). Byte-sorted, so '
            . 'reordering the corpus cannot flip it',
        );

        // The documented escape hatch has to survive the widening: after leading
        // whitespace, "??" must still land one literal "?" — same rule as on an
        // empty line, which is the whole point of widening rather than special-casing.
        // typeDraft() relies on this to build the "?"-leading members above, so a
        // break here reds those fixtures as well as this loop.
        foreach (['', ' ', '  ', "\t"] as $draft) {
            $chat = $this->typeDraft($draft);
            [$open] = $chat->update(new KeyMsg(KeyType::Char, '?'));
            [$typed] = $open->update(new KeyMsg(KeyType::Char, '?'));

            $this->assertNull($typed->keyHelp(), 'the second "?" closes the reference');
            $this->assertSame($draft . '?', $typed->inputBuf, 'and lands exactly one literal "?"');
        }

        // The RECALLED route, driven end to end rather than traced, because "no
        // keystroke can produce them" was the claim this corpus made about exactly
        // these drafts. A checkpoint row with any role but 'assistant' revives as a
        // USER message with its content unchanged (a 'tool' row's output is full of
        // tabs), and one Up copies the last user message into the box verbatim. The
        // outcome asserted here is the same one the corpus loop above asserts for
        // the identical draft, which is what makes those rows about a real user.
        foreach ([
            "\t" => true,
            " \t " => true,
            "\u{000C}" => false,
            "\t/keys" => false,
        ] as $draft => $questionOpens) {
            $revived = Chat::reviveCheckpointMessage(['role' => 'tool', 'content' => $draft]);
            $this->assertSame(
                'User',
                $revived->role->name,
                'fixture: reviveCheckpointMessage() maps a non-assistant row to a USER message, which is '
                . 'what puts untypeable content where the Up arm can reach it',
            );

            $chat = (new Chat(
                history: [$revived, Message::assistant('ok')],
                inputBuf: '',
                backend: new EchoBackend(),
            ))->withSize(100, 30);
            [$recalled] = $chat->update(new KeyMsg(KeyType::Up));
            $this->assertSame(
                $draft,
                $recalled->inputBuf,
                'Up recalls the revived message VERBATIM, so this draft is reachable without being typeable',
            );

            [$viaShortcut] = $recalled->update(new KeyMsg(KeyType::Char, '?'));
            $this->assertSame(
                $questionOpens,
                $viaShortcut->keyHelp() !== null,
                'and from there "?" behaves exactly as the corpus loop above says it does for this draft',
            );
        }

        // The COMPOSED route, likewise. THREE producers of a "\n" draft, not one:
        // Chat's arm accepts `Enter` with any of alt/shift/ctrl, and candy-core's
        // decoder emits all three — `\x1b\r` is Alt+Enter, `\x1b[13;2u` is
        // Shift+Enter and `\x1b[13;5u` is Ctrl+Enter (asserted below). A previous
        // revision said "Alt+Enter is the only producer" beside an assertion that
        // drove only the alt variant, so the over-claim was invisible: the
        // docblock bullet naming all three was right and this line was not. Each
        // is driven to the same blank draft, on which "?" opens and Enter sends
        // nothing.
        foreach ([
            'alt' => new KeyMsg(KeyType::Enter, alt: true),
            'shift' => new KeyMsg(KeyType::Enter, shift: true),
            'ctrl' => new KeyMsg(KeyType::Enter, ctrl: true),
        ] as $mod => $key) {
            [$newline] = $this->chat()->update($key);
            $this->assertSame(
                "\n",
                $newline->inputBuf,
                "fixture: {$mod}+Enter composes the newline draft — Chat's arm accepts all three modifiers",
            );
            [$newlineShortcut] = $newline->update(new KeyMsg(KeyType::Char, '?'));
            $this->assertNotNull($newlineShortcut->keyHelp(), 'a composed newline draft is blank, so "?" opens');
            [$newlineSubmitted] = $newline->update(new KeyMsg(KeyType::Enter));
            $this->assertSame(
                $newline->history,
                $newlineSubmitted->history,
                'and submitting it sends nothing, which is the disagreement the set above records for it',
            );
        }
        $this->assertSame(
            [[KeyType::Enter->name, '']],
            self::decode("\x1b[13;2u"),
            'fixture: the decoder really does deliver Shift+Enter…',
        );
        $this->assertSame(['shift'], self::decodeMods("\x1b[13;2u"), '…with shift and nothing else');
        $this->assertSame(
            [[KeyType::Enter->name, '']],
            self::decode("\x1b[13;5u"),
            'fixture: …and Ctrl+Enter…',
        );
        $this->assertSame(['ctrl'], self::decodeMods("\x1b[13;5u"), '…with ctrl, so all three producers are real');
    }

    /**
     * What candy-core's terminal decoder makes of a byte string, as
     * `[[KeyType name, rune], …]` — the instrument behind the "no keystroke can
     * TYPE this draft" claims above, so they are measured at the decoder
     * rather than argued from reading it.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function decode(string $bytes): array
    {
        return array_map(
            static fn(object $msg): array => [$msg->type->name, (string) $msg->rune],
            array_values(array_filter(
                (new InputReader())->parse($bytes),
                static fn(object $msg): bool => $msg instanceof KeyMsg,
            )),
        );
    }

    /**
     * The modifiers {@see decode()} deliberately drops, flattened to the names set
     * on the FIRST decoded key. Two of the reachability facts above turn on them
     * and on nothing else: `\x1b\r` is Alt+Enter, the arm that appends `"\n"`, and
     * `\x0C` is Ctrl+L, which types the letter `l` rather than a form feed. Read
     * off the same `[[type, rune]]` pair, both would look like drafts they are not.
     *
     * @return list<string>
     */
    private static function decodeMods(string $bytes): array
    {
        $keys = array_values(array_filter(
            (new InputReader())->parse($bytes),
            static fn(object $msg): bool => $msg instanceof KeyMsg,
        ));
        self::assertNotSame([], $keys, 'fixture: decodeMods() needs at least one key to report on');

        $mods = [];
        foreach (['alt', 'ctrl', 'shift'] as $mod) {
            if ($keys[0]->{$mod}) {
                $mods[] = $mod;
            }
        }

        return $mods;
    }

    /**
     * A draft typed one keystroke at a time rather than handed to the constructor,
     * because the whole class of bug this file now guards was invisible to
     * constructor-built fixtures.
     *
     * A `?` on a blank line opens the reference instead of landing, so a
     * `?`-leading draft is typed the way a user types one — twice, the second
     * press closing the reference and landing the character (the hatch pinned at
     * the foot of {@see testTheTwoRoutesAgreeOnEveryBlankAndNonBlankDraft()}).
     *
     * A `"\n"` is COMPOSED with `Alt`+`Enter` rather than typed as
     * `KeyMsg(Char, "\n")`. Both reach the box (measured — the `Char` arm accepts
     * the byte), but only one of them is a route a user has, and the corpus's
     * reachability bullet claims the composed one. Driving the byte directly
     * would have made every `"\n"`-bearing member of that corpus a fixture
     * asserting about a keystroke nobody can press.
     *
     * Every caller asserts the draft reached the box verbatim, so this helper
     * cannot quietly build something else.
     */
    private function typeDraft(string $draft, bool $spaceAsSpaceKey = false): Chat
    {
        $chat = $this->chat();
        foreach (preg_split('//u', $draft, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $rune) {
            if ($rune === '?' && trim($chat->inputBuf) === '') {
                [$chat] = $chat->update(new KeyMsg(KeyType::Char, '?'));
                [$chat] = $chat->update(new KeyMsg(KeyType::Char, '?'));
                continue;
            }

            $msg = match (true) {
                $rune === "\n" => new KeyMsg(KeyType::Enter, alt: true),
                $spaceAsSpaceKey && $rune === ' ' => new KeyMsg(KeyType::Space),
                default => new KeyMsg(KeyType::Char, $rune),
            };
            [$chat] = $chat->update($msg);
        }

        return $chat;
    }

    /**
     * @return array{0:Chat,1:?\Closure}
     */
    private function typeAndEnter(Chat $chat, string $text): array
    {
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $rune) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Char, $rune));
        }

        return $chat->update(new KeyMsg(KeyType::Enter));
    }

    /**
     * States from which either route to the reference might be tried, each
     * verified DISTINCT rather than assumed to be. `Ctrl+R` was a seventh entry and
     * was dropped: it finds no sessions on this fixture and the resulting frame is
     * byte-identical to the idle one, so it was a second copy of the first row
     * wearing a different label.
     *
     * Five of the six are driven as keystrokes. The sixth, `'a permission prompt
     * pending'`, is NOT — {@see blockedOnPermission()} hand-dispatches a
     * `PermissionRequestMsg`, so an earlier "each driven rather than assembled so
     * it is a state `Chat` can really be in" covered it falsely. The state it
     * produces is nevertheless real: driven with a `PreToolUse` hook returning
     * `HookResult::ask()` over a real turn, the same shape appears
     * (`pendingPermission()` set, `inFlight` true), and in both states `keyHelp`
     * stays NULL by either route. Only that much is claimed, and only that much is
     * measured — a previous revision wrote "both routes behave the same way there
     * as here", which is true of `keyHelp` and false of BEHAVIOUR. Typing `/keys`
     * at a live prompt is not an inert no-op: `handlePermissionKey()` reads the
     * `y` in "keys" as `PermissionReply::Once`, so measured, the third
     * keystroke APPROVES the pending call (`rm -rf build/` in that fixture) and
     * `pendingPermission()` goes null before `Enter` is ever pressed. The prompt
     * owning the keyboard is what makes the reference unreachable AND what makes
     * that happen; the same arm does both. That hazard predates this file's
     * interest in it and is reported rather than fixed here.
     *
     * What is NOT reachable is that prompt with the reference ALSO open, which is
     * asserted by
     * {@see testThePromptAndTheReferenceCannotBothBeRaisedByRealInput()}.
     *
     * @return array<string, Chat>
     */
    private function openRouteStates(): array
    {
        [$inFlight] = $this->chat('hello')->update(new KeyMsg(KeyType::Enter));
        $this->assertTrue($inFlight->inFlight, 'fixture: a turn must be in flight');
        $this->assertSame('', $inFlight->inputBuf, 'fixture: sending clears the draft');

        [$palette] = $this->chat()->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
        $this->assertNotNull($palette->palette(), 'fixture: Ctrl+P must open the palette');

        // A long transcript, because PageUp on the two-message fixture scrolls
        // nowhere — measured, offset stays 0 and the frame is byte-identical to
        // the idle one, so it would have been another duplicate row. The extra
        // history is the price of the state being real.
        $history = [];
        for ($i = 0; $i < 40; $i++) {
            $history[] = Message::user('question ' . $i);
            $history[] = Message::assistant('answer ' . $i);
        }
        $long = (new Chat(history: $history, inputBuf: '', backend: new EchoBackend()))->withSize(100, 30);
        $long->view();
        [$scrolled] = $long->update(new KeyMsg(KeyType::PageUp));
        $this->assertGreaterThan(0, $scrolled->scrollOffset(), 'fixture: PageUp must scroll back');

        $states = [
            'empty and idle' => $this->chat(),
            'a half-typed draft' => $this->chat('why'),
            'a turn in flight' => $inFlight,
            'the palette open' => $palette,
            'a permission prompt pending' => $this->blockedOnPermission(),
            'the transcript scrolled back' => $scrolled,
        ];

        $frames = array_map(fn(Chat $c): string => $this->body($c), $states);
        $this->assertCount(
            count($states),
            array_unique($frames),
            'fixture: every state must paint a DIFFERENT frame — two rows that render alike are one row',
        );

        return $states;
    }

    public function testTheSlashPopupListsKeys(): void
    {
        $this->assertSame(['keys'], self::popup('keys'));
    }

    /**
     * `/help` has to be discoverable too. It was originally added to the
     * registry as a second spelling of `/keys` — it worked when typed in full
     * and appeared in no popup, the registry-vs-behaviour drift
     * {@see CommandRegistry} exists to close. Phase 4 item 2 made it a command
     * in its own right (the slash-command list), and the row it needs is the
     * same row either way, which is why this test did not have to change.
     */
    public function testTheSlashPopupListsHelpToo(): void
    {
        $this->assertSame(['help'], self::popup('help'));
    }

    /**
     * @return list<string>
     */
    private static function popup(string $prefix): array
    {
        return array_map(
            static fn($spec): string => $spec->name,
            CommandRegistry::filter($prefix),
        );
    }

    // ── dismissal + key routing ──────────────────────────────────────────

    /**
     * @return list<array{0: string, 1: KeyMsg}>
     */
    public static function dismissKeys(): array
    {
        return [
            ['Escape', new KeyMsg(KeyType::Escape)],
            ['Enter', new KeyMsg(KeyType::Enter)],
            ['q', new KeyMsg(KeyType::Char, 'q')],
            ['?', new KeyMsg(KeyType::Char, '?')],
        ];
    }

    public function testEveryDocumentedDismissKeyCloses(): void
    {
        foreach (self::dismissKeys() as [$label, $key]) {
            [$open] = $this->chat()->update(new KeyMsg(KeyType::Char, '?'));
            [$closed] = $open->update($key);

            $this->assertNull($closed->keyHelp(), $label . ' must close the reference');
        }
    }

    /**
     * The regression `?` introduced, and the escape hatch that repays it.
     *
     * Binding `?` on an empty line made a message that STARTS with `?`
     * untypeable — not awkward, impossible. Measured on the commit that
     * introduced the bind: this input box HAD no cursor movement (there was no
     * `KeyType::Left`/`Right` arm anywhere in `Chat`) and no paste path, so
     * column 0 was reachable only by typing the first character, `?why` on an
     * empty line left `inputBuf` empty, and backspacing an existing draft down
     * to empty never yields `?` either. `/keys` is not a mitigation: it opens
     * the very screen such a user is trying to get past.
     *
     * The cursor half of that measurement has since expired: the draft moved
     * into `candy-forms`' TextArea (crush_code.md Phase 3 item 1), so a
     * NON-empty draft now has a second route — Home, then `?` — driven by
     * `ChatInputCursorTest::testHomeThenAQuestionMarkComposesALeadingQuestionMark()`.
     * On a genuinely empty line there is still no character for the cursor to
     * sit in front of, so what this test pins is unchanged and remains the only
     * route in that case.
     *
     * So the second `?` closes the reference AND lands the character. Driven
     * here as real keystrokes, one per character, exactly as a user types them.
     */
    public function testAMessageStartingWithAQuestionMarkIsTypeable(): void
    {
        $chat = $this->chat();
        foreach (['?', '?', 'w', 'h', 'y'] as $rune) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Char, $rune));
        }

        $this->assertNull($chat->keyHelp(), 'the second "?" closed the reference');
        $this->assertSame('?why', $chat->inputBuf);

        // And it really sends: the draft is a message like any other, not a
        // command the submit path intercepts.
        [$sent] = $chat->update(new KeyMsg(KeyType::Enter));
        $this->assertSame('', $sent->inputBuf);
        $this->assertContains('?why', self::contents($sent));
    }

    /**
     * The shortest case, and the one a "next printable rune falls through"
     * design would still not have covered: the whole message is `?`.
     */
    public function testALoneQuestionMarkIsATypeableMessage(): void
    {
        $chat = $this->chat();
        foreach (['?', '?'] as $rune) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Char, $rune));
        }

        $this->assertSame('?', $chat->inputBuf);
        $this->assertNull($chat->keyHelp());

        [$sent] = $chat->update(new KeyMsg(KeyType::Enter));
        $this->assertContains('?', self::contents($sent));
    }

    /**
     * @return list<string>
     */
    private static function contents(Chat $chat): array
    {
        return array_map(static fn(Message $m): string => $m->content, $chat->history);
    }

    /**
     * The other three dismissals stay CLEAN — the insert is `?`'s alone, which
     * is what leaves a reader who only wanted to close the screen unaffected.
     */
    public function testTheOtherDismissKeysLeaveNothingInTheInputBox(): void
    {
        foreach (self::dismissKeys() as [$label, $key]) {
            if ($label === '?') {
                continue;
            }

            [$open] = $this->chat()->update(new KeyMsg(KeyType::Char, '?'));
            [$closed] = $open->update($key);

            $this->assertSame('', $closed->inputBuf, $label . ' must not type anything');
        }
    }

    /**
     * The insert is disclosed ON SCREEN, not only in the code: a key that
     * silently puts a character in an input box the user was not typing into is
     * exactly the surprise this feature's own review flagged.
     */
    public function testTheFooterSaysThatTheSecondQuestionMarkTypesOne(): void
    {
        // Two sizes, because the hint is a different string in each: 100x30
        // overflows the box (62 live rows plus 9 headers and 8 separators = 79
        // content lines, against a 25-line body) so the footer carries the
        // scroll clause as well, while 100x90 fits the whole list and drops it.
        //
        // The live-row count moved 53 -> 54 when `permission.rearm` was
        // declared: a permission prompt disarmed by a stray keystroke can only
        // be answered again after Enter re-arms it, and a live binding the
        // reference does not list is exactly what this file exists to catch.
        // It moved 54 -> 62 when the draft's own editing keyboard was declared
        // — eight rows for keystrokes that had been live and undocumented since
        // the draft moved into `candy-forms`' TextArea, which is the same
        // failure one round later.
        //
        // The 25 derives from renderKeyHelp() rather than being counted off a
        // screenshot: at 100x30, keyHelpGeometry() gives boxRows = rows - 2 = 28,
        // the border takes two more so viewport = 26, and the footer itself
        // takes one, so body = 25. Which is why the measured
        // Renderer::keyHelpMaxOffset() here is 79 - 25 = 54; an earlier version
        // of this comment said 27, a body that would have made it 43. Both
        // overflow figures are asserted below rather than left in the prose,
        // since a body height stated and not read back is what went wrong.
        //
        // The second size was 100x80 and had to GROW to 100x90 when those eight
        // rows landed, which is the same arithmetic read the other way: a body
        // of 80 - 2 - 2 - 1 = 75 no longer holds 79 lines, while 90 gives 85 and
        // does. The 0 below is what measures that, so the "fits" half of this
        // test cannot quietly become a second overflow case.
        foreach ([[100, 30, 54], [100, 90, 0]] as [$cols, $rows, $expectedOverflow]) {
            [$open] = $this->chat('', $cols, $rows)->update(new KeyMsg(KeyType::Char, '?'));

            $this->assertStringContainsString(
                'closes and types',
                $this->body($open),
                "the footer must disclose the literal-? insert at {$cols}x{$rows}",
            );
            $this->assertSame(
                $expectedOverflow,
                Renderer::keyHelpMaxOffset(),
                "the box overflows by {$expectedOverflow} lines at {$cols}x{$rows}, which is what selects "
                . 'which of the two footer strings is painted',
            );
        }
    }

    /**
     * The footer's own width margin, asserted — the symmetric treatment to
     * {@see testTheTooSmallCueIsNeverWiderThanTheBarItReplaces()}. The footer
     * grew from 39 to 63 columns when the `?` clause was added, against
     * `Renderer::KEY_HELP_COLS = 64`, and one column of headroom stated in a
     * comment and read back by nothing is exactly the shape of figure this
     * feature keeps getting wrong.
     *
     * Instrument: `Width::of` on the footer row of the box
     * ({@see overlay()}'s output, second-to-last line) with its SGR runs and
     * border/padding stripped — the columns the row actually spends. `strlen`
     * reads 69 for the same string because of the multi-byte `·` and `↑↓`, and
     * that number is not the one the renderer sizes against: `Width::truncate()`
     * is column-correct, so those bytes never make the row over-wide.
     *
     * Domain: cols 68 and up, which is where the box reaches its full
     * `KEY_HELP_COLS` content width (`cols - KEY_HELP_CHROME_COLS >= 64`) and
     * therefore where the footer is under the least pressure — if it fits
     * nowhere, it fits here.
     *
     * The second half is the truncation COST, which the renderer's comment used
     * to understate as "may be truncated without losing a binding": no row of
     * the reference is lost, but the footer loses its own content, and by cols
     * 14 it is down to `Esc closes` with the `?` clause gone entirely. That is
     * the ordering working as intended (Esc first, scroll clause last), not a
     * free truncation — so it is measured rather than described.
     */
    public function testTheFooterFitsTheBoxWithARealMarginAndLosesItsTailFirst(): void
    {
        $chrome = (new \ReflectionClass(Renderer::class))->getConstant('KEY_HELP_CHROME_COLS');
        $limit = (new \ReflectionClass(Renderer::class))->getConstant('KEY_HELP_COLS');
        self::assertIsInt($chrome);
        self::assertIsInt($limit);

        foreach ([68, 80, 120, 200] as $cols) {
            $this->assertGreaterThanOrEqual(
                $limit,
                $cols - $chrome,
                "fixture: the box must be at its full content width at cols={$cols}",
            );

            $this->assertSame(
                63,
                Width::of($this->footer($this->chat('', $cols, 30))),
                "the scrolling footer spends 63 of the {$limit} columns available at cols={$cols} — one "
                . 'column of margin, and it is this test that keeps it real',
            );
            // 90 rows, not 80: the list is 79 content lines now (62 live rows,
            // 9 headers, 8 separators), and an 80-row terminal gives a body of
            // 80 - 2 - 2 - 1 = 75, so it would paint the SCROLLING form and this
            // assertion would be measuring the same string twice. See
            // testTheFooterSaysThatTheSecondQuestionMarkTypesOne() for the same
            // arithmetic spelled out.
            $this->assertSame(
                35,
                Width::of($this->footer($this->chat('', $cols, 90))),
                'and the non-scrolling form, which is what a box tall enough for the whole list paints',
            );
        }

        // The tail goes first, all the way down: Esc — named first precisely so
        // it survives — is the last thing standing.
        $this->assertSame('Esc closes', $this->footer($this->chat('', 14, 30)));
        $this->assertStringStartsWith('Esc closes', $this->footer($this->chat('', 40, 30)));
        $this->assertStringNotContainsString(
            'scroll',
            $this->footer($this->chat('', 40, 30)),
            'the scroll clause is last, so it is the first clause a narrow box loses',
        );
    }

    /**
     * The reference box's footer row — its last content line — with SGR runs,
     * border glyphs and right-hand padding stripped, so a width measurement
     * counts the columns the hint itself spends.
     */
    private function footer(Chat $chat): string
    {
        $box = $this->overlay($chat->withKeyHelp(0));
        $this->assertNotSame([], $box, 'fixture: the box must be painted for it to have a footer');

        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', $box[count($box) - 2]) ?? '';

        return rtrim(trim($plain, '│ '), ' ');
    }

    public function testAStrayLetterCannotTypeIntoTheInputBoxBehindIt(): void
    {
        [$open] = $this->chat()->update(new KeyMsg(KeyType::Char, '?'));
        [$after] = $open->update(new KeyMsg(KeyType::Char, 'z'));

        $this->assertSame('', $after->inputBuf);
        $this->assertSame(0, $after->keyHelp(), 'and the reference stays up');
    }

    public function testCtrlCStillQuitsWithTheReferenceUp(): void
    {
        [$open] = $this->chat()->update(new KeyMsg(KeyType::Char, '?'));
        [, $cmd] = $open->update(new KeyMsg(KeyType::Char, 'c', ctrl: true));

        $this->assertNotNull($cmd, 'quitting must never require dismissing the modal first');
    }

    public function testArrowsAndPagesScrollAndStopAtTheEnd(): void
    {
        [$chat] = $this->chat()->update(new KeyMsg(KeyType::Char, '?'));
        $chat->view();

        [$down] = $chat->update(new KeyMsg(KeyType::Down));
        $this->assertSame(1, $down->keyHelp());

        [$up] = $down->update(new KeyMsg(KeyType::Up));
        $this->assertSame(0, $up->keyHelp());

        $down->view();
        [$paged] = $down->update(new KeyMsg(KeyType::PageDown));
        $this->assertGreaterThan(1, $paged->keyHelp());

        // Held down past the end: the offset must stop at the last screen
        // rather than running away and leaving the next Up press feeling dead.
        for ($i = 0; $i < 200; $i++) {
            $chat->view();
            [$chat] = $chat->update(new KeyMsg(KeyType::Down));
        }
        $chat->view();
        $this->assertSame(Renderer::keyHelpMaxOffset(), $chat->keyHelp());
        $this->assertGreaterThan(0, Renderer::keyHelpMaxOffset());
    }

    // ── content ──────────────────────────────────────────────────────────

    /**
     * Scrolling from top to bottom must surface every live row. This is the
     * render-side half of the anti-drift contract: a row added to
     * {@see KeyBindingRegistry} that the renderer drops (or a column layout
     * that truncates one away) fails here.
     */
    public function testScrollingThroughItShowsEveryLiveBinding(): void
    {
        [$chat] = $this->chat('', 120, 40)->update(new KeyMsg(KeyType::Char, '?'));

        $seen = '';
        for ($i = 0; $i < 200; $i++) {
            $seen .= $this->body($chat) . "\n";
            [$chat] = $chat->update(new KeyMsg(KeyType::Down));
        }

        foreach (KeyBindingRegistry::live() as $binding) {
            $this->assertStringContainsString(
                $binding->keys,
                $seen,
                $binding->id . ' is documented but never painted',
            );
            $this->assertStringContainsString(
                $binding->description,
                $seen,
                $binding->id . "'s description is never painted in full",
            );
        }
    }

    public function testDormantBindingsAreNotAdvertised(): void
    {
        [$chat] = $this->chat('', 120, 40)->update(new KeyMsg(KeyType::Char, '?'));

        $seen = '';
        for ($i = 0; $i < 200; $i++) {
            $seen .= $this->body($chat) . "\n";
            [$chat] = $chat->update(new KeyMsg(KeyType::Down));
        }

        foreach (KeyBindingRegistry::dormant() as $binding) {
            $this->assertStringNotContainsString(
                $binding->description,
                $seen,
                $binding->id . ' has no effect yet and must not be promised',
            );
        }
    }

    public function testContextHeadingsAreShown(): void
    {
        [$chat] = $this->chat('', 120, 40)->update(new KeyMsg(KeyType::Char, '?'));
        $seen = '';
        for ($i = 0; $i < 200; $i++) {
            $seen .= $this->body($chat) . "\n";
            [$chat] = $chat->update(new KeyMsg(KeyType::Down));
        }

        foreach (array_keys(KeyBindingRegistry::grouped()) as $context) {
            $this->assertStringContainsString($context, $seen);
        }
    }

    public function testTheFooterAdvertisesScrollingOnlyWhenThereIsMoreToSee(): void
    {
        [$tall] = $this->chat('', 120, 40)->update(new KeyMsg(KeyType::Char, '?'));
        $this->assertStringContainsString('Esc closes', $this->body($tall));
        $this->assertStringContainsString('scroll', $this->body($tall));
    }

    // ── render invariants ────────────────────────────────────────────────

    /**
     * The size sweep: 15 widths × 12 heights = **180** sizes, each rendered by
     * {@see testTheOverlayBoxItselfFitsTheTerminal()} and again by
     * {@see testTheReferenceNeverOverflowsTheTerminal()}. On top of that,
     * {@see testItStaysWithinTheTerminalWhileScrolledToTheEnd()} drives 4 sizes
     * through 100 scroll steps each.
     *
     * @return list<array{0: int, 1: int}>
     */
    public static function terminalSizes(): array
    {
        $sizes = [];
        foreach ([4, 5, 8, 12, 20, 24, 30, 40, 55, 72, 80, 100, 120, 160, 200] as $cols) {
            // Down to 3 rows: the box is bounded by rows - 2 so that it cannot
            // land on the status bar, and the bound has no floor — see
            // testItIsNotDrawnAtAllWhenNothingCanFit().
            foreach ([3, 4, 5, 6, 8, 10, 14, 20, 24, 30, 40, 60] as $rows) {
                $sizes[] = [$cols, $rows];
            }
        }

        return $sizes;
    }

    /**
     * The overlay's OWN box, measured before it is composited, must fit the
     * terminal in both directions.
     *
     * Measured separately from the whole frame because the frame comparison
     * has to tolerate the status bar's pre-existing overflow on a very narrow
     * terminal, and that tolerance is wide enough to hide a box that is itself
     * too big. It was: the height bound used to carry a floor that let the box
     * grow to the full frame height on a 3–4 row terminal, land on the status
     * bar, and leave the bar's un-truncated tail hanging past the box's right
     * edge — an over-wide frame line, which is the one thing a renderer that
     * repaints with an absolute cursorTo() may never emit.
     *
     * The sweep is {@see terminalSizes()}'s 15 widths × 12 heights = 180 sizes.
     * 140 of them clear the documented 5×5 floor, and the count of boxes
     * actually produced is asserted against that number rather than against a
     * loose lower bound: "the box got too small to find" and "the box stopped
     * being drawn" are both real regressions, and a `greaterThan` guard reports
     * neither.
     */
    public function testTheOverlayBoxItselfFitsTheTerminal(): void
    {
        $eligible = 0;
        foreach (self::terminalSizes() as [$cols, $rows]) {
            if ($cols >= 5 && $rows >= 5) {
                $eligible++;
            }
        }
        $this->assertSame(140, $eligible, '15 widths x 12 heights, 140 of them at or above the 5x5 floor');

        $measured = 0;
        foreach (self::terminalSizes() as [$cols, $rows]) {
            $box = $this->overlay($this->chat('', $cols, $rows)->withKeyHelp(0));
            if ($box === []) {
                continue;
            }
            $measured++;

            $this->assertLessThanOrEqual(
                $rows - 2,
                count($box),
                "the box must leave the last two rows alone at {$cols}x{$rows}",
            );
            foreach ($box as $line) {
                $this->assertLessThanOrEqual(
                    $cols,
                    Width::of($line),
                    "box line wider than the terminal at {$cols}x{$rows}",
                );
            }
        }

        $this->assertSame(
            $eligible,
            $measured,
            'the box must be drawn at every size the 5x5 floor admits, and at no other',
        );
    }

    /**
     * Below five rows or five columns nothing legible fits, so no box is
     * painted — and the reference stays OPEN, because the state is the user's
     * and only the room to draw it is missing. Growing the terminal reveals it.
     *
     * Stated as "the transcript region is byte-identical to the closed one"
     * rather than "no box was found", so it cannot pass merely because the box
     * got too narrow to recognise. The one line that MUST differ is the status
     * bar: an open reference swallows every keystroke, and a frame that is
     * pixel-for-pixel a closed one while input vanishes reads as a hung app.
     */
    public function testItIsNotDrawnAtAllWhenNothingCanFit(): void
    {
        foreach ([[80, 4], [80, 3], [4, 30], [3, 30]] as [$cols, $rows]) {
            $closed = $this->chat('', $cols, $rows);
            $open = $closed->withKeyHelp(0);

            $closedLines = explode("\n", $this->body($closed));
            $openLines = explode("\n", $this->body($open));

            $this->assertSame(
                array_slice($closedLines, 0, -1),
                array_slice($openLines, 0, -1),
                "nothing may be painted over the transcript at {$cols}x{$rows}",
            );
            $this->assertStringNotContainsString(self::TITLE, $this->body($open));
            $this->assertSame(0, $open->keyHelp(), 'and the reference stays open');

            $bar = end($openLines);
            $this->assertNotSame(end($closedLines), $bar, "the bar must say something at {$cols}x{$rows}");
            $this->assertStringContainsString('window too small', (string) $bar);
            $this->assertLessThanOrEqual(
                Width::of((string) end($closedLines)),
                Width::of((string) $bar),
                'the cue may not be wider than the bar it replaces — the bar is never truncated',
            );
        }

        // One row taller / one column wider than the smallest refusal, it does
        // appear — so the bound is a bound, not the feature quietly never
        // rendering at all.
        foreach ([[80, 5], [5, 30]] as [$cols, $rows]) {
            $closed = $this->chat('', $cols, $rows);

            $this->assertNotSame(
                $this->body($closed),
                $this->body($closed->withKeyHelp(0)),
                "the reference must appear at {$cols}x{$rows}",
            );
        }
    }

    /**
     * The overlay's OWN lines, as `Renderer::renderKeyHelp()` returns them —
     * before Veil composites them onto anything. Empty when nothing is drawn.
     *
     * Taken by reflection rather than carved back out of the finished frame.
     * The extractor this replaced walked the composited frame looking for the
     * title and stopping at the first row starting `╰`, which isolates the box
     * only while the box happens to span the full frame width: measured at
     * rows=30 against a real box 28 rows tall, it returned 28 rows at cols ≤ 68
     * and **4** from cols=69 up — the run was terminating on the input box's
     * border instead — so `assertLessThanOrEqual($rows - 2, count($box))` was
     * comparing 4 against 28 at every ordinary terminal width, 80 and 120
     * included. The bound it is guarding really does hold; the guard did not
     * bite.
     *
     * @return list<string>
     */
    private function overlay(Chat $chat): array
    {
        $rendered = (new \ReflectionMethod(Renderer::class, 'renderKeyHelp'))
            ->invoke(null, $chat, $chat->theme());

        return $rendered === '' ? [] : explode("\n", $rendered);
    }

    /**
     * The reference must never be the thing that makes a frame overflow. The
     * comparison is against the SAME chat with the reference closed, because
     * the status bar is already allowed to exceed a very narrow terminal
     * (pre-existing, {@see Renderer::renderStatusBar()} does not truncate);
     * what this pins is that opening the overlay does not widen the frame
     * past the terminal.
     */
    public function testTheReferenceNeverOverflowsTheTerminal(): void
    {
        foreach (self::terminalSizes() as [$cols, $rows]) {
            $closed = $this->chat('', $cols, $rows);
            $open = $closed->withKeyHelp(0);

            $lines = explode("\n", $this->body($open));

            $this->assertCount($rows, $lines, "frame height at {$cols}x{$rows}");

            $widest = 0;
            foreach ($lines as $line) {
                $widest = max($widest, Width::of($line));
            }

            $baseline = 0;
            foreach (explode("\n", $this->body($closed)) as $line) {
                $baseline = max($baseline, Width::of($line));
            }

            $this->assertLessThanOrEqual(
                max($cols, $baseline),
                $widest,
                "frame width at {$cols}x{$rows}",
            );
        }
    }

    public function testItStaysWithinTheTerminalWhileScrolledToTheEnd(): void
    {
        // Every width here is >= 54, the narrowest at which the status bar
        // itself fits (it is not width-truncated — a pre-existing property of
        // renderStatusBar(), and the reason the sweep above has to allow slack
        // on a narrower terminal). So here $cols is the plain bound.
        foreach ([[54, 10], [55, 6], [80, 24], [120, 40]] as [$cols, $rows]) {
            $chat = $this->chat('', $cols, $rows)->withKeyHelp(0);
            for ($i = 0; $i < 100; $i++) {
                $chat->view();
                [$chat] = $chat->update(new KeyMsg(KeyType::Down));
            }

            $lines = explode("\n", $this->body($chat));
            $this->assertCount($rows, $lines, "frame height at {$cols}x{$rows}, scrolled");
            foreach ($lines as $line) {
                // The plain terminal width, with none of the narrow-terminal
                // slack testTheReferenceNeverOverflowsTheTerminal() has to
                // allow: every size here is wide enough that the status bar
                // fits, so nothing legitimately exceeds $cols.
                $this->assertLessThanOrEqual(
                    $cols,
                    Width::of($line),
                    "frame width at {$cols}x{$rows}, scrolled",
                );
            }

            // Scrolled to the very end, the box still respects the bound that
            // keeps it off the status bar.
            $this->assertLessThanOrEqual($rows - 2, count($this->overlay($chat)));
        }
    }

    /**
     * The overlay slot goes to whichever modal `Chat::update()` routes the next
     * keystroke to — and that is the reference, which is checked immediately
     * after Ctrl+C and therefore ahead of the permission prompt (Chat::update()
     * order, measured: keyHelp → pendingPermission → palette → sessionPicker).
     * Painting the prompt here would put a modal on screen that the keyboard is
     * not driving, which is the one thing the chain in `Renderer::renderView()`
     * exists to prevent.
     *
     * What the precedence may NOT do is hide the prompt in silence: it is a
     * blocking modal that holds the turn `inFlight` and whose own `y`/`n`/`a`
     * are swallowed while the reference is up, so the status bar has to say it
     * is there — {@see testTheBarAnnouncesAPromptTheReferenceIsCovering()}.
     *
     * A DORMANT guarantee, and that label is the correction: this state is not
     * front-door reachable, and a previous revision listed it as one of the two
     * pairs that are. `withKeyHelp(0)` is applied to a
     * {@see blockedOnPermission()} fixture, so both the prompt and the reference
     * are set from outside the routing that refuses to raise them together. Driven
     * with a real `PreToolUse` ask hook over a real turn, neither order works —
     * asserted by
     * {@see testThePromptAndTheReferenceCannotBothBeRaisedByRealInput()}, not left
     * as a sentence here. Kept anyway, because the ordering it pins is what the engine
     * path will land into and because the cue this owes the user
     * ({@see Renderer::KEY_HELP_OVER_PROMPT}) exists only on the strength of this
     * precedence being fixed.
     */
    public function testTheReferenceOutranksAPermissionPromptBecauseItOwnsTheKeyboard(): void
    {
        $blocked = $this->blockedOnPermission();

        $frame = $this->body($blocked->withKeyHelp(0));

        $this->assertStringContainsString(self::TITLE, $frame);
        $this->assertStringNotContainsString('permission required', $frame);

        // With the reference closed the prompt has the slot back, so this is a
        // statement about precedence rather than about the prompt never drawing.
        $this->assertStringContainsString('permission required', $this->body($blocked));
    }

    /**
     * A Chat suspended on a permission prompt.
     *
     * Stamped with no generation on purpose: an unstamped ASK is applied
     * whatever the current turn is, which is what every internal caller relies
     * on. {@see testASupersededAskNeverPutsUpAPrompt()} covers the stamped one.
     *
     * `$tool` is the name the ASK is about, and therefore the key an
     * {@see \SugarCraft\Crush\Permissions\PermissionReply::Always} answer writes
     * into `permissionGrants` — which is the property
     * {@see testTheSessionGrantTakesASecondDeliberateKeystroke()} measures, and
     * which {@see testTypingAtALivePromptIsSwallowedUntilEnterReArmsIt()} reads
     * back for every row of its table.
     *
     * The prompt it hands back is ARMED, because that is what
     * {@see Chat::requestPermission()} raises. Every test here that types a
     * non-answer key first is deliberately leaving that state.
     */
    private function blockedOnPermission(string $tool = 'Bash', ?int $generation = null): Chat
    {
        [$blocked] = $this->chat()->update(new \SugarCraft\Crush\PermissionRequestMsg(
            Message::assistant(''),
            new \SugarCraft\Crush\ToolCall($tool, ['description' => 'rm'], 'call_1'),
            'Run rm -rf build/?',
            $generation,
        ));
        $this->assertNotNull($blocked->pendingPermission(), 'fixture: the prompt must be up');

        return $blocked;
    }

    /**
     * Why {@see blockedOnPermission()} exists at all, asserted rather than asserted
     * about: the reference and a permission prompt cannot BOTH be raised by real
     * input, so the tests that put them up together are dormant guarantees and have
     * to say so. A previous revision counted that pair among the front-door
     * reachable ones on the strength of a fixture that hand-dispatches the Msg.
     *
     * Driven end to end through the real gate — a `PreToolUse` hook returning
     * `HookResult::ask()`, a real `Enter`, a real `AssistantMsg` carrying a tool
     * call — so the prompt here is produced by the production path and not by this
     * file. Both orders are then attempted and both are refused, which is the
     * whole claim:
     *
     * 1. reference first is impossible, because `?` needs a blank line AND no turn
     *    in flight, while a prompt only exists while `inFlight`;
     * 2. prompt first is impossible, because with the prompt up neither `?` nor
     *    `Ctrl+P` reaches its arm, and that frame marks NO click zones, so the
     *    mouse route that DOES reach reference+palette has nothing to click.
     *
     * ── WHICH lock refuses (2), because TWO revisions have now named the wrong one ──
     *
     * `Chat::update()` checks `pendingPermission` (the `handlePermissionKey()`
     * dispatch) above the blanket `inFlight` swallow. Round 6 wrote that a prompt
     * exists ONLY while `inFlight`, on the strength of `requestPermission()` being
     * the sole non-null writer and setting `inFlight` in the same `mutate()`. That
     * is refuted, and it is refuted BY `update()` itself:
     *
     * - `update()`'s `AssistantMsg` arm writes `'inFlight' => false` and does NOT
     *   clear `pendingPermission`, and `mutate()` carries the field forward. ONE
     *   further `update()` on the fixture built below — an unstamped
     *   `AssistantMsg`, the shape the generation guard deliberately admits for the
     *   unwired engine path — leaves `pending=SET inFlight=false`;
     * - and a second non-null writer exists at the language level: constructor
     *   parameters are not visibility-scoped, so
     *   `new Chat(history: [], inputBuf: '', pendingPermission: $ask)` builds that
     *   state with no exception.
     *
     * The whole-suite probe round 6 offered as proof ("a probe throwing on
     * `pendingPermission !== null && !inFlight` at the top of `update()` ran 6,603
     * tests without firing") proves nothing about it: a probe at the TOP of
     * `update()` only fires on a state fed BACK into `update()`, and no test built
     * this one. Round 6's own text says a whole-suite probe is not a proof over
     * unwritten code.
     *
     * And the consequence INVERTS the conclusion, which is why it matters here.
     * Driven in that separated state, with `inFlight` already false: `?` still
     * leaves `keyHelp` null and `Ctrl+P` still leaves `palette` null. So where the
     * locks come apart, **the `pendingPermission` arm alone does the refusing** —
     * `inFlight` is not "the live half for these two keys". That state has its own
     * test ({@see testAPromptOutlivesItsTurnAndTheReferenceIsStillRefused()}) and
     * that is where the permission arm is pinned for these two keys.
     *
     * In the state THIS test builds the two locks are genuinely stacked, and for a
     * key the prompt IGNORES they are indistinguishable: `handlePermissionKey()`'s
     * `default` arm and the swallow both `return [$this, null]` — measured, the
     * very same object comes back either way. Measured too: letting BOTH `?` and
     * `Ctrl+P` walk past the `pendingPermission` arm ALONE leaves `KeyHelpTest` +
     * `ChatTest` + `RendererTest` + `Commands/KeyBindingDriftTest` green here. So
     * the assertions in the split-off order-2 test say only what they can fail on
     * in that state: something above those two arms refuses them, and it takes
     * bypassing both to red them.
     *
     * ── WHY order 2 IS A SEPARATE TEST ──
     *
     * It was in this method, below order 1, and could not be the reported failure
     * of anything. Measured, whole suite, with the `?` arm hoisted above both
     * locks: two reds, `testTheCommandAndTheShortcutOpenTheReferenceInExactlyTheSameStates`
     * and this test's ORDER-1 assertion — which aborts the method before order 2
     * is ever evaluated. Any mutation able to red the order-2 `?` line must first
     * let `?` past the plain `inFlight` swallow, which reds order 1. Split, each
     * line is reachable as a reported failure. (The sibling `Ctrl+P` line was
     * always alive: hoisting only `Ctrl+P` reds it and nothing else.)
     */
    public function testThePromptAndTheReferenceCannotBothBeRaisedByRealInput(): void
    {
        $chat = (new Chat(history: [], inputBuf: '', backend: new EchoBackend()))
            ->registerTool('bash', static fn(array $args): string => 'total 0')
            ->withHooks(self::askEveryToolHooks())
            ->withSize(100, 30);

        $typed = $chat;
        foreach (['h', 'i'] as $rune) {
            [$typed] = $typed->update(new KeyMsg(KeyType::Char, $rune));
        }
        [$inFlight] = $typed->update(new KeyMsg(KeyType::Enter));
        $this->assertTrue($inFlight->inFlight, 'fixture: Enter must put a turn in flight');

        // Order 1: the reference cannot be opened while the turn that could raise a
        // prompt is running.
        [$duringFlight] = $inFlight->update(new KeyMsg(KeyType::Char, '?'));
        $this->assertNull(
            $duringFlight->keyHelp(),
            '"?" must not open the reference while a turn is in flight, which is the window in which a '
            . 'prompt appears',
        );
    }

    /**
     * Order 2 of {@see testThePromptAndTheReferenceCannotBothBeRaisedByRealInput()},
     * split out so its assertions can be the REPORTED failure of a mutation — see
     * that method's docblock for the measurement that forced the split.
     *
     * The zone sweep is the load-bearing half here: the reference+palette pair is
     * reachable precisely because the status bar keeps its `pane:menu` zone under
     * the overlay ({@see testAMouseOpenedPaletteDoesNotTakeTheSlotFromTheReference()}),
     * so "no zones at all" is what distinguishes this pair from that one rather
     * than a detail.
     */
    public function testWithAPromptUpNeitherKeyReachesItsOverlay(): void
    {
        $blocked = $this->promptRaisedByTheRealGate();

        [$viaShortcut] = $blocked->update(new KeyMsg(KeyType::Char, '?'));
        $this->assertNull(
            $viaShortcut->keyHelp(),
            '"?" cannot open the reference at a live prompt. Both the prompt arm and the inFlight swallow '
            . 'sit above the "?" arm here, and in THIS state either one alone suffices — which is exactly '
            . 'what this line can fail on, and no more',
        );
        [$viaPalette] = $blocked->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
        $this->assertNull($viaPalette->palette(), 'and neither does Ctrl+P reach the palette');

        $blocked->view();
        $zones = [];
        for ($row = 1; $row <= 30; $row++) {
            for ($col = 1; $col <= 100; $col++) {
                $id = Chat::zoneAt($col, $row)?->id;
                if ($id !== null) {
                    $zones[$id] = true;
                }
            }
        }
        $this->assertSame(
            [],
            array_keys($zones),
            'and the prompt frame marks no click zones at all, so the mouse route that reaches '
            . 'reference+palette has nothing to click here',
        );
    }

    /**
     * The permission arm pinned by the one thing ONLY it can do: turn a keystroke
     * into an ANSWER. The `inFlight` swallow returns `[$this, null]` for every key
     * alike, so a key the prompt ACTS on is the sole observable difference.
     *
     * And pinned on WHICH answer, which round 6's version was not. That version
     * asserted only that `pendingPermission()` became null — something a DENIAL
     * does just as well. Measured: changing `'y' => PermissionReply::Once` to
     * `PermissionReply::Reject` in `Chat::handlePermissionKey()` left `KeyHelpTest`
     * OK at 47 tests / 25,426 assertions, while the surrounding prose (and the
     * hazard {@see testTypingAtALivePromptIsSwallowedUntilEnterReArmsIt()}
     * measures) rests entirely on `y` meaning APPROVE. `ChatTest` catches that
     * flip repo-wide; this file did not, and an assertion is owed by the file it
     * lives in.
     *
     * The four properties below are what separate the three replies from each
     * other, measured on this fixture: Once leaves `inFlight` true, hands back a
     * Cmd, appends the running placeholder and grants nothing; Reject clears
     * `inFlight`, hands back no Cmd and writes a denial line; Always is Once plus
     * `permissionGrants['bash']` — and Always now takes two keystrokes to reach,
     * which is {@see testTheSessionGrantTakesASecondDeliberateKeystroke()}'s
     * subject rather than this one's.
     */
    public function testAKeyThePromptActsOnReachesItAndYApprovesRatherThanRefuses(): void
    {
        $blocked = $this->promptRaisedByTheRealGate();

        [$answered, $cmd] = $blocked->update(new KeyMsg(KeyType::Char, 'y'));

        $this->assertNull(
            $answered->pendingPermission(),
            'the prompt owns the keyboard in the one sense a mutation can see: "y" ANSWERS it. Bypass the '
            . 'pendingPermission arm and this key falls into the inFlight swallow, which drops it, so the '
            . 'prompt would still be pending',
        );
        $this->assertTrue(
            $answered->inFlight,
            '"y" APPROVES: the batch is released and the turn stays in flight. A refusal ends the turn, so '
            . 'this is the assertion that would red if "y" were remapped to Reject',
        );
        $this->assertNotNull($cmd, 'and a released batch hands back the Cmd that runs it — a refusal does not');
        $this->assertSame(
            [],
            $answered->permissionGrants(),
            'ONCE, not always: approving one call must not write a session grant',
        );
        $denials = array_filter(
            $answered->history,
            static fn(Message $m): bool => str_contains((string) $m->content, 'Permission denied'),
        );
        $this->assertSame([], $denials, 'and nothing in the transcript says the call was refused');
    }

    /**
     * THE STATE ROUND 6 CALLED IMPOSSIBLE: a prompt up with the turn NOT in flight.
     *
     * Reached in one `update()` from the same real-gate fixture — an unstamped
     * `AssistantMsg`, which `update()`'s generation guard admits by design
     * (`generation === null` is the shape `PermissionRequestMsg`'s own docblock
     * names for the unwired engine path). That arm writes `'inFlight' => false`
     * and leaves `pendingPermission` alone; `mutate()` carries it forward.
     *
     * WHAT THIS IS AND IS NOT. No WIRED producer sends a second `AssistantMsg`
     * while a prompt is up: `beginToolCalls()` parks the WHOLE gated batch on the
     * first ask and dispatches nothing, so across a live prompt the only
     * outstanding Cmd is the permission `Deferred`, which is not a Msg source.
     * So this is an API-SURFACE hole today — anyone holding a `Chat` (the Program,
     * an embedder, a test, the engine path when it lands) can produce it — rather
     * than a live correctness bug reachable from the keyboard. It is pinned so
     * that the day the engine path IS wired, the behaviour is already nailed down
     * instead of discovered.
     *
     * And it is what makes the "which lock" question answerable at all: with
     * `inFlight` already false, `?` and `Ctrl+P` are still refused, so here the
     * `pendingPermission` arm is refusing them ALONE. Bypassing that arm
     * (`if (false && $this->pendingPermission !== null)`) reds this test and not
     * {@see testWithAPromptUpNeitherKeyReachesItsOverlay()}, which is the pair of
     * mutations round 6's single merged test could not distinguish.
     */
    public function testAPromptOutlivesItsTurnAndTheReferenceIsStillRefused(): void
    {
        $blocked = $this->promptRaisedByTheRealGate();

        [$separated] = $blocked->update(new \SugarCraft\Crush\AssistantMsg(Message::assistant('late reply')));

        $this->assertNotNull(
            $separated->pendingPermission(),
            'the AssistantMsg arm does not clear the prompt, and mutate() carries it forward',
        );
        $this->assertFalse(
            $separated->inFlight,
            'and it DOES clear inFlight — so "a prompt only ever exists while inFlight" is false, in one '
            . 'update() call, on the fixture the claim was written beside',
        );

        [$viaShortcut] = $separated->update(new KeyMsg(KeyType::Char, '?'));
        $this->assertNull(
            $viaShortcut->keyHelp(),
            'with inFlight already false the swallow cannot be what refuses "?": the pendingPermission arm '
            . 'is doing it alone',
        );
        [$viaPalette] = $separated->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
        $this->assertNull($viaPalette->palette(), 'and alone for Ctrl+P too');

        // The prompt is still the thing on screen and still answerable, which is
        // what makes this a state rather than a corrupted object.
        $this->assertStringContainsString(
            'permission required',
            $this->body($separated),
            'the frame still carries the prompt',
        );
        [$answered] = $separated->update(new KeyMsg(KeyType::Char, 'y'));
        $this->assertNull($answered->pendingPermission(), 'and "y" still answers it');
    }

    /**
     * The keys the draft grew when it moved into `candy-forms`' TextArea
     * (crush_code.md Phase 3 item 1) are swallowed by a live prompt too.
     *
     * The arm rule below was measured when `Chat` had no cursor at all: every
     * key that could reach the draft was a `Char`, a `Space` or a `Backspace`.
     * `Left`/`Right`/`Home`/`End`/`Delete` now reach it as well, through the
     * delegation at the foot of `Chat::update()`, as do `Ctrl+Space` and the
     * two ctrl word-deletes through their own arms just above it. All of them
     * sit BELOW the `pendingPermission` arm — so this is the same claim as the
     * table below, extended to the arms that did not exist when the table was
     * written. Motion keys are the interesting half: they are not answers, so
     * they must DISARM the prompt exactly as `/` does, and they must not move a
     * cursor in a box the user cannot see.
     *
     * A seeded draft rather than {@see blockedOnPermission()}'s empty one,
     * because an empty draft cannot tell "nothing was typed" from "the tail was
     * deleted forward".
     *
     * And the cursor is parked in the MIDDLE of it, which is what gives the
     * table its power. Measured with no modal at all, on this same draft with
     * the cursor at its end: `Right`, `End` and `Delete` are already no-ops
     * there (nowhere further right to go, no tail to take), so a row asserting
     * they changed nothing would have held with the swallow deleted. From
     * offset 4 all six of the motion/edit keys have somewhere to go or
     * something to take.
     *
     * Lives in THIS file and not beside the rest of the cursor tests in
     * `ChatInputCursorTest` for a reason that is about measurement, not tidiness:
     * this file is one of the three that {@see
     * testTheGuardMutationDomainIsTheFilesThatBuildAPermissionRequestMsg()} pins
     * as the domain `Chat::requestPermission()`'s mutation table was measured
     * over. Adding a fourth constructing file would silently widen that domain
     * and stale every figure in that table; adding one prompt-dependent test to
     * a file already inside it is exactly the growth that table's own caveat
     * ("it grows by one for every prompt-dependent test added to those three
     * files") accounts for.
     */
    public function testCursorMotionAndEditingAreSwallowedByALivePromptToo(): void
    {
        $draft = 'half written';
        $seeded = $this->chat($draft);
        // Parked mid-draft BEFORE the prompt goes up, through the same Left
        // arm the table below then has to prove is unreachable.
        foreach (array_fill(0, 8, new KeyMsg(KeyType::Left)) as $left) {
            [$seeded] = $seeded->update($left);
        }
        [$blocked] = $seeded->update(new \SugarCraft\Crush\PermissionRequestMsg(
            Message::assistant(''),
            new \SugarCraft\Crush\ToolCall('bash', ['description' => 'rm'], 'call_1'),
            'Run rm -rf build/?',
        ));
        $this->assertNotNull($blocked->pendingPermission(), 'fixture: the prompt must be up');
        $this->assertSame($draft, $blocked->inputBuf, 'fixture: with a draft behind it');
        $this->assertSame(
            4,
            $blocked->inputCursorOffset(),
            'fixture: and the cursor mid-draft, so every key below has somewhere to go or something to take',
        );

        foreach ([
            'Left' => new KeyMsg(KeyType::Left),
            'Right' => new KeyMsg(KeyType::Right),
            'Home' => new KeyMsg(KeyType::Home),
            'End' => new KeyMsg(KeyType::End),
            'Delete' => new KeyMsg(KeyType::Delete),
            'Backspace' => new KeyMsg(KeyType::Backspace),
            'Space' => new KeyMsg(KeyType::Space),
            'Char' => new KeyMsg(KeyType::Char, 'z'),
            'Alt+Left' => new KeyMsg(KeyType::Left, alt: true),
            'Ctrl+W' => new KeyMsg(KeyType::Char, 'w', ctrl: true),
            // The three ctrl forms `Chat::update()` claims explicitly rather
            // than delegating (TextArea::update() drops every ctrl-flagged key
            // outside its own rune table, so a delegated one would be inert
            // for the wrong reason and this table could not tell the swallow
            // from that).
            'Ctrl+Space' => new KeyMsg(KeyType::Space, ' ', ctrl: true),
            'Ctrl+Backspace' => new KeyMsg(KeyType::Backspace, ctrl: true),
            'Ctrl+Delete' => new KeyMsg(KeyType::Delete, ctrl: true),
        ] as $label => $key) {
            [$next] = $blocked->update($key);

            $this->assertSame($draft, $next->inputBuf, "{$label} reached the draft's text");
            $this->assertSame(4, $next->inputCursorOffset(), "{$label} moved the draft's cursor");
            $this->assertNotNull($next->pendingPermission(), "{$label} answered the prompt");
            $this->assertSame(
                PermissionPromptStage::Disarmed,
                $next->permissionStage(),
                "{$label} is not an answer, so it must take the answer keys away",
            );
        }

        // Alt+Enter is in the inertness claim and OUT of the disarm one, and
        // that is measured rather than assumed: `handlePermissionKey()` reads
        // `KeyType::Enter` without looking at modifiers, so an Alt+Enter at a
        // live prompt takes the RE-ARM arm — it leaves the stage Armed, not
        // Disarmed, which is the opposite of every key above. The half this
        // change is responsible for still holds: it puts no newline in the
        // draft. That is pre-existing routing, not something the widget
        // delegation touches, and rebinding it would be a permission-keymap
        // decision.
        [$altEnter] = $blocked->update(new KeyMsg(KeyType::Enter, alt: true));
        $this->assertSame($draft, $altEnter->inputBuf, 'Alt+Enter must not insert a newline behind the modal');
        $this->assertSame(
            PermissionPromptStage::Armed,
            $altEnter->permissionStage(),
            'and it re-arms, because the prompt Enter arm does not inspect modifiers',
        );
    }

    /**
     * THE ARM RULE, driven keystroke by keystroke — the same table that used to
     * pin the defect, re-measured against the fix.
     *
     * The defect: a prompt owns the keyboard, so every `Char` key reached
     * `Chat::handlePermissionKey()` and nothing typed. `/agents` hit `a` on its
     * SECOND keystroke and wrote `permissionGrants['bash'] = true`, which
     * `gateToolCall()` honours for the rest of the session — every later `bash`
     * call then ran unasked. `/init` denied on its third, `/keys` approved on
     * its fourth.
     *
     * The fix: a prompt goes up ARMED and one keystroke that is not an answer
     * DISARMS it ({@see \SugarCraft\Crush\Permissions\PermissionPromptStage}),
     * so none of the six slash commands reaches an answer at all — each one's
     * first keystroke is the `/`, which is not an answer and takes the answer
     * keys away. What remains is `y`/`n` in FIRST position, which is the
     * residual `Chat::handlePermissionKey()`'s docblock states and defends
     * rather than hides: those keys ARE the answers, and at keystroke one the
     * prompt has no evidence to the contrary.
     *
     * Recovery is a row of this table rather than a separate story: `"\n"` in a
     * typed string is an Enter, and the `/agents` + Enter + `y` row proves the
     * disarm is a state a user can get out of. {@see testEnterIsTheWayBackFromADisarmedPrompt()}
     * takes that row apart keystroke by keystroke.
     *
     * A bracketed PASTE does not qualify, then or now: `Chat::update()` drops
     * `PasteMsg` (asserted in
     * {@see testTheTwoRoutesAgreeOnEveryBlankAndNonBlankDraft()}). Only an
     * unbracketed paste, delivered by the terminal as raw `Char` keys, walks
     * this table.
     */
    public function testTypingAtALivePromptIsSwallowedUntilEnterReArmsIt(): void
    {
        foreach ([
            // The six that used to answer, and the three that never did. All
            // nine are now fully swallowed, and all nine leave the prompt
            // DISARMED — which is the state the modal has to disclose.
            '/keys' => [null, null, [], PermissionPromptStage::Disarmed],
            '/agents' => [null, null, [], PermissionPromptStage::Disarmed],
            '/branch main' => [null, null, [], PermissionPromptStage::Disarmed],
            '/compact' => [null, null, [], PermissionPromptStage::Disarmed],
            '/init' => [null, null, [], PermissionPromptStage::Disarmed],
            '/new' => [null, null, [], PermissionPromptStage::Disarmed],
            '/help' => [null, null, [], PermissionPromptStage::Disarmed],
            '/quit' => [null, null, [], PermissionPromptStage::Disarmed],
            '/model' => [null, null, [], PermissionPromptStage::Disarmed],

            // The recovery: disarmed by the `/`, re-armed by the Enter, then
            // answered ONCE by the `y` — and with no session grant behind it.
            "/agents\ny" => [9, 'y', [], PermissionPromptStage::Armed],

            // The residual, stated rather than hidden: a message that BEGINS
            // with an answer letter still answers on keystroke one.
            'yes' => [1, 'y', [], PermissionPromptStage::Armed],
            'Y' => [1, 'Y', [], PermissionPromptStage::Armed],
            'no' => [1, 'n', [], PermissionPromptStage::Armed],
            'nay' => [1, 'n', [], PermissionPromptStage::Armed],

            // A leading `a` opens the confirm instead of granting, so it takes
            // a `y` in SECOND position to buy a session grant — and any other
            // next key takes the confirm away again.
            'a' => [null, null, [], PermissionPromptStage::ConfirmingAlways],
            'and' => [null, null, [], PermissionPromptStage::Disarmed],
            'an' => [null, null, [], PermissionPromptStage::Armed],
            'aye' => [2, 'y', ['bash' => true], PermissionPromptStage::Armed],
        ] as $typed => [$answersAt, $rune, $grants, $stage]) {
            $chat = $this->blockedOnPermission('bash');
            $at = null;
            $answering = null;
            foreach (str_split($typed) as $index => $char) {
                [$chat] = $chat->update(
                    $char === "\n" ? new KeyMsg(KeyType::Enter) : new KeyMsg(KeyType::Char, $char),
                );
                if ($chat->pendingPermission() === null) {
                    $at = $index + 1;
                    $answering = $char;
                    break;
                }
            }

            $label = str_replace("\n", '<Enter>', $typed);
            $this->assertSame(
                $answersAt,
                $at,
                "typing {$label} at a live permission prompt answers it at this keystroke (null = never)",
            );
            $this->assertSame($rune, $answering, "and the rune that answered it, typing {$label}");
            $this->assertSame(
                $grants,
                $chat->permissionGrants(),
                "and the session grants left behind, typing {$label} — a non-empty one means every later "
                . 'call to that tool runs UNASKED for the rest of the session',
            );
            $this->assertSame(
                $stage,
                $chat->permissionStage(),
                "and the stage the prompt is left in, typing {$label}",
            );
        }
    }

    /**
     * The second half of the fix, which the table above can only see the shadow
     * of: `a` does not grant, it ASKS, and the grant is what the confirm's `y`
     * writes.
     *
     * `Always` is the only reply that outlives the call it answers, so it is
     * the only one that costs a second keystroke. All four exits from the
     * confirm are driven here because they are not the same exit: `y` commits;
     * `n` and Escape are the confirm's own answers, so they cancel back to an
     * ARMED prompt (a user pressing them is plainly deciding in this dialog and
     * must not have their next `y` swallowed); anything else is the same
     * evidence that opened the confirm by accident, so it cancels back to a
     * DISARMED one.
     */
    public function testTheSessionGrantTakesASecondDeliberateKeystroke(): void
    {
        $blocked = $this->blockedOnPermission('bash');

        [$confirming] = $blocked->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertNotNull($confirming->pendingPermission(), '"a" alone must not answer the prompt');
        $this->assertSame(PermissionPromptStage::ConfirmingAlways, $confirming->permissionStage());
        $this->assertSame(
            [],
            $confirming->permissionGrants(),
            'and above all it must not grant: THAT is the keystroke that used to cost the session',
        );

        [$granted, $cmd] = $confirming->update(new KeyMsg(KeyType::Char, 'y'));
        $this->assertNull($granted->pendingPermission(), '"y" at the confirm commits the grant and releases the call');
        $this->assertSame(['bash' => true], $granted->permissionGrants());
        $this->assertNotNull($cmd, 'and a released batch hands back the Cmd that runs it');

        foreach ([
            'n' => new KeyMsg(KeyType::Char, 'n'),
            'Escape' => new KeyMsg(KeyType::Escape),
        ] as $label => $key) {
            [$cancelled] = $confirming->update($key);
            $this->assertNotNull(
                $cancelled->pendingPermission(),
                "{$label} at the confirm cancels it — it must not fall through to the base prompt's own "
                . 'meaning and refuse the call',
            );
            $this->assertSame([], $cancelled->permissionGrants(), "nothing is granted by {$label}");
            $this->assertSame(
                PermissionPromptStage::Armed,
                $cancelled->permissionStage(),
                "and {$label} leaves the prompt ARMED, so the next \"y\" still allows the call",
            );

            [$afterCancel] = $cancelled->update(new KeyMsg(KeyType::Char, 'y'));
            $this->assertNull($afterCancel->pendingPermission(), "which is what {$label} owes the user");
            $this->assertSame([], $afterCancel->permissionGrants(), 'once, not always');
        }

        [$stray] = $confirming->update(new KeyMsg(KeyType::Char, 'q'));
        $this->assertNotNull($stray->pendingPermission());
        $this->assertSame(
            PermissionPromptStage::Disarmed,
            $stray->permissionStage(),
            'any OTHER key cancels the confirm AND disarms — it is the same evidence that the person at '
            . 'the keyboard is typing rather than answering',
        );
        $this->assertSame([], $stray->permissionGrants());
    }

    /**
     * The recovery, taken apart: a disarmed prompt that could never be answered
     * again would be a worse defect than the one the disarm closes, so Enter
     * puts the answer keys back — and answers nothing itself, because
     * "press Enter to continue" muscle memory would otherwise put a session
     * grant one keystroke away again.
     */
    public function testEnterIsTheWayBackFromADisarmedPrompt(): void
    {
        $chat = $this->blockedOnPermission('bash');
        foreach (str_split('/agents') as $char) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Char, $char));
        }
        $this->assertNotNull($chat->pendingPermission(), 'the whole command is swallowed');
        $this->assertSame(PermissionPromptStage::Disarmed, $chat->permissionStage());

        foreach (['y', 'n', 'a'] as $rune) {
            [$inert] = $chat->update(new KeyMsg(KeyType::Char, $rune));
            $this->assertNotNull(
                $inert->pendingPermission(),
                "\"{$rune}\" does nothing at a disarmed prompt — that is what disarmed MEANS",
            );
            $this->assertSame(PermissionPromptStage::Disarmed, $inert->permissionStage());
            $this->assertSame([], $inert->permissionGrants());
        }

        [$rearmed] = $chat->update(new KeyMsg(KeyType::Enter));
        $this->assertNotNull($rearmed->pendingPermission(), 'Enter answers nothing');
        $this->assertSame(PermissionPromptStage::Armed, $rearmed->permissionStage(), 'it re-arms');

        [$answered, $cmd] = $rearmed->update(new KeyMsg(KeyType::Char, 'y'));
        $this->assertNull($answered->pendingPermission(), 'and now the same "y" answers');
        $this->assertTrue($answered->inFlight, 'APPROVED: the batch is released and the turn stays in flight');
        $this->assertNotNull($cmd, 'with the Cmd that runs it');
        $this->assertSame([], $answered->permissionGrants(), 'once — recovery must not cost the session');
    }

    /**
     * Escape is the one answer the disarm does NOT take away, and this is where
     * that exception is pinned rather than only argued for in a comment.
     *
     * Nothing a user TYPES produces an Escape, so it is not the accident the
     * arm rule exists to stop; and the answer it gives is the refusing one, so
     * even a stray Escape costs the paused call and can never grant anything. A
     * modal that could not be dismissed while disarmed would be the worse
     * trade.
     */
    public function testEscapeStillRefusesEvenAtADisarmedPrompt(): void
    {
        [$disarmed] = $this->blockedOnPermission('bash')->update(new KeyMsg(KeyType::Char, '/'));
        $this->assertSame(PermissionPromptStage::Disarmed, $disarmed->permissionStage());

        [$refused] = $disarmed->update(new KeyMsg(KeyType::Escape));
        $this->assertNull($refused->pendingPermission(), 'Escape answers a disarmed prompt');
        $this->assertFalse($refused->inFlight, 'and it REFUSES, which ends the turn');
        $denials = array_filter(
            $refused->history,
            static fn(Message $m): bool => str_contains((string) $m->content, 'Permission denied'),
        );
        $this->assertNotSame([], $denials, 'with the refusal written into the transcript');
        $this->assertSame(
            PermissionPromptStage::Armed,
            $refused->permissionStage(),
            'and the stage goes back to its ground state with the prompt: a Chat carrying "disarmed" with '
            . 'nothing pending describes a state that does not exist',
        );
    }

    /**
     * A batch can carry several outstanding asks, and each one that is RAISED
     * arms afresh — otherwise the stage the user left question ONE in would
     * decide what question TWO answers to, which is the same defect one level
     * deeper.
     *
     * The instrument has to answer question one from a stage that is NOT
     * `Armed`, or the assertion is vacuous: an answer given at an armed prompt
     * would leave `Armed` behind for `mutate()` to carry forward, and a
     * `requestPermission()` that arms nothing would look identical. Both
     * non-armed exits are driven, one per half:
     *
     *  1. through the CONFIRM — `a` then `y`, so the answering Chat is
     *     `ConfirmingAlways`. Two DIFFERENT tools, because an `Always` on
     *     `alpha` clears `alpha`'s other queued asks by design and would take
     *     the second question with it;
     *  2. through the `PermissionReplyMsg` path from a DISARMED prompt — the
     *     palette/embedder route, which is an explicit decision rather than a
     *     keystroke and so is deliberately not gated on the stage at all.
     */
    public function testEachQueuedAskArmsAfresh(): void
    {
        $chat = (new Chat(history: [], inputBuf: '', backend: new EchoBackend()))
            ->registerTool('alpha', static fn(array $args): string => 'alpha ok')
            ->registerTool('beta', static fn(array $args): string => 'beta ok')
            ->withHooks(self::askEveryToolHooks())
            ->withSize(100, 30);

        foreach (['h', 'i'] as $rune) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Char, $rune));
        }
        [$chat] = $chat->update(new KeyMsg(KeyType::Enter));
        [$first] = $chat->update(new \SugarCraft\Crush\AssistantMsg(
            Message::assistant('running')->withToolCalls([
                new \SugarCraft\Crush\ToolCall('alpha', [], 'call_a'),
                new \SugarCraft\Crush\ToolCall('beta', [], 'call_b'),
            ]),
        ));
        $this->assertNotNull($first->pendingPermission(), 'fixture: the first call is gated');
        $this->assertSame('call_a', $first->pendingPermission()?->toolCall->id, 'fixture: alpha is asked first');

        // 1. answered from ConfirmingAlways.
        [$confirming] = $first->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertSame(
            PermissionPromptStage::ConfirmingAlways,
            $confirming->permissionStage(),
            'fixture: the answer to question one has to be given from a stage that is not Armed, or this '
            . 'test cannot tell arming afresh from inheriting',
        );
        [$second] = $confirming->update(new KeyMsg(KeyType::Char, 'y'));

        $this->assertSame(
            'call_b',
            $second->pendingPermission()?->toolCall->id,
            'fixture: answering alpha raises beta rather than releasing the batch',
        );
        $this->assertSame(
            PermissionPromptStage::Armed,
            $second->permissionStage(),
            'the newly-raised ask is armed afresh — it must not inherit the stage question one was '
            . 'answered from',
        );
        [$answered] = $second->update(new KeyMsg(KeyType::Char, 'y'));
        $this->assertNull($answered->pendingPermission(), 'which is what "armed afresh" has to MEAN');

        // 2. answered from Disarmed, through the explicit-decision path.
        [$disarmed] = $first->update(new KeyMsg(KeyType::Char, '/'));
        $this->assertSame(PermissionPromptStage::Disarmed, $disarmed->permissionStage(), 'fixture');
        [$viaMsg] = $disarmed->update(new PermissionReplyMsg(PermissionReply::Once));

        $this->assertSame(
            'call_b',
            $viaMsg->pendingPermission()?->toolCall->id,
            'fixture: the reply Msg answers a disarmed prompt too — it is a decision, not a keystroke',
        );
        $this->assertSame(
            PermissionPromptStage::Armed,
            $viaMsg->permissionStage(),
            'and question two is armed even though question one was answered while disarmed',
        );
        [$answeredViaMsg] = $viaMsg->update(new KeyMsg(KeyType::Char, 'y'));
        $this->assertNull($answeredViaMsg->pendingPermission());

        // 3. the arm inside requestPermission() itself, isolated from the reset
        // answerPermission() does when it clears a prompt. The two are
        // redundant with each other on the resume path above — removing EITHER
        // alone leaves that path armed — so the one that belongs to "a raised
        // prompt is armed" is pinned here on its own. Reached through the
        // public constructor, the same API surface `pendingPermission`'s own
        // tests use: parameters are not visibility-scoped, so a Chat can be
        // built carrying a stage with no prompt to go with it.
        [$raised] = (new Chat(
            history: [],
            inputBuf: '',
            permissionStage: PermissionPromptStage::Disarmed,
        ))->update(new \SugarCraft\Crush\PermissionRequestMsg(
            Message::assistant(''),
            new \SugarCraft\Crush\ToolCall('bash', [], 'call_1'),
            'Run it?',
        ));

        $this->assertNotNull($raised->pendingPermission(), 'fixture: the ask must put a prompt up');
        $this->assertSame(
            PermissionPromptStage::Armed,
            $raised->permissionStage(),
            'raising a prompt ARMS it, whatever the Chat was carrying before — that is what makes every '
            . 'question answerable rather than only the first',
        );
    }

    /**
     * A `PreToolUse` chain that asks about every tool, so a prompt in this file can
     * be raised by the production path instead of by a hand-dispatched Msg.
     */
    private static function askEveryToolHooks(): HookManager
    {
        $asks = new class implements HookInterface {
            public function name(): string { return 'ask-every-tool'; }

            public function event(): HookEvent { return HookEvent::PreToolUse; }

            public function matcher(): string { return '.*'; }

            public function execute(HookContext $context): HookResult { return HookResult::ask('Run rm -rf build/?'); }
        };
        $hooks = new HookManager(new HookRegistry());
        $hooks->register($asks);

        return $hooks;
    }

    /**
     * A live prompt produced end to end by the real gate — a `PreToolUse` hook
     * returning `HookResult::ask()`, a real `Enter`, a real `AssistantMsg` carrying
     * a tool call. Not {@see blockedOnPermission()}, which hand-dispatches the Msg:
     * the whole point of the tests that use this one is that the prompt is
     * production-made.
     */
    private function promptRaisedByTheRealGate(): Chat
    {
        $chat = (new Chat(history: [], inputBuf: '', backend: new EchoBackend()))
            ->registerTool('bash', static fn(array $args): string => 'total 0')
            ->withHooks(self::askEveryToolHooks())
            ->withSize(100, 30);

        $typed = $chat;
        foreach (['h', 'i'] as $rune) {
            [$typed] = $typed->update(new KeyMsg(KeyType::Char, $rune));
        }
        [$inFlight] = $typed->update(new KeyMsg(KeyType::Enter));

        [$blocked] = $inFlight->update(new \SugarCraft\Crush\AssistantMsg(
            Message::assistant('running')->withToolCalls([
                new \SugarCraft\Crush\ToolCall('bash', ['cmd' => 'rm -rf build/'], 'call_1'),
            ]),
        ));
        $this->assertNotNull(
            $blocked->pendingPermission(),
            'fixture: the ask hook must suspend the turn on a prompt, or there is nothing to attempt',
        );
        $this->assertTrue($blocked->inFlight, 'fixture: and the prompt holds the turn in flight');

        return $blocked;
    }

    /**
     * The cue the precedence above owes the user. The reference wins the slot
     * AND the keyboard, so without this the prompt is invisible, its keys do
     * nothing, and the turn stays `inFlight` — the same "invisible AND silent"
     * state {@see Renderer::KEY_HELP_TOO_SMALL} exists to avoid, reached a
     * different way.
     *
     * The width bound is asserted, not assumed: the bar is the one line the
     * renderer never truncates, so a cue wider than what it replaces would make
     * a narrow terminal overflow by MORE than it already does.
     */
    public function testTheBarAnnouncesAPromptTheReferenceIsCovering(): void
    {
        $blocked = $this->blockedOnPermission();

        foreach ([[100, 30], [80, 24], [54, 10], [20, 30], [5, 30]] as [$cols, $rows]) {
            $sized = $blocked->withSize($cols, $rows);

            $this->assertStringContainsString(
                'permission waiting',
                $this->body($sized->withKeyHelp(0)),
                "the frame must say a prompt is waiting at {$cols}x{$rows}",
            );

            // The bar itself, not the frame's last line: Veil widens the
            // backdrop to the overlay's width, so a composited frame's last
            // line carries padding this comparison is not about.
            $bar = $this->statusBar($sized->withKeyHelp(0));
            $replaced = $this->statusBar($sized);

            $this->assertNotSame($replaced, $bar);
            $this->assertLessThanOrEqual(
                Width::of($replaced),
                Width::of($bar),
                "the cue may not be wider than the bar it replaces at {$cols}x{$rows}"
                . ' — the bar is never truncated',
            );
        }

        // And it is the reference that triggers it: with the reference closed
        // the prompt is on screen and the bar goes back to its readouts.
        $this->assertStringNotContainsString('permission waiting', $this->body($blocked));
    }

    /**
     * The two cues collide in a real state, and the documented priority is
     * which one wins: too-small outranks over-a-prompt.
     *
     * Reachable, not hypothetical — `cols < 5` or `rows < 5` with a prompt
     * pending, which is `renderStatusBar()`'s own condition for the first cue
     * and this fixture's for the second. Unpinned, swapping the two branches
     * changed nothing anywhere in the suite: a 4-column terminal would have
     * announced "permission waiting" while painting no reference at all, which
     * is the less urgent of the two facts and the one the user cannot act on.
     *
     * Domain: the four sizes below, all of them under the documented 5x5 floor,
     * each with a prompt pending AND the reference open — the only state in
     * which both cue conditions hold at once.
     */
    public function testTheTooSmallCueOutranksThePromptCue(): void
    {
        $blocked = $this->blockedOnPermission();

        foreach ([[4, 30], [3, 30], [80, 4], [80, 3]] as [$cols, $rows]) {
            $open = $blocked->withSize($cols, $rows)->withKeyHelp(0);

            // Fixture: both conditions really do hold here, so this is a
            // precedence assertion rather than one cue simply not applying.
            $this->assertNotNull($open->keyHelp(), "fixture: the reference is open at {$cols}x{$rows}");
            $this->assertNotNull($open->pendingPermission(), "fixture: a prompt pends at {$cols}x{$rows}");
            $this->assertSame([], $this->overlay($open), "fixture: nothing fits at {$cols}x{$rows}");

            $bar = $this->statusBar($open);
            $this->assertStringContainsString(
                'window too small',
                $bar,
                "the more urgent cue must win at {$cols}x{$rows}: nothing is painted at all, and a bar "
                . 'that says only "permission waiting" describes a modal the user cannot even see is there',
            );
            $this->assertStringNotContainsString(
                'permission waiting',
                $bar,
                'the bar is one un-wrappable line — two messages on it would not fit, which is why this '
                . 'is a priority and not a concatenation',
            );
        }

        // And with room to paint, the prompt cue is the one that shows — so
        // this is a precedence, not the too-small cue swallowing the other.
        $roomy = $this->statusBar($blocked->withSize(100, 30)->withKeyHelp(0));
        $this->assertStringContainsString('permission waiting', $roomy);
        $this->assertStringNotContainsString('window too small', $roomy);
    }

    /**
     * The width bound {@see Renderer::KEY_HELP_TOO_SMALL}'s comment rests on,
     * asserted against the rendered bar rather than restated — the same
     * treatment its sibling {@see Renderer::KEY_HELP_OVER_PROMPT} already gets
     * in {@see testTheBarAnnouncesAPromptTheReferenceIsCovering()}. The comment
     * previously quoted a range ("73–94") that matches no instrument at all on
     * this fixture, which is what a figure with no fixture and no instrument
     * named is worth.
     *
     * Instrument: {@see statusBar()}, i.e. `Width::of` after
     * `stripZoneMarkers()` — the columns actually painted.
     * Domain: the four sizes below, every one of them under
     * `keyHelpGeometry()`'s documented 5x5 floor, which is the entire set of
     * shapes in which this cue is ever emitted.
     */
    public function testTheTooSmallCueIsNeverWiderThanTheBarItReplaces(): void
    {
        foreach ([[4, 30], [1, 30], [100, 4], [4, 4]] as [$cols, $rows]) {
            $sized = $this->chat('', $cols, $rows);

            $cue = $this->statusBar($sized->withKeyHelp(0));
            $replaced = $this->statusBar($sized);

            $this->assertStringContainsString(
                'window too small',
                $cue,
                "fixture: the cue is what the bar says at {$cols}x{$rows}",
            );
            $this->assertLessThanOrEqual(
                Width::of($replaced),
                Width::of($cue),
                "the cue may not be wider than the bar it replaces at {$cols}x{$rows} — the bar is the "
                . 'one line this renderer never truncates, so a wider cue would overflow by more than '
                . 'the bar already does',
            );
        }
    }

    /**
     * The same bound as a SWEEP over terminal SIZES rather than four samples, and
     * the reason {@see Renderer::renderStatusBar()}'s comment no longer quotes a
     * width table: the range in that comment was wrong in two consecutive rounds,
     * because prose has nothing reading it back. These are the figures the
     * comment used to state.
     *
     * **This sweep does not bound the cue, and a previous round read it as if it
     * did.** Its fixture is idle, and the idle bar is the WIDE one. The bar the
     * cue actually replaces where a user meets it is 18 columns narrower —
     * {@see testTheCuesFitTheNarrowestBarAnyAppStateCanProduce()} is the sweep
     * that covers that, over app states rather than sizes. What this one is for is
     * the column/row structure of the idle bar, which is a different fact.
     *
     * Instrument: {@see statusBar()}, i.e. `Width::of` after
     * `stripZoneMarkers()`.
     * Domain: cols 1-400 against rows {1,2,3,4,5,6,10,20,30,50,80,200} on
     * {@see chat()}'s fixture — a two-message chat over `EchoBackend`, idle, no
     * prompt pending. 9,600 renders, well under a second.
     *
     * Note the split inside that domain, which is itself a correction: the cue
     * is emitted only where `keyHelpGeometry()` returns null, i.e. `cols <= 4`
     * or `rows <= 4`. Everywhere else the box fits and the bar with the
     * reference OPEN is just the ordinary bar. Writing this test as "the cue is
     * always 33 across the sweep" failed on the first run for exactly that
     * reason, which is the same domain slip in miniature that put a wrong width
     * range in the renderer's comment twice.
     *
     * What it pins, each over the part of the domain named:
     *
     * 1. wherever the cue IS emitted, it is a CONSTANT 33 columns — it carries
     *    no readout, so nothing in it varies with the terminal;
     * 2. the IDLE bar takes exactly the four values 54/62/65/75 over this sweep,
     *    so 54 is the floor **of the idle bar on this fixture** — not of the bar
     *    generally, which is the domain the word "everywhere" used to smuggle in
     *    here;
     * 3. the bar responds to COLUMNS ONLY. That is the half the old comment got
     *    backwards when it said the bar "is still 54 columns" wherever this cue
     *    fires: `rows <= 4` fires it too, and at 100x4 the bar is 75.
     */
    public function testTheBarIsNeverNarrowerThanTheTooSmallCueAtAnySize(): void
    {
        $cueWidths = [];
        $barWidths = [];
        $cueSizes = 0;
        /** @var array<int, array<int, true>> $barByCol */
        $barByCol = [];

        foreach ([1, 2, 3, 4, 5, 6, 10, 20, 30, 50, 80, 200] as $rows) {
            for ($cols = 1; $cols <= 400; $cols++) {
                $sized = $this->chat('', $cols, $rows);

                $withReference = $this->statusBar($sized->withKeyHelp(0));
                if (str_contains($withReference, 'window too small')) {
                    ++$cueSizes;
                    $cueWidths[Width::of($withReference)] = true;
                    $this->assertTrue(
                        $cols <= 4 || $rows <= 4,
                        "the cue may only fire under keyHelpGeometry()'s 5x5 floor ({$cols}x{$rows})",
                    );
                }

                $bar = Width::of($this->statusBar($sized));
                $barWidths[$bar] = true;
                $barByCol[$cols][$bar] = true;
            }
        }

        // 4 rows x 400 cols, plus 400 cols' worth of the cols<=4 band at the
        // other 8 row values: 1600 + 32.
        $this->assertSame(1632, $cueSizes, 'fixture: the cue really is emitted over the band claimed');
        $this->assertSame([33], array_keys($cueWidths), 'the cue carries no readout, so it cannot vary');

        $bars = array_keys($barWidths);
        sort($bars);
        $this->assertSame([54, 62, 65, 75], $bars, 'the bar widens in four steps as the readouts fit');
        // Deliberately NOT an `assertLessThanOrEqual(min($bars), 33)` here: with
        // the four values hard-coded on the line above, such an assertion can
        // never be the one that fires, so counting it as coverage of the cue's
        // margin was double-counting. The margin is asserted where it can bite, in
        // testTheCuesFitTheNarrowestBarAnyAppStateCanProduce(), against the bar
        // this fixture cannot produce.

        // Rows do not enter it: one width per column across every row tried.
        // Without this, "the bar does not shrink with the terminal" is the
        // unverified sentence that produced the wrong claim.
        foreach ($barByCol as $cols => $seen) {
            $this->assertCount(1, $seen, "the bar's width must not depend on rows (cols={$cols})");
        }
    }

    /**
     * The bound that actually protects the frame, swept over APP STATES because
     * that — not the terminal size — is what the status bar's width depends on.
     *
     * The sibling sweep above ranges over 9,600 terminal sizes and never sees a bar
     * narrower than 54, because its fixture is idle and cannot become anything
     * else. Both cues, however, are substituted for the bar in states the idle
     * fixture cannot reach:
     *
     * - a turn in flight and a pending permission prompt both render
     *   `0% · ⠴ thinking… · Esc Esc to cancel` — **36** columns at its narrowest —
     *   because `requestPermission()` sets `inFlight` true;
     * - `KEY_HELP_TOO_SMALL` (33) replaces it whenever `cols <= 4 || rows <= 4`,
     *   which a small terminal reaches in those states as readily as when idle:
     *   **3 columns of margin, not 21**;
     * - `KEY_HELP_OVER_PROMPT` (35) fires *only* while a prompt pends, i.e. only
     *   ever against that same 36-column bar: **1 column of margin.** It is the
     *   tighter of the two and the sweep that quoted 54 could not see it at all.
     *
     * So this asserts the substitution PER (state, size) — cue width against the
     * width the very same state and size renders with the reference closed — rather
     * than against an aggregate floor, and separately records the aggregate floor
     * and the SET of states that attain it (a set, not one state: two do, and the
     * old single-state pin was reading `barStates()`'s ordering back as a fact).
     *
     * NOT the only test that reads `requestPermission()`'s `'inFlight' => true`,
     * and the commit that added this one claimed it was "caught by the new test
     * alone". Measured over the WHOLE suite, flipping that flag to `false` reds
     * three tests: this one,
     * {@see testThePromptAndTheReferenceCannotBothBeRaisedByRealInput()}, and
     * `ChatTest::testAskHookSuspendsTheTurnInsteadOfRunningOrDenyingTheCall()`,
     * which asserts it directly and reaches the prompt through a real `PreToolUse`
     * hook. `ChatTest` was already one of the two domains that commit measured, so
     * the counter-example was inside the measured universe and the exclusivity
     * claim was avoidable. What this test adds over `ChatTest`'s is not that the
     * flag is set but what it COSTS: the bar the flag selects is the narrow one the
     * cue has to fit inside — and the margin assertion is what reports it here
     * (measured, `19 is not identical to 1`, because with the flag off the prompt
     * state renders the wide idle bar).
     *
     * Instrument: {@see statusBar()}, i.e. `Width::of` after `stripZoneMarkers()`.
     * Domain: the nine states in {@see barStates()} against cols 1-400 × rows
     * {1,4,5,30} — 14,400 (state, size) pairs, chosen so both cue branches and both
     * geometry branches are crossed with every state. What the corpus still cannot
     * produce is a translated bar: every figure here is measured against the
     * hardcoded English literals, which is the caveat
     * {@see Renderer::KEY_HELP_OVER_PROMPT}'s docblock already carries.
     *
     * ~8 seconds, which is why the two big-history fixtures are hoisted rather than
     * rebuilt per render — see {@see barStates()}.
     */
    public function testTheCuesFitTheNarrowestBarAnyAppStateCanProduce(): void
    {
        $rowsSet = [1, 4, 5, 30];
        $cueWidths = [];
        $barWidths = [];
        /** @var array<string, int> $narrowestPerState */
        $narrowestPerState = [];
        $tooSmallSubstitutions = 0;
        $overPromptSubstitutions = 0;
        $tightestTooSmall = PHP_INT_MAX;
        $tightestOverPrompt = PHP_INT_MAX;

        foreach ($this->barStates() as $label => $make) {
            foreach ($rowsSet as $rows) {
                for ($cols = 1; $cols <= 400; $cols++) {
                    $state = $make($cols, $rows);
                    $closed = Width::of($this->statusBar($state));
                    $barWidths[$closed] = true;

                    $narrowestPerState[$label] = min($narrowestPerState[$label] ?? PHP_INT_MAX, $closed);

                    $shown = $this->statusBar($state->withKeyHelp(0));
                    $width = Width::of($shown);
                    $isCue = str_contains($shown, 'window too small') || str_contains($shown, 'permission waiting');
                    if (!$isCue) {
                        continue;
                    }

                    $cueWidths[$width] = true;
                    if (str_contains($shown, 'window too small')) {
                        ++$tooSmallSubstitutions;
                        $tightestTooSmall = min($tightestTooSmall, $closed - $width);
                    } else {
                        ++$overPromptSubstitutions;
                        $tightestOverPrompt = min($tightestOverPrompt, $closed - $width);
                    }

                    $this->assertLessThanOrEqual(
                        $closed,
                        $width,
                        "the cue may not be wider than the bar it replaces in this very state "
                        . "({$label} @ {$cols}x{$rows}: bar {$closed}, cue {$width}) — the bar is the one "
                        . 'line this renderer never truncates, so a wider cue would overflow by more than '
                        . 'the bar already does',
                    );
                }
            }
        }

        // ORDER MATTERS HERE, and it is the reason the margins come first. A cue
        // that WIDENS by one column drops its margin to 0 and changes $cues, and
        // whichever of the two assertions runs first is the one a reader sees. The
        // $cues pin used to be first: measured, widening KEY_HELP_OVER_PROMPT by one
        // column reported "two arrays are identical, 35 vs 36" and the margin
        // assertion never ran — the same double-counting that got
        // assertLessThanOrEqual(min($bars), 33) deleted from the sibling sweep one
        // method up. So the assertion advertised as catching a shrinking margin is
        // now the one that catches it, and $cues is left as the corroborating detail
        // it always was.
        $this->assertSame(3, $tightestTooSmall, 'KEY_HELP_TOO_SMALL keeps 3 columns against the in-flight bar');
        $this->assertSame(1, $tightestOverPrompt, 'KEY_HELP_OVER_PROMPT keeps exactly 1 — it is the load-bearing one');

        // The floor, and the states that attain it — a SET, because $narrowest was
        // computed with a strict `<` and therefore named whichever state
        // barStates() happens to list first among the ties. Measured, two states
        // reach 36 at 1x1 with the byte-identical bar
        // `0% · ⠴ thinking… · Esc Esc to cancel` — `turn in flight` and
        // `prompt pending` — so "and it is the in-flight bar" was a tie-break
        // artifact stated as an identification: with a strict `<` over a tie, the
        // winner is whichever barStates() lists first, and reordering the array
        // would have flipped the assertion with the fact unchanged. Verified the
        // other way round instead — iterating barStates() in REVERSE leaves the two
        // assertions below green, which the old pin could not have been. The fact is
        // that the floor is 36 and that exactly these two states own it, both of
        // them unreachable by the idle fixture the sibling sweep uses.
        $floor = min($narrowestPerState);
        $this->assertSame(36, $floor, 'the narrowest bar any state in this corpus can produce');
        $atFloor = array_keys(array_filter($narrowestPerState, static fn(int $w): bool => $w === $floor));
        // Sorted, so that reordering barStates() cannot flip this the way it could
        // flip the single-state pin this replaces.
        sort($atFloor);
        $this->assertSame(
            ['prompt pending', 'turn in flight'],
            $atFloor,
            'and these are the states that attain it — both in flight, because requestPermission() sets '
            . 'inFlight, which is what makes the cue meet this bar and not the wide idle one',
        );

        $cues = array_keys($cueWidths);
        sort($cues);
        $this->assertSame([33, 35], $cues, 'the two cues carry no readout, so neither can vary with the terminal');

        // Both cues really are substituted, over the band each one owns:
        //   TOO_SMALL: 9 states x (2 rows in {1,4} x 400 cols + 2 rows in {5,30} x
        //   4 cols) = 9 x 808;
        //   OVER_PROMPT: the one prompt-pending state, on the complement of that
        //   band within its own 1600 pairs = 4 x 400 - 808.
        $this->assertSame(9 * 808, $tooSmallSubstitutions, 'fixture: TOO_SMALL fires over the band claimed');
        $this->assertSame(4 * 400 - 808, $overPromptSubstitutions, 'fixture: OVER_PROMPT fires over its complement');
    }

    /**
     * App states the status bar is measured over, each built fresh per size so a
     * state cannot leak into the next render.
     *
     * Nine, and the two that matter are the last two: the idle bar carries the
     * context readout and is wide, while an in-flight turn replaces the whole bar
     * with the cancel hint and is narrow. `prompt pending` is in-flight too —
     * `requestPermission()` sets `inFlight` — which is why it is the state
     * `KEY_HELP_OVER_PROMPT` is measured against. `context over 100%` is here to
     * push the readout the OTHER way — measured, 1,200 exchanges render
     * `~122.4K / 100K context (122%) · …` at 81 columns — so the corpus brackets the
     * bar rather than sampling one end of it.
     *
     * @return array<string, callable(int, int): Chat>
     */
    private function barStates(): array
    {
        // Built ONCE and closed over, not per (state, size): the corpus renders each
        // state 1,600 times, and rebuilding a 2,400-message history that often cost
        // 30 seconds of the sweep's runtime on its own.
        $history = static function (int $pairs): array {
            $out = [];
            for ($i = 0; $i < $pairs; $i++) {
                $out[] = Message::user(str_repeat('question ', 20) . $i);
                $out[] = Message::assistant(str_repeat('answer ', 20) . $i);
            }

            return $out;
        };
        $long = $history(40);
        $huge = $history(1200);

        return [
            'empty history' => static fn(int $cols, int $rows): Chat
                => (new Chat(history: [], inputBuf: '', backend: new EchoBackend()))->withSize($cols, $rows),
            'two-message idle' => fn(int $cols, int $rows): Chat => $this->chat('', $cols, $rows),
            'half-typed draft' => fn(int $cols, int $rows): Chat => $this->chat('why', $cols, $rows),
            'palette open' => function (int $cols, int $rows): Chat {
                [$palette] = $this->chat('', $cols, $rows)->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));

                return $palette;
            },
            'long history' => static fn(int $cols, int $rows): Chat
                => (new Chat(history: $long, inputBuf: '', backend: new EchoBackend()))->withSize($cols, $rows),
            'context over 100%' => static fn(int $cols, int $rows): Chat
                => (new Chat(history: $huge, inputBuf: '', backend: new EchoBackend()))->withSize($cols, $rows),
            'turn in flight' => function (int $cols, int $rows): Chat {
                [$flight] = $this->chat('hello', $cols, $rows)->update(new KeyMsg(KeyType::Enter));

                return $flight;
            },
            'turn in flight, big context' => static function (int $cols, int $rows) use ($huge): Chat {
                $chat = (new Chat(history: $huge, inputBuf: 'hello', backend: new EchoBackend()))
                    ->withSize($cols, $rows);
                [$flight] = $chat->update(new KeyMsg(KeyType::Enter));

                return $flight;
            },
            'prompt pending' => function (int $cols, int $rows): Chat {
                [$blocked] = $this->chat('', $cols, $rows)->update(new \SugarCraft\Crush\PermissionRequestMsg(
                    Message::assistant(''),
                    new \SugarCraft\Crush\ToolCall('Bash', ['description' => 'rm'], 'call_1'),
                    'Run rm -rf build/?',
                    null,
                ));

                return $blocked;
            },
        ];
    }

    /**
     * `Renderer::$keyHelpMaxOffset` is process-global and read by production
     * code ({@see Chat::withKeyHelp()} clamps against it), so it is the same
     * shape as the three statics this feature's review round had to add resets
     * for — and it needs no reset seam because it resets ITSELF. That is a
     * property of the overlay chain rather than a habit, so it is read back
     * here: `renderKeyHelp()` runs first in `renderView()`, therefore on every
     * frame, and its "reference closed" early return zeroes the ceiling.
     *
     * Without this, the argument for not giving it a seam is an unverified
     * sentence, and the day the chain is reordered the leak is silent.
     */
    public function testTheOverflowCeilingResetsItselfOnAFrameWithoutTheReference(): void
    {
        [$open] = $this->chat()->update(new KeyMsg(KeyType::Char, '?'));
        $open->view();
        $this->assertGreaterThan(
            0,
            Renderer::keyHelpMaxOffset(),
            'fixture: the list must overflow a 100x30 box, or there is no stale value to leak',
        );

        // One ordinary frame — the reference closed — and the ceiling is gone.
        $this->chat()->view();
        $this->assertSame(0, Renderer::keyHelpMaxOffset());

        // Same for the frame that CANNOT paint the box: the other early return.
        $open->view();
        $this->assertGreaterThan(0, Renderer::keyHelpMaxOffset(), 'fixture: warmed again');
        $this->chat('', 4, 30)->withKeyHelp(0)->view();
        $this->assertSame(0, Renderer::keyHelpMaxOffset());
    }

    /**
     * The status bar as `Renderer` assembles it, with its invisible click-zone
     * sentinels stripped so a width comparison measures what is on screen.
     */
    private function statusBar(Chat $chat): string
    {
        $bar = (new \ReflectionMethod(Renderer::class, 'renderStatusBar'))->invoke(null, $chat);

        return (string) (new \ReflectionMethod(Renderer::class, 'stripZoneMarkers'))->invoke(null, $bar);
    }

    /**
     * `Chat::requestPermission()`'s generation guard, driven.
     *
     * Domain — and it is narrower than this test used to claim: a HAND-BUILT
     * `PermissionRequestMsg`. It has to be. Measured, both internal producers
     * (`beginToolCalls()`, `answerPermission()`) stamp the generation that is
     * current on the object they then call, so no producer in `src/` can emit
     * the stale ASK this covers, and `grep 'new PermissionRequestMsg' src/`
     * finds only those two.
     *
     * Deleting the guard outright turns exactly this one test red, and so does
     * making its body throw when it fires — measured over the three files that
     * build a `PermissionRequestMsg` at all AND, separately, over `ChatTest`,
     * which stays green under both.
     *
     * Those two domains are named by FILE and not by test count, deliberately. A
     * count here is a figure this file's own size feeds, so it goes stale the
     * moment a test is added below it: the previous revision promised it would be
     * "re-measured whenever tests are added here" and then went stale inside the
     * very commit that added three, having already stood at 252 for a round. (Those
     * are historical trio counts as of round 4, kept as the story and not as a
     * current measurement: 265 was written where the trio then held 268.) The
     * file SET is a claim that can assert itself, and does —
     * {@see testTheGuardMutationDomainIsTheFilesThatBuildAPermissionRequestMsg()}.
     * The load-bearing figures in the table are the failure counts per mutation,
     * which a reader reproduces by mutating rather than by counting tests.
     *
     * `ChatTest` is why the earlier wording here was wrong in a second way: it said
     * no other test can even REACH a stamped ASK, and every `ChatTest` test that
     * drives a tool call through the two producers does. No count is given for that
     * either, and the previous revision's "14 ChatTest tests" is gone for the same
     * reason the trio counts three paragraphs up are: it was correct when written,
     * fed by a file this round is not measuring, and correct-but-fragile is the
     * shape every figure in this neighbourhood has failed in. What those tests
     * cannot do is make the comparison true. See `Chat::requestPermission()`'s own
     * comment for the four-mutation table. So what this pins is the guard's LOGIC,
     * not a live path.
     *
     * That makes it a dormant-defence test, and worth keeping as one: the engine
     * path `PermissionRequestMsg`'s docblock names is the producer it is for,
     * and the unstamped case below is the one that must keep working when that
     * path lands. What protects a real user from the reference-over-prompt
     * state is not this guard but the status-bar cue —
     * {@see testTheBarAnnouncesAPromptTheReferenceIsCovering()}.
     */
    public function testASupersededAskNeverPutsUpAPrompt(): void
    {
        [$sent] = $this->chat('hello')->update(new KeyMsg(KeyType::Enter));
        $this->assertTrue($sent->inFlight, 'fixture: a turn must be in flight');

        $ask = static fn(?int $generation): \SugarCraft\Crush\PermissionRequestMsg
            => new \SugarCraft\Crush\PermissionRequestMsg(
                Message::assistant(''),
                new \SugarCraft\Crush\ToolCall('Bash', ['description' => 'rm'], 'call_1'),
                'Run rm -rf build/?',
                $generation,
            );

        // Generation 0 is the turn the Enter above superseded.
        [$stale] = $sent->update($ask(0));
        $this->assertNull($stale->pendingPermission(), 'an ASK from a superseded turn is dropped');

        [$current] = $sent->update($ask(1));
        $this->assertNotNull($current->pendingPermission(), 'the current turn is still asked about');

        [$unstamped] = $sent->update($ask(null));
        $this->assertNotNull($unstamped->pendingPermission(), 'an unstamped ASK still applies');
    }

    /**
     * The DOMAIN `Chat::requestPermission()`'s mutation table is measured over,
     * asserted instead of narrated — the third thing in that table's neighbourhood
     * to be moved out of prose, after the site count and the owning methods.
     *
     * The table's first column is "the three files that construct a
     * `PermissionRequestMsg` at all". A fourth file building one puts a fourth
     * file's tests inside the measured universe, so every row of the table becomes
     * a figure taken over the wrong domain. Stated in prose it had already gone
     * stale once as a test COUNT — 265 written where the trio then held 268, in the
     * same commit that added three tests to it (both figures historical, round 4's
     * trio) — and the file set is the part of that claim a test can hold, so here it
     * is held.
     *
     * ── EXACTLY what this measures, because both halves used to be wrong ──
     *
     * The instrument is `token_get_all()`, looking for a `T_NEW` whose next
     * significant token is a name ending in `PermissionRequestMsg`. It is NOT the
     * `grep -rl PermissionRequestMsg tests/` this docblock used to cite as its
     * reproduction, because that grep answers a different question and answered it
     * wrongly in both directions:
     *
     * - false positive, measured: a fourth file whose only occurrence is the word
     *   in a `//` comment reddened the grep-based version. It builds nothing. The
     *   token scan ignores it, along with docblock `{@see}`s and string literals —
     *   which matters because this very file mentions the class a dozen times in
     *   prose;
     * - false negative, measured and now FIXED: a fourth file that imported the
     *   class under an alias (`use …\PermissionRequestMsg as Ask; new Ask(…)`)
     *   drove a real prompt while this scan reported three files. That is the
     *   more idiomatic shape of the two, and the revision that documented only
     *   the computed-name miss called the plain form "the only form that
     *   constructs one by name" while this one existed;
     * - false negative, measured and NOT fixed: `new $cls(…)`, a parenthesised
     *   class expression, and an anonymous subclass. Each drives a real prompt
     *   and is invisible to any static instrument. No parse can close them; they
     *   are stated instead, and asserted AS misses by
     *   {@see testTheConstructionScanSeesAnAliasedImportAndSaysWhatItCannotSee()}
     *   so this paragraph cannot drift.
     *
     * And the residual worth naming, because it is the one a future round will trip
     * over: the table's REAL domain is "tests that need a prompt to appear", which
     * need not construct the Msg at all.
     * {@see testThePromptAndTheReferenceCannotBothBeRaisedByRealInput()} builds its
     * prompt through a `PreToolUse` hook and is inside the measured universe of the
     * table's rows 3 and 4 without this test seeing it — it happens to live in a
     * file already listed. A future file doing the same joins that universe
     * silently. So this asserts the file set the table's rows were measured over,
     * which is a NARROWER thing than the domain those rows have.
     *
     * `ChatTest` is outside the set — that is why the table measures it as a
     * separate column — and is NOT separately asserted to be. With the three names
     * hard-coded below, an `assertNotContains('ChatTest.php', …)` can never be the
     * assertion that fires; keeping it would be the same double-counting deleted
     * from {@see testTheBarIsNeverNarrowerThanTheTooSmallCueAtAnySize()} in the
     * round that added it.
     */
    public function testTheGuardMutationDomainIsTheFilesThatBuildAPermissionRequestMsg(): void
    {
        $root = \dirname(__DIR__);
        $found = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            if (self::constructs((string) file_get_contents($file->getPathname()), 'PermissionRequestMsg')) {
                $found[] = str_replace($root . '/', '', $file->getPathname());
            }
        }
        sort($found);

        $this->assertSame(
            ['Commands/KeyBindingDriftTest.php', 'Renderer/KeyHelpTest.php', 'RendererTest.php'],
            $found,
            "the trio column in Chat::requestPermission()'s mutation table is exactly these files; a fourth "
            . 'one CONSTRUCTING a PermissionRequestMsg widens the universe every row of that table was '
            . 'measured over. Merely naming the class in a comment does not, which is the half the grep '
            . 'this replaces got wrong',
        );
    }

    /**
     * True when `$src` contains a `new <…>\$short(` — construction, not mention.
     *
     * Token-based rather than textual because the difference is exactly what
     * {@see testTheGuardMutationDomainIsTheFilesThatBuildAPermissionRequestMsg()}
     * exists to measure: comments, docblocks and string literals name the class
     * without putting a prompt anywhere.
     *
     * ── WHAT IT SEES, and what it does NOT ──
     *
     * `T_NEW` followed (past whitespace, comments and a whole attribute group) by
     * `T_STRING` / `T_NAME_QUALIFIED` / `T_NAME_FULLY_QUALIFIED` whose last
     * segment is `$short`, OR by a name this file IMPORTED as an alias of
     * `$short`. A previous revision called the first of those "the only form that
     * constructs one by name", and that was false in the most idiomatic direction
     * available: with `use SugarCraft\Crush\PermissionRequestMsg as Ask;` at the
     * top, `new Ask(…)` really is a `T_NEW` followed by a `T_STRING` and really
     * does build one — measured against a real fourth test file that drove a real
     * prompt while this scan reported three. That gap is CLOSED here (the `use …
     * as …` sweep below), and pinned by
     * {@see testTheConstructionScanSeesAnAliasedImportAndSaysWhatItCannotSee()}.
     *
     * Three forms remain invisible, each measured rather than assumed, and each
     * left open because closing it needs evaluation rather than parsing:
     *
     * - `new $variable(…)`, including the computed-name case
     *   (`$cls = 'SugarCraft\\Permission' . 'RequestMsg'`) the previous revision
     *   already documented;
     * - `new (PermissionRequestMsg::class)(…)` — a parenthesised class
     *   expression, where the token after `T_NEW` is `(`;
     * - `new class(…) extends PermissionRequestMsg {}` — an anonymous subclass,
     *   where the token after `T_NEW` is `T_CLASS`. This one would put a prompt
     *   up through a subtype rather than the type itself.
     *
     * The attribute skip is a real skip now and was not before: `T_ATTRIBUTE` is
     * only the opening `#[`, so skipping that single token used to land on the
     * attribute's own name and then hit the closing `]` (a non-array token) and
     * `break` — measured, an inert entry in the skip list. It balances brackets
     * to the matching `]` here ({@see skipAttributeGroup()}). It is still
     * DORMANT and is labelled so: the one position PHP allows an attribute in
     * after `new` is an anonymous class, which is a blind spot above for an
     * unrelated reason, so no source this makes reachable can return `true`. It
     * is fixed rather than dropped because the skip list is a claim about what
     * the scan steps over, and a claim that does not hold is worse than a
     * dormant one that does.
     */
    private static function constructs(string $src, string $short): bool
    {
        $tokens = token_get_all($src);
        $count = \count($tokens);
        $names = [$short => true];

        // `use A\B\PermissionRequestMsg as Ask;` makes `Ask` a name that
        // constructs one. Swept first so the scan below can match on it.
        for ($i = 0; $i < $count; $i++) {
            if (!\is_array($tokens[$i]) || $tokens[$i][0] !== T_USE) {
                continue;
            }

            $imported = null;
            for ($j = $i + 1; $j < $count; $j++) {
                $token = $tokens[$j];
                if (!\is_array($token)) {
                    break;
                }
                if ($token[0] === T_WHITESPACE || $token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                if (\in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    $parts = explode('\\', $token[1]);
                    if ($imported === null) {
                        $imported = end($parts);

                        continue;
                    }
                    // The token after `as`.
                    if ($imported === $short) {
                        $names[end($parts)] = true;
                    }

                    break;
                }
                if ($token[0] !== T_AS) {
                    break;
                }
            }
        }

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!\is_array($token) || $token[0] !== T_NEW) {
                continue;
            }

            for ($j = $i + 1; $j < $count; $j++) {
                $next = $tokens[$j];
                if (\is_array($next) && $next[0] === T_ATTRIBUTE) {
                    $j = self::skipAttributeGroup($tokens, $j);

                    continue;
                }
                if (!\is_array($next)) {
                    break;
                }
                if (\in_array($next[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if (!\in_array($next[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    break;
                }

                $parts = explode('\\', $next[1]);
                if (isset($names[end($parts)])) {
                    return true;
                }

                break;
            }
        }

        return false;
    }

    /**
     * The index of the `]` closing the attribute group whose `#[` is at `$at`.
     *
     * `token_get_all()` emits `#[` as one `T_ATTRIBUTE` and then the group's
     * contents as ordinary tokens, so the group has to be walked by balancing
     * brackets — a nested `#[Foo(Bar::class)]` inside an attribute argument
     * would otherwise close the outer group early.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private static function skipAttributeGroup(array $tokens, int $at): int
    {
        $depth = 1;
        $count = \count($tokens);
        for ($i = $at + 1; $i < $count; $i++) {
            $token = $tokens[$i];
            if (\is_array($token) && $token[0] === T_ATTRIBUTE) {
                $depth++;

                continue;
            }
            if ($token === '[') {
                $depth++;

                continue;
            }
            if ($token === ']' && --$depth === 0) {
                return $i;
            }
        }

        return $count;
    }

    /**
     * The instrument behind the domain test, measured on sources rather than
     * described — because its docblock made a uniqueness claim
     * (`T_NEW` + name is "the only form that constructs one by name") that a real
     * fourth test file refuted while every test in this file stayed green.
     *
     * The refutation, verbatim, was three lines:
     *
     *     use SugarCraft\Crush\PermissionRequestMsg as Ask;
     *     $m = new Ask(Message::assistant('x'), new ToolCall('bash', [], '1'), 'p?', null);
     *
     * That file drove a real prompt. `constructs()` reported three files, so the
     * table's domain silently excluded it. The alias sweep closes it; the three
     * rows below it are the misses that REMAIN, asserted as misses so the
     * docblock cannot drift back into claiming completeness.
     */
    public function testTheConstructionScanSeesAnAliasedImportAndSaysWhatItCannotSee(): void
    {
        $seen = [
            'plain new' => '<?php $m = new PermissionRequestMsg($a, $b, "p", null);',
            'fully qualified' => '<?php $m = new \SugarCraft\Crush\PermissionRequestMsg($a, $b, "p", null);',
            'aliased import' => '<?php use SugarCraft\Crush\PermissionRequestMsg as Ask; $m = new Ask($a, $b, "p", null);',
        ];
        $blind = [
            'variable class name' => '<?php $c = PermissionRequestMsg::class; $m = new $c($a, $b, "p", null);',
            'parenthesised class expression' => '<?php $m = new (PermissionRequestMsg::class)($a, $b, "p", null);',
            'anonymous subclass' => '<?php $m = new class($a, $b, "p", null) extends PermissionRequestMsg {};',
            'attributed anonymous subclass'
                => '<?php $m = new #[Foo(PermissionRequestMsg::class)] class extends PermissionRequestMsg {};',
        ];
        $inert = [
            'line comment' => '<?php // PermissionRequestMsg is only mentioned here',
            'docblock' => '<?php /** {@see PermissionRequestMsg} */ $x = 1;',
            'string literal' => '<?php $s = "PermissionRequestMsg";',
            'unrelated alias' => '<?php use A\B\SomethingElse as PermissionRequestMsg2; $m = new PermissionRequestMsg2();',
        ];

        foreach ($seen as $label => $src) {
            $this->assertTrue(
                self::constructs($src, 'PermissionRequestMsg'),
                "the scan must see this construction ({$label}) — an unseen one puts a fourth file's tests "
                . "inside the mutation table's domain without the table knowing",
            );
        }
        foreach ($blind as $label => $src) {
            $this->assertFalse(
                self::constructs($src, 'PermissionRequestMsg'),
                "this construction is a KNOWN blind spot ({$label}) and is asserted as one: if it starts "
                . "being seen, constructs()'s docblock is overstating what it misses in the other direction",
            );
        }
        foreach ($inert as $label => $src) {
            $this->assertFalse(
                self::constructs($src, 'PermissionRequestMsg'),
                "mentioning the class is not constructing one ({$label}) — this is the half the grep this "
                . 'instrument replaced got wrong',
            );
        }

        // The attribute skip, pinned on the thing it can actually do rather than
        // through constructs(), and labelled DORMANT rather than left in the skip
        // list looking active. `#[` is a single `T_ATTRIBUTE` token, so the
        // previous one-token skip landed on the attribute's own NAME and then
        // broke on the closing `]` — inert, measured. Balancing brackets makes it
        // a real skip; it still cannot turn any source TRUE, because the one
        // position PHP allows an attribute in after `new` is an anonymous class,
        // which is the blind spot asserted directly above. It is fixed rather
        // than deleted because the skip list is a claim about what the scan steps
        // over, and a claim that does not hold is worse than a dormant one that
        // does.
        $group = token_get_all('<?php new #[A(["x" => [1]])] class {};');
        $at = null;
        foreach ($group as $i => $token) {
            if (\is_array($token) && $token[0] === T_ATTRIBUTE) {
                $at = $i;
                break;
            }
        }
        $this->assertNotNull($at, 'fixture: token_get_all emits "#[" as one T_ATTRIBUTE');
        $closed = self::skipAttributeGroup($group, $at);
        $this->assertSame(
            ']',
            $group[$closed],
            'skipAttributeGroup() must land on the "]" that closes the group…',
        );
        $this->assertSame(
            'T_CLASS',
            token_name($group[$closed + 2][0]),
            '…so the very next significant token is the anonymous class, not a bracket left over from the '
            . "attribute's own array argument",
        );
    }

    /**
     * The reason `Chat::requestPermission()`'s mutation table names a SITE and not
     * just a predicate, asserted instead of narrated.
     *
     * That predicate —
     * `$msg->generation !== null && $msg->generation !== $this->generation` —
     * appears four times in `Chat.php`, in four blocks that are byte-identical
     * apart from indentation. A replace-first `sed` therefore lands in
     * `update()`'s `AssistantMsg` arm, not in `requestPermission()`, and one
     * review round published the wrong site's mutation figures as a refutation of
     * the right site's: at the `AssistantMsg` arm the "drop the second conjunct"
     * mutation leaves the trio GREEN, which reads as "the trio does not cover
     * this" when it means "you mutated the wrong line".
     *
     * So this pins the hazard itself: how many copies there are, and which method
     * owns each. A fifth copy, or a copy moving to another method, changes what
     * "the guard" means in that table and must force it to be re-read.
     */
    public function testTheGenerationGuardPredicateAppearsInExactlyFourNamedMethods(): void
    {
        $file = \dirname(__DIR__, 2) . '/src/Chat.php';
        $lines = explode("\n", (string) file_get_contents($file));
        // The whole statement, and comment lines skipped: the method's own comment
        // quotes the predicate twice (once as the warning, once as a reproducing
        // grep), so a substring match over every line counts prose as code.
        $needle = 'if ($msg->generation !== null && $msg->generation !== $this->generation) {';

        /** @var array<string, list<int>> $byMethod */
        $byMethod = [];
        foreach ($lines as $i => $line) {
            $trimmed = ltrim($line);
            if (!str_starts_with($trimmed, $needle) || str_starts_with($trimmed, '//')) {
                continue;
            }
            $method = null;
            for ($j = $i; $j >= 0 && $method === null; $j--) {
                if (preg_match('/function\s+(\w+)\s*\(/', $lines[$j], $m) === 1) {
                    $method = $m[1];
                }
            }
            $byMethod[(string) $method][] = $i + 1;
        }

        // SORTED, for the same reason `ksort($disagreesOnSubmit)` one method away
        // is: `$byMethod` is keyed by file-scan order, so asserting the raw
        // array_keys() asserted an ORDERED list while the message called it a
        // SET. Measured on the unsorted version: swapping the whole texts of
        // finishToolCalls() and applyBackendToolEvent() in Chat.php — a pure
        // relocation, zero behaviour change, the guard's own text untouched —
        // reddened this line while RendererTest + ChatTest + Commands/KeyBindingDriftTest
        // stayed green. That is the third order artifact found in this file, and it
        // mattered more than tidiness: the mutation table in
        // Chat::requestPermission() rests on "raw = the behavioural column plus
        // exactly one, and that one is THIS test, because you edited the guard's
        // text". A test that also fires when you edited nothing about the guard
        // breaks that rule.
        ksort($byMethod, SORT_STRING);
        $this->assertSame(
            ['applyBackendToolEvent', 'finishToolCalls', 'requestPermission', 'update'],
            array_keys($byMethod),
            'the generation guard is spelled out per-site; if the SET of sites changed, '
            . "Chat::requestPermission()'s mutation table names one of them and must be re-measured. "
            . 'Byte-sorted, so MOVING a method cannot flip it',
        );
        foreach ($byMethod as $method => $at) {
            $this->assertCount(1, $at, "one copy per method ({$method} has " . \count($at) . ')');
        }

        // And the copies really are indistinguishable by text, which is the whole
        // hazard: a table that named only the predicate would be ambiguous.
        $bodies = [];
        foreach ($byMethod as $at) {
            $bodies[] = implode('|', array_map('trim', array_slice($lines, $at[0] - 1, 3)));
        }
        $this->assertCount(1, array_unique($bodies), 'the four blocks differ only in indentation');
        $this->assertSame(
            $needle . '|return [$this, null];|}',
            $bodies[0],
            'and each is the same three lines, which is why a mutation must be anchored by line number',
        );
    }

    /**
     * The reference and the palette CAN both be open, so the chain's order
     * matters for this pair too — it is not the unreachable case.
     *
     * The route is the mouse: the box is bounded off the last two rows, so the
     * status bar's `pane:` click zone stays live underneath it, and clicking
     * "Ctrl+P menu" opens the palette with `keyHelp` untouched. The reference
     * then keeps both the slot and the keyboard, which is the invariant
     * {@see Renderer::renderView()}'s chain exists to hold; before the chain was
     * ordered by routing, the palette painted while the reference ate the keys.
     */
    public function testAMouseOpenedPaletteDoesNotTakeTheSlotFromTheReference(): void
    {
        [$open] = $this->chat()->update(new KeyMsg(KeyType::Char, '?'));
        $open->view();

        $zone = null;
        for ($row = 1; $row <= 30 && $zone === null; $row++) {
            for ($col = 1; $col <= 100; $col++) {
                if (Chat::zoneAt($col, $row)?->id === Renderer::PANE_ZONE_PREFIX . 'menu') {
                    $zone = [$col, $row];
                    break;
                }
            }
        }
        $this->assertNotNull($zone, 'fixture: the status bar keeps its pane zone under the overlay');

        [$col, $row] = $zone;
        [$clicked] = $open->update(new MouseClickMsg($col, $row, MouseButton::Left, MouseAction::Press));
        [$clicked] = $clicked->update(new MouseReleaseMsg($col, $row, MouseButton::Left, MouseAction::Release));

        $this->assertNotNull($clicked->palette(), 'the click opened the palette');
        $this->assertSame(0, $clicked->keyHelp(), 'and left the reference open');

        $frame = $this->body($clicked);
        $this->assertStringContainsString(self::TITLE, $frame, 'the reference keeps the slot');
        // renderPalette()'s query line is the palette's own signature.
        $this->assertStringNotContainsString('🔍', $frame, 'and the palette does not paint under it');
    }

    /**
     * The whole overlay chain in {@see Renderer::renderView()}, walked end to
     * end: reference → permission prompt → palette → session picker.
     *
     * Four links make SIX pairs. Exactly ONE of them is reachable through the
     * front door — reference+palette, by the mouse route
     * ({@see testAMouseOpenedPaletteDoesNotTakeTheSlotFromTheReference()}) — and
     * the other five are fixed precedence between modals that no sequence of real
     * input puts up together, which `Chat::update()` states outright of
     * palette+picker ("even though they cannot both be open"). Dormant is not the
     * same as unpinned: a documented order nothing reads back is an order that
     * gets reshuffled by the next person to touch the `if` chain, and swapping the
     * palette and the picker used to change nothing anywhere in this suite.
     *
     * reference+prompt is one of the five, and a previous revision counted it
     * among the reachable ones on the strength of
     * {@see testTheReferenceOutranksAPermissionPromptBecauseItOwnsTheKeyboard()} —
     * which reaches that state through {@see blockedOnPermission()}, i.e. by
     * hand-dispatching a `PermissionRequestMsg`, the same non-front-door
     * construction this file correctly labels as such around
     * {@see testASupersededAskNeverPutsUpAPrompt()}. The unreachability is not
     * narrated here either: it is driven with a real `PreToolUse` hook returning
     * `HookResult::ask()` over a real turn, from both ends, by
     * {@see testThePromptAndTheReferenceCannotBothBeRaisedByRealInput()}.
     * `Chat::update()`'s ordering makes the reference outrank the prompt anyway,
     * and the sibling test is a deliberate determinism guarantee over a state
     * today's producers cannot build — kept as one, labelled as one.
     *
     * Domain, stated because it is not a user-reachable state: ONE
     * hand-assembled Chat with all four overlays set at 100x30, walked by
     * removing the winner four times. It pins the ordering, not the reachability.
     */
    public function testTheOverlayChainPaintsInRoutingOrderRightDownTheChain(): void
    {
        $picker = SessionPicker::new([[
            'sessionId' => 'sess-a',
            'sessionName' => 'alpha',
            'summary' => 'the first one',
            'gitBranch' => 'master',
            'lastActivity' => '2026-01-01T00:00:00+00:00',
        ]]);

        [$withPalette] = $this->chat()->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
        $palette = $withPalette->palette();
        $this->assertNotNull($palette, 'fixture: a real palette state to graft on');

        $all = self::graft($this->blockedOnPermission(), [
            'palette' => $palette,
            'sessionPicker' => $picker,
        ])->withKeyHelp(0);

        // Every link is present, so each assertion below is about precedence
        // rather than about the loser not existing.
        $this->assertSame(0, $all->keyHelp());
        $this->assertNotNull($all->pendingPermission());
        $this->assertNotNull($all->palette());
        $this->assertNotNull($all->sessionPicker());

        // Each needle must be unique to the overlay that paints it. The
        // picker's own title is NOT: the reference screen documents a row
        // reading "Open the session picker", so matching on its title would
        // report the picker as painted on every frame the reference wins. Its
        // controls line is the signature that only the picker emits.
        $signature = [
            'the reference' => self::TITLE,
            'the permission prompt' => 'permission required',
            'the palette' => '🔍',
            'the session picker' => '↑↓ browse',
        ];
        $order = array_keys($signature);

        foreach ($order as $position => $winner) {
            $frame = $this->body($all);
            $this->assertStringContainsString(
                $signature[$winner],
                $frame,
                "{$winner} must hold the overlay slot at link {$position} of the chain",
            );
            foreach (array_slice($order, $position + 1) as $loser) {
                $this->assertStringNotContainsString(
                    $signature[$loser],
                    $frame,
                    "{$loser} must not paint while {$winner} holds the slot — Renderer::renderView()'s "
                    . 'chain is Chat::update()\'s routing order, and an overlay the keyboard is not '
                    . 'driving misrepresents what the next key does',
                );
            }

            // Drop the winner and go round again, so the next link is exercised
            // against the same fully-populated state.
            $all = match ($winner) {
                'the reference' => $all->withKeyHelp(null),
                'the permission prompt' => self::graft($all, ['pendingPermission' => null]),
                'the palette' => self::graft($all, ['palette' => null]),
                default => $all,
            };
        }
    }

    /**
     * A Chat with overlay state set DIRECTLY, bypassing the routing that would
     * normally refuse to put two of these up at once.
     *
     * Only justified for pinning a documented precedence between modals that
     * cannot coexist — see
     * {@see testTheOverlayChainPaintsInRoutingOrderRightDownTheChain()}. Every
     * other test in this file goes through the front door.
     *
     * @param array<string, mixed> $overlays
     */
    private static function graft(Chat $chat, array $overlays): Chat
    {
        $mutate = new \ReflectionMethod(Chat::class, 'mutate');

        return $mutate->invoke($chat, $overlays);
    }

    /**
     * The scroll ceiling {@see Chat::withKeyHelp()} clamps against belongs to
     * the LAST painted reference, so it has to be cleared by any frame that
     * does not paint one — including a frame where a different overlay has the
     * slot. Reaching `renderKeyHelp()` first in the chain is what guarantees
     * that; this is the property, not the ordering.
     */
    public function testTheScrollCeilingDoesNotSurviveAFrameWithoutTheReference(): void
    {
        [$open] = $this->chat()->update(new KeyMsg(KeyType::Char, '?'));
        $open->view();
        $this->assertGreaterThan(0, Renderer::keyHelpMaxOffset(), 'fixture: the list must overflow');

        [$closed] = $open->update(new KeyMsg(KeyType::Escape));
        [$palette] = $closed->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
        $this->assertNotNull($palette->palette(), 'fixture: another overlay must hold the slot');
        $palette->view();

        $this->assertSame(0, Renderer::keyHelpMaxOffset());
    }

    /**
     * The narrow-box column split, which was documented backwards and untested:
     * the KEY is truncated so the description keeps a column, not the other way
     * round. Deleting `renderKeyHelp()`'s `min($keyCol, max(1, $width - 2))`
     * leaves the box the same size — `Style::width()` truncates the assembled
     * line rather than wrapping it — so nothing else in this file notices; only
     * the split changes, and this is what reads it.
     *
     * Expected values are derived from the registry rather than spelled out, so
     * a reworded first row does not make this fail for the wrong reason.
     */
    public function testANarrowBoxTruncatesTheKeyToKeepAColumnOfDescription(): void
    {
        $first = KeyBindingRegistry::live()[0];

        // cols=8: content width 4 (8 minus the box's own 4 columns of chrome),
        // so the key field is capped at 2 and the description gets 1.
        $box = $this->overlay($this->chat('', 8, 30)->withKeyHelp(0));
        $this->assertNotSame([], $box, 'fixture: the box must be drawn at 8 columns');

        $rows = array_map(
            static fn(string $line): string => (string) preg_replace('/\e\[[0-9;]*m/', '', $line),
            $box,
        );

        $key = Width::truncate($first->keys, 2);
        $desc = Width::truncate($first->description, 1);
        $this->assertContains(
            '│ ' . $key . ' ' . $desc . ' │',
            $rows,
            'the key must lose characters while the description keeps its column',
        );

        // Wide enough for both in full, as the same row read at 100 columns.
        $wide = implode("\n", $this->overlay($this->chat('', 100, 40)->withKeyHelp(0)));
        $this->assertStringContainsString($first->keys, $wide);
        $this->assertStringContainsString($first->description, $wide);
    }

    /**
     * {@see Renderer::KEY_HELP_CHROME_COLS}'s stated derivation — 2 border
     * columns plus the 2 of `padding(0, 1)` — made checkable: the box the
     * renderer draws is exactly that much wider than the content width
     * `keyHelpGeometry()` hands `Style::width()`.
     *
     * Raising the constant to 6 fails here (the box comes out 4 wider than a
     * content width computed with 6), which is what the old `> 50` style of
     * bound could not catch.
     */
    public function testTheBoxGrowsPastItsContentWidthByExactlyTheDeclaredChrome(): void
    {
        $chrome = (new \ReflectionClass(Renderer::class))->getConstant('KEY_HELP_CHROME_COLS');
        $max = (new \ReflectionClass(Renderer::class))->getConstant('KEY_HELP_COLS');
        $this->assertIsInt($chrome);
        $this->assertIsInt($max);

        foreach ([20, 40, 68, 80, 120, 200] as $cols) {
            $box = $this->overlay($this->chat('', $cols, 40)->withKeyHelp(0));
            $this->assertNotSame([], $box, "fixture: a box at {$cols} columns");

            $widest = 0;
            foreach ($box as $line) {
                $widest = max($widest, Width::of($line));
            }

            $this->assertSame(
                min($max, $cols - $chrome) + $chrome,
                $widest,
                "the box at {$cols} columns must be its content width plus exactly {$chrome}",
            );
        }
    }

    public function testWithKeyHelpClampsANegativeOffsetToTheTop(): void
    {
        $this->assertSame(0, $this->chat()->withKeyHelp(-5)->keyHelp());
    }

    /**
     * A clamped press that cannot move hands back the SAME Chat, the way
     * `scrollBy()` does — otherwise holding Down at the end of the list
     * allocates a model and diffs a frame per dead notch.
     */
    public function testADeadPressAtTheEndDoesNotAllocateANewChat(): void
    {
        [$chat] = $this->chat()->update(new KeyMsg(KeyType::Char, '?'));
        for ($i = 0; $i < 200; $i++) {
            $chat->view();
            [$chat] = $chat->update(new KeyMsg(KeyType::Down));
        }
        $chat->view();
        $this->assertSame(Renderer::keyHelpMaxOffset(), $chat->keyHelp(), 'fixture: parked at the end');

        [$again] = $chat->update(new KeyMsg(KeyType::Down));
        $this->assertSame($chat, $again);
    }

    /**
     * The wheel drives the reference while it is up, not the transcript behind
     * it: scrolling something the user cannot see is the same defect
     * `handleKeyHelpKey()` swallows stray letters to avoid.
     */
    public function testTheWheelScrollsTheReferenceAndNotTheHiddenTranscript(): void
    {
        $history = [];
        for ($i = 0; $i < 200; $i++) {
            $history[] = Message::user('line ' . $i);
        }
        $chat = (new Chat(history: $history, backend: new EchoBackend()))->withSize(100, 30);
        [$open] = $chat->update(new KeyMsg(KeyType::Char, '?'));
        $open->view();

        [$down] = $open->update(new MouseWheelMsg(1, 1, MouseButton::WheelDown, MouseAction::Press));
        $this->assertGreaterThan(0, $down->keyHelp(), 'wheel-down moves further into the list');
        $this->assertSame(0, $down->scrollOffset(), 'and leaves the hidden transcript alone');

        $down->view();
        [$up] = $down->update(new MouseWheelMsg(1, 1, MouseButton::WheelUp, MouseAction::Press));
        $this->assertSame(0, $up->keyHelp(), 'wheel-up comes back towards the first row');
        $this->assertSame(0, $up->scrollOffset());

        // With the reference closed the wheel is the transcript's again.
        $chat->view();
        [$scrolled] = $chat->update(new MouseWheelMsg(1, 1, MouseButton::WheelUp, MouseAction::Press));
        $this->assertGreaterThan(0, $scrolled->scrollOffset());
    }
}
