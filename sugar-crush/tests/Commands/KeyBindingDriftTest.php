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
use SugarCraft\Crush\AssistantMsg;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Commands\KeyBindingRegistry;
use SugarCraft\Crush\Hooks\BuiltIn\PermissionGateHook;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\PermissionRequestMsg;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Permissions\PermissionPromptStage;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Session\SessionStore;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Tests\Support\PinsEnglishLocaleTrait;
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
use SugarCraft\Crush\Tui\Settings\SettingsSources;
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
    // The rows are read back from the English catalogue (audit 15b-14).
    use PinsEnglishLocaleTrait;

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
     * Zero false positives across all 113 declared rows — and THAT is the domain
     * of the zero. It says nothing about prose not yet written; it says those 92
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
     * near-misses probed, because eight `*.move` rows describe arrow movement
     * (twenty-one rows carry an arrow GLYPH in their label; both counts are asserted
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
            8,
            $move,
            'KEYISH\'s docblock says eight `*.move` rows describe arrow movement; it found: '
            . implode(', ', $move),
        );
        $this->assertCount(
            21,
            $arrowLabelled,
            'KEYISH\'s docblock says twenty-one rows carry an arrow glyph in their label; it found: '
            . implode(', ', $arrowLabelled),
        );
        // Every `*.move` row is arrow-labelled, which is what makes the first
        // count the interesting one — the extra rows include `chat.slash-menu`,
        // `chat.recall`, `chat.cursor`, `chat.word-motion`, `chat.draft-rows`,
        // `chat.agents-strip`, `menu.switch` and `settings.category`.
        $this->assertSame([], array_diff($move, $arrowLabelled));
    }

    /**
     * `chord()` flattens `A / B` (a choice) and `A B` (a sequence) into the same
     * ordered list, so the label of a row whose MEANING depends on which form it
     * is written in is pinned here rather than left to the parser.
     *
     * `chat.cancel` is that row: "Esc Esc … twice, quickly" is two presses one
     * after the other, and rewriting the label as `Esc / Esc` would read as two
     * ways to do it while its observation kept passing unchanged. The Agent
     * View's five `Ctrl+X <letter>` chords (P-D3, P-E3) are the same shape: a
     * leader, then the letter — and each observation presses the two keys
     * in that order and asserts the leader went down first.
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
            ['chat.cancel', 'agentview.cancel', 'agentview.pause', 'agentview.stop-all', 'agentview.open-session', 'agentview.background'],
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

    /** An engine turn in flight with $draft in the box (roadmap 1.C-3). */
    private function midTurn(\SugarCraft\Crush\Backend\CancellationToken $token, string $draft): Chat
    {
        return (new Chat(
            history: [Message::user('go')],
            backend: \SugarCraft\Crush\Backend\EngineBackend::new(new \SugarCraft\Crush\Tests\Support\ScriptedProvider([]), 'm'),
            inFlight: true,
            generation: 1,
            inFlightCancellation: $token,
            inputBuf: $draft,
        ))->withSize(120, 20);
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
            // Roadmap 1.C-3: the same Enter, mid-turn on an engine turn, goes
            // into the running turn through its handle — and is held on the
            // queue as the fallback for a turn that ends before reading it.
            'chat.steer' => function (array $k): void {
                $token = new \SugarCraft\Crush\Backend\CancellationToken();
                [$next] = $this->midTurn($token, 'look at the tests first')->update($k[0]);
                $this->assertSame('', $next->inputBuf);
                $this->assertSame(['look at the tests first'], array_column($token->takeSteers(), 'text'), 'Enter steers the running turn');
                $this->assertSame(['look at the tests first'], $next->queuedPrompts(), 'and holds the fallback');
                $this->assertTrue($next->inFlight, 'the turn runs on');
            },
            'chat.queue' => function (array $k): void {
                $token = new \SugarCraft\Crush\Backend\CancellationToken();
                $chat = $this->midTurn($token, 'then update the docs');
                $this->assertTrue($chat->queueOwnsTab(), 'fixture: the shell yields Tab on this predicate');
                [$next] = $chat->update($k[0]);
                $this->assertSame(['then update the docs'], $next->queuedPrompts());
                $this->assertSame([], $token->takeSteers(), 'Tab queues; it does not steer');
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
            // Driven at the Chat level like every other `chat.*` row. The
            // half that row cannot show — that the App shell YIELDS the Tab
            // instead of cycling a pane, which is where the reported bug
            // actually lived — is driven through `App::update()` in
            // `App\SlashMenuTabCompletionTest`.
            'chat.slash-complete' => function (array $k): void {
                // `/compa`, not `/comp`: `/compress` (roadmap 3.B-4) shares `comp`.
                $chat = $this->chat([], '/compa');
                $this->assertSame(
                    ['compact'],
                    array_map(static fn (object $spec): string => $spec->name, $chat->slashMenuMatches()),
                    'fixture: one unambiguous match to complete to',
                );

                [$next] = $chat->update($k[0]);

                $this->assertSame('/compact ', $next->inputBuf);
            },
            // Audit 15b-15: the second thing a bare Tab completes. A unique
            // FILE comes back whole with the closing space; the App-level
            // half (the shell yielding Tab) reads the same predicate,
            // Chat::mentionOwnsTab(), asserted here too.
            'chat.mention-complete' => function (array $k): void {
                $root = $this->sandbox . '/mention-root';
                @mkdir($root . '/src', 0777, true);
                file_put_contents($root . '/src/Uniquely.php', "<?php\n");
                $chat = (new Chat(inputBuf: 'read @src/Uni', backend: new EchoBackend(), projectRoot: $root))
                    ->withSize(100, 30);
                $this->assertTrue($chat->mentionOwnsTab(), 'fixture: the caret ends an @ mention');

                [$next] = $chat->update($k[0]);

                $this->assertSame('read @src/Uniquely.php ', $next->inputBuf);

                // Roadmap 5.14l: the "$skill name" half — a user-invocable
                // skill's name, from the workspace's registry.
                $skills = new \SugarCraft\Crush\Skills\SkillRegistry();
                $skills->register(['security-audit' => \SugarCraft\Crush\Skills\Skill::parse("---\nname: security-audit\ndescription: audit\n---\nbody", 'security-audit')]);
                $chat = (new Chat(
                    inputBuf: 'review $sec',
                    backend: new EchoBackend(),
                    workspace: \SugarCraft\Crush\Host\WorkspaceContext::new(skills: $skills),
                ))->withSize(100, 30);
                $this->assertTrue($chat->mentionOwnsTab(), 'fixture: the caret ends a $ mention');

                [$next] = $chat->update($k[0]);

                $this->assertSame('review $security-audit ', $next->inputBuf);
            },
            'chat.recall' => function (array $k): void {
                [$next] = $this->chat([Message::user('earlier')])->update($k[0]);
                $this->assertSame('earlier', $next->inputBuf);
            },
            'chat.recall-next' => function (array $k): void {
                $up = new \SugarCraft\Core\Msg\KeyMsg(\SugarCraft\Core\KeyType::Up);
                [$recalled] = $this->chat([Message::user('first'), Message::user('second')])->update($up);
                [$recalled] = $recalled->update($up);
                $this->assertSame('first', $recalled->inputBuf, 'fixture: two presses walk back to the older prompt');

                [$next] = $recalled->update($k[0]);
                $this->assertSame('second', $next->inputBuf);
                [$draft] = $next->update($k[0]);
                $this->assertSame('', $draft->inputBuf, 'past the newest prompt the (empty) draft comes back');
            },
            'chat.accept-suggestion' => function (array $k): void {
                $history = [Message::user('fix it'), Message::assistant('Fixed.')];
                [$suggested] = $this->chat($history)->update(new \SugarCraft\Crush\PromptSuggestionMsg('run the tests', 0, count($history), null));
                [$next] = $suggested->update($k[0]);
                $this->assertSame('run the tests', $next->inputBuf);
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
                // "…and thought": the newest thought rides the same toggle.
                $call = Message::assistant('done', null, 'a thought')->withToolResults([ToolResult::ok('Bash', 'output', 'call-1')]);
                $thought = \SugarCraft\Crush\Renderer::thoughtKey('a thought');
                [$expanded] = $this->chat([Message::user('run it'), $call])->update($k[0]);
                $this->assertArrayHasKey('call-1', $expanded->expanded());
                $this->assertArrayHasKey($thought, $expanded->expanded(), 'the newest thought must open with the tool output');

                [$collapsed] = $expanded->update($k[0]);
                $this->assertArrayNotHasKey('call-1', $collapsed->expanded(), 'the same key must collapse it again');
                $this->assertArrayNotHasKey($thought, $collapsed->expanded(), 'and the thought with it');
            },
            // Audit 15b-15: the chord reads the clipboard's image off the
            // update path (a Cmd), and its answer lands in the draft as an @
            // mention. Both halves are driven, through the tool seams.
            'chat.paste-image' => function (array $k): void {
                $pastes = $this->sandbox . '/pastes';
                \SugarCraft\Crush\Support\ClipboardImage::useDirectoryForTesting($pastes);
                \SugarCraft\Crush\Support\ClipboardImage::useCandidatesForTesting([['clipboard-tool']]);
                \SugarCraft\Crush\Support\ClipboardImage::useRunnerForTesting(
                    static fn (array $argv, string $dest): bool => file_put_contents($dest, "\x89PNG\r\n\x1a\n" . 'pixels') !== false,
                );
                try {
                    [$next, $cmd] = $this->chat([], 'look at ')->update($k[0]);
                    $this->assertNotNull($cmd, 'the chord must read the clipboard');
                    $answer = $cmd();
                    $this->assertInstanceOf(\SugarCraft\Crush\ClipboardImagePastedMsg::class, $answer);
                    [$pasted] = $next->update($answer);

                    $this->assertMatchesRegularExpression('~^look at @/\S+\.png $~', $pasted->inputBuf);
                    $this->assertFileExists(substr(trim($pasted->inputBuf), strlen('look at @')));
                } finally {
                    \SugarCraft\Crush\Support\ClipboardImage::useRunnerForTesting(null);
                    \SugarCraft\Crush\Support\ClipboardImage::useCandidatesForTesting(null);
                    \SugarCraft\Crush\Support\ClipboardImage::useDirectoryForTesting(null);
                }
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
            // An engine turn that has reported a step (1.C-4a): one press
            // asks the fork to stop at the step boundary and the turn runs on.
            'chat.stop' => function (array $k): void {
                $token = new \SugarCraft\Crush\Backend\CancellationToken();
                $chat = (new Chat(
                    history: [Message::user('go'), Message::toolRunning(new ToolCall('Bash', ['command' => 'sleep 600'], 'call_run'))],
                    backend: new EchoBackend(),
                    inFlight: true,
                    generation: 1,
                    inFlightCancellation: $token,
                    liveStep: new \SugarCraft\Crush\Events\StepStarted(2, 1000, null),
                    liveStepGeneration: 1,
                ))->withSize(120, 20);
                $this->assertCount(1, $k, 'the label names one press');
                [$asked] = $chat->update($k[0]);
                $this->assertSame(['call_run'], $token->takeToolCancels(), 'the press stops the running tool (cancel_tool)');
                $this->assertTrue($token->isSoftCancelled(), 'then the turn, at its step boundary');
                $this->assertFalse($token->isCancelled(), 'and kills nothing else');
                $this->assertTrue($asked->inFlight, 'the turn runs on to its step boundary');
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
            // Roadmap 5.7-1: Alt+M puts the session's gate into plan mode and
            // a second press brings it back to the mode it left.
            'chat.plan-mode' => function (array $k): void {
                $gate = new \SugarCraft\Crush\Permissions\PermissionGate(\SugarCraft\Crush\Permissions\PermissionMode::Default);
                $chat = (new Chat(
                    backend: \SugarCraft\Crush\Backend\EngineBackend::new(new \SugarCraft\Crush\Tests\Support\ScriptedProvider([]), 'm')
                        ->withPermissionGate($gate),
                ))->withSize(100, 30);
                [$planning] = $chat->update($k[0]);
                $this->assertSame(\SugarCraft\Crush\Permissions\PermissionMode::Plan, $planning->currentPermissionMode());
                [$back] = $planning->update($k[0]);
                $this->assertSame(\SugarCraft\Crush\Permissions\PermissionMode::Default, $back->currentPermissionMode());
            },
            'chat.quit' => function (array $k): void {
                [, $cmd] = $this->chat()->update($k[0]);
                $this->assertNotNull($cmd, 'Ctrl+C must return a quit Cmd');
            },

            // ── Panes & windows (the App shell's KeyboardHandler) ─────────
            // Three docked panes, not the default two: with [Chat, Files] the
            // cycle wraps onto the same row in both directions, so the pair of
            // rows below could be swapped and both observations would still
            // pass — the lesson `chat.session-cycle` already learned. Tools
            // joins on the RIGHT, making the order [Chat, Files, Tools].
            'shell.pane-next' => function (array $k): void {
                [$app] = $this->claim($k[0], $this->app()->togglePaneDocking(Pane::Tools));
                $this->assertSame(Pane::Files, $app->pane);
            },
            'shell.pane-prev' => function (array $k): void {
                [$app] = $this->claim($k[0], $this->app()->togglePaneDocking(Pane::Tools));
                $this->assertSame(Pane::Tools, $app->pane, 'backward from chat must wrap to the LAST docked pane');
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
            // The door is the SAME fixture's other polarity: Files focused and
            // the draft EMPTY answers Enter with the palette, which is why the
            // row qualifies its condition in prose. Typing a character first
            // (PaneFocusCycleTest pins that half) sends the draft to chat
            // instead, exactly as the `chat.send` row promises.
            'shell.pane-palette' => function (array $k): void {
                [, $cmd] = $this->claim($k[0], $this->app()->withChat(new Chat())->withPane(Pane::Files));
                $this->assertInstanceOf(CommandPaletteCmd::class, $cmd);
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
            // Both halves of the description: the first press focuses the
            // pane and opens nothing; the second, from the focused pane,
            // opens the settings view.
            'shell.settings' => function (array $k): void {
                [$focused] = $this->claim($k[0], $this->settingsSourcedApp());
                $this->assertSame(Pane::Settings, $focused->pane);
                $this->assertNull($focused->settingsEditor, 'the first press only focuses');

                [$opened] = $this->claim($k[0], $focused);
                $this->assertNotNull($opened->settingsEditor, 'the second press opens the settings view');
            },
            // The Settings pane's Enter door, against the palette door's
            // fixture: same empty draft, but the pane it is pressed from
            // decides which surface opens.
            'shell.settings-open' => function (array $k): void {
                [$app, $cmd] = $this->claim(
                    $k[0],
                    $this->settingsSourcedApp()->withChat(new Chat())->withPane(Pane::Settings),
                );
                $this->assertNotNull($app->settingsEditor);
                $this->assertNull($cmd, 'the settings pane opens the view, not the palette');
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
            // Both keys, with DIFFERENT expected answers. E744: the picker's
            // selection model is the ItemList, which CLAMPS at both ends — the
            // pre-E744 hand-rolled list wrapped, and wrap was the reason three
            // rows were needed to separate the directions ("from row 0,
            // up-wrapping and down both land on row 1"). Under the clamp the
            // directions separate on their own: from the top the UP chord holds
            // at row 0 while the DOWN chord advances, so a label respelled
            // `↓ / ↑` (or a description `(or j / k)` with the letters swapped)
            // moves where this expects stillness. The three-row fixture is kept
            // simply because it was already the shape that made the two
            // answers unique, and nothing here needs it to be smaller.
            'picker.move' => function (array $k): void {
                $open = $this->chatWithPicker(3);
                $this->assertSame(0, $open->sessionPicker()?->selectedIndex(), 'fixture: starts at the top');

                [$up] = $open->update($k[0]);
                [$down] = $open->update($k[1]);
                $this->assertSame(0, $up->sessionPicker()?->selectedIndex(), 'the first key must hold at the top — the clamp, not a wrap');
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
                    'and must leave the highlight where it was',
                );
                $this->assertSame(
                    $open->sessionPicker()?->selectedSession()['sessionId'] ?? null,
                    $previewed->sessionPicker()?->preview()['id'] ?? null,
                    'and must load the preview of THAT session — the promise the row makes',
                );
            },
            // Both halves of "to the current git branch, OR ALL", and neither
            // of them is "the highlight moved": rebinding Ctrl+B to 'down'
            // satisfied the old form too.
            //
            // Driven from inside a throwaway repo on a KNOWN branch, because
            // the picker filters by the branch SessionStore::gitBranchAt()
            // reads from the process CWD when the picker opens: run from this
            // checkout the answer is whatever branch happens to be out, and in
            // a detached-HEAD PR build it is null — which would make BOTH
            // directions assert null against null and observe nothing at all.
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
            // Both halves: with no filter Esc closes; with a filter typed the
            // first Esc clears it and leaves the list up, the second closes.
            'picker.close' => function (array $k): void {
                [$closed] = $this->chatWithPicker()->update($k[0]);
                $this->assertNull($closed->sessionPicker());

                $filtered = $this->pressAll($this->chatWithPicker(), [new KeyMsg(KeyType::Char, '/'), new KeyMsg(KeyType::Char, '2')]);
                $this->assertSame('2', $filtered->sessionPicker()?->query(), 'fixture: a filter is typed');
                [$cleared] = $filtered->update($k[0]);
                $this->assertNotNull($cleared->sessionPicker(), 'the first press clears the filter, it does not close');
                $this->assertSame('', $cleared->sessionPicker()?->query());
                [$closedToo] = $cleared->update($k[0]);
                $this->assertNull($closedToo->sessionPicker(), 'and the next one closes');
            },
            // The key opens the filter, and what is typed after it is a query
            // rather than a row key: `2` narrows to the one session it names.
            'picker.filter' => function (array $k): void {
                $open = $this->chatWithPicker(3);
                [$filtering] = $open->update($k[0]);
                $this->assertTrue($filtering->sessionPicker()?->isFiltering(), 'the key must open the filter');

                [$typed] = $filtering->update(new KeyMsg(KeyType::Char, '2'));
                $this->assertSame(
                    ['session-2'],
                    array_column($typed->sessionPicker()?->filteredSessions() ?? [], 'sessionId'),
                    'typed text must filter the list',
                );
            },
            'picker.rename' => function (array $k): void {
                $open = $this->chatWithPicker(3);
                $id = $this->highlightedOther($open);
                $before = (string) $open->sessionStore()?->getSession($id)['name'];

                [$renaming] = $open->update($k[0]);
                $this->assertTrue($renaming->sessionPicker()?->isRenaming(), 'the key must open the inline rename');
                $saved = $this->pressAll($renaming, [new KeyMsg(KeyType::Char, '!'), new KeyMsg(KeyType::Enter)]);

                $row = $saved->sessionStore()?->getSession($id);
                $this->assertSame($before . '!', $row['name'] ?? null, 'Enter must save the edited title');
                $this->assertSame('user', $row['title_source'] ?? null, 'as a user title the auto-titler leaves alone');
                $this->assertNotNull($saved->sessionPicker(), 'and leave the list up');
            },
            // "Pressed twice": the first press only arms, the second deletes.
            'picker.delete' => function (array $k): void {
                $open = $this->chatWithPicker(3);
                $id = $this->highlightedOther($open);

                [$armed] = $open->update($k[0]);
                $this->assertSame($id, $armed->sessionPicker()?->armedDeleteId(), 'the first press arms');
                $this->assertNotNull($armed->sessionStore()?->getSession($id), 'and deletes nothing yet');

                [$deleted] = $armed->update($k[0]);
                $this->assertNull($deleted->sessionStore()?->getSession($id), 'the second press deletes');
            },
            // Confirms an armed delete AND takes the branch under it, which a
            // plain second `d` detaches and keeps.
            'picker.delete-children' => function (array $k): void {
                $chat = $this->chatWithSessions(3);
                $branch = $chat->sessionStore()?->forkSession('session-3');
                $this->assertIsString($branch);
                [$open] = $chat->update($this->pressLabelled('chat.session-picker'));
                $open = $this->highlight($open, 'session-3');

                $deleted = $this->pressAll($open, [new KeyMsg(KeyType::Char, 'd'), $k[0]]);
                $this->assertNull($deleted->sessionStore()?->getSession('session-3'), 'the armed row is deleted');
                $this->assertNull($deleted->sessionStore()?->getSession($branch), 'and so is its branch');
            },
            'picker.pin' => function (array $k): void {
                $open = $this->chatWithPicker(3);
                $id = $this->highlightedOther($open);

                [$pinned] = $open->update($k[0]);
                $this->assertSame(1, (int) $pinned->sessionStore()?->getSession($id)['pinned'], 'the first press pins');
                $this->assertTrue($pinned->sessionPicker()?->selectedSession()['pinned'] ?? false, 'the highlight follows the row');

                [$unpinned] = $pinned->update($k[0]);
                $this->assertSame(0, (int) $unpinned->sessionStore()?->getSession($id)['pinned'], 'and the second unpins');
            },
            'picker.fork' => function (array $k): void {
                $open = $this->chatWithPicker(3);
                $id = $this->highlightedOther($open);

                [$forked] = $open->update($k[0]);
                $this->assertNull($forked->sessionPicker(), 'forking switches away from the list');
                $now = $forked->currentSessionId();
                $this->assertNotNull($now);
                $this->assertNotContains($now, ['session-1', 'session-2', 'session-3'], 'onto a NEW session');
                $this->assertSame($id, $forked->sessionStore()?->getSession($now)['parent_id'] ?? null, 'copied from the highlighted one');
            },
            'picker.archive' => function (array $k): void {
                $open = $this->chatWithPicker(3);
                $id = $this->highlightedOther($open);

                [$archived] = $open->update($k[0]);
                $this->assertNotNull($archived->sessionStore()?->getSession($id)['archived_at'] ?? null, 'the row is archived');
                $this->assertNotContains($id, array_column($archived->sessionPicker()?->filteredSessions() ?? [], 'sessionId'), 'and leaves the list');
            },
            'picker.unarchive' => function (array $k): void {
                $chat = $this->chatWithSessions(3);
                $chat->sessionStore()?->archive('session-3');
                [$open] = $chat->update($this->pressLabelled('chat.session-picker'));
                $open = $this->highlight($this->pressAll($open, [new KeyMsg(KeyType::Char, 'a')]), 'session-3');

                [$back] = $open->update($k[0]);
                $row = $back->sessionStore()?->getSession('session-3');
                $this->assertIsArray($row);
                $this->assertNull($row['archived_at'], 'the row is back');
            },
            // Both halves of "Show or hide".
            'picker.archived' => function (array $k): void {
                $chat = $this->chatWithSessions(3);
                $chat->sessionStore()?->archive('session-3');
                [$open] = $chat->update($this->pressLabelled('chat.session-picker'));
                $ids = fn(Chat $c): array => array_column($c->sessionPicker()?->filteredSessions() ?? [], 'sessionId');
                $this->assertNotContains('session-3', $ids($open), 'fixture: archived rows start hidden');

                [$shown] = $open->update($k[0]);
                $this->assertContains('session-3', $ids($shown), 'the first press shows them');
                [$hidden] = $shown->update($k[0]);
                $this->assertNotContains('session-3', $ids($hidden), 'the second hides them again');
            },
            'picker.children' => function (array $k): void {
                $chat = $this->chatWithSessions(3);
                $child = $chat->sessionStore()?->createChildSession(
                    'session-3',
                    \SugarCraft\Crush\Session\SessionKind::Subagent,
                    'explore',
                    'call-1',
                    'openai',
                    'gpt-4',
                    'Map the login flow',
                );
                $this->assertIsString($child);
                [$open] = $chat->update($this->pressLabelled('chat.session-picker'));
                $ids = fn(Chat $c): array => array_column($c->sessionPicker()?->filteredSessions() ?? [], 'sessionId');
                $this->assertNotContains($child, $ids($open), 'fixture: sub-agent rows start hidden');

                [$shown] = $open->update($k[0]);
                $this->assertContains($child, $ids($shown), 'the first press shows them');
                [$hidden] = $shown->update($k[0]);
                $this->assertNotContains($child, $ids($hidden), 'the second hides them again');
            },

            // ── Folder picker (/new) ─────────────────────────────────────
            // Clamped like the session list: from "▶ Start session here" (row
            // 0) the UP key holds and the DOWN key reaches the first directory.
            'dirpicker.move' => function (array $k): void {
                $open = $this->chatWithDirPicker();
                [$up] = $open->update($k[0]);
                [$down] = $open->update($k[1]);
                $this->assertSame(0, $up->dirPicker()?->selectedIndex(), 'the first key must hold at the top');
                $this->assertSame(1, $down->dirPicker()?->selectedIndex(), 'the second key must move DOWN');
            },
            // Both halves: on a directory Enter opens it; on "▶ Start session
            // here" (this project root) it starts a new session in this process.
            'dirpicker.enter' => function (array $k): void {
                $open = $this->chatWithDirPicker();
                [$onAlpha] = $open->update(new KeyMsg(KeyType::Down));
                [$opened] = $onAlpha->update($k[0]);
                $this->assertSame($this->dirPickerRoot . '/alpha', $opened->dirPicker()?->path());

                [$started] = $open->update($k[0]);
                $this->assertNull($started->dirPicker(), 'starting closes the picker');
                $this->assertNotSame($open->currentSessionId(), $started->currentSessionId(), 'and a new session id is minted');
                $this->assertNull($started->pendingRelaunch(), 'this root needs no restart');
            },
            'dirpicker.open' => function (array $k): void {
                [$onAlpha] = $this->chatWithDirPicker()->update(new KeyMsg(KeyType::Down));
                [$opened] = $onAlpha->update($k[0]);
                $this->assertSame($this->dirPickerRoot . '/alpha', $opened->dirPicker()?->path());
            },
            'dirpicker.up' => function (array $k): void {
                $this->assertUpReturnsToTheParent($k[0]);
            },
            'dirpicker.parent' => function (array $k): void {
                $this->assertUpReturnsToTheParent($k[0]);
            },
            'dirpicker.hidden' => function (array $k): void {
                $open = $this->chatWithDirPicker();
                $this->assertNotContains('.hidden', \array_map(static fn ($e): string => $e->name, $open->dirPicker()?->listing()->entries ?? []));
                [$shown] = $open->update($k[0]);
                $this->assertTrue($shown->dirPicker()?->showsHidden());
                $this->assertContains('.hidden', \array_map(static fn ($e): string => $e->name, $shown->dirPicker()?->listing()->entries ?? []));
                [$hidden] = $shown->update($k[0]);
                $this->assertFalse($hidden->dirPicker()?->showsHidden(), 'and the next press hides them again');
            },
            'dirpicker.path' => function (array $k): void {
                [$typing] = $this->chatWithDirPicker()->update($k[0]);
                $this->assertSame('', $typing->dirPicker()?->typedPath(), 'the key opens the path box');
                $went = $this->pressAll($typing, [new KeyMsg(KeyType::Char, 'b'), new KeyMsg(KeyType::Char, 'e'), new KeyMsg(KeyType::Char, 't'), new KeyMsg(KeyType::Char, 'a'), new KeyMsg(KeyType::Enter)]);
                $this->assertSame($this->dirPickerRoot . '/beta', $went->dirPicker()?->path(), 'and Enter goes to what was typed');
            },
            // Both halves: this root starts here; another one asks first, and
            // yes quits for bin/sugarcrush to restart there.
            'dirpicker.start' => function (array $k): void {
                $open = $this->chatWithDirPicker();
                [$here] = $open->update($k[0]);
                $this->assertNull($here->dirPicker());
                $this->assertNotSame($open->currentSessionId(), $here->currentSessionId());

                $inAlpha = $this->pressAll($open, [new KeyMsg(KeyType::Down), new KeyMsg(KeyType::Right)]);
                [$asking] = $inAlpha->update($k[0]);
                $this->assertSame($this->dirPickerRoot . '/alpha', $asking->dirPicker()?->confirming(), 'another directory asks first');
                [$quitting, $cmd] = $asking->update(new KeyMsg(KeyType::Char, 'y'));
                $this->assertNotNull($cmd, 'yes quits');
                $this->assertSame($this->dirPickerRoot . '/alpha', $quitting->pendingRelaunch());
            },
            'dirpicker.close' => function (array $k): void {
                $open = $this->chatWithDirPicker();
                [$closed, $cmd] = $open->update($k[0]);
                $this->assertNull($closed->dirPicker());
                $this->assertNull($cmd);
                $this->assertSame($open->currentSessionId(), $closed->currentSessionId(), 'nothing starts');
            },

            // ── Permission prompt ────────────────────────────────────────
            'permission.once' => fn(array $k) => $this->assertPermissionAnsweredBy($k[0]),
            // `a` answers at once — the row's description promises the
            // session grant, and this is the observation that holds the two
            // together: one key, the prompt comes down, and the grant map
            // holds the scope the modal's `a` row named.
            //
            // Raised by the REAL permission gate, not hand-dispatched: since
            // audit F-P9 a grant answers only the gate's own question, so a
            // prompt with no attributed ask behind it grants nothing at all.
            'permission.always' => function (array $k): void {
                $blocked = $this->blockedOnTheGate();
                $this->assertSame('Bash(make clean *)', $blocked->permissionAlwaysScope());

                [$granted, $cmd] = $blocked->update($k[0]);
                $this->assertNull($granted->pendingPermission(), "'a' answers the prompt on its own — no confirm box");
                $this->assertSame(
                    ['rule:Bash(make clean)' => true, 'rule:Bash(make clean *)' => true],
                    $granted->permissionGrants(),
                    'and THAT is what the row promises: calls like this one, for the whole session',
                );
                $this->reapReleasedBatch($cmd);

                // The prose half: nothing else in this suite reads a
                // description's WORDS back.
                $this->assertStringStartsWith(
                    'Allow calls like this one',
                    KeyBindingRegistry::byId('permission.always')?->description ?? '',
                    'the row must say what the key does',
                );
            },
            // `e` opens the scope editor prefilled with what `a` would
            // remember; Enter saves the edited scope and allows the call.
            'permission.edit' => function (array $k): void {
                [$editing] = $this->blockedOnTheGate()->update($k[0]);
                $this->assertSame(PermissionPromptStage::EditingScope, $editing->permissionStage());
                $this->assertSame('make clean *', $editing->inputBuf, 'prefilled with the suggestion');
                $this->assertNotNull($editing->pendingPermission(), "'e' does not answer");

                // Widen the suggestion to `make *`: back over "clean *".
                foreach (range(1, 7) as $_) {
                    [$editing] = $editing->update(new KeyMsg(KeyType::Backspace));
                }
                [$editing] = $editing->update(new KeyMsg(KeyType::Char, '*'));
                $this->assertSame('make *', $editing->inputBuf);
                [$granted, $cmd] = $editing->update(new KeyMsg(KeyType::Enter));
                $this->assertNull($granted->pendingPermission(), 'Enter saves it and allows the call');
                $this->assertSame(['rule:Bash(make)' => true, 'rule:Bash(make *)' => true], $granted->permissionGrants(), '"any arguments" includes none');
                $this->reapReleasedBatch($cmd);
            },
            'permission.deny' => fn(array $k) => $this->assertPermissionAnsweredBy($k[0]),
            // The note is typed after the key, and Enter sends it with the
            // refusal (the modal's own footer names those keys).
            'permission.note' => function (array $k): void {
                [$writing] = $this->blockedOnPermission()->update($k[0]);
                $this->assertSame(PermissionPromptStage::WritingNote, $writing->permissionStage());
                foreach (str_split('too risky') as $char) {
                    [$writing] = $writing->update(new KeyMsg(KeyType::Char, $char));
                }
                $this->assertNotNull($writing->pendingPermission(), 'letters type the note');
                [$answered] = $writing->update(new KeyMsg(KeyType::Enter));
                $this->assertNull($answered->pendingPermission(), 'Enter refuses with it');
                $this->assertStringContainsString('too risky', implode("\n", array_map(static fn (Message $m): string => $m->content, $answered->history)));
            },
            'permission.stop' => function (array $k): void {
                $token = new \SugarCraft\Crush\Backend\CancellationToken();
                [$blocked] = (new Chat(history: [Message::user('clean up')], backend: new EchoBackend(), inFlightCancellation: $token))
                    ->withSize(100, 30)
                    ->update(new PermissionRequestMsg(
                        Message::assistant(''),
                        new ToolCall('Bash', ['description' => 'Delete build/'], 'call_1'),
                        'Run rm -rf build/?',
                    ));
                $this->assertNotNull($blocked->pendingPermission(), 'fixture: the prompt must be up');
                [$answered] = $blocked->update($k[0]);
                $this->assertNull($answered->pendingPermission(), 'the call is refused');
                $this->assertTrue($token->isSoftCancelled(), 'and the turn asked to stop');
            },
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

            // Roadmap 5.7-2: on AskUser's own question a digit answers with
            // that choice — `once` carrying the number, which the tool reads as
            // the pick — while on an ordinary prompt it is a non-answer.
            'permission.choice' => function (): void {
                $resolved = null;
                $ask = \SugarCraft\Crush\Backend\PendingAsk::fromFrame(
                    \SugarCraft\Crush\Backend\PendingAsk::describe(
                        new \SugarCraft\Crush\Tools\ToolCall('call_q', 'AskUser', ['question' => 'Which?', 'options' => ['red', 'green', 'blue']]),
                        \SugarCraft\Crush\Hooks\HookResult::ask('Which?'),
                        'default',
                    ),
                    static function (\SugarCraft\Crush\Events\PermissionResolved $r) use (&$resolved): void {
                        $resolved = $r;
                    },
                );
                $this->assertNotNull($ask);
                [$asking] = $this->chat([Message::user('pick')])->update(new PermissionRequestMsg(
                    Message::assistant(''),
                    new ToolCall('AskUser', ['question' => 'Which?'], 'call_q'),
                    'Which?',
                    null,
                    $ask,
                ));
                $this->assertNotNull($asking->pendingPermission(), 'fixture: the question must be up');

                [$answered] = $asking->update(new KeyMsg(KeyType::Char, '2'));
                $this->assertNull($answered->pendingPermission());
                $this->assertInstanceOf(\SugarCraft\Crush\Events\PermissionResolved::class, $resolved);
                $this->assertSame(\SugarCraft\Crush\Permissions\PermissionReply::Once, $resolved->reply);
                $this->assertSame('2', $resolved->note);

                [$bash] = $this->blockedOnPermission()->update(new KeyMsg(KeyType::Char, '2'));
                $this->assertNotNull($bash->pendingPermission(), 'a digit answers nothing on a permission prompt');
                $this->assertSame(PermissionPromptStage::Disarmed, $bash->permissionStage());
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
            // Roadmap P-C2: the peek's Enter attaches — the main area shows the
            // agent. Through App::update(), so the shell's delivery of the
            // open message is on the path, not just the mode flip.
            'agents.attach' => function (array $k): void {
                $peeking = $this->agentApp(1)->withSelectedAgentIndex(0)->withAgentViewMode(AgentViewMode::Peek);
                [$app] = $peeking->update($k[0]);
                $this->assertSame(AgentViewMode::Attach, $app->agentViewMode);
                $this->assertSame('agent-1', $app->agentViewTarget, 'the peeked agent is on screen');
                $this->assertSame(Pane::Chat, $app->pane, 'in the main area');
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
            // P-D3: the dashboard's c/r/s act on the runs, through their
            // mailboxes. Through App::update(), the live route.
            'agents.cancel' => function (array $k): void {
                $token = new \SugarCraft\Crush\Backend\CancellationToken();
                [$app, $session] = $this->dashboardOn($this->stripApp($token), 'run-2');

                [$asked] = $app->update($k[0]);
                $this->assertSame(['cancel'], $this->controls($session, 'run-2'), 'the selected run is asked to stop');
                $this->assertSame([], $this->controls($session, 'run-1'));
                $this->assertSame([], $token->takeToolCancels(), 'softly: the turn\'s call is left alone');

                $asked->update($k[0]);
                $this->assertSame([], $token->takeToolCancels());
                $this->assertSame(
                    [['agentId' => 'run-2', 'callId' => 'call_2']],
                    $token->takeAgentCancels(),
                    'a second press hard-stops it (P-E1 agent_cancel)',
                );
            },
            'agents.resume' => function (array $k): void {
                [$app, $session] = $this->dashboardOn($this->stripApp(), 'run-2');
                $app->update($k[0]);
                $this->assertSame(['resume'], $this->controls($session, 'run-2'));
            },
            'agents.stop-all' => function (array $k): void {
                [$app, $session] = $this->dashboardOn($this->stripApp(), 'run-2');
                $app->update($k[0]);
                foreach (['run-1', 'run-2', 'run-3'] as $run) {
                    $this->assertSame(['cancel'], $this->controls($session, $run), "{$run} is asked to stop");
                }
            },

            // ── Skill picker ─────────────────────────────────────────────
            // Three options, for the reason picker.move gives: the picker wraps,
            // so two would make up and down indistinguishable.
            // Roadmap P-B3: the live agents strip. Every press goes through
            // App::update(), the live route, so the claim, the strip's arm
            // and the shell's delivery to the chat are all on the path.
            'chat.agents-strip' => function (array $k): void {
                $this->assertCount(1, $k, 'the label names one press');
                [$app] = $this->stripApp()->update($k[0]);
                $this->assertSame('run-1', $app->agentStripFocus, 'the strip takes the keyboard on its first run');
            },
            'strip.move' => function (array $k): void {
                $this->assertCount(2, $k);
                $middle = $this->stripApp()->withAgentStripFocus('run-2');
                [$back] = $middle->update($k[0]);
                [$on] = $middle->update($k[1]);
                $this->assertSame('run-1', $back->agentStripFocus, 'the first key moves back');
                $this->assertSame('run-3', $on->agentStripFocus, 'the second moves on');
            },
            'strip.open' => function (array $k): void {
                [$app] = $this->stripApp()->withAgentStripFocus('run-2')->update($k[0]);
                $this->assertSame('run-2', $app->agentViewTarget, 'the run opens in the Agent View');
                $this->assertSame(Pane::Chat, $app->pane);
                $this->assertSame(AgentViewMode::Attach, $app->agentViewMode);
                $this->assertSame('run-2', \SugarCraft\Crush\Tui\Components\AgentDashboardPane::entries($app)[$app->selectedAgentIndex]->key);
                $this->assertNull($app->agentStripFocus);
            },
            // ── Agent transcript (P-C2) ─────────────────────────────────
            'agentview.back' => function (array $k): void {
                $open = $this->stripApp()->openAgentView('run-2');
                $this->assertSame('run-2', $open->agentViewTarget, 'fixture: the view is open');

                [$app] = $open->update($k[0]);
                $this->assertNull($app->agentViewTarget, 'the main transcript is back');
                $this->assertSame(AgentViewMode::List, $app->agentViewMode);
                $this->assertTrue($app->chat?->inFlight, 'and the turn was not touched');
            },
            // Both directions from the MIDDLE run, so swapping the two keys —
            // or walking the wrong batch — fails.
            'agentview.next' => function (array $k): void {
                [$app] = $this->batchApp()->openAgentView('b-2')->update($k[0]);
                $this->assertSame('b-3', $app->agentViewTarget);
            },
            'agentview.prev' => function (array $k): void {
                [$app] = $this->batchApp()->openAgentView('b-2')->update($k[0]);
                $this->assertSame('b-1', $app->agentViewTarget);
            },
            // P-D3: the view's Ctrl+X chords, pressed as the sequence the
            // label names.
            'agentview.cancel' => function (array $k): void {
                $session = 'drift-x-' . bin2hex(random_bytes(4));
                $app = $this->composing($this->stripApp(), $session, '')->openAgentView('run-2');
                [$leader] = $app->update($k[0]);
                $this->assertTrue($leader->agentViewLeader, 'the first key is the leader');
                [$after] = $leader->update($k[1]);
                $this->assertFalse($after->agentViewLeader);
                $this->assertSame(['cancel'], $this->controls($session, 'run-2'));
                $this->assertSame('', $after->chat?->inputBuf, 'no letter was typed');
            },
            'agentview.pause' => function (array $k): void {
                $session = 'drift-x-' . bin2hex(random_bytes(4));
                $app = $this->composing($this->stripApp(), $session, '')->openAgentView('run-2');
                [$paused] = $app->update($k[0])[0]->update($k[1]);
                $this->assertSame(['pause'], $this->controls($session, 'run-2'));
                $this->assertTrue($paused->isAgentPaused('run-2'));

                [$going] = $paused->update($k[0])[0]->update($k[1]);
                $this->assertSame(['resume'], $this->controls($session, 'run-2'), 'and the same chord lets it go on');
                $this->assertFalse($going->isAgentPaused('run-2'));
            },
            'agentview.stop-all' => function (array $k): void {
                $session = 'drift-x-' . bin2hex(random_bytes(4));
                $app = $this->composing($this->stripApp(), $session, '')->openAgentView('run-2');
                $app->update($k[0])[0]->update($k[1]);
                foreach (['run-1', 'run-2', 'run-3'] as $run) {
                    $this->assertSame(['cancel'], $this->controls($session, $run), "{$run} is asked to stop");
                }
            },
            'agentview.open-session' => function (array $k): void {
                $store = new \SugarCraft\Crush\Session\EnhancedSessionStore($this->sandbox . '/open-session.db');
                $store->createSession('parent', 'p', 'm', null, 'Parent');
                $child = $store->createChildSession('parent', \SugarCraft\Crush\Session\SessionKind::Subagent, 'explore', 'call_1', 'p', 'm', 'Map it (@explore)');
                $store->saveTranscript($child, [['role' => 'user', 'content' => 'map the login flow']]);
                $chat = (new Chat(history: [Message::user('go')], backend: new EchoBackend(), sessionStore: $store, currentSessionId: 'parent'))
                    ->withSize(120, 20);
                $chat->agentLive()->apply((new \SugarCraft\Crush\Events\SubAgentActivity(
                    \SugarCraft\Crush\Events\SubAgentActivity::OP_FINISHED,
                    'run-1',
                    'explore',
                    '',
                    2,
                    'done',
                    parentCallId: 'call_1',
                    outcome: \SugarCraft\Crush\Events\SubAgentActivity::OUTCOME_COMPLETE,
                ))->withChildSessionId($child));
                $app = $this->app()->withChat($chat)->openAgentView('run-1');

                [$opened] = $app->update($k[0])[0]->update($k[1]);
                $this->assertNull($opened->agentViewTarget, 'the view gave way');
                $this->assertSame($child, $opened->chat?->currentSessionId(), 'to the agent\'s session, a normal one to type into');
            },
            // P-E3: the run is asked to move to the background — the one
            // control its Task call answers by returning at once; the turn
            // and the composer are untouched.
            'agentview.background' => function (array $k): void {
                $session = 'drift-x-' . bin2hex(random_bytes(4));
                $app = $this->composing($this->stripApp(), $session, '')->openAgentView('run-2');
                [$leader] = $app->update($k[0]);
                $this->assertTrue($leader->agentViewLeader, 'the first key is the leader');
                [$after] = $leader->update($k[1]);
                $this->assertFalse($after->agentViewLeader);
                $this->assertSame(['background'], $this->controls($session, 'run-2'));
                $this->assertSame([], $this->controls($session, 'run-1'), 'only the run on screen');
                $this->assertTrue($after->chat?->inFlight, 'the turn goes on');
                $this->assertSame('', $after->chat?->inputBuf, 'no letter was typed');
            },
            // P-D2: the box is the open run's composer. Mid-turn on purpose:
            // the same Enter in the main view would steer the parent's turn.
            'agentview.send' => function (array $k): void {
                $session = 'drift-send-' . bin2hex(random_bytes(4));
                $open = $this->composing($this->stripApp(), $session, 'also check the cookie')->openAgentView('run-2');

                [$sent, $cmd] = $open->update($k[0]);
                $this->assertSame('', $sent->chat?->inputBuf, 'the draft was sent');
                $this->assertTrue($sent->chat?->inFlight, 'and the main turn was neither steered nor touched');
                $this->assertInstanceOf(\Closure::class, $cmd);

                $mail = \SugarCraft\Crush\Agents\Live\AgentInbox::forSession($session)?->drain('run-2') ?? [];
                $this->assertSame(['also check the cookie'], array_map(static fn ($m): string => $m->text, $mail), 'into that run\'s mailbox');
            },
            // P-D3: Ctrl+G turns the composer into a broadcast — it opens the
            // view on the newest running run, and Enter reaches every one.
            'shell.group-input' => function (array $k): void {
                $session = 'drift-group-' . bin2hex(random_bytes(4));
                [$group] = $this->composing($this->stripApp(), $session, 'wrap up')->update($k[0]);
                $this->assertTrue($group->agentBroadcast);
                $this->assertSame('run-3', $group->agentViewTarget, 'the view opened on the newest running run');
                $this->assertSame(['run-1', 'run-2', 'run-3'], $group->agentComposerTargets());

                $group->update(new KeyMsg(KeyType::Enter));
                foreach (['run-1', 'run-2', 'run-3'] as $run) {
                    $mail = \SugarCraft\Crush\Agents\Live\AgentInbox::forSession($session)?->drain($run) ?? [];
                    $this->assertSame(['wrap up'], array_map(static fn ($m): string => $m->text, $mail), "{$run} got it");
                }
            },
            'strip.cancel' => function (array $k): void {
                $token = new \SugarCraft\Crush\Backend\CancellationToken();
                $this->stripApp($token)->withAgentStripFocus('run-2')->update($k[0]);
                $this->assertSame(['call_2'], $token->takeToolCancels(), 'only that run\'s Task call is stopped');
                $this->assertFalse($token->isSoftCancelled(), 'the turn carries on');
            },
            'strip.dismiss' => function (array $k): void {
                $token = new \SugarCraft\Crush\Backend\CancellationToken();
                $app = $this->stripApp($token, finishedThird: true);
                [$after] = $app->withAgentStripFocus('run-3')->update($k[0]);
                $this->assertSame(['run-1', 'run-2'], array_map(static fn ($s): string => $s->id, $after->agentStripItems()), 'a finished run is dismissed');
                $this->assertSame([], $token->takeToolCancels());

                $app->withAgentStripFocus('run-1')->update($k[0]);
                $this->assertSame(['call_1'], $token->takeToolCancels(), 'a running one is stopped');
            },
            'strip.back' => function (array $k): void {
                [$app] = $this->stripApp()->withAgentStripFocus('run-2')->update($k[0]);
                $this->assertNull($app->agentStripFocus, 'the input box has the keyboard again');
                $this->assertTrue($app->chat?->inFlight, 'and the turn was not touched');
            },
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

            // ── Settings view (N-P1) ─────────────────────────────────────
            // Driven through App::update(), which hands the open view every
            // key before the shell's own handler is consulted. Both arrow
            // rows wrap from row/tab 0, so each key is asserted to land on a
            // DIFFERENT, named place — the menu.switch lesson below.
            'settings.move' => function (array $k): void {
                $open = $this->settingsOpenApp();
                $last = \count($open->settingsEditor->rows()) - 1;
                $this->assertGreaterThan(1, $last, 'fixture: the first category lists more than two keys');

                [$up] = $open->update($k[0]);
                [$down] = $open->update($k[1]);
                $this->assertSame($last, $up->settingsEditor->cursor, 'up from the first row wraps to the last');
                $this->assertSame(1, $down->settingsEditor->cursor, 'down moves to the second row');
            },
            'settings.category' => function (array $k): void {
                $open = $this->settingsOpenApp();
                $tabs = \count($open->settingsEditor->tabLabels());

                [$left] = $open->update($k[0]);
                [$right] = $open->update($k[1]);
                $this->assertSame($tabs - 1, $left->settingsEditor->tab, 'left from the first tab wraps to Files');
                $this->assertTrue($left->settingsEditor->onFilesTab());
                $this->assertSame(1, $right->settingsEditor->tab);
            },
            'settings.search' => function (array $k): void {
                [$app] = $this->settingsOpenApp()->update($k[0]);
                $this->assertTrue($app->settingsEditor->searching);

                [$typed] = $app->update(new KeyMsg(KeyType::Char, 'k'));
                $this->assertSame('k', $typed->settingsEditor->query, 'while searching, letters type rather than move');
            },
            'settings.search-keep' => function (array $k): void {
                [$kept] = $this->settingsSearchingApp('trust')->update($k[0]);
                $this->assertFalse($kept->settingsEditor->searching);
                $this->assertSame('trust', $kept->settingsEditor->query);
                $this->assertNotSame([], $kept->settingsEditor->rows(), 'the matches stay listed');
            },
            'settings.erase' => function (array $k): void {
                [$erased] = $this->settingsSearchingApp('trust')->update($k[0]);
                $this->assertSame('trus', $erased->settingsEditor->query);
            },
            'settings.close' => function (array $k): void {
                [$cleared] = $this->settingsSearchingApp('trust')->update($k[0]);
                $this->assertNotNull($cleared->settingsEditor, 'the first Esc clears the search');
                $this->assertSame('', $cleared->settingsEditor->query);

                [$closed] = $cleared->update($k[0]);
                $this->assertNull($closed->settingsEditor, 'the second closes the view');
            },
            // The editor's keys (carried from W4-g). Each drives the shell's
            // App::update(), so the keys the shell answers (`s`, `y`) are on
            // the path, and writes land in a per-call sandbox file.
            'settings.edit' => function (array $k): void {
                [$app] = $this->settingsEditApp('max tool steps')->update($k[0]);
                $this->assertNotNull($app->settingsEditor?->editing, 'the highlighted setting opens in its field');
            },
            'settings.stage' => function (array $k): void {
                [$editing] = $this->settingsEditApp('max tool steps')->update(new KeyMsg(KeyType::Enter));
                // The key is unset by default, so the field opens empty.
                [$typed] = $editing->update(new KeyMsg(KeyType::Char, '7'));
                [$app] = $typed->update($k[0]);
                $this->assertNull($app->settingsEditor?->editing);
                $this->assertSame(['maxToolSteps' => 7], $app->settingsEditor?->set, 'the value is staged');
            },
            'settings.cancel-edit' => function (array $k): void {
                [$editing] = $this->settingsEditApp('max tool steps')->update(new KeyMsg(KeyType::Enter));
                [$app] = $editing->update($k[0]);
                $this->assertNotNull($app->settingsEditor, 'the view stays open');
                $this->assertNull($app->settingsEditor->editing);
                $this->assertFalse($app->settingsEditor->hasChanges());
            },
            'settings.reset' => function (array $k): void {
                [$app] = $this->settingsEditApp('max tool steps')->update($k[0]);
                $this->assertSame(['maxToolSteps'], $app->settingsEditor?->unset);
            },
            'settings.tier' => function (array $k): void {
                [$app] = $this->settingsEditApp('max tool steps')->update($k[0]);
                $this->assertSame(\SugarCraft\Crush\Config\Settings\SettingsTier::ProjectLocal, $app->settingsEditor?->tier);
            },
            'settings.save' => function (array $k): void {
                [$app] = $this->settingsEditApp('max tool steps', stage: true)->update($k[0]);
                $this->assertNotNull($app->settingsEditor?->preview, 'the save is previewed, not written');
            },
            'settings.confirm' => function (array $k): void {
                $config = '';
                [$previewing] = $this->settingsEditApp('max tool steps', stage: true, config: $config)->update(new KeyMsg(KeyType::Char, 's'));
                [$app, $cmd] = $previewing->update($k[0]);
                $this->assertInstanceOf(\Closure::class, $cmd, 'the write is a Cmd');
                $app->update($cmd());
                $this->assertSame(['maxToolSteps' => 40], json_decode((string) file_get_contents($config), true));
            },
            'settings.back' => function (array $k): void {
                [$previewing] = $this->settingsEditApp('max tool steps', stage: true)->update(new KeyMsg(KeyType::Char, 's'));
                [$app] = $previewing->update($k[0]);
                $this->assertNull($app->settingsEditor?->preview);
                $this->assertSame(['maxToolSteps' => 40], $app->settingsEditor?->set, 'nothing staged is lost');
            },
            'settings.trust' => function (array $k): void {
                $config = '';
                [$asking] = $this->settingsEditApp('trustedProjectSettings', config: $config)->update(new KeyMsg(KeyType::Enter));
                [, $cmd] = $asking->update($k[0]);
                $this->assertInstanceOf(\Closure::class, $cmd);
                $cmd();
                $this->assertCount(1, (array) (json_decode((string) file_get_contents($config), true)['trustedProjectSettings'] ?? []), 'the project was granted');
            },
            'settings.discard' => function (array $k): void {
                $app = $this->settingsEditApp('max tool steps', stage: true);
                [$app] = $app->update(new KeyMsg(KeyType::Escape));
                [$asking] = $app->update(new KeyMsg(KeyType::Escape));
                $this->assertNotNull($asking->settingsEditor?->confirm, 'Esc with changes asks first');
                [$closed] = $asking->update($k[0]);
                $this->assertNull($closed->settingsEditor);
            },
            'settings.details' => function (array $k): void {
                [$detail] = $this->settingsOpenApp()->update($k[0]);
                $this->assertTrue($detail->settingsEditor?->detail, 'the details take the list\'s place');
                [$list] = $detail->update($k[0]);
                $this->assertFalse($list->settingsEditor?->detail, 'and the same key brings the list back');
            },
            // N-P5: `e` hands the terminal to $EDITOR (the Cmd is not run
            // here — it would start one), `x` / `p` open the profile prompt
            // and Enter on it runs the export or the import.
            'settings.open-file' => function (array $k): void {
                [$app, $cmd] = $this->settingsEditApp('max tool steps')->update($k[0]);
                $this->assertInstanceOf(\Closure::class, $cmd, 'the editor runs as a Cmd');
                $this->assertNull($app->settingsEditor?->status, 'nothing refused it');
            },
            'settings.export' => function (array $k): void {
                $config = '';
                $app = $this->settingsEditApp('max tool steps', config: $config);
                file_put_contents($config, '{"maxToolSteps": 33}');
                [$prompt] = $app->openSettings()->update($k[0]);
                $this->assertSame(\SugarCraft\Crush\Tui\Settings\SettingsEditor::PROFILE_EXPORT, $prompt->settingsEditor?->profileAction());
                [$done, $cmd] = $prompt->update(new KeyMsg(KeyType::Enter));
                $this->assertInstanceOf(\Closure::class, $cmd);
                [$after] = $done->update($cmd());
                $path = \dirname($config) . '/profiles/settings-profile.json';
                $this->assertSame(['maxToolSteps' => 33], json_decode((string) file_get_contents($path), true));
                $this->assertStringStartsWith('Exported 1 setting', (string) $after->settingsEditor?->status);
            },
            'settings.import' => function (array $k): void {
                $config = '';
                $app = $this->settingsEditApp('max tool steps', config: $config);
                @mkdir(\dirname($config) . '/profiles', 0o700, true);
                file_put_contents(\dirname($config) . '/profiles/settings-profile.json', '{"maxToolSteps": 12}');
                [$prompt] = $app->update($k[0]);
                $this->assertSame(\SugarCraft\Crush\Tui\Settings\SettingsEditor::PROFILE_IMPORT, $prompt->settingsEditor?->profileAction());
                [$done, $cmd] = $prompt->update(new KeyMsg(KeyType::Enter));
                [$after] = $done->update($cmd());
                $this->assertSame(['maxToolSteps' => 12], $after->settingsEditor?->set, 'the profile is staged, not written');
                $this->assertFileDoesNotExist($config);
            },
            'settings.profile-go' => function (array $k): void {
                [$prompt] = $this->settingsEditApp('max tool steps')->update(new KeyMsg(KeyType::Char, 'p'));
                [$app, $cmd] = $prompt->update($k[0]);
                $this->assertInstanceOf(\Closure::class, $cmd, 'the read is a Cmd');
                $this->assertNull($app->settingsEditor?->profileAction(), 'the prompt closes');
            },
            'settings.preview-scroll' => function (array $k): void {
                [$previewing] = $this->settingsEditApp('max tool steps', stage: true)->update(new KeyMsg(KeyType::Char, 's'));
                $this->assertNotNull($previewing->settingsEditor?->preview, 'fixture: the save preview is open');
                [$down] = $previewing->update($k[1]);
                $this->assertSame(1, $down->settingsEditor?->previewOffset, 'down scrolls the preview, not the list');
                [$up] = $down->update($k[0]);
                $this->assertSame(0, $up->settingsEditor?->previewOffset);
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
            // A docked Tools row, through the live chrome routing: the zone
            // the painted frame published, pressed and released through
            // App::update(). Both halves of "Expand or collapse".
            'mouse.side-row' => function (): void {
                \SugarCraft\Crush\Tui\Renderer::chromeScanner()->clear();
                (new \ReflectionProperty(App::class, 'chromeClickTracker'))->setValue(null, null);
                $call = Message::assistant('done')->withToolResults([ToolResult::ok('Bash', 'output', 'call-1')]);
                $dock = \SugarCraft\Layout\Dock\DockLayout::new('chat')
                    ->withSlotAdded(\SugarCraft\Layout\Dock\Side::Right, 'tools');
                [$app] = $this->app()->withDock($dock)->withChat($this->chat([$call]))
                    ->update(new \SugarCraft\Core\Msg\WindowSizeMsg(160, 40));

                $click = static function (App $app): App {
                    \SugarCraft\Crush\Tui\Renderer::renderView($app, 160, 40);
                    $zone = \SugarCraft\Crush\Tui\Renderer::chromeScanner()->get(Renderer::SIDE_ROW_ZONE_PREFIX . 'tools:call-1');
                    self::assertInstanceOf(Zone::class, $zone, 'the docked Tools row is a click target');
                    [$app] = $app->update(new MouseClickMsg($zone->startCol, $zone->startRow, MouseButton::Left, MouseAction::Press));
                    [$app] = $app->update(new MouseReleaseMsg($zone->startCol, $zone->startRow, MouseButton::Left, MouseAction::Release));

                    return $app;
                };

                $expanded = $click($app);
                $this->assertArrayHasKey('call-1', $expanded->chat?->expanded() ?? []);
                $collapsed = $click($expanded);
                $this->assertArrayNotHasKey('call-1', $collapsed->chat?->expanded() ?? [], 'a second click must collapse it');
                \SugarCraft\Crush\Tui\Renderer::chromeScanner()->clear();
            },
            // "RUN that palette row", which the old form could not see: it
            // asserted only that the click produced a different Chat, and a
            // click that merely moved the highlight does that too. The row
            // clicked is the one with an in-model effect, at whatever index the
            // current match order puts it — so this also pins that the zone
            // suffix and the match list are indexed the same way.
            // `★` pins and `✎` opens the rename — two of the three glyphs, on
            // the highlighted row's own zones.
            'mouse.session-action' => function (): void {
                $open = $this->chatWithPicker(3);
                $id = $this->highlightedOther($open);

                $pinned = $this->clickZone($open, Renderer::SESSION_ACT_ZONE_PREFIX . '0:pin');
                $this->assertSame(1, (int) $pinned->sessionStore()?->getSession($id)['pinned'], 'clicking the star pins');

                $renaming = $this->clickZone($pinned, Renderer::SESSION_ACT_ZONE_PREFIX . $pinned->sessionPicker()?->selectedIndex() . ':rename');
                $this->assertTrue($renaming->sessionPicker()?->isRenaming(), 'clicking the pencil opens the rename');
            },
            // A Task row's live line, clicked in the painted frame: the chat
            // asks its shell to open that run, and the shell does.
            'mouse.agent' => function (): void {
                $app = $this->stripApp();
                $chat = $app->chat;
                $this->assertNotNull($chat);
                Renderer::render($chat);
                $zone = Renderer::scanner()->get(Renderer::AGENT_LINE_ZONE_PREFIX . 'run-2');
                $this->assertInstanceOf(Zone::class, $zone, 'the live line is a click zone');

                [$pressed] = $chat->update(new MouseClickMsg($zone->startCol, $zone->startRow, MouseButton::Left, MouseAction::Press));
                [, $cmd] = $pressed->update(new MouseReleaseMsg($zone->startCol, $zone->startRow, MouseButton::Left, MouseAction::Release));
                $this->assertInstanceOf(\Closure::class, $cmd);

                [$opened] = $app->update($cmd());
                $this->assertSame('run-2', $opened->agentViewTarget);
            },
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

    /**
     * A Chat over a real, throwaway {@see SessionStore} holding $count rows,
     * pointed at the first of them.
     */
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

    /** The real path of the `/new` folder picker fixture's project root. */
    private string $dirPickerRoot = '';

    /**
     * A chat on a throwaway project root holding `alpha/`, `beta/` and
     * `.hidden/`, with `/new` run: the folder picker open on that root.
     */
    private function chatWithDirPicker(): Chat
    {
        $root = $this->sandbox . '/dirpicker-root';
        foreach (['alpha/inner', 'beta', '.hidden'] as $dir) {
            if (!\is_dir($root . '/' . $dir)) {
                \mkdir($root . '/' . $dir, 0o700, true);
            }
        }
        $this->dirPickerRoot = (string) \realpath($root);
        $store = new SessionStore($this->sandbox . '/sessions-' . (++$this->storeSeq) . '.db');
        $store->createSession('session-1', 'openai', 'gpt-4', null, 'Session 1');
        $chat = (new Chat(
            history: [],
            backend: new EchoBackend(),
            sessionStore: $store,
            currentSessionId: 'session-1',
            projectRoot: $this->dirPickerRoot,
        ))->withSize(100, 30);
        [$open] = $chat->runCommand('/new');
        $this->assertNotNull($open->dirPicker(), 'fixture: /new must open the folder picker');
        $this->assertSame($this->dirPickerRoot, $open->dirPicker()?->path(), 'fixture: on the project root');

        return $open;
    }

    /** Open `alpha/`, then press $key: back on the root with `alpha/` highlighted. */
    private function assertUpReturnsToTheParent(KeyMsg $key): void
    {
        $inAlpha = $this->pressAll($this->chatWithDirPicker(), [new KeyMsg(KeyType::Down), new KeyMsg(KeyType::Right)]);
        $this->assertSame($this->dirPickerRoot . '/alpha', $inAlpha->dirPicker()?->path(), 'fixture: inside alpha/');
        [$up] = $inAlpha->update($key);
        $this->assertSame($this->dirPickerRoot, $up->dirPicker()?->path());
        $this->assertSame(1, $up->dirPicker()?->selectedIndex(), 'with the directory just left highlighted');
    }

    private function chatWithPicker(int $sessions = 2): Chat
    {
        [$open] = $this->chatWithSessions($sessions)->update($this->pressLabelled('chat.session-picker'));
        $this->assertNotNull($open->sessionPicker(), 'fixture: Ctrl+R must open the picker');

        return $open;
    }

    /**
     * The highlighted picker row's id, asserted NOT to be the session on
     * screen — delete and archive refuse that one, so an observation on it
     * would see a refusal rather than the binding.
     */
    private function highlightedOther(Chat $open): string
    {
        $id = $open->sessionPicker()?->selectedSession()['sessionId'] ?? null;
        $this->assertIsString($id, 'fixture: a row must be highlighted');
        $this->assertNotSame($open->currentSessionId(), $id, 'fixture: the highlighted row must not be the current session');

        return $id;
    }

    /** Walk the picker's highlight down onto session $id. */
    private function highlight(Chat $open, string $id): Chat
    {
        for ($i = 0; $i < 10 && ($open->sessionPicker()?->selectedSession()['sessionId'] ?? null) !== $id; $i++) {
            [$open] = $open->update(new KeyMsg(KeyType::Down));
        }
        $this->assertSame($id, $open->sessionPicker()?->selectedSession()['sessionId'] ?? null, "fixture: {$id} must be reachable");

        return $open;
    }

    /** @param list<KeyMsg> $keys */
    private function pressAll(Chat $chat, array $keys): Chat
    {
        foreach ($keys as $key) {
            [$chat] = $chat->update($key);
        }

        return $chat;
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

    /**
     * An App with the settings view open on `$query`, reading a per-call
     * sandbox and saving into it (its `config.json` path comes back in
     * `$config`); `$stage` stages `maxToolSteps = 40` first.
     */
    private function settingsEditApp(string $query, bool $stage = false, ?string &$config = null): App
    {
        $dir = $this->sandbox . '/settings-keys-' . ++$this->storeSeq;
        $home = $dir . '/home/' . \SugarCraft\Crush\Config\LayeredSettings::dir();
        @mkdir($home, 0o700, true);
        @mkdir($dir . '/project', 0o700, true);
        $configPath = $home . '/config.json';
        $config = $configPath;
        $sources = static fn (): SettingsSources => SettingsSources::fromLaunch($dir . '/project', false, $home, $configPath, []);

        $app = $this->app()
            ->withRoot($dir . '/project')
            ->withSettingsSources($sources)
            ->withSettingsWriter(\SugarCraft\Crush\Config\Settings\SettingsWriter::new($configPath, static function (array $set, array $unset) use ($configPath): void {
                $data = is_file($configPath) ? (array) json_decode((string) file_get_contents($configPath), true) : [];
                file_put_contents($configPath, (string) json_encode(\SugarCraft\Crush\Config\Settings\SettingsWriter::patched($data, $set, $unset)));
            }))
            ->openSettings($query);

        return $stage ? $app->withSettingsEditor($app->settingsEditor?->stage('maxToolSteps', 40)) : $app;
    }

    /** An App whose settings view reads fixed, file-free sources. */
    private function settingsSourcedApp(): App
    {
        return $this->app()->withSettingsSources(
            static fn (): SettingsSources => SettingsSources::fromLaunch(null, null, null, null, []),
        );
    }

    private function settingsOpenApp(): App
    {
        return $this->settingsSourcedApp()->openSettings();
    }

    private function settingsSearchingApp(string $query): App
    {
        [$app] = $this->settingsOpenApp()->update(new KeyMsg(KeyType::Char, '/'));
        foreach (mb_str_split($query) as $char) {
            [$app] = $app->update(new KeyMsg(KeyType::Char, $char));
        }

        return $app;
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

    /**
     * An App over a chat whose turn runs three delegated runs (`run-1..3`
     * under Task calls `call_1..3`), live on the agents strip (P-B3).
     */
    private function stripApp(?\SugarCraft\Crush\Backend\CancellationToken $token = null, bool $finishedThird = false): App
    {
        $manager = new AgentManager($this->provider, new SkillRegistry());
        $manager->register(\SugarCraft\Crush\Tests\Support\RosterAgent::named('explore'));
        $history = [Message::user('go')];
        foreach ([1, 2, 3] as $n) {
            $history[] = Message::toolRunning(new ToolCall('Task', [], 'call_' . $n));
        }
        $chat = (new Chat(
            history: $history,
            backend: new EchoBackend(),
            inFlight: true,
            generation: 1,
            inFlightCancellation: $token ?? new \SugarCraft\Crush\Backend\CancellationToken(),
            agentManager: $manager,
        ))->withSize(120, 20);
        foreach ([1, 2, 3] as $n) {
            $started = new \SugarCraft\Crush\Events\SubAgentActivity(
                \SugarCraft\Crush\Events\SubAgentActivity::OP_STARTED,
                'run-' . $n,
                'explore',
                'a task',
                1,
                '',
                parentCallId: 'call_' . $n,
            );
            $chat->agentLive()->apply($started);
            $manager->projectRemoteSubAgent($started);
        }
        if ($finishedThird) {
            $chat->agentLive()->apply(new \SugarCraft\Crush\Events\SubAgentActivity(
                \SugarCraft\Crush\Events\SubAgentActivity::OP_FINISHED,
                'run-3',
                'explore',
                '',
                2,
                'done',
                parentCallId: 'call_3',
                outcome: \SugarCraft\Crush\Events\SubAgentActivity::OUTCOME_COMPLETE,
            ));
        }

        return $this->app()->withChat($chat);
    }

    /**
     * $app on session $session with the agent dashboard focused and run
     * $run's row selected (P-D3).
     *
     * @return array{0: App, 1: string} the app and the session
     */
    private function dashboardOn(App $app, string $run): array
    {
        $session = 'drift-dash-' . bin2hex(random_bytes(4));
        $app = $this->composing($app, $session, '')->withPane(Pane::Agents);
        foreach (\SugarCraft\Crush\Tui\Components\AgentDashboardPane::entries($app) as $index => $entry) {
            if ($entry->key === $run) {
                return [$app->withSelectedAgentIndex($index), $session];
            }
        }
        $this->fail("fixture: no dashboard row for {$run}");
    }

    /**
     * The control verbs waiting in run $run's mailbox on session $session,
     * taken (so the next read starts clean).
     *
     * @return list<string>
     */
    private function controls(string $session, string $run): array
    {
        $inbox = \SugarCraft\Crush\Agents\Live\AgentInbox::forSession($session);
        $this->assertNotNull($inbox);

        return array_map(static fn ($m): string => $m->text, $inbox->takeControls($run));
    }

    /**
     * $app with its chat on session $session and $draft typed into the box —
     * the composer fixture (P-D2). Typed key by key, so the draft is what the
     * input widget holds, not a field set behind its back.
     */
    private function composing(App $app, string $session, string $draft): App
    {
        $chat = $app->chat?->withCurrentSessionId($session);
        $this->assertNotNull($chat);
        foreach (mb_str_split($draft) as $rune) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Char, $rune));
        }
        $this->assertInstanceOf(Chat::class, $chat);

        return $app->withChat($chat);
    }

    /**
     * An App over a chat whose ONE Task call runs a batch of three runs
     * (`b-1..3` under `call_b`) — the siblings `Alt+N` / `Alt+P` walk.
     */
    private function batchApp(): App
    {
        $chat = (new Chat(
            history: [Message::user('go'), Message::toolRunning(new ToolCall('Task', [], 'call_b'))],
            backend: new EchoBackend(),
            inFlight: true,
            generation: 1,
        ))->withSize(120, 20);
        foreach ([1, 2, 3] as $n) {
            $chat->agentLive()->apply(new \SugarCraft\Crush\Events\SubAgentActivity(
                \SugarCraft\Crush\Events\SubAgentActivity::OP_STARTED,
                'b-' . $n,
                'explore',
                'a task',
                1,
                '',
                parentCallId: 'call_b',
            ));
        }

        return $this->app()->withChat($chat);
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

    /**
     * A prompt the real {@see PermissionGateHook} raised (`default` mode asks
     * for Bash) over a registered `Bash` tool — the one kind of prompt whose
     * "always" writes a grant (audit F-P9).
     */
    private function blockedOnTheGate(): Chat
    {
        $hooks = new HookManager(new HookRegistry());
        $hooks->register(new PermissionGateHook(new PermissionGate(PermissionMode::Default)));

        [$blocked] = $this->chat([Message::user('clean up')])
            ->registerTool('Bash', static fn(array $args): string => 'cleaned')
            ->withHooks($hooks)
            ->update(new AssistantMsg(Message::assistant('running')->withToolCalls([
                new ToolCall('Bash', ['command' => 'make clean'], 'call_1'),
            ])));
        $this->assertNotNull($blocked->pendingPermission(), 'fixture: the gate must ask');
        $this->assertSame(PermissionPromptStage::Armed, $blocked->permissionStage());

        return $blocked;
    }

    /** Run a released batch's Cmd to completion so its forked child is reaped. */
    private function reapReleasedBatch(?\Closure $cmd): void
    {
        $this->assertInstanceOf(\Closure::class, $cmd, 'a released batch hands back the Cmd that runs it');
        $asyncCmd = $cmd();
        $this->assertInstanceOf(\SugarCraft\Core\AsyncCmd::class, $asyncCmd);

        $loop = \React\EventLoop\Loop::get();
        $resolved = null;
        $asyncCmd->promise->then(function ($msg) use (&$resolved, $loop): void {
            $resolved = $msg;
            $loop->stop();
        });

        if ($resolved === null) {
            $safety = $loop->addTimer(10.0, static function () use ($loop): void { $loop->stop(); });
            $loop->run();
            $loop->cancelTimer($safety);
        }

        $this->assertInstanceOf(\SugarCraft\Crush\ToolResultsMsg::class, $resolved, 'the released batch did not complete');
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

    /**
     * Rows whose label is prose or a range rather than a literal chord, and
     * which therefore drive their own input. Held as an explicit list so that
     * adding one is a decision: a new row that merely LOOKS unparseable (a
     * typo, a chord spelled in a way the reference's readers would not
     * recognise) lands in {@see testEveryLabelIsALiteralChordOrADeclaredException()}
     * instead of quietly opting out of label coverage.
     */
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
        // "1…6" — AskUser's choices, a range like the slot row above. The
        // observation presses 2, the lowest a "take option 1" mis-wiring
        // would answer wrongly.
        'permission.choice',
        // Mouse gestures are not keystrokes at all.
        'mouse.wheel',
        'mouse.tab',
        'mouse.pane',
        'mouse.tool-call',
        'mouse.side-row',
        'mouse.palette-row',
        'mouse.session-action',
        'mouse.agent',
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

        // `shell.pane-prev`'s label, added the day the row was declared: the
        // bare Shift pair of Tab reaches the shell as a named key with the
        // shift flag, the same shape Ctrl+Tab takes above — and the label must
        // read back or the row could only live in HAND_DRIVEN, which would
        // leave 'Shift+Tab' itself uncovered by this suite.
        if ($token === 'Shift+Tab') {
            return new KeyMsg(KeyType::Tab, '', shift: true);
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
