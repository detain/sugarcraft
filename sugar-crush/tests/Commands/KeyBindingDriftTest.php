<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Commands;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseReleaseMsg;
use SugarCraft\Core\Msg\MouseWheelMsg;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\App\SelectSkillMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Commands\KeyBindingRegistry;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\PermissionRequestMsg;
use SugarCraft\Crush\Permissions\PermissionPromptStage;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Session\SessionStore;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\ToolResult;
use SugarCraft\Crush\Tui\AgentViewMode;
use SugarCraft\Crush\Tui\Commands\CommandPaletteCmd;
use SugarCraft\Crush\Tui\Commands\NewSessionCmd;
use SugarCraft\Crush\Tui\Commands\QuitAgentViewCmd;
use SugarCraft\Crush\Tui\Commands\SourceSkillCmd;
use SugarCraft\Crush\Tui\Components\MenuBar;
use SugarCraft\Crush\Tui\Components\MenuSelectedMsg;
use SugarCraft\Crush\Tui\KeyboardHandler;
use SugarCraft\Crush\Tui\Pane;
use SugarCraft\Mouse\Zone;

/**
 * The anti-drift half of the in-app keybinding reference (crush_code.md
 * Phase 8 item 2).
 *
 * A hand-written cheat sheet is worth less than nothing the moment it
 * disagrees with the code — the exact failure §4.11 documents for the two
 * command surfaces before {@see KeyBindingRegistry}'s sibling
 * {@see \SugarCraft\Crush\Commands\CommandRegistry} unified them. So every
 * row the reference shows is DRIVEN here through the real handler
 * ({@see Chat::update()}, {@see KeyboardHandler}, {@see MenuBar::handleKey()})
 * and asserted to still produce the effect its one-line description promises.
 *
 * Both directions are closed:
 *
 * - a row added to the registry with no observation below fails
 *   {@see testEveryDocumentedBindingStillDoesWhatItSays()} on the missing key,
 *   so a binding cannot be documented without being demonstrated;
 * - an observation whose row was deleted or marked dormant fails
 *   {@see testNoObservationDescribesABindingThatIsNoLongerDeclared()}.
 *
 * Deleting the handler arm itself is what this is really for: remove
 * `Chat::update()`'s Ctrl+O arm and `chat.tool-output` goes red rather than
 * the reference quietly continuing to advertise it.
 *
 * One rule the review of this file's first version had to establish, because
 * five rows broke it: a label naming TWO keys must be driven with both, and the
 * two must be asserted to land in DIFFERENT places. Every list in this app wraps,
 * so from row 0 either direction "moves the highlight" and either menu is "not
 * the one we started on" — `menu.switch` proved the point, staying green with
 * `MenuBar::handleKey()`'s whole `'left', 'h'` arm deleted and green again with
 * left and right swapped. The same applies to a row whose description promises
 * two effects ("Expand or collapse", "Drop the selection, then leave the view"):
 * one half observed is one half advertised on trust.
 *
 * Dormant rows are deliberately NOT driven — they have no effect to observe,
 * which is precisely why the reference does not list them.
 */
final class KeyBindingDriftTest extends TestCase
{
    use HomeSandboxTrait;

    private ProviderInterface $provider;
    private string $sandbox = '';
    private int $storeSeq = 0;

    protected function setUp(): void
    {
        $this->provider = $this->createMock(ProviderInterface::class);
        // Sessions, skills and the instruction loader all walk HOME; the
        // sandbox keeps every fixture below off the developer's real one.
        $this->sandbox = $this->useHomeSandbox(
            sys_get_temp_dir() . '/crush_keybind_drift_' . uniqid('', true),
        );
        $this->resetSharedState();
    }

    protected function tearDown(): void
    {
        $this->resetSharedState();
        $this->restoreHomeSandbox();
        $this->removeTree($this->sandbox);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function liveBindingIds(): iterable
    {
        foreach (KeyBindingRegistry::live() as $binding) {
            yield $binding->id => [$binding->id];
        }
    }

    #[DataProvider('liveBindingIds')]
    public function testEveryDocumentedBindingStillDoesWhatItSays(string $id): void
    {
        $observations = $this->observations();

        $this->assertArrayHasKey(
            $id,
            $observations,
            "KeyBindingRegistry documents '{$id}' but nothing here drives it. The reference "
            . 'may only advertise bindings this suite proves still work — add an observation '
            . 'or mark the row dormant.',
        );

        $binding = KeyBindingRegistry::byId($id);
        $this->assertNotNull($binding);

        // The keystrokes come from the row's own LABEL, not from the
        // observation. That is what makes the label itself covered: an
        // observation that built its own KeyMsg would keep passing after the
        // label was changed to a chord the app does not answer, which is the
        // one failure a drift test exists to catch.
        $primary = self::chord($binding->keys);
        $this->resetSharedState();
        ($observations[$id])($primary);

        // A description's "(or …)" aside is a second promise the reference
        // makes, and an unkept one reads exactly like a kept one. So the same
        // observation runs again on the alternate spelling and has to reach the
        // same conclusion.
        foreach (self::alternates($binding->description) as $spelling => $keys) {
            $this->assertNotSame(
                [],
                $keys,
                "'{$id}' promises \"or {$spelling}\" but this suite cannot read that back as a "
                . 'keystroke, so nothing checks it. Spell it as a literal chord, or move the note '
                . 'out of the parenthesised "or" form.',
            );
            $this->assertCount(
                count($primary),
                $keys,
                "'{$id}' promises \"or {$spelling}\", which names a different number of keys than "
                . "its label '{$binding->keys}' — one of the two is wrong.",
            );

            // Every invocation starts from the same shared state. MenuBar's
            // open menu is a static, and MenuBar::openMenu() TOGGLES — so an
            // observation that opens menu 1 and leaves it open would close it
            // on the next run and report the alternate key as unclaimed.
            $this->resetSharedState();
            ($observations[$id])($keys);
        }
    }

    /**
     * The alternate spellings a description offers, as `spelling => keys`.
     *
     * Only the `(or …)` form, which is the one that names keys. The other
     * parenthesised asides qualify WHEN a binding applies ("(empty input box)")
     * or what it runs ("(runs /agents)"), and naming a second key is not what
     * they are doing.
     *
     * @return array<string, list<KeyMsg>>
     */
    private static function alternates(string $description): array
    {
        preg_match_all('/\(or ([^)]+)\)/u', $description, $matches);

        $out = [];
        foreach ($matches[1] as $spelling) {
            $out[$spelling] = self::chord($spelling);
        }

        return $out;
    }

    /**
     * Every live label must be readable back as the chord it names — that is
     * what lets {@see testEveryDocumentedBindingStillDoesWhatItSays()} press
     * the label instead of a hand-written copy of it. The handful of rows whose
     * label is prose or a range are listed in {@see HAND_DRIVEN}, and this
     * pins that list closed in both directions.
     */
    public function testEveryLabelIsALiteralChordOrADeclaredException(): void
    {
        foreach (KeyBindingRegistry::live() as $binding) {
            $parsed = self::chord($binding->keys);
            $exempt = in_array($binding->id, self::HAND_DRIVEN, true);

            if ($exempt) {
                $this->assertSame(
                    [],
                    $parsed,
                    "'{$binding->id}' now has a readable label — drop it from HAND_DRIVEN so its "
                    . 'observation presses the label rather than its own key.',
                );

                continue;
            }

            $this->assertNotSame(
                [],
                $parsed,
                "'{$binding->id}' has the label '{$binding->keys}', which this suite cannot read "
                . 'back as a keystroke, so nothing checks that the app answers it. Spell it as a '
                . 'literal chord, or add the row to HAND_DRIVEN with a reason.',
            );
        }
    }

    /**
     * A description may name a key ONLY inside the `(or …)` form, because that
     * is the only form {@see alternates()} reads back and presses. Anywhere
     * else a chord is a promise nothing here drives — which is exactly what
     * `chat.session-cycle`'s old "(Ctrl+Shift+Tab for the previous)" and
     * `palette.filter`'s old "Backspace erases" were, both painted in the
     * reference and neither ever pressed. Each is a declared row of its own
     * now, and this is what keeps the next one from sneaking back in as prose.
     *
     * The pattern deliberately over-reaches (any modifier prefix in either
     * spelling, the caret form, the named keys, the function keys, the arrows in
     * glyph AND word form): a false positive costs one row split out or one word
     * reworded, a false negative costs an undriven promise. It is
     * case-INSENSITIVE, and covers `Ctrl-C` and `^C` as well as `Ctrl+C`,
     * because a chord written in prose is exactly where the casing and the
     * separator drift.
     *
     * Both directions are measured, and both are ASSERTED rather than stated —
     * {@see testThePatternCatchesEverySpellingItClaimsToCatch()} and
     * {@see testThePatternLeavesItsDocumentedHolesOpen()}. They read this same
     * const, which is the point: the "catches eight spellings the first version
     * missed" claim was true and unasserted, so the tightening could be reverted
     * to its loose pre-fix form without a single test going red.
     *
     * Zero false positives across all 66 declared rows — and THAT is the domain
     * of the zero. It says nothing about prose not yet written; it says those 66
     * rows are clean under this pattern.
     *
     * The eight rows the draft's editing keyboard added are what it cost to keep
     * that zero: `chat.line-ends` reads "Jump to the first or last column"
     * rather than naming the two keys, because `End` is in the alternation
     * above and its own label already says `Home / End`. That is the trade this
     * pattern is for.
     *
     * Holes left open ON PURPOSE, because closing them costs more than it buys:
     *
     * - `Delete`/`Del` and `Insert` are key names AND the verbs three live rows
     *   use ("Delete the previous character", "Insert a newline"), so matching
     *   them would force those descriptions to be reworded to avoid a key
     *   nothing here binds;
     * - a bare letter (`j`, `k`, `q`, `y`, `n`) is indistinguishable from prose,
     *   which is precisely why the `(or …)` form exists — inside it,
     *   {@see alternates()} reads the letter back and presses it;
     * - the word-spelled Mac/word modifiers take a `+`/`-` separator only, never
     *   a space: `Command ` as a modifier spelling matches "the command palette",
     *   an EXISTING row. `Ctrl`/`Alt`/`Shift`/`Cmd`/`Super`/`Meta`/`Fn` do take a
     *   space (`Ctrl P`, `Alt 1`) because no description uses them as words.
     *
     * The word forms of the arrow keys (`Up`/`Down`/`Left`/`Right`) were a
     * third undocumented hole and are now closed — they mattered most of the
     * near-misses probed, because five `*.move` rows describe arrow movement
     * (eleven rows carry an arrow GLYPH in their label; both counts are asserted
     * by {@see testTheArrowRowCountsThisFileQuotesAreStillRight()}, because they
     * were quoted as "four" here and nothing read them back), so
     * "Down moves the highlight" is the likeliest next prose regression. The
     * price is that a description may no longer use those words as ordinary
     * prose ("scroll up"); say "towards the top" instead. `Delete`/`Insert` show
     * why that trade is not automatic, and why these two lists are asserted
     * rather than left to judgement.
     */
    private const KEYISH = '/(?:Ctrl|Control|Alt|Shift|Cmd|Command|Super|Meta)[-+]\S+'
        . '|(?:Ctrl|Alt|Shift|Cmd|Super|Meta|Fn)[ ][\p{L}\p{Nd}]\b'
        . '|\^[A-Za-z]\b'
        . '|\bF-?[0-9]{1,2}\b'
        . '|\b(?:Enter|Return|Esc|Escape|Tab|Backtab|Backspace|BkSp|Space|Spacebar'
        . '|PgUp|PgDn|Page ?Up|Page ?Down|Home|End|Up|Down|Left|Right'
        . '|Numpad|Print ?Screen|Caps ?Lock|Menu ?key|Fn)\b'
        . '|[\x{2190}-\x{2193}]|\x{2318}/iu';

    /**
     * @see KEYISH for the pattern, its two measured directions, and the holes
     *      it leaves open on purpose.
     */
    public function testNoDescriptionNamesAKeyOutsideTheOrForm(): void
    {
        foreach (KeyBindingRegistry::all() as $binding) {
            $withoutAlternates = preg_replace('/\(or [^)]+\)/u', '', $binding->description) ?? '';

            $this->assertSame(
                0,
                preg_match_all(self::KEYISH, $withoutAlternates, $found),
                "'{$binding->id}' names " . implode(', ', $found[0] ?? []) . ' in its description '
                . 'outside the "(or …)" form, so nothing in this suite presses it. Give the key its '
                . 'own row, or reword the description so it does not promise a chord.',
            );
        }
    }

    /**
     * The pattern's POSITIVE power, which nothing measured before: it can be
     * reverted to any looser form and
     * {@see testNoDescriptionNamesAKeyOutsideTheOrForm()} stays green, because
     * that test only ever asserts a count of ZERO against rows that are already
     * clean. Reverting the tightening to its pre-fix shape — `(?:Ctrl|Alt)\+`,
     * no `/i`, none of the named keys — turned no test red.
     *
     * That claim was written with no scope, which for a "nothing went red"
     * measurement is the whole content of it. Its scope is in fact COMPLETE, and
     * by construction rather than by sweep: {@see KEYISH} is a `private const`
     * of this class, `grep -rn KEYISH tests/ src/` finds no other file, so the
     * only tests that can observe it are the ones here — and before this test
     * and its sibling existed, the only one that read it asserted a count of
     * zero, which every looser pattern also satisfies.
     *
     * So the spellings the tightening claims to catch are asserted here, one
     * case per row, against the SAME const the real test reads.
     *
     * Domain: the 44 spellings in {@see keyishSpellings()}. They are the eight
     * the first version missed, the arrow word-forms
     * ({@see keyishSpellings()} explains why those matter most), the near-miss
     * spellings probed against this table, and the spellings the original
     * pattern already caught — the last group so that tightening cannot quietly
     * lose ground it already held.
     *
     * @dataProvider keyishSpellings
     */
    public function testThePatternCatchesEverySpellingItClaimsToCatch(string $label, string $spelling): void
    {
        $this->assertSame(
            1,
            preg_match(self::KEYISH, $spelling),
            "the pattern must read '{$spelling}' as a named key ({$label}): a description that spells a "
            . 'chord this way would promise a binding nothing in this suite presses',
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function keyishSpellings(): array
    {
        $cases = [
            // The eight the first version missed. The claim that it missed them
            // was true and unasserted, which is how it could be reverted.
            'missed: lowercase word' => 'escape',
            'missed: abbreviated' => 'esc',
            'missed: hyphen separator' => 'ctrl-c',
            'missed: caret form' => '^C',
            'missed: spaced named key' => 'Page Up',
            'missed: function key' => 'F11',
            'missed: lowercase modifier' => 'shift+tab',
            'missed: bare named key' => 'Home',
            // The arrow WORD forms — the most valuable of the newly closed
            // holes. Five `*.move` rows describe arrow movement (see
            // testTheArrowRowCountsThisFileQuotesAreStillRight()), so "Down
            // moves the highlight" is the likeliest next prose regression, and
            // the glyph class [\x{2190}-\x{2193}] covers only ↑↓←→.
            'arrow word: up' => 'Up',
            'arrow word: down' => 'Down',
            'arrow word: left' => 'Left',
            'arrow word: right' => 'Right',
            'arrow word in prose' => 'press Down to move',
            'arrow word with noun' => 'the Up arrow',
            // Near-miss spellings this pattern now closes.
            'spacebar' => 'Spacebar',
            'abbreviated backspace' => 'BkSp',
            'backtab' => 'Backtab',
            'space-separated modifier' => 'Ctrl P',
            'modifier spelled out' => 'control+p',
            'mac modifier spelled out' => 'command+k',
            'mac modifier glyph' => '⌘K',
            'print screen' => 'Print Screen',
            'caps lock' => 'Caps Lock',
            'hyphenated function key' => 'F-10',
            'space-separated digit' => 'Alt 1',
            'numpad' => 'Numpad 5',
            'fn' => 'Fn',
            'menu key' => 'Menu key',
            // Ground the original pattern already held.
            'held: canonical chord' => 'Ctrl+P',
            'held: alt chord' => 'Alt+Enter',
            'held: two modifiers' => 'Ctrl+Shift+Tab',
            'held: up glyph' => '↑',
            'held: down glyph' => '↓',
            'held: pgdn' => 'PgDn',
            'held: backspace' => 'Backspace',
            'held: space' => 'Space',
            'held: return' => 'Return',
            'held: end' => 'End',
            'held: tab' => 'Tab',
            'held: enter' => 'Enter',
            'held: page down' => 'Page Down',
            'held: meta' => 'Meta+x',
            'held: super' => 'Super+l',
            'held: cmd' => 'Cmd+K',
        ];

        $provided = [];
        foreach ($cases as $label => $spelling) {
            $provided[$label] = [$label, $spelling];
        }

        return $provided;
    }

    /**
     * The other half, and the reason the pattern is not simply "any capitalised
     * word": the holes it leaves open must STAY open, or three live descriptions
     * have to be reworded to avoid naming a key nothing binds.
     *
     * Domain: the 20 strings in {@see nonKeyishProse()} — the two documented
     * holes (`Delete`/`Del`/`Insert`, bare letters) plus real and near-real
     * description prose that the space-separated modifier form must not trip.
     * "the command palette" is the specific trap: allowing `Command ` as a
     * modifier spelling would match an EXISTING row.
     *
     * @dataProvider nonKeyishProse
     */
    public function testThePatternLeavesItsDocumentedHolesOpen(string $prose): void
    {
        $this->assertSame(
            0,
            preg_match(self::KEYISH, $prose),
            "'{$prose}' must not read as a named key — see the two holes this pattern leaves open on "
            . 'purpose, and the prose it may not trip',
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonKeyishProse(): array
    {
        $cases = [
            // Hole 1: key names that are also the verbs three live rows use.
            'Delete the previous character',
            'Insert a newline instead of sending',
            'Del',
            'Insert',
            // Hole 2: a bare letter is indistinguishable from prose, which is
            // exactly why the `(or …)` form exists.
            'j', 'k', 'q', 'y', 'n',
            // Real description prose. "command palette" is why the word-spelled
            // Mac modifier may not take a SPACE separator.
            'Open the command palette',
            'Run the highlighted command',
            'the command palette',
            'Move the highlighted row',
            'Scroll the transcript',
            'Group the input',
            'Filter the list as you type',
            'Switch menu',
            'Open the menu bar',
            'Look at the selected agent',
            'Cancel the selected agent',
        ];

        $provided = [];
        foreach ($cases as $prose) {
            $provided[$prose] = [$prose];
        }

        return $provided;
    }

    /**
     * The two row counts this file quotes in prose, read back off the registry.
     *
     * {@see KEYISH}'s reasoning for closing the arrow WORD-form hole rests on
     * how many rows describe arrow movement, and that number was quoted as
     * "four" when it was five — the sort of figure that is right when written
     * and silently wrong two rows later. Both counts are asserted here so the
     * prose cannot drift again.
     *
     * Domain: {@see KeyBindingRegistry::live()}. Dormant rows are excluded
     * because the reference does not list them and no prose here is about them.
     */
    public function testTheArrowRowCountsThisFileQuotesAreStillRight(): void
    {
        $move = [];
        $arrowLabelled = [];
        foreach (KeyBindingRegistry::live() as $binding) {
            if (str_ends_with($binding->id, '.move')) {
                $move[] = $binding->id;
            }
            if (preg_match('/[\x{2190}-\x{2193}]/u', $binding->keys) === 1) {
                $arrowLabelled[] = $binding->id;
            }
        }

        $this->assertCount(
            5,
            $move,
            'KEYISH\'s docblock says five `*.move` rows describe arrow movement; it found: '
            . implode(', ', $move),
        );
        $this->assertCount(
            11,
            $arrowLabelled,
            'KEYISH\'s docblock says eleven rows carry an arrow glyph in their label; it found: '
            . implode(', ', $arrowLabelled),
        );
        // Every `*.move` row is arrow-labelled, which is what makes the first
        // count the interesting one — the extra six are `chat.slash-menu`,
        // `chat.recall`, `chat.cursor`, `chat.word-motion`, `chat.draft-rows`
        // and `menu.switch`.
        $this->assertSame([], array_diff($move, $arrowLabelled));
    }

    /**
     * `chord()` flattens `A / B` (a choice) and `A B` (a sequence) into the same
     * ordered list, so the label of a row whose MEANING depends on which form it
     * is written in is pinned here rather than left to the parser.
     *
     * `chat.cancel` is that row: "Esc Esc … twice, quickly" is two presses one
     * after the other, and rewriting the label as `Esc / Esc` would read as two
     * ways to do it while its observation kept passing unchanged.
     *
     * Asserted in both directions — a new sequence row shows up here as an
     * unexpected entry, which is the prompt to decide whether its observation
     * really presses the keys in order.
     */
    public function testTheOnlySequenceLabelIsStillWrittenAsASequence(): void
    {
        $sequences = [];
        foreach (KeyBindingRegistry::live() as $binding) {
            if (count(self::chord($binding->keys)) > 1 && !str_contains($binding->keys, '/')) {
                $sequences[] = $binding->id;
            }
        }

        $this->assertSame(
            ['chat.cancel'],
            $sequences,
            'chord() cannot tell a sequence from a choice, so the set of rows written as a sequence is '
            . 'held here explicitly',
        );
    }

    /**
     * {@see token()}'s ASCII-printable narrowing, pinned — the sibling of
     * {@see testTheOnlySequenceLabelIsStillWrittenAsASequence()}, which pins the
     * other latent parser hazard in the same method. This one shipped with no
     * pin at all: reverting the guard to its loose pre-fix form
     * (`mb_strlen($token) === 1 ? new KeyMsg(KeyType::Char, $token) : null`)
     * left this whole file green.
     *
     * What the loose form did: relabelling a row from `Enter` to `⏎` produced a
     * `KeyMsg(Char, '⏎')` — a rune no terminal sends and no arm in the app
     * answers — while
     * {@see testEveryLabelIsALiteralChordOrADeclaredException()}'s "not []"
     * guard still passed, because a KeyMsg HAD been produced. The observation
     * would then press a key nobody can type and assert whatever that no-op
     * left behind. Rejecting the glyph sends such a relabel to that test
     * instead, which is where a label this suite cannot press belongs.
     *
     * Both directions are asserted, because a narrowing aimed at glyphs could
     * easily take the real single-character tokens with it. Measured over
     * {@see KeyBindingRegistry::all()} and the `(or …)` spellings inside every
     * description, those are exactly `? a c h j k l n o q r s y` plus the four
     * arrows — 13 printable-ASCII runes and 4 glyphs — so the positive half
     * below covers the two extremes of that set and the range's own boundaries.
     */
    public function testAGlyphLabelIsUnreadableRatherThanPressedAsARune(): void
    {
        // Rejected: outside printable ASCII and not in token()'s named map.
        // '⏎' is the concrete relabel the guard exists for; the control bytes
        // are the other half of "printable", which "single glyph" also let in.
        $rejected = [
            '⏎' => 'return glyph',
            '⇧' => 'shift glyph',
            '␛' => 'escape glyph',
            "\x01" => 'Ctrl-A byte',
            "\x7f" => 'DEL byte',
        ];
        foreach ($rejected as $token => $why) {
            $this->assertNull(
                self::token($token),
                "token() must not read the {$why} back as a printable rune press — nothing in the app "
                . 'answers it, so the label would look driven and be inert',
            );
            $this->assertSame(
                [],
                self::chord($token),
                "and one unreadable token makes the whole label unreadable ({$why})",
            );
        }

        // Accepted: runes actually in use (`?` labels `chat.keys`, `j`/`k` are
        // `*.move`'s alternates, `y`/`n` are the permission answers) plus the
        // printable range's own two boundaries, ' ' and '~'.
        foreach (['?', 'j', 'k', 'y', 'n', ' ', '~'] as $rune) {
            $key = self::token($rune);
            $this->assertNotNull($key, "token() must still read '{$rune}' as a rune press");
            $this->assertSame(KeyType::Char, $key->type);
            $this->assertSame($rune, $key->rune);
        }

        // And the named glyphs still resolve through the map ABOVE the guard, so
        // narrowing it cannot have cost the eight arrow-labelled rows.
        $arrows = ['↑' => KeyType::Up, '↓' => KeyType::Down, '←' => KeyType::Left, '→' => KeyType::Right];
        foreach ($arrows as $glyph => $type) {
            $key = self::token($glyph);
            $this->assertNotNull($key, "the named map must still resolve '{$glyph}'");
            $this->assertSame($type, $key->type);
        }
    }

    public function testNoObservationDescribesABindingThatIsNoLongerDeclared(): void
    {
        foreach (array_keys($this->observations()) as $id) {
            $binding = KeyBindingRegistry::byId($id);
            $this->assertNotNull($binding, "'{$id}' is observed here but no longer declared.");
            $this->assertTrue($binding->isLive(), "'{$id}' is observed here but marked dormant.");
        }
    }

    /**
     * One closure per live row: it drives the real handler and asserts the
     * described effect.
     *
     * The keystrokes arrive as the argument, parsed out of the row's own label
     * by {@see chord()} — an observation that built its own KeyMsg would leave
     * the label uncovered. The few rows whose label is not a literal chord are
     * listed in {@see HAND_DRIVEN} and take no argument.
     *
     * @return array<string, \Closure(list<KeyMsg>): void>
     */
    private function observations(): array
    {
        return [
            // ── Chat ─────────────────────────────────────────────────────
            'chat.send' => function (array $k): void {
                [$next] = $this->chat([], 'hello')->update($k[0]);
                $this->assertSame('', $next->inputBuf);
                $this->assertNotSame([], $next->history);
            },
            'chat.newline' => function (array $k): void {
                [$next] = $this->chat([], 'a')->update($k[0]);
                $this->assertSame("a\n", $next->inputBuf);
            },
            // Both keys, with DIFFERENT expected answers, for the reason
            // picker.move gives below: the popup wraps, so "the highlight
            // moved" is satisfied by either direction and the label could be
            // written `↓ / ↑`. Pressing only $k[1] was the old form.
            'chat.slash-menu' => function (array $k): void {
                $chat = $this->chat([], '/');
                $rows = count($chat->slashMenuMatches());
                $this->assertGreaterThan(
                    2,
                    $rows,
                    'fixture: a popup of two rows or fewer cannot tell up from down on a wrapping list',
                );
                $this->assertSame(0, $chat->slashMenuIndex(), 'fixture: the popup starts on its first row');

                [$up] = $chat->update($k[0]);
                [$down] = $chat->update($k[1]);
                $this->assertSame($rows - 1, $up->slashMenuIndex(), 'the first key must move UP');
                $this->assertSame(1, $down->slashMenuIndex(), 'the second key must move DOWN');
            },
            'chat.recall' => function (array $k): void {
                [$next] = $this->chat([Message::user('earlier')])->update($k[0]);
                $this->assertSame('earlier', $next->inputBuf);
            },
            'chat.backspace' => function (array $k): void {
                [$next] = $this->chat([], 'ab')->update($k[0]);
                $this->assertSame('a', $next->inputBuf);
            },
            // ── the draft's editing keyboard ─────────────────────────────
            //
            // Every one of these drives Chat::update() and reads the effect
            // back off inputBuf / inputCursorOffset(), never off the widget:
            // it is the ROUTING that these rows document and that can break.
            // Each starts from a cursor parked mid-draft, because at the end of
            // the draft a forward move and a forward delete are both no-ops and
            // an observation could not tell them from an unanswered key.
            'chat.delete-forward' => function (array $k): void {
                $mid = $this->draftCursorAt($this->chat([], 'abcd'), 2);
                [$next] = $mid->update($k[0]);
                $this->assertSame('abd', $next->inputBuf, 'the character UNDER the cursor goes');
                $this->assertSame(2, $next->inputCursorOffset(), 'and the cursor stays put');
            },
            'chat.cursor' => function (array $k): void {
                $mid = $this->draftCursorAt($this->chat([], 'abcd'), 2);

                [$left] = $mid->update($k[0]);
                [$right] = $mid->update($k[1]);
                $this->assertSame(1, $left->inputCursorOffset(), 'the first key must move LEFT');
                $this->assertSame(3, $right->inputCursorOffset(), 'the second key must move RIGHT');
                $this->assertSame('abcd', $left->inputBuf, 'motion must never edit');
                $this->assertSame('abcd', $right->inputBuf);
            },
            'chat.word-motion' => function (array $k): void {
                $mid = $this->draftCursorAt($this->chat([], 'alpha beta gamma'), 8);

                [$left] = $mid->update($k[0]);
                [$right] = $mid->update($k[1]);
                $this->assertSame(6, $left->inputCursorOffset(), 'the first key must move a word LEFT');
                $this->assertSame(10, $right->inputCursorOffset(), 'the second key must move a word RIGHT');
                $this->assertSame('alpha beta gamma', $left->inputBuf, 'word motion must never edit');
            },
            'chat.line-ends' => function (array $k): void {
                $mid = $this->draftCursorAt($this->chat([], 'abcd'), 2);

                [$home] = $mid->update($k[0]);
                [$end] = $mid->update($k[1]);
                $this->assertSame(0, $home->inputCursorOffset(), 'the first key must go to the first column');
                $this->assertSame(4, $end->inputCursorOffset(), 'the second key must go to the last');
            },
            'chat.draft-rows' => function (array $k): void {
                // THREE rows, built the way a user builds them, with the cursor
                // parked on the middle one — the only starting point from which
                // both directions have somewhere to go, so "the highlight
                // moved" cannot be satisfied by a clamp.
                $draft = $this->chat([], 'ab');
                foreach (['cd', 'ef'] as $row) {
                    [$draft] = $draft->update(new KeyMsg(KeyType::Enter, alt: true));
                    foreach (['0' => $row[0], '1' => $row[1]] as $rune) {
                        [$draft] = $draft->update(new KeyMsg(KeyType::Char, $rune));
                    }
                }
                $this->assertSame("ab\ncd\nef", $draft->inputBuf, 'fixture: a three-row draft');
                [$draft] = $draft->update($k[0]);
                $this->assertSame(5, $draft->inputCursorOffset(), 'fixture: cursor on the middle row');

                [$up] = $draft->update($k[0]);
                [$down] = $draft->update($k[1]);
                $this->assertSame(2, $up->inputCursorOffset(), 'the first key must move to the row ABOVE');
                $this->assertSame(8, $down->inputCursorOffset(), 'the second key must move to the row BELOW');
                $this->assertSame("ab\ncd\nef", $up->inputBuf, 'vertical motion must never edit');
                $this->assertSame("ab\ncd\nef", $down->inputBuf);
            },
            'chat.word-delete' => function (array $k): void {
                [$next] = $this->chat([], 'foo bar')->update($k[0]);
                $this->assertNotSame('foo bar', $next->inputBuf);
                $this->assertStringNotContainsString('bar', $next->inputBuf);
            },
            'chat.word-delete-back' => function (array $k): void {
                $mid = $this->draftCursorAt($this->chat([], 'alpha beta gamma'), 11);
                [$next] = $mid->update($k[0]);
                $this->assertSame('alpha gamma', $next->inputBuf, 'the word BEFORE the cursor goes');
                $this->assertSame(6, $next->inputCursorOffset());
            },
            'chat.word-delete-forward' => function (array $k): void {
                // Parked at the end of "alpha", so the whitespace under the
                // cursor goes with the word — the same run wordRightOffset()
                // skips, which is what makes the two share one boundary.
                $mid = $this->draftCursorAt($this->chat([], 'alpha beta gamma'), 5);
                [$next] = $mid->update($k[0]);
                $this->assertSame('alpha gamma', $next->inputBuf, 'the word AFTER the cursor goes');
                $this->assertSame(5, $next->inputCursorOffset(), 'and the cursor stays put');
            },
            'chat.space' => function (array $k): void {
                $mid = $this->draftCursorAt($this->chat([], 'abcd'), 2);
                [$next] = $mid->update($k[0]);
                $this->assertSame('ab cd', $next->inputBuf, 'a blank lands AT the cursor');
                $this->assertSame(3, $next->inputCursorOffset());
            },
            'chat.page' => function (array $k): void {
                $history = [];
                for ($i = 0; $i < 200; $i++) {
                    $history[] = Message::user('line ' . $i);
                }
                $chat = $this->chat($history);
                $chat->view();
                [$up] = $chat->update($k[0]);
                $this->assertGreaterThan(0, $up->scrollOffset());

                // Both halves of the "PgUp / PgDn" label, because a row that
                // names two keys must answer both.
                $up->view();
                [$down] = $up->update($k[1]);
                $this->assertLessThan($up->scrollOffset(), $down->scrollOffset());
            },
            'chat.palette' => function (array $k): void {
                [$next] = $this->chat()->update($k[0]);
                $this->assertNotNull($next->palette());
            },
            // "Expand OR COLLAPSE": both halves, because a one-way arm reads
            // exactly like a toggle from the expand side alone.
            'chat.tool-output' => function (array $k): void {
                $call = Message::assistant('done')->withToolResults([ToolResult::ok('Bash', 'output', 'call-1')]);
                [$expanded] = $this->chat([Message::user('run it'), $call])->update($k[0]);
                $this->assertArrayHasKey('call-1', $expanded->expanded());

                [$collapsed] = $expanded->update($k[0]);
                $this->assertArrayNotHasKey('call-1', $collapsed->expanded(), 'the same key must collapse it again');
            },
            'chat.session-picker' => function (array $k): void {
                [$next] = $this->chatWithSessions(2)->update($k[0]);
                $this->assertNotNull($next->sessionPicker());
            },
            // "(runs /agents)" is the promise, so the dispatched COMMAND is
            // what gets asserted: "the history grew" was satisfied by any
            // appended message at all, including one from an unrelated arm.
            'chat.agents' => function (array $k): void {
                $chat = $this->chat();
                [$next] = $chat->update($k[0]);
                $appended = array_slice($next->history, count($chat->history));
                $this->assertNotSame([], $appended, 'the chord must dispatch something');
                $this->assertSame('/agents', $appended[0]->content, 'and it must be the /agents command');
            },
            // Three sessions, not two: with two, "next" and "previous" land on
            // the same row, so the pair of rows below could be swapped and both
            // observations would still pass. The expected id is computed from
            // the STORE's own listing order, which is the order the tab strip
            // shows and the order cycleSessionTab() walks.
            'chat.session-cycle' => function (array $k): void {
                $chat = $this->chatWithSessions(3);
                [$next] = $chat->update($k[0]);
                $this->assertSame($this->neighbourSessionId($chat, 1), $next->currentSessionId());
            },
            'chat.session-cycle-prev' => function (array $k): void {
                $chat = $this->chatWithSessions(3);
                [$prev] = $chat->update($k[0]);
                $this->assertSame($this->neighbourSessionId($chat, -1), $prev->currentSessionId());
            },
            'chat.keys' => function (array $k): void {
                [$next] = $this->chat()->update($k[0]);
                $this->assertSame(0, $next->keyHelp());
                // The FIRST press does not type the character — the second one
                // does, which is what makes a "?"-initial message composable
                // (Chat::handleKeyHelpKey()). If this row ever starts typing as
                // well, the shortcut has stopped being a shortcut.
                $this->assertSame('', $next->inputBuf);
            },
            'chat.cancel' => function (array $k): void {
                [$busy] = $this->chat([], 'hello')->update(new KeyMsg(KeyType::Enter));
                $this->assertTrue($busy->inFlight);
                // Both presses come off the label: "Esc Esc" is a sequence, and
                // shortening it to one key must not keep passing here.
                $this->assertCount(2, $k, 'the label must still name two presses');
                [$armed] = $busy->update($k[0]);
                $this->assertTrue($armed->inFlight, 'one press only arms the cancel');
                [$cancelled] = $armed->update($k[1]);
                $this->assertFalse($cancelled->inFlight);
            },
            'chat.quit' => function (array $k): void {
                [, $cmd] = $this->chat()->update($k[0]);
                $this->assertNotNull($cmd, 'Ctrl+C must return a quit Cmd');
            },

            // ── Panes & windows (the App shell's KeyboardHandler) ─────────
            'shell.pane-next' => function (array $k): void {
                [$app] = $this->claim($k[0], $this->app());
                $this->assertSame(Pane::Files, $app->pane);
            },
            // The FIRST menu, not merely "some menu": `> 0` passed with F10
            // wired to any index at all, and "open the menu bar" means the
            // strip opens where the user can see it start.
            'shell.menu' => function (array $k): void {
                $this->claim($k[0], $this->app());
                $this->assertSame(1, MenuBar::getActiveMenu(), 'F10 must open the first menu');
            },
            'shell.pane-chat' => function (array $k): void {
                [$app] = $this->claim($k[0], $this->app()->withPane(Pane::Files));
                $this->assertSame(Pane::Chat, $app->pane);
            },
            'shell.new-session' => function (array $k): void {
                [, $cmd] = $this->claim($k[0], $this->app());
                $this->assertInstanceOf(NewSessionCmd::class, $cmd);
            },
            'shell.palette' => function (array $k): void {
                [, $cmd] = $this->claim($k[0], $this->app());
                $this->assertInstanceOf(CommandPaletteCmd::class, $cmd);
            },
            'shell.skills' => function (array $k): void {
                [, $cmd] = $this->claim($k[0], $this->app());
                $this->assertInstanceOf(SourceSkillCmd::class, $cmd);
            },
            'shell.settings' => function (array $k): void {
                [$app] = $this->claim($k[0], $this->app());
                $this->assertSame(Pane::Settings, $app->pane);
            },

            // ── Command palette ──────────────────────────────────────────
            // Both keys, with DIFFERENT expected answers — the palette list
            // wraps too, so pressing only $k[1] and asserting "the index
            // changed" (the old form) stayed green with the two arms swapped.
            'palette.move' => function (array $k): void {
                $open = $this->chatWithPalette();
                $rows = count($open->paletteMatches());
                $this->assertGreaterThan(
                    2,
                    $rows,
                    'fixture: a list of two rows or fewer cannot tell up from down on a wrapping list',
                );
                $this->assertSame(0, $open->palette()?->selectedIndex, 'fixture: starts on the first row');

                [$up] = $open->update($k[0]);
                [$down] = $open->update($k[1]);
                $this->assertSame($rows - 1, $up->palette()?->selectedIndex, 'the first key must move UP');
                $this->assertSame(1, $down->palette()?->selectedIndex, 'the second key must move DOWN');
            },
            // "Run the HIGHLIGHTED command", which is the half the old form
            // could not see: it narrowed the list to the single Exit row and
            // asserted only that a Cmd came back, so an arm that ignored
            // selectedIndex and always ran match 0 passed.
            'palette.run' => function (array $k): void {
                [$chat, $at] = $this->paletteHighlightedOnSwitchModel();

                $this->assertGreaterThan(0, $at, 'fixture: the row must not be the first match');
                [$ran] = $chat->update($k[0]);
                $this->assertSame(
                    'providers',
                    $ran->palette()?->mode,
                    'Enter must run the row the highlight is on, not the first match',
                );
            },
            // "any text" — the printable-Char arm AND the separate
            // KeyType::Space arm beside it, which no other row reaches. Space
            // arriving as its own key type rather than as Char ' ' is how
            // candy-core reports it, so an unbound Space arm would leave the
            // filter unable to hold a two-word query.
            'palette.filter' => function (): void {
                [$typed] = $this->chatWithPalette()->update(new KeyMsg(KeyType::Char, 'x'));
                $this->assertSame('x', $typed->palette()?->query);

                [$spaced] = $typed->update(new KeyMsg(KeyType::Space));
                $this->assertSame('x ', $spaced->palette()?->query, 'Space must reach the filter too');

                [$word] = $spaced->update(new KeyMsg(KeyType::Char, 'y'));
                $this->assertSame('x y', $word->palette()?->query);
            },
            'palette.erase' => function (array $k): void {
                [$typed] = $this->chatWithPalette()->update(new KeyMsg(KeyType::Char, 'x'));
                [$erased] = $typed->update($k[0]);
                $this->assertSame('', $erased->palette()?->query);
            },
            'palette.close' => function (array $k): void {
                [$closed] = $this->chatWithPalette()->update($k[0]);
                $this->assertNull($closed->palette());
            },

            // ── Session picker ───────────────────────────────────────────
            // Both keys, with DIFFERENT expected answers. "the highlight moved"
            // is not enough on a wrapping list of two: from row 0, up-wrapping
            // and down both land on row 1, so the label could say `↓ / ↑` (or
            // the description `(or j / k)`) and still pass. Three rows separate
            // the two directions: up wraps to the last, down goes to the
            // second.
            'picker.move' => function (array $k): void {
                $open = $this->chatWithPicker(3);
                $this->assertSame(0, $open->sessionPicker()?->selectedIndex(), 'fixture: starts at the top');

                [$up] = $open->update($k[0]);
                [$down] = $open->update($k[1]);
                $this->assertSame(2, $up->sessionPicker()?->selectedIndex(), 'the first key must move UP');
                $this->assertSame(1, $down->sessionPicker()?->selectedIndex(), 'the second key must move DOWN');
            },
            'picker.resume' => function (array $k): void {
                $open = $this->chatWithPicker();
                // Whichever row is highlighted — not "the other session":
                // the picker orders rows by its own recency rule, so naming
                // an id here would assert that ordering rather than Enter.
                $highlighted = $open->sessionPicker()?->selectedSession()['sessionId'] ?? null;
                $this->assertNotNull($highlighted, 'fixture: a row must be highlighted');
                $this->assertNotSame(
                    $open->currentSessionId(),
                    $highlighted,
                    'fixture: the highlighted row must not already be the current session, '
                    . 'or "Enter resumed it" would be indistinguishable from "Enter did nothing"',
                );

                [$resumed] = $open->update($k[0]);
                $this->assertNull($resumed->sessionPicker(), 'resuming closes the overlay');
                $this->assertSame($highlighted, $resumed->currentSessionId());
            },
            // "STAY on the highlighted session" is the promise, so the
            // highlight not moving is the assertion. Three rows, so that from
            // row 0 neither direction could land back on 0: rebinding Space to
            // 'down' passed the old "answered, and the picker is still up"
            // form, which is every mutation this row can suffer except being
            // unbound.
            'picker.preview' => function (array $k): void {
                $open = $this->chatWithPicker(3);
                $this->assertSame(0, $open->sessionPicker()?->selectedIndex(), 'fixture: starts at the top');

                [$previewed] = $open->update($k[0]);
                $this->assertNotSame($open, $previewed, 'Space must be answered, not ignored');
                $this->assertNotNull($previewed->sessionPicker(), 'and must leave the picker up');
                $this->assertSame(
                    0,
                    $previewed->sessionPicker()?->selectedIndex(),
                    'and must leave the highlight where it was — that is the whole promise',
                );
            },
            // Both halves of "to the current git branch, OR ALL", and neither
            // of them is "the highlight moved": rebinding Ctrl+B to 'down'
            // satisfied the old form too.
            //
            // Driven from inside a throwaway repo on a KNOWN branch, because
            // SessionPicker::getCurrentGitBranch() shells out to git against
            // the process CWD: run from this checkout the answer is whatever
            // branch happens to be out, and in a detached-HEAD PR build it is
            // null — which would make BOTH directions assert null against null
            // and observe nothing at all.
            'picker.branch' => function (array $k): void {
                $this->inGitRepoOnBranch('drift-branch', function () use ($k): void {
                    $open = $this->chatWithPicker(3);
                    $this->assertNull(
                        $open->sessionPicker()?->branchFilter(),
                        'fixture: the picker opens showing every session',
                    );

                    [$filtered] = $open->update($k[0]);
                    $this->assertNotSame($open, $filtered, 'Ctrl+B must be answered, not ignored');
                    $this->assertNotNull($filtered->sessionPicker());
                    $this->assertSame(
                        0,
                        $filtered->sessionPicker()?->selectedIndex(),
                        'Ctrl+B filters, it does not move the highlight',
                    );
                    $this->assertSame(
                        'drift-branch',
                        $filtered->sessionPicker()?->branchFilter(),
                        'the first press filters to the current branch',
                    );

                    [$unfiltered] = $filtered->update($k[0]);
                    $this->assertNull(
                        $unfiltered->sessionPicker()?->branchFilter(),
                        'and the second press goes back to all sessions — the "or all" half',
                    );
                });
            },
            'picker.close' => function (array $k): void {
                [$closed] = $this->chatWithPicker()->update($k[0]);
                $this->assertNull($closed->sessionPicker());
            },

            // ── Permission prompt ────────────────────────────────────────
            'permission.once' => fn(array $k) => $this->assertPermissionAnsweredBy($k[0]),
            // "Ask to allow", not "allow": the row's description changed
            // because the key did, and this is the observation that holds the
            // two together. `a` alone must leave the prompt up and the grant
            // map empty; the grant is what the CONFIRM writes.
            'permission.always' => function (array $k): void {
                [$confirming] = $this->blockedOnPermission()->update($k[0]);

                $this->assertNotNull(
                    $confirming->pendingPermission(),
                    "'a' must not answer the prompt on its own any more — it asks",
                );
                $this->assertSame(PermissionPromptStage::ConfirmingAlways, $confirming->permissionStage());
                $this->assertSame(
                    [],
                    $confirming->permissionGrants(),
                    'and nothing is granted until the confirm is answered',
                );

                [$granted] = $confirming->update(new KeyMsg(KeyType::Char, 'y'));
                $this->assertNull($granted->pendingPermission(), 'the confirm answers the prompt');
                $this->assertSame(
                    ['Bash' => true],
                    $granted->permissionGrants(),
                    'and THAT is what the row promises: the whole session, once confirmed',
                );

                // The prose half. Everything above proves `a` no longer grants
                // on its own; nothing else in this suite reads a description's
                // WORDS back, so a row left saying "Allow this tool for the
                // whole session" would keep making the promise the key stopped
                // keeping — measured, reverting that wording alone left this
                // file, KeyBindingRegistryTest, KeyHelpTest and RendererTest
                // all green.
                $this->assertStringStartsWith(
                    'Ask',
                    KeyBindingRegistry::byId('permission.always')?->description ?? '',
                    'the row must say it ASKS, because that is what the key does',
                );
            },
            'permission.deny' => fn(array $k) => $this->assertPermissionAnsweredBy($k[0]),
            // The recovery, and the reason Enter is a declared row rather than
            // an undocumented key: a disarmed prompt ignores every answer
            // letter, so without this one binding it could not be answered from
            // the keyboard at all.
            'permission.rearm' => function (array $k): void {
                [$disarmed] = $this->blockedOnPermission()->update(new KeyMsg(KeyType::Char, '/'));
                $this->assertSame(
                    PermissionPromptStage::Disarmed,
                    $disarmed->permissionStage(),
                    'fixture: one non-answer keystroke disarms the prompt',
                );

                [$ignored] = $disarmed->update(new KeyMsg(KeyType::Char, 'y'));
                $this->assertNotNull(
                    $ignored->pendingPermission(),
                    'fixture: and a disarmed prompt ignores "y", or there is nothing to recover from',
                );

                [$rearmed] = $disarmed->update($k[0]);
                $this->assertSame(PermissionPromptStage::Armed, $rearmed->permissionStage());
                $this->assertNotNull(
                    $rearmed->pendingPermission(),
                    'and the re-arm answers nothing itself — it only makes the answers live again',
                );

                [$answered] = $rearmed->update(new KeyMsg(KeyType::Char, 'y'));
                $this->assertNull($answered->pendingPermission(), 'which the same "y" now proves');
            },

            // ── Agent view ───────────────────────────────────────────────
            'agents.move' => function (array $k): void {
                [$down] = $this->claim($k[1], $this->agentApp(1));
                $this->assertSame(0, $down->selectedAgentIndex, 'the second key must move DOWN');

                // The direction half: from "nothing selected" there is nowhere
                // above row 0 to go, so up must leave the selection alone. The
                // asymmetry is what makes swapping the two keys fail.
                [$up] = $this->claim($k[0], $this->agentApp(1));
                $this->assertSame(-1, $up->selectedAgentIndex, 'the first key must move UP');
            },
            'agents.peek' => function (array $k): void {
                [$app] = $this->claim($k[0], $this->agentApp(1)->withSelectedAgentIndex(0));
                $this->assertSame(AgentViewMode::Peek, $app->agentViewMode);
            },
            'agents.slot' => function (): void {
                [$app] = $this->claim(
                    new KeyMsg(KeyType::Char, '2', alt: true),
                    $this->agentApp(2),
                );
                $this->assertSame(1, $app->selectedAgentIndex);
            },
            // "Drop the selection, THEN leave the view" is two presses, and the
            // second half was unobserved: an Esc arm that dropped the selection
            // and then went on ignoring the key passed.
            'agents.back' => function (array $k): void {
                [$dropped] = $this->claim($k[0], $this->agentApp(1)->withSelectedAgentIndex(0));
                $this->assertSame(-1, $dropped->selectedAgentIndex);
                $this->assertSame(Pane::Agents, $dropped->pane, 'the first press stays in the view');

                [$left] = $this->claim($k[0], $dropped);
                $this->assertSame(Pane::Chat, $left->pane, 'the second press leaves it');
            },
            'agents.quit' => function (array $k): void {
                [$app, $cmd] = $this->claim($k[0], $this->agentApp(1));
                $this->assertSame(Pane::Chat, $app->pane);
                $this->assertInstanceOf(QuitAgentViewCmd::class, $cmd);
            },

            // ── Skill picker ─────────────────────────────────────────────
            // Three options, for the reason picker.move gives: the picker wraps,
            // so two would make up and down indistinguishable.
            'skills.move' => function (array $k): void {
                [$up] = $this->claim($k[0], $this->skillApp());
                [$down] = $this->claim($k[1], $this->skillApp());
                $this->assertSame(2, $up->skillPickerIndex, 'the first key must move UP');
                $this->assertSame(1, $down->skillPickerIndex, 'the second key must move DOWN');
            },
            'skills.select' => function (array $k): void {
                [, $msg] = $this->claim($k[0], $this->skillApp());
                $this->assertInstanceOf(SelectSkillMsg::class, $msg);
            },
            'skills.close' => function (array $k): void {
                [$app] = $this->claim($k[0], $this->skillApp());
                $this->assertSame([], $app->skillPickerOptions);
                $this->assertSame(Pane::Chat, $app->pane);
            },

            // ── Menu bar ─────────────────────────────────────────────────
            // Both directions, from a menu in the MIDDLE of the strip. The
            // strip wraps, so from menu 1 every move lands on a different,
            // valid menu — which is why the old form ($k[1] only, then "not 1
            // and greater than 0") stayed green with MenuBar::handleKey()'s
            // entire `'left', 'h'` arm DELETED, and green again with the left
            // and right arms swapped. That was the one row where this file's
            // headline promise, that the screen cannot describe a keyboard the
            // app does not have, was false.
            'menu.switch' => function (array $k): void {
                $menus = $this->menuCount();
                $this->assertGreaterThan(
                    2,
                    $menus,
                    'fixture: a strip of two menus or fewer cannot tell left from right on a wrapping list',
                );
                // Away from both ends, so neither direction can be answered by
                // a wrap that happens to look right.
                $from = intdiv($menus, 2) + 1;

                $this->openMenuAt($from);
                $this->claim($k[0], $this->app());
                $this->assertSame($from - 1, MenuBar::getActiveMenu(), 'the first key must move LEFT');

                $this->openMenuAt($from);
                $this->claim($k[1], $this->app());
                $this->assertSame($from + 1, MenuBar::getActiveMenu(), 'the second key must move RIGHT');
            },
            // Menu 1's dropdown wraps too, and has more than two rows, so the
            // two directions land in different places from row 0: up on the
            // last row, down on the second.
            'menu.move' => function (array $k): void {
                $rows = $this->openMenuRowCount(1);
                $this->assertGreaterThan(
                    2,
                    $rows,
                    'fixture: a menu of two rows or fewer cannot tell up from down on a wrapping list',
                );

                $this->claim($k[0], $this->app());
                $this->assertSame($rows - 1, $this->activeMenuItem(), 'the first key must move UP');

                $this->openMenuRowCount(1);
                $this->claim($k[1], $this->app());
                $this->assertSame(1, $this->activeMenuItem(), 'the second key must move DOWN');
            },
            'menu.run' => function (array $k): void {
                MenuBar::openMenu(1);
                [, $msg] = $this->claim($k[0], $this->app());
                $this->assertInstanceOf(MenuSelectedMsg::class, $msg);
            },
            'menu.close' => function (array $k): void {
                MenuBar::openMenu(1);
                $this->claim($k[0], $this->app());
                $this->assertSame(0, MenuBar::getActiveMenu());
            },

            // ── Mouse ────────────────────────────────────────────────────
            'mouse.wheel' => function (): void {
                $history = [];
                for ($i = 0; $i < 200; $i++) {
                    $history[] = Message::user('line ' . $i);
                }
                $chat = $this->chat($history);
                $chat->view();
                [$next] = $chat->update(
                    new MouseWheelMsg(1, 1, MouseButton::WheelUp, MouseAction::Press),
                );
                $this->assertGreaterThan(0, $next->scrollOffset());
            },
            'mouse.tab' => function (): void {
                $chat = $this->chatWithSessions(2);
                $ids = array_column($chat->sessionStore()?->listSessions() ?? [], 'id');
                $other = $ids[0] === $chat->currentSessionId() ? $ids[1] : $ids[0];
                $after = $this->clickZone($chat, Renderer::SESSION_TAB_ZONE_PREFIX . $other);
                $this->assertSame($other, $after->currentSessionId());
            },
            'mouse.pane' => function (): void {
                $chat = $this->chat();
                $after = $this->clickZone($chat, Renderer::PANE_ZONE_PREFIX . Pane::Menu->value);
                $this->assertNotNull($after->palette(), 'the status bar\'s menu region opens the palette');
            },
            // Both halves of "Expand or collapse", as for chat.tool-output.
            'mouse.tool-call' => function (): void {
                $call = Message::assistant('done')->withToolResults([ToolResult::ok('Bash', 'output', 'call-1')]);
                $chat = $this->chat([Message::user('run it'), $call]);
                $expanded = $this->clickZone($chat, Renderer::TOOL_CALL_ZONE_PREFIX . 'call-1');
                $this->assertArrayHasKey('call-1', $expanded->expanded());

                $collapsed = $this->clickZone($expanded, Renderer::TOOL_CALL_ZONE_PREFIX . 'call-1');
                $this->assertArrayNotHasKey('call-1', $collapsed->expanded(), 'a second click must collapse it');
            },
            // "RUN that palette row", which the old form could not see: it
            // asserted only that the click produced a different Chat, and a
            // click that merely moved the highlight does that too. The row
            // clicked is the one with an in-model effect, at whatever index the
            // current match order puts it — so this also pins that the zone
            // suffix and the match list are indexed the same way.
            'mouse.palette-row' => function (): void {
                $chat = $this->chatWithPalette();
                $at = array_search(self::PALETTE_ROW_WITH_AN_EFFECT, $chat->paletteMatches(), true);
                $this->assertIsInt($at, 'fixture: the root list must offer ' . self::PALETTE_ROW_WITH_AN_EFFECT);

                $after = $this->clickZone($chat, Renderer::PALETTE_ITEM_ZONE_PREFIX . $at);
                $this->assertSame(
                    'providers',
                    $after->palette()?->mode,
                    'clicking a row must RUN it, not merely highlight it',
                );
            },
        ];
    }

    // ── fixtures ─────────────────────────────────────────────────────────

    /** @param list<Message> $history */
    private function chat(array $history = [], string $input = ''): Chat
    {
        return (new Chat(history: $history, inputBuf: $input, backend: new EchoBackend()))
            ->withSize(100, 30);
    }

    /**
     * A Chat over a real, throwaway {@see SessionStore} holding $count rows,
     * pointed at the first of them.
     */
    /**
     * Park the draft's cursor at `$offset` by pressing Left, which is a real
     * keystroke through the real arm rather than a reach into the widget.
     *
     * Every seeded draft starts with the cursor at its END (see
     * `Chat::freshInput()`), so counting back is the only direction needed.
     */
    private function draftCursorAt(Chat $chat, int $offset): Chat
    {
        $from = $chat->inputCursorOffset();
        $this->assertGreaterThanOrEqual($offset, $from, 'fixture: the seed cursor starts at the end');

        for ($i = $from; $i > $offset; $i--) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Left));
        }
        $this->assertSame($offset, $chat->inputCursorOffset(), 'fixture: the cursor did not reach that column');

        return $chat;
    }

    private function chatWithSessions(int $count): Chat
    {
        $store = new SessionStore($this->sandbox . '/sessions-' . (++$this->storeSeq) . '.db');
        $ids = [];
        for ($i = 1; $i <= $count; $i++) {
            $id = 'session-' . $i;
            $store->createSession($id, 'openai', 'gpt-4', null, 'Session ' . $i);
            $ids[] = $id;
        }

        return (new Chat(
            history: [],
            backend: new EchoBackend(),
            sessionStore: $store,
            currentSessionId: $ids[0],
        ))->withSize(100, 30);
    }

    private function chatWithPicker(int $sessions = 2): Chat
    {
        [$open] = $this->chatWithSessions($sessions)->update($this->pressLabelled('chat.session-picker'));
        $this->assertNotNull($open->sessionPicker(), 'fixture: Ctrl+R must open the picker');

        return $open;
    }

    /**
     * The id $step places along the store's own session listing from the one
     * $chat is currently on, wrapping — the answer a session-cycling key must
     * arrive at, derived from the listing rather than from the key's own code.
     */
    private function neighbourSessionId(Chat $chat, int $step): string
    {
        $ids = array_column($chat->sessionStore()?->listSessions() ?? [], 'id');
        $this->assertGreaterThan(2, count($ids), 'fixture: two sessions cannot tell next from previous');

        $at = array_search($chat->currentSessionId(), $ids, true);
        $this->assertIsInt($at, 'fixture: the current session must be in the listing');

        return $ids[($at + $step + count($ids)) % count($ids)];
    }

    /**
     * Open menu $menu at its first row.
     *
     * Closed first because {@see MenuBar::openMenu()} TOGGLES, and it is the
     * call that resets the row cursor to 0 — an observation pressing two keys
     * has to start each from the same place.
     */
    private function openMenuAt(int $menu): void
    {
        MenuBar::closeMenu();
        MenuBar::openMenu($menu);
        $this->assertSame($menu, MenuBar::getActiveMenu(), 'fixture: the menu must be open');
        $this->assertSame(0, $this->activeMenuItem(), 'fixture: the row cursor must start at the top');
    }

    /** Open menu $menu at its first row and report how many rows it has. */
    private function openMenuRowCount(int $menu): int
    {
        $this->openMenuAt($menu);

        /** @var list<string> $rows */
        $rows = (new \ReflectionMethod(MenuBar::class, 'itemsOf'))->invoke(null, $menu);

        return count($rows);
    }

    /**
     * How many menus the strip has, read from {@see MenuBar}'s own derivation
     * rather than counted off {@see \SugarCraft\Crush\Commands\CommandRegistry}
     * here — the strip is one menu per command CATEGORY, and duplicating that
     * grouping rule in a test is how the two drift apart.
     */
    private function menuCount(): int
    {
        /** @var array<string, list<string>> $menus */
        $menus = (new \ReflectionMethod(MenuBar::class, 'menus'))->invoke(null);

        return count($menus);
    }

    /**
     * Run $body with the process CWD inside a throwaway git repo checked out on
     * $branch, then put the CWD back whatever happens.
     *
     * For the rows whose behaviour reads the CURRENT branch:
     * {@see \SugarCraft\Crush\Tui\SessionPicker} shells out to `git` against
     * the process CWD, so run from this checkout the answer is whichever branch
     * a developer has out and in a detached-HEAD build there is none at all.
     */
    private function inGitRepoOnBranch(string $branch, \Closure $body): void
    {
        $repo = $this->sandbox . '/repo-' . $branch;
        if (!is_dir($repo) && !mkdir($repo, 0o777, true) && !is_dir($repo)) {
            $this->fail("could not create the fixture repo at {$repo}");
        }
        exec(
            'git init -q -b ' . escapeshellarg($branch) . ' ' . escapeshellarg($repo) . ' 2>&1',
            $output,
            $status,
        );
        $this->assertSame(0, $status, 'fixture: git init failed — ' . implode("\n", $output));

        $was = getcwd();
        $this->assertIsString($was, 'fixture: the CWD must be readable so it can be restored');
        $this->assertTrue(chdir($repo), 'fixture: could not enter the fixture repo');

        try {
            $body();
        } finally {
            chdir($was);
        }
    }

    /**
     * The root palette row whose action has an effect visible in the MODEL —
     * `Switch model` swaps the palette into its providers list rather than
     * returning a `Cmd` only a running `Program` would execute.
     *
     * That is what lets the two "run the row" rows (`palette.run`,
     * `mouse.palette-row`) assert the row RAN rather than that something
     * changed. Its index is looked up in the live match list every time, never
     * hardcoded: the order is a scoring decision, not a declaration order.
     */
    private const PALETTE_ROW_WITH_AN_EFFECT = 'Switch model';

    /**
     * A Chat with the palette open, filtered so the list has several rows, and
     * the highlight moved onto {@see PALETTE_ROW_WITH_AN_EFFECT}.
     *
     * Filtered by TYPING, and moved by pressing the label of `palette.move`, so
     * the fixture cannot go on working after either of those has broken.
     *
     * @return array{0: Chat, 1: int}
     */
    private function paletteHighlightedOnSwitchModel(): array
    {
        $chat = $this->chatWithPalette();
        foreach (['s', 'w', 'i', 't', 'c', 'h'] as $char) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Char, $char));
        }

        $matches = $chat->paletteMatches();
        $this->assertGreaterThan(1, count($matches), 'fixture: the filter must leave more than one row');
        $at = array_search(self::PALETTE_ROW_WITH_AN_EFFECT, $matches, true);
        $this->assertIsInt($at, 'fixture: the filtered list must contain ' . self::PALETTE_ROW_WITH_AN_EFFECT);

        $down = $this->pressLabelled('palette.move', 1);
        for ($i = 0; $i < $at; $i++) {
            [$chat] = $chat->update($down);
        }
        $this->assertSame($at, $chat->palette()?->selectedIndex, 'fixture: the highlight is on that row');

        return [$chat, $at];
    }

    private function chatWithPalette(): Chat
    {
        [$open] = $this->chat()->update($this->pressLabelled('chat.palette'));
        $this->assertNotNull($open->palette(), 'fixture: Ctrl+P must open the palette');

        return $open;
    }

    /**
     * A keystroke another row's label names — for the fixtures that have to get
     * INTO an overlay, or move around inside one, before the row under test can
     * be pressed. Read from the registry rather than hardcoded so the setup
     * cannot go on working after the documented way in has changed.
     *
     * $index picks one key out of a multi-key label (`↑ / ↓`); omitted, the
     * label must name exactly one chord, which is the stricter default and the
     * right one for "the way in".
     */
    private function pressLabelled(string $id, ?int $index = null): KeyMsg
    {
        $keys = self::chord(KeyBindingRegistry::byId($id)?->keys ?? '');

        if ($index === null) {
            $this->assertCount(1, $keys, "'{$id}' must name exactly one chord to be used as setup");

            return $keys[0];
        }

        $this->assertArrayHasKey($index, $keys, "'{$id}' must still name a key at position {$index}");

        return $keys[$index];
    }

    private function app(): App
    {
        return App::new($this->provider, 'test-model');
    }

    private function agentApp(int $agents): App
    {
        $manager = new AgentManager($this->provider, new SkillRegistry());
        for ($i = 1; $i <= $agents; $i++) {
            $manager->register(new Agent(
                name: 'agent-' . $i,
                description: 'A worker',
                prompt: 'You are a worker.',
                model: 'test-model',
                provider: 'test',
                tools: [],
                skillNames: [],
                hooks: [],
                isActive: true,
            ));
        }

        return $this->app()
            ->withPane(Pane::Agents)
            ->withChat(new Chat(agentManager: $manager));
    }

    private function skillApp(): App
    {
        // Three options, not two: the picker wraps, so from row 0 a two-row
        // list answers up and down with the same index and `skills.move` could
        // not tell the two apart.
        return $this->app()
            ->withPane(Pane::Skills)
            ->withSkillPickerOptions([$this->skill('alpha'), $this->skill('beta'), $this->skill('gamma')])
            ->withSkillPickerIndex(0);
    }

    private function skill(string $name): Skill
    {
        return new Skill(
            name: $name,
            description: 'A skill',
            userInvocable: true,
            disableModelInvocation: false,
            allowedTools: null,
            disallowedTools: null,
            model: null,
            effort: 'medium',
            context: 'inline',
            paths: [],
            content: 'Do the thing.',
            sourcePath: $this->sandbox . '/' . $name . '.md',
        );
    }

    // ── drivers ──────────────────────────────────────────────────────────

    /**
     * Offer $msg to the pane shell and require that it CLAIMS it.
     *
     * The claim half matters as much as the effect: a chord dropped from
     * {@see KeyBindingRegistry}'s shell rows stops being claimed, and this is
     * where that shows up.
     *
     * @return array{0: App, 1: ?object}
     */
    private function claim(KeyMsg $msg, App $app): array
    {
        $result = (new KeyboardHandler())->handleKeyMsg($msg, $app);
        $this->assertNotNull(
            $result,
            "the shell no longer claims '{$msg->string()}' — check KeyBindingRegistry's rune lists",
        );

        return $result;
    }

    private function assertPermissionAnsweredBy(KeyMsg $key): void
    {
        [$answered] = $this->blockedOnPermission()->update($key);
        $this->assertNull($answered->pendingPermission(), "'{$key->string()}' must answer the prompt");
    }

    /** A live, ARMED permission prompt on a `Bash` call. */
    private function blockedOnPermission(): Chat
    {
        [$blocked] = $this->chat([Message::user('clean up')])->update(new PermissionRequestMsg(
            Message::assistant(''),
            new ToolCall('Bash', ['description' => 'Delete build/'], 'call_1'),
            'Run rm -rf build/?',
        ));
        $this->assertNotNull($blocked->pendingPermission(), 'fixture: the prompt must be up');
        $this->assertSame(
            PermissionPromptStage::Armed,
            $blocked->permissionStage(),
            'fixture: a newly-raised prompt is armed, or none of these rows can be pressed at all',
        );

        return $blocked;
    }

    /**
     * Press and release inside the zone $zoneId, on a frame rendered from
     * $chat, and hand back the resulting Chat.
     */
    private function clickZone(Chat $chat, string $zoneId): Chat
    {
        Renderer::render($chat);
        $zone = Renderer::scanner()->get($zoneId);
        $this->assertInstanceOf(Zone::class, $zone, "no '{$zoneId}' click zone in the frame");

        [$pressed] = $chat->update(
            new MouseClickMsg($zone->startCol, $zone->startRow, MouseButton::Left, MouseAction::Press),
        );
        [$released] = $pressed->update(
            new MouseReleaseMsg($zone->startCol, $zone->startRow, MouseButton::Left, MouseAction::Release),
        );

        return $released;
    }

    private static function ctrl(string $rune): KeyMsg
    {
        return new KeyMsg(KeyType::Char, $rune, ctrl: true);
    }

    /**
     * The process-wide state the handlers keep outside any model, all THREE
     * pieces of it: {@see MenuBar}'s open menu (and, via `closeMenu()`, its row
     * cursor), the renderer's click-zone scan, and `Chat::$clickTracker` — the
     * press/release pairing a {@see clickZone()} observation leaves half-armed
     * if it is not cleared, which would make the NEXT click in this file
     * resolve against the previous row.
     */
    private function resetSharedState(): void
    {
        MenuBar::closeMenu();
        Renderer::scanner()->clear();
        $this->resetClickTracker();
    }

    /**
     * Rows whose label is prose or a range rather than a literal chord, and
     * which therefore drive their own input. Held as an explicit list so that
     * adding one is a decision: a new row that merely LOOKS unparseable (a
     * typo, a chord spelled in a way the reference's readers would not
     * recognise) lands in {@see testEveryLabelIsALiteralChordOrADeclaredException()}
     * instead of quietly opting out of label coverage.
     */
    /**
     * The named keys a label may carry a `Ctrl+`/`Alt+` prefix on, mapped to
     * the KeyType a terminal reports for them.
     *
     * Shared by both modifier branches of {@see token()} so the two cannot
     * drift apart, and it exists because the arrow glyphs are ONE character
     * long: without it, `Ctrl+←` would be read back as a printable rune press
     * (see that method's comment).
     */
    private const MODIFIABLE_NAMED = [
        'Enter' => KeyType::Enter,
        'Backspace' => KeyType::Backspace,
        'Delete' => KeyType::Delete,
        'Space' => KeyType::Space,
        'Home' => KeyType::Home,
        'End' => KeyType::End,
        '↑' => KeyType::Up,
        '↓' => KeyType::Down,
        '←' => KeyType::Left,
        '→' => KeyType::Right,
    ];

    /**
     * The one entry of {@see MODIFIABLE_NAMED} that arrives with a rune as well
     * as a type. Measured through candy-core's decoder, not assumed:
     * `InputReader::parse("\x1b[32;5u")` yields `KeyMsg(Space, ctrl)` with rune
     * `" "`, while `parse("\x1b[127;5u")` yields `KeyMsg(Backspace, ctrl)` with
     * rune `""`. Anything absent here is pressed with an empty rune.
     */
    private const MODIFIABLE_RUNE = ['Space' => ' '];

    private const HAND_DRIVEN = [
        // "any text" — the palette filter answers every printable character,
        // so there is no single chord to press.
        'palette.filter',
        // "Alt+1…9" — a range, so there is no single chord to read back. The
        // observation presses Alt+2: the second slot, chosen because it is the
        // lowest one that a "jump to the first row" mis-wiring would answer
        // wrongly, not because it is the middle of the range (that would be
        // Alt+5, and nothing here needs it to be).
        'agents.slot',
        // Mouse gestures are not keystrokes at all.
        'mouse.wheel',
        'mouse.tab',
        'mouse.pane',
        'mouse.tool-call',
        'mouse.palette-row',
    ];

    /**
     * Read a row's label back as the keystrokes it names, or `[]` when the
     * label is not a literal chord.
     *
     * Labels list either alternatives (`↑ / ↓`) or a sequence (`Esc Esc`);
     * both flatten to the same ordered list because the observation knows
     * which of its own keys it wants — `$k[1]` is "the down arrow" in the
     * first form and would be "the second Esc" in the second. Parenthesised
     * asides ("(or j / k)") live in the DESCRIPTION, never the label, so
     * nothing here has to strip them.
     *
     * The `/` is DISCARDED, so the two forms are indistinguishable after
     * parsing — see
     * {@see testTheOnlySequenceLabelIsStillWrittenAsASequence()}, which is what
     * keeps the one row that depends on the difference honest.
     *
     * @return list<KeyMsg>
     */
    private static function chord(string $label): array
    {
        $keys = [];
        foreach (preg_split('/\s+(?:\/\s+)?/u', trim($label)) ?: [] as $token) {
            if ($token === '') {
                continue;
            }
            $key = self::token($token);
            // One unreadable token makes the whole label unreadable: half a
            // chord is worse than none, because the observation would press a
            // key the label never named.
            if ($key === null) {
                return [];
            }
            $keys[] = $key;
        }

        return $keys;
    }

    private static function token(string $token): ?KeyMsg
    {
        if (str_starts_with($token, 'Ctrl+')) {
            $rest = substr($token, 5);

            // Ctrl+Tab and Ctrl+Shift+Tab are reported as a named key with the
            // flags set, not as a Char — the same distinction
            // KeyBinding::ctrlRune() draws when it refuses to read a rune off
            // them. Shift only picks the direction, so both reach Chat's one
            // session-cycling arm.
            //
            // The arrow GLYPHS have to be named here too, and not for
            // tidiness: they are one character long, so the `mb_strlen === 1`
            // arm below would read `Ctrl+←` back as `KeyMsg(Char, '←', ctrl)`
            // — a rune no terminal sends — and the observation would press a
            // key the app cannot answer while looking driven. Same hazard
            // testAGlyphLabelIsUnreadableRatherThanPressedAsARune() pins for
            // the unmodified form, arriving through a different door.
            return match (true) {
                $rest === 'Tab' => new KeyMsg(KeyType::Tab, '', ctrl: true),
                $rest === 'Shift+Tab' => new KeyMsg(KeyType::Tab, '', ctrl: true, shift: true),
                isset(self::MODIFIABLE_NAMED[$rest])
                    => new KeyMsg(self::MODIFIABLE_NAMED[$rest], self::MODIFIABLE_RUNE[$rest] ?? '', ctrl: true),
                mb_strlen($rest) === 1 => self::ctrl(mb_strtolower($rest)),
                default => null,
            };
        }

        if (str_starts_with($token, 'Alt+')) {
            $rest = substr($token, 4);

            if (isset(self::MODIFIABLE_NAMED[$rest])) {
                return new KeyMsg(
                    self::MODIFIABLE_NAMED[$rest],
                    self::MODIFIABLE_RUNE[$rest] ?? '',
                    alt: true,
                );
            }

            return mb_strlen($rest) === 1 ? new KeyMsg(KeyType::Char, $rest, alt: true) : null;
        }

        $named = [
            'Enter' => KeyType::Enter,
            'Esc' => KeyType::Escape,
            'Tab' => KeyType::Tab,
            'Backspace' => KeyType::Backspace,
            'Delete' => KeyType::Delete,
            'Space' => KeyType::Space,
            'Home' => KeyType::Home,
            'End' => KeyType::End,
            'F10' => KeyType::F10,
            'PgUp' => KeyType::PageUp,
            'PgDn' => KeyType::PageDown,
            '↑' => KeyType::Up,
            '↓' => KeyType::Down,
            '←' => KeyType::Left,
            '→' => KeyType::Right,
        ];

        if (isset($named[$token])) {
            return new KeyMsg($named[$token]);
        }

        // ASCII printable only, deliberately narrower than "any single glyph".
        // The loose form turned ANY unnamed single character into a printable
        // rune press, so relabelling `chat.send` from `Enter` to `⏎` would have
        // pressed the ⏎ CHARACTER — a rune no terminal sends and no arm answers
        // — while testEveryLabelIsALiteralChordOrADeclaredException()'s "not
        // []" guard still passed, because a KeyMsg had been produced. Rejecting
        // it here sends that relabel to that test instead, which is where a
        // label this suite cannot press is supposed to surface. Every glyph
        // label in use (↑ ↓ ← →) is in the named map above; the day another one
        // is added it goes there, or the row goes in HAND_DRIVEN.
        //
        // Pinned by testAGlyphLabelIsUnreadableRatherThanPressedAsARune(): with
        // this narrowing reverted to `mb_strlen($token) === 1`, the whole file
        // stayed green, so the tightening could be undone without a single red.
        return strlen($token) === 1 && $token >= ' ' && $token <= '~'
            ? new KeyMsg(KeyType::Char, $token)
            : null;
    }

    private function activeMenuItem(): int
    {
        $property = new \ReflectionProperty(MenuBar::class, 'activeItem');

        return (int) $property->getValue();
    }

    private function resetClickTracker(): void
    {
        (new \ReflectionProperty(Chat::class, 'clickTracker'))->setValue(null, null);
    }

    /**
     * `scandir()` rather than a `glob()` of `$dir` plus slash-star, which does
     * not match DOT entries:
     * {@see inGitRepoOnBranch()}'s fixture repo contains nothing BUT
     * `.git` (measured with `find` on the fixture: `repo-drift-branch/.git` and
     * nothing else), so the glob form unlinked nothing, both `rmdir()` calls
     * failed as non-empty, and every run left a 128K repo plus its sandbox root
     * in `sys_get_temp_dir()` forever. Measured before and after: with the glob
     * form, one leaked `crush_keybind_drift_*` tree per run; with this one, a run
     * from an empty `/tmp` leaves zero.
     *
     * `is_link()` before `is_dir()` because a symlink to a directory answers
     * `is_dir()` true, and recursing through one would delete OUTSIDE the
     * sandbox. Nothing here creates one today; `git init` templates are the kind
     * of thing that could.
     */
    private function removeTree(string $dir): void
    {
        if ($dir === '' || !is_dir($dir) || is_link($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) && !is_link($path) ? $this->removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
