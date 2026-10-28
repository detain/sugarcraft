<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Code\Attachments\MentionIndex;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Config\Settings\SessionSettings;
use SugarCraft\Code\Config\Settings\UiSettings;
use SugarCraft\Code\Tui\DirectoryPicker\DirectoryPicker;
use SugarCraft\Code\Tui\HistorySearch;
use SugarCraft\Code\Tui\SessionPicker;
use SugarCraft\Forms\TextInput\TextInput;

/**
 * End-to-end pins for the vim-mode composer (plan_comparison.md §3 TUI-3
 * Cut A): the user-tier `vimMode` setting, Chat's three wiring arms around
 * the sugar-forms TextArea Vim layer (w5f), and the app-side operator state
 * in `ComposerVim`.
 *
 * The pure operator semantics live in {@see \SugarCraft\Code\Tests\Tui\ComposerVimTest};
 * these tests prove the WIRING contract instead: with the setting off the
 * dispatch is byte-identical to the pre-vim Chat (dormant zero-risk),
 * enabling costs exactly one keystroke (the sync arm re-arms the widget),
 * the minimum key set works through `Chat::update()` alone, and every
 * owns-predicate on the key path — the palette, the pickers, the popups,
 * the queue, Escape — keeps owning its keys with a vim operator pending.
 *
 * Every key goes through the real `Chat::update()` with decoded `KeyMsg`s;
 * no internals are poked. The widget is armed lazily, so the first key
 * after `new Chat(...)` is the sync keystroke and never reaches grammar.
 */
final class ComposerVimModeTest extends TestCase
{
    protected function setUp(): void
    {
        SessionSettings::apply(['vimMode' => true]);
    }

    protected function tearDown(): void
    {
        SessionSettings::reset();
        UiSettings::forget();
    }

    private function k(string $rune, bool $ctrl = false, bool $alt = false): KeyMsg
    {
        return new KeyMsg(KeyType::Char, $rune, alt: $alt, ctrl: $ctrl);
    }

    private function km(KeyType $type): KeyMsg
    {
        return new KeyMsg($type);
    }

    /** Feed keys through update(), returning the settled Chat. */
    private function drive(Chat $chat, KeyMsg ...$keys): Chat
    {
        foreach ($keys as $key) {
            [$chat] = $chat->update($key);
        }

        return $chat;
    }

    /** Chat whose composer widget is vim-armed: consumes the sync keystroke. */
    private function armed(Chat $chat): Chat
    {
        $settled = $this->drive($chat, $this->k('q'));
        self::assertTrue($settled->input->vimMode, 'the sync arm must have re-armed the widget');

        return $settled;
    }

    /** @return array{0:string,1:int,2:int,3=bool} value, row, col, pending-d? */
    private function st(Chat $chat): array
    {
        return [
            $chat->input->value(),
            $chat->input->row,
            $chat->input->col,
            $chat->composerVim->pending !== null,
        ];
    }

    private function lastEscapeAt(Chat $chat): float|null
    {
        $property = new \ReflectionProperty(Chat::class, 'lastEscapeAt');
        $property->setAccessible(true);

        return $property->getValue($chat);
    }

    public function testADefaultOffTreeIsByteIdenticalDormant(): void
    {
        SessionSettings::apply(['vimMode' => false]);

        $chat = $this->drive(new Chat(inputBuf: 'alpha'), $this->k('d'), $this->k('x'));

        // Unchanged from the pre-vim Chat: both letters type at the caret,
        // nothing arms, the widget is not even in vim mode.
        self::assertSame(['alphadx', 0, 7, false], $this->st($chat));
        self::assertFalse($chat->input->vimMode);
        self::assertNull($chat->composerVim->pending);
    }

    public function testEnablingConsumesExactlyOneKeystrokeThenGrammarArms(): void
    {
        SessionSettings::apply(['vimMode' => false]);
        $off = $this->drive(new Chat(inputBuf: 'alpha'), $this->k('d'));
        self::assertSame('alphad', $off->input->value(), 'dormant until the sync arm lands');
        self::assertFalse($off->input->vimMode);

        SessionSettings::apply(['vimMode' => true]);
        $sync = $this->drive($off, $this->k('x'));
        self::assertSame('alphad', $sync->input->value(), 'the toggle key is eaten by the re-arm');
        self::assertTrue($sync->input->vimMode);

        $armed = $this->drive($sync, $this->k('d'));
        self::assertSame('alphad', $armed->input->value(), 'a claimed d now arms instead of typing');
        self::assertSame('d', $armed->composerVim->pending);
    }

    public function testTheInsertFamilyWalksThroughRealKeys(): void
    {
        $chat = $this->armed(new Chat(inputBuf: 'alpha beta'));
        self::assertSame([0, 10], [$chat->input->row, $chat->input->col], 'a fresh draft starts at the end');

        $chat = $this->drive($chat, $this->k('0'));
        self::assertSame([0, 0], [$chat->input->row, $chat->input->col]);

        $chat = $this->drive($chat, $this->k('i'), $this->k('z'), $this->km(KeyType::Escape));
        self::assertSame('zalpha beta', $chat->input->value());
        self::assertSame([0, 1], [$chat->input->row, $chat->input->col], 'Esc stops on the cell after the typed text');

        $chat = $this->drive($chat, $this->k('a'), $this->k('y'), $this->km(KeyType::Escape));
        self::assertSame('zaylpha beta', $chat->input->value(), 'a types AFTER the caret cell');

        $chat = $this->drive($chat, $this->k('I'), $this->k('1'), $this->km(KeyType::Escape));
        self::assertSame('1zaylpha beta', $chat->input->value(), 'I inserts at the first glyph');

        $chat = $this->drive($chat, $this->k('A'), $this->k('2'), $this->km(KeyType::Escape));
        self::assertSame('1zaylpha beta2', $chat->input->value(), 'A appends at the line end');

        self::assertSame(
            \SugarCraft\Forms\Vim\VimState::Normal,
            $chat->input->vimState,
            'every Esc above returned the widget to Normal'
        );
    }

    public function testOpenLineEntersInsertWithAnEmptyLineBeneath(): void
    {
        $base = $this->armed(new Chat(inputBuf: "one\ntwo")); // caret (1,3)
        $top = $this->drive($base, $this->k('0'));
        self::assertSame([1, 0], [$top->input->row, $top->input->col], '0 lands at the row start (Home is the lib-swallows list)');

        $chat = $this->drive($top, $this->k('o'), $this->k('h'), $this->k('i'), $this->km(KeyType::Escape));
        self::assertSame("one\ntwo\nhi", $chat->input->value());

        $chat = $this->drive($chat, $this->k('O'), $this->k('x'), $this->km(KeyType::Escape));
        self::assertSame("one\ntwo\nx\nhi", $chat->input->value(), 'O opens above the caret row');
        self::assertSame(\SugarCraft\Forms\Vim\VimState::Normal, $chat->input->vimState);
    }

    public function testCharacterMotionsAndDeletionsDriveTheDraft(): void
    {
        $base = $this->armed(new Chat(inputBuf: 'alpha beta')); // caret (0,10)
        $head = $this->drive($base, $this->k('0'));
        self::assertSame(0, $head->input->col, '0 lands the caret at the row start');

        $left = $this->drive($base, $this->k('h'));
        self::assertSame(9, $left->input->col, 'h steps left from the last cell');
        self::assertSame(10, $this->drive($left, $this->k('l'))->input->col, 'l steps right back');

        self::assertSame(0, $this->drive($base, $this->km(KeyType::Up))->input->row, 'single-row Up stays put (k too)');
        self::assertSame(6, $this->drive($head, $this->k('w'))->input->col, 'w is the LIBS motion, answered inside the widget');

        $wordEnd = $this->drive($this->drive($base, $this->k('0')), $this->k('w'));
        self::assertSame(10, $this->drive($wordEnd, $this->k('e'))->input->col, 'bare e walks to the next word end');
        self::assertSame(0, $this->drive($head, $this->k('b'))->input->col, 'b at the row start refuses to move');
        self::assertSame(6, $this->drive($head, $this->k('$'), $this->k('b'))->input->col, 'b from EOL lands on the last word');

        $x = $this->drive($base, $this->k('h'), $this->k('x'));
        self::assertSame('alpha bet', $x->input->value(), 'x deletes under the caret, lib-side');
        self::assertSame('alpha beta', $this->drive($base, $this->k('x'))->input->value(), 'x at EOL refuses (the cell under the caret does not exist)');
        self::assertSame(10, $this->drive($head, $this->k('$'))->input->col);
    }

    public function testDeleteOperatorsUseThePendingStateEndToEnd(): void
    {
        $base = $this->armed(new Chat(inputBuf: 'alpha beta'));

        self::assertSame(['beta', 0, 0, false], $this->st($this->drive($base, $this->k('0'), $this->k('d'), $this->k('w'))), 'dw cuts to the next word');
        self::assertSame(['lpha beta', 0, 0, false], $this->st($this->drive($base, $this->k('0'), $this->k('d'), $this->k('l'))), 'dl kills one cell');

        $wordEnd = $this->drive($base, $this->k('0'), $this->k('d'), $this->k('e'));
        self::assertSame([' beta', 0, 0, false], $this->st($wordEnd), 'de kills the word the caret sits in');
        self::assertSame('alpha', $wordEnd->composerVim->register);

        self::assertSame(['', 0, 0, false], $this->st($this->drive($base, $this->k('0'), $this->k('d'), $this->k('$'))), 'd$ empties the row');

        $back = $this->drive($base, $this->k('d'), $this->k('b'));
        self::assertSame(['alpha ', 0, 6, false], $this->st($back), 'db from the end kills the last word');
        self::assertSame('beta', $back->composerVim->register);

        $toEnd = $this->drive($base, $this->k('h'), $this->k('D'));
        self::assertSame('alpha bet', $toEnd->input->value(), 'D is d$ without the second keystroke');
        self::assertSame('a', $toEnd->composerVim->register);
    }

    public function testYankAndCharwisePasteCompleteTheGrammarEndToEnd(): void
    {
        $base = $this->armed(new Chat(inputBuf: 'alpha beta'));

        $yanked = $this->drive($base, $this->k('0'), $this->k('y'), $this->k('w'));
        self::assertSame('alpha beta', $yanked->input->value(), 'yank edits nothing');
        self::assertSame(0, $yanked->input->col, '…and the motion the span borrowed is undone');
        self::assertSame('alpha ', $yanked->composerVim->register);
        self::assertFalse($yanked->composerVim->registerLinewise);

        $pasted = $this->drive($yanked, $this->k('$'), $this->k('p'));
        self::assertSame('alpha betaalpha ', $pasted->input->value(), 'p re-inserts the register at the caret');
        self::assertSame(15, $pasted->input->col);

        $before = $this->drive($yanked, $this->k('P'));
        self::assertSame('alpha alpha beta', $before->input->value(), 'P splices at the caret cell');
    }

    public function testLinelikeOperatorsAndPasteRoundTripRows(): void
    {
        $base = $this->armed(new Chat(inputBuf: "one\ntwo\nthree")); // caret (2,5)

        $deleted = $this->drive($base, $this->k('d'), $this->k('d'));
        self::assertSame(["one\ntwo", 1, 0, false], $this->st($deleted), 'dd at the last row kills it, caret clamps onto the survivor');
        self::assertSame('three', $deleted->composerVim->register);
        self::assertTrue($deleted->composerVim->registerLinewise);

        $below = $this->drive($deleted, $this->k('p'));
        self::assertSame("one\ntwo\nthree", $below->input->value(), 'linewise p restores a whole row');
        self::assertSame([2, 0], [$below->input->row, $below->input->col]);

        $above = $this->drive($deleted, $this->k('P'));
        self::assertSame("one\nthree\ntwo", $above->input->value(), 'linewise P lands above the caret row');

        $killed = $this->drive($deleted, $this->k('d'), $this->km(KeyType::Enter));
        self::assertSame('one', $killed->input->value(), 'd<CR> is dd spelled with Enter');

        $yanked = $this->drive($deleted, $this->k('0'), $this->k('Y'), $this->k('j'), $this->k('p'));
        self::assertSame('two', $deleted->input->lines[1], 'sanity: caret row is two');
        self::assertSame("one\ntwo\ntwo", $yanked->input->value(), 'Y linewise, j clamps at the last row, p pastes below it');
    }

    public function testVisualModeEntersAndEscapes(): void
    {
        $chat = $this->drive($this->armed(new Chat(inputBuf: 'alpha beta')), $this->k('0'), $this->k('v'), $this->k('w'));
        self::assertSame(\SugarCraft\Forms\Vim\VimState::Visual, $chat->input->vimState);
        self::assertTrue($chat->input->hasSelection());

        $escaped = $this->drive($chat, $this->km(KeyType::Escape));
        self::assertSame(\SugarCraft\Forms\Vim\VimState::Normal, $escaped->input->vimState);
        self::assertFalse($escaped->input->hasSelection());
        self::assertSame('alpha beta', $escaped->input->value());
    }

    public function testUnknownRunesBEEPAndNeverType(): void
    {
        $chat = $this->drive($this->armed(new Chat(inputBuf: 'alpha')), $this->k('q'));
        self::assertSame('alpha', $chat->input->value(), 'Normal mode cannot type an unbound letter');
        self::assertNull($chat->composerVim->pending);

        $half = $this->drive($chat, $this->k('d'), $this->k('!'));
        self::assertSame('alpha', $half->input->value());
        self::assertNull($half->composerVim->pending, 'the unknown continuation cancels the operator');
    }

    public function testEscapeExitsInsertButNormalEscapeKeepsTheTurnCancelMeaning(): void
    {
        $chat = $this->drive($this->armed(new Chat(inputBuf: 'alpha')), $this->k('i'), $this->k('z'));
        self::assertSame(\SugarCraft\Forms\Vim\VimState::Insert, $chat->input->vimState);

        $out = $this->drive($chat, $this->km(KeyType::Escape));
        self::assertSame(\SugarCraft\Forms\Vim\VimState::Normal, $out->input->vimState, 'the relay delivers Esc to the widget');
        self::assertNull($this->lastEscapeAt($out), 'leaving Insert is not a turn-cancel');

        $normal = $this->armed(new Chat(inputBuf: 'alpha'));
        self::assertNull($this->lastEscapeAt($normal));
        $cancelled = $this->drive($normal, $this->km(KeyType::Escape));
        self::assertSame(
            \SugarCraft\Forms\Vim\VimState::Normal,
            $cancelled->input->vimState,
            'Normal-state Esc is byte-identical to the pre-vim Chat: the widget never sees it'
        );
        self::assertSame('alpha', $cancelled->input->value());
    }

    public function testAPendingOperatorLetsTabPassToTheQueueOwner(): void
    {
        $chat = $this->drive($this->armed(new Chat(inputBuf: 'x')), $this->k('d'));
        self::assertSame('d', $chat->composerVim->pending);
        self::assertFalse($chat->queueOwnsTab(), 'the queue only owns Tab mid-turn; the reroute shape is identical either way');

        $settled = $this->drive($chat, $this->km(KeyType::Tab));
        self::assertNull($settled->composerVim->pending, 'a forgotten d must not hold Tab hostage');
        self::assertSame('x', $settled->input->value());
    }

    public function testAPendingOperatorIsForgottenByEscapeWithoutStealingTheTurnCancelArm(): void
    {
        $chat = $this->drive($this->armed(new Chat(inputBuf: 'alpha')), $this->k('d'));
        $settled = $this->drive($chat, $this->km(KeyType::Escape));

        self::assertNull($settled->composerVim->pending);
        self::assertSame('alpha', $settled->input->value());
    }

    public function testModalsOwnTheirKeysAheadOfTheComposerGrammar(): void
    {
        $session = $this->drive(new Chat(inputBuf: '/compa', sessionPicker: SessionPicker::new([])), $this->k('0'), $this->k('d'));
        self::assertNull($session->composerVim->pending, 'the picker owns the keys; nothing arms behind it');
        self::assertSame('/compa', $session->input->value());

        $dir = $this->drive(new Chat(dirPicker: DirectoryPicker::open(sys_get_temp_dir())), $this->k('0'), $this->k('o'));
        self::assertNull($dir->composerVim->pending);

        $title = $this->drive(new Chat(inputBuf: 'alpha', titleEditor: TextInput::new()), $this->k('0'), $this->k('d'));
        self::assertNull($title->composerVim->pending);
        self::assertSame('alpha', $title->input->value());

        $history = $this->drive(new Chat(inputBuf: 'seed', historySearch: HistorySearch::open(['older'], 'seed', 4)), $this->k('0'), $this->k('d'));
        self::assertNull($history->composerVim->pending);
    }

    public function testAPaletteQueryKeepsTheLetterOutOfTheOperator(): void
    {
        $chat = $this->armed(new Chat(inputBuf: 'alpha'));
        $opened = $this->drive($chat, $this->k('p', ctrl: true));
        self::assertNotNull($opened->palette(), 'Ctrl+P opens the palette as before');

        $typed = $this->drive($opened, $this->k('d'));
        self::assertNotNull($typed->palette());
        self::assertSame('d', $typed->palette()->query, 'the palette is typing-owns-keys, vim stands down');
        self::assertNull($typed->composerVim->pending);
    }

    public function testAVisibleFilePopupKeepsTheOperatorAsleepAndEnterCompleting(): void
    {
        MentionIndex::forget();
        $root = sys_get_temp_dir() . '/w6b-vim-popup-' . uniqid('p', true);
        mkdir($root . '/src', recursive: true);
        exec('git init --quiet ' . escapeshellarg($root) . ' 2>&1');
        foreach (['src/Chat.php', 'src/Renderer.php', 'index.php'] as $file) {
            touch($root . '/' . $file);
        }

        try {
            $base = $this->armed(new Chat(inputBuf: '@cha', backend: new EchoBackend(), projectRoot: $root));
            self::assertTrue($base->mentionPopupOpen(), 'sanity: the @ popup is up');

            $typed = $this->drive($base, $this->k('d'));
            self::assertTrue($typed->mentionPopupOpen(), 'a d is typing material while the popup is up');
            self::assertSame('@cha', $typed->input->value());
            self::assertNull($typed->composerVim->pending, 'arm-2 stands down behind the file popup');

            $killed = $this->drive($base, $this->k('d'), $this->km(KeyType::Enter));
            self::assertSame('@src/Chat.php ', $killed->input->value(), 'Enter still ACCEPTS the highlighted row, not kills the line');
            self::assertFalse($killed->mentionPopupOpen());
        } finally {
            MentionIndex::forget();
            exec('rm -rf ' . escapeshellarg($root) . ' 2>&1');
        }
    }

    public function testAVisibleSlashMenuKeepsTheOperatorAsleepAndEnterCompleting(): void
    {
        $base = $this->armed(new Chat(inputBuf: '/compa'));
        self::assertNotSame([], $base->slashMenuMatches(), 'sanity: the menu is up over /compa');

        $typed = $this->drive($base, $this->k('d'));
        self::assertNull($typed->composerVim->pending, 'the claim layer stands down while the menu paints');
        self::assertSame('/compa', $typed->input->value(), 'and the lib beeps the letter away rather than typing');

        $completed = $this->drive($base, $this->k('d'), $this->km(KeyType::Enter));
        self::assertStringStartsWith('/compa', $completed->input->value(), 'Enter still belongs to the menu (completing, never killing)');
        self::assertStringNotContainsString("\n", $completed->input->value(), 'never the d<CR> line-kill');
    }

    public function testCountDigitsAreClaimedInNormalButTypedinInsertAndSidelinedBehindTheMenu(): void
    {
        $normal = $this->drive($this->armed(new Chat(inputBuf: 'x')), $this->k('3'));
        self::assertSame('x', $normal->input->value(), 'a NORMAL digit arms a count instead of typing itself');
        self::assertTrue($normal->composerVim->isArmed());

        $insert = $this->drive($this->armed(new Chat(inputBuf: 'ab')), $this->k('i'), $this->k('3'));
        self::assertSame('ab3', $insert->input->value(), 'INSERT owns every keystroke verbatim, digits included');
        self::assertFalse($insert->composerVim->isArmed());

        $menu = $this->drive($this->armed(new Chat(inputBuf: '/compa')), $this->k('3'));
        self::assertNotSame([], $menu->slashMenuMatches(), 'sanity: the menu is up');
        self::assertFalse($menu->composerVim->isArmed(), 'a visible menu claims the keys — digits included — first');
        self::assertSame('/compa', $menu->input->value());
    }

    public function testTheChangeOperatorLandsInTheLibsInsertStateThroughChat(): void
    {
        $chat = $this->drive($this->armed(new Chat(inputBuf: 'alpha beta')), $this->k('0'), $this->k('c'), $this->k('w'));
        self::assertSame(' beta', $chat->input->value(), 'cw kills the word body like ce');
        self::assertSame(\SugarCraft\Forms\Vim\VimState::Insert, $chat->input->vimState, 'c = the d kill then Insert, fed through the lib');
        self::assertFalse($chat->composerVim->isArmed());
    }

    public function testAForgottenCountDoesNotHoldTabHostage(): void
    {
        $chat = $this->drive($this->armed(new Chat(inputBuf: 'x')), $this->k('3'));
        self::assertTrue($chat->composerVim->isArmed(), 'sanity: the count is armed');

        $settled = $this->drive($chat, $this->km(KeyType::Tab));
        self::assertFalse($settled->composerVim->isArmed(), 'a forgotten count is dropped for Tab exactly like a forgotten d');
        self::assertSame('x', $settled->input->value());
    }
}
