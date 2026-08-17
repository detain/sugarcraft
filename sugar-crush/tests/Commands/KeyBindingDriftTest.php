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
     * Zero false positives across all 57 declared rows — and THAT is the domain
     * of the zero. It says nothing about prose not yet written; it says those 57
     * rows are clean under this pattern.
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
     * near-misses probed, because four `*.move` rows describe arrow movement, so
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
     * no `/i`, none of the named keys — turned no test red at all.
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
            // holes. Four `*.move` rows describe arrow movement, so "Down moves
            // the highlight" is the likeliest next prose regression, and the
            // glyph class [\x{2190}-\x{2193}] covers only ↑↓←→.
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
            'chat.slash-menu' => function (array $k): void {
                [$next] = $this->chat([], '/')->update($k[1]);
                $this->assertSame(1, $next->slashMenuIndex());
            },
            'chat.recall' => function (array $k): void {
                [$next] = $this->chat([Message::user('earlier')])->update($k[0]);
                $this->assertSame('earlier', $next->inputBuf);
            },
            'chat.backspace' => function (array $k): void {
                [$next] = $this->chat([], 'ab')->update($k[0]);
                $this->assertSame('a', $next->inputBuf);
            },
            'chat.word-delete' => function (array $k): void {
                [$next] = $this->chat([], 'foo bar')->update($k[0]);
                $this->assertNotSame('foo bar', $next->inputBuf);
                $this->assertStringNotContainsString('bar', $next->inputBuf);
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
            'chat.tool-output' => function (array $k): void {
                $call = Message::assistant('done')->withToolResults([ToolResult::ok('Bash', 'output', 'call-1')]);
                [$next] = $this->chat([Message::user('run it'), $call])->update($k[0]);
                $this->assertArrayHasKey('call-1', $next->expanded());
            },
            'chat.session-picker' => function (array $k): void {
                [$next] = $this->chatWithSessions(2)->update($k[0]);
                $this->assertNotNull($next->sessionPicker());
            },
            'chat.agents' => function (array $k): void {
                $chat = $this->chat();
                [$next] = $chat->update($k[0]);
                $this->assertGreaterThan(count($chat->history), count($next->history));
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
            'shell.menu' => function (array $k): void {
                $this->claim($k[0], $this->app());
                $this->assertGreaterThan(0, MenuBar::getActiveMenu());
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
            'palette.move' => function (array $k): void {
                $open = $this->chatWithPalette();
                [$moved] = $open->update($k[1]);
                $this->assertNotSame($open->palette()?->selectedIndex, $moved->palette()?->selectedIndex);
            },
            'palette.run' => function (array $k): void {
                $chat = $this->chatWithPalette();
                foreach (['e', 'x', 'i', 't'] as $char) {
                    [$chat] = $chat->update(new KeyMsg(KeyType::Char, $char));
                }
                [, $cmd] = $chat->update($k[0]);
                $this->assertNotNull($cmd, 'Enter on the palette\'s Exit row must return its Cmd');
            },
            'palette.filter' => function (): void {
                [$typed] = $this->chatWithPalette()->update(new KeyMsg(KeyType::Char, 'x'));
                $this->assertSame('x', $typed->palette()?->query);
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
            'picker.preview' => function (array $k): void {
                $open = $this->chatWithPicker();
                [$previewed] = $open->update($k[0]);
                $this->assertNotSame($open, $previewed, 'Space must be answered, not ignored');
                $this->assertNotNull($previewed->sessionPicker(), 'and must leave the picker up');
            },
            'picker.branch' => function (array $k): void {
                $open = $this->chatWithPicker();
                [$filtered] = $open->update($k[0]);
                $this->assertNotSame($open, $filtered, 'Ctrl+B must be answered, not ignored');
                $this->assertNotNull($filtered->sessionPicker());
            },
            'picker.close' => function (array $k): void {
                [$closed] = $this->chatWithPicker()->update($k[0]);
                $this->assertNull($closed->sessionPicker());
            },

            // ── Permission prompt ────────────────────────────────────────
            'permission.once' => fn(array $k) => $this->assertPermissionAnsweredBy($k[0]),
            'permission.always' => fn(array $k) => $this->assertPermissionAnsweredBy($k[0]),
            'permission.deny' => fn(array $k) => $this->assertPermissionAnsweredBy($k[0]),

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
            'agents.back' => function (array $k): void {
                [$app] = $this->claim($k[0], $this->agentApp(1)->withSelectedAgentIndex(0));
                $this->assertSame(-1, $app->selectedAgentIndex);
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
            'menu.switch' => function (array $k): void {
                MenuBar::openMenu(1);
                $this->claim($k[1], $this->app());
                $this->assertNotSame(1, MenuBar::getActiveMenu());
                $this->assertGreaterThan(0, MenuBar::getActiveMenu());
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
            'mouse.tool-call' => function (): void {
                $call = Message::assistant('done')->withToolResults([ToolResult::ok('Bash', 'output', 'call-1')]);
                $chat = $this->chat([Message::user('run it'), $call]);
                $after = $this->clickZone($chat, Renderer::TOOL_CALL_ZONE_PREFIX . 'call-1');
                $this->assertArrayHasKey('call-1', $after->expanded());
            },
            'mouse.palette-row' => function (): void {
                $chat = $this->chatWithPalette();
                $after = $this->clickZone($chat, Renderer::PALETTE_ITEM_ZONE_PREFIX . '0');
                $this->assertNotSame($chat, $after, 'clicking a row must run it, not be dropped');
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
     * Open menu $menu at its first row and report how many rows it has.
     *
     * Closed first because {@see MenuBar::openMenu()} TOGGLES, and it is the
     * call that resets the row cursor to 0 — an observation pressing two keys
     * has to start each from the same place.
     */
    private function openMenuRowCount(int $menu): int
    {
        MenuBar::closeMenu();
        MenuBar::openMenu($menu);
        $this->assertSame($menu, MenuBar::getActiveMenu(), 'fixture: the menu must be open');
        $this->assertSame(0, $this->activeMenuItem(), 'fixture: the row cursor must start at the top');

        /** @var list<string> $rows */
        $rows = (new \ReflectionMethod(MenuBar::class, 'itemsOf'))->invoke(null, $menu);

        return count($rows);
    }

    private function chatWithPalette(): Chat
    {
        [$open] = $this->chat()->update($this->pressLabelled('chat.palette'));
        $this->assertNotNull($open->palette(), 'fixture: Ctrl+P must open the palette');

        return $open;
    }

    /**
     * The single keystroke another row's label names — for the fixtures that
     * have to get INTO an overlay before the row under test can be pressed.
     * Read from the registry rather than hardcoded so the setup cannot go on
     * working after the documented way in has changed.
     */
    private function pressLabelled(string $id): KeyMsg
    {
        $keys = self::chord(KeyBindingRegistry::byId($id)?->keys ?? '');
        $this->assertCount(1, $keys, "'{$id}' must name exactly one chord to be used as setup");

        return $keys[0];
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
        [$blocked] = $this->chat([Message::user('clean up')])->update(new PermissionRequestMsg(
            Message::assistant(''),
            new ToolCall('Bash', ['description' => 'Delete build/'], 'call_1'),
            'Run rm -rf build/?',
        ));
        $this->assertNotNull($blocked->pendingPermission(), 'fixture: the prompt must be up');

        [$answered] = $blocked->update($key);
        $this->assertNull($answered->pendingPermission(), "'{$key->string()}' must answer the prompt");
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
     * The process-wide state the handlers keep outside any model: MenuBar's
     * open menu and the renderer's click-zone scan.
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
    private const HAND_DRIVEN = [
        // "any text" — the palette filter answers every printable character,
        // so there is no single chord to press.
        'palette.filter',
        // "Alt+1…9" — a range. The observation presses Alt+2 from its middle.
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
            return match (true) {
                $rest === 'Tab' => new KeyMsg(KeyType::Tab, '', ctrl: true),
                $rest === 'Shift+Tab' => new KeyMsg(KeyType::Tab, '', ctrl: true, shift: true),
                mb_strlen($rest) === 1 => self::ctrl(mb_strtolower($rest)),
                default => null,
            };
        }

        if (str_starts_with($token, 'Alt+')) {
            $rest = substr($token, 4);
            $named = ['Enter' => KeyType::Enter, 'Backspace' => KeyType::Backspace];

            if (isset($named[$rest])) {
                return new KeyMsg($named[$rest], '', alt: true);
            }

            return mb_strlen($rest) === 1 ? new KeyMsg(KeyType::Char, $rest, alt: true) : null;
        }

        $named = [
            'Enter' => KeyType::Enter,
            'Esc' => KeyType::Escape,
            'Tab' => KeyType::Tab,
            'Backspace' => KeyType::Backspace,
            'Space' => KeyType::Space,
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

        return mb_strlen($token) === 1 ? new KeyMsg(KeyType::Char, $token) : null;
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

    private function removeTree(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/*') ?: [] as $path) {
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
