<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\App\InitialPromptMsg;
use SugarCraft\Code\Session\PromptHistory;
use SugarCraft\Code\Support\AiCommentWatcher;
use SugarCraft\Code\Theme;
use SugarCraft\Code\Tui\HistorySearch;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\PasteMsg;

/**
 * plan_comparison TUI-2: the Ctrl+G reverse history search — the widget's
 * filter/cycle/seed rules, and `Chat`'s modal handling of the chord (open,
 * route, restore, and the two seams the plan names: Escape must close the
 * overlay BEFORE the stop-binding two-beat arms, and the mention popup must
 * stand down while the search owns the keys).
 */
final class HistorySearchTest extends TestCase
{
    private string $historyPath = '';

    protected function setUp(): void
    {
        $this->historyPath = (string) \tempnam(\sys_get_temp_dir(), 'w2b-history-');
    }

    protected function tearDown(): void
    {
        @\unlink($this->historyPath);
    }

    public function testTheChordOpensTheSearchOverTheStoredPromptsNewestFirst(): void
    {
        $chat = $this->searchableChat();

        [$open] = $chat->update($this->ctrlG());

        $search = $open->historySearch();
        $this->assertNotNull($search, 'Ctrl+G must open the history search');
        $this->assertSame(
            ['fix the lexer', 'wrap up the report', 'fix the parser'],
            $search->matches(),
            'rows are newest-first',
        );
    }

    public function testNothingOpensWhenThereIsNoHistoryYet(): void
    {
        $chat = new Chat(backend: new EchoBackend());

        [$open] = $chat->update($this->ctrlG());

        $this->assertNull($open->historySearch(), 'an empty history must not open an empty overlay');
    }

    public function testTypingFiltersByCaseInsensitiveContainmentAndResetsTheHighlight(): void
    {
        $chat = $this->searchableChat();
        [$open] = $chat->update($this->ctrlG());
        [$down] = $open->update(new KeyMsg(KeyType::Down));
        $this->assertSame(1, $down->historySearch()?->selectedIndex(), 'fixture: moved off the top');

        [$f] = $down->update(new KeyMsg(KeyType::Char, 'F'));
        [$fi] = $f->update(new KeyMsg(KeyType::Char, 'i'));
        [$fix] = $fi->update(new KeyMsg(KeyType::Char, 'x'));

        $search = $fix->historySearch();
        $this->assertNotNull($search);
        $this->assertSame(['fix the lexer', 'fix the parser'], $search->matches());
        $this->assertSame(0, $search->selectedIndex(), 'a new query restarts at the newest match');
    }

    public function testRepeatedPressesCycleTheMatchesAndWrap(): void
    {
        $chat = $this->searchableChat();
        [$one] = $chat->update($this->ctrlG());

        [$two] = $one->update($this->ctrlG());
        [$three] = $two->update($this->ctrlG());
        [$wrapped] = $three->update($this->ctrlG());

        $this->assertSame(1, $two->historySearch()?->selectedIndex());
        $this->assertSame(2, $three->historySearch()?->selectedIndex());
        $this->assertSame(0, $wrapped->historySearch()?->selectedIndex(), 'the cycle wraps');
    }

    public function testEnterAcceptsIntoTheDraftWithoutSubmitting(): void
    {
        $chat = $this->searchableChat();
        [$open] = $chat->update($this->ctrlG());

        [$done] = $open->update(new KeyMsg(KeyType::Enter));

        $this->assertNull($done->historySearch());
        $this->assertSame('fix the lexer', $done->inputBuf, 'the highlighted entry lands in the composer');
        $this->assertCount(\count($chat->history), $done->history, 'accepting must not start a turn');
    }

    public function testEscapeRestoresTheSeedDraftAndCursorAndDoesNotArmTheStopTwoBeat(): void
    {
        $seed = 'mid-sentence ';
        $chat = $this->searchableChat($seed);

        [$open] = $chat->update($this->ctrlG());
        [$typed] = $open->update(new KeyMsg(KeyType::Char, 'z'));
        [$back] = $typed->update(new KeyMsg(KeyType::Escape));

        $this->assertNull($back->historySearch());
        $this->assertSame($seed, $back->inputBuf, 'Escape restores the draft the search was opened on');
        $this->assertSame(\mb_strlen($seed, 'UTF-8'), $back->inputCursorOffset(), 'and its cursor');
        $this->assertNull(
            $this->readLastEscapeStamp($back),
            'the closing Escape belongs to the overlay: it must not touch the stop binding\'s stamp',
        );
    }

    public function testTheMentionPopupStandsDownWhileTheSearchOwnsTheKeys(): void
    {
        $draft = '@src/Chat';
        $chat = $this->searchableChat($draft);
        $this->assertTrue($chat->mentionPopupOpen(), 'fixture: the @ token raises the popup when nothing is up');

        [$open] = $chat->update($this->ctrlG());
        $this->assertNotNull($open->historySearch());
        $this->assertFalse($open->mentionPopupOpen(), 'the search owns the keys: the popup stands down');

        [$tabbed] = $open->update(new KeyMsg(KeyType::Tab));
        $this->assertSame($draft, $tabbed->inputBuf, 'Tab is swallowed while the search is up');

        [$closed] = $tabbed->update(new KeyMsg(KeyType::Escape));
        $this->assertTrue($closed->mentionPopupOpen(), 'with the search gone the popup returns');
    }

    public function testTheOverlayRendersInsideItsGeometryAndCarriesTheRows(): void
    {
        $search = HistorySearch::open(['newest entry', 'older entry'], '', 0);
        $rendered = $search->render(60, 16, Theme::default());

        $this->assertStringContainsString('newest entry', $rendered);
        $this->assertStringContainsString('History search', $rendered);
        foreach (explode("\n", $rendered) as $line) {
            $cells = (string) \preg_replace('/\x1b\[[0-9;]*m/', '', $line);
            $this->assertLessThanOrEqual(
                76,
                \mb_strwidth($cells, 'UTF-8'),
                'a paint row exceeds the geometry ceiling: ' . $line,
            );
        }
    }

    public function testSpaceIsPartOfTheQueryAndBackspaceShrinksIt(): void
    {
        $chat = $this->searchableChat();
        [$open] = $chat->update($this->ctrlG());

        $typed = $open;
        foreach (['t', 'h', 'e'] as $letter) {
            [$typed] = $typed->update(new KeyMsg(KeyType::Char, $letter));
        }
        $this->assertCount(3, $typed->historySearch()?->matches(), 'fixture: every entry holds "the"');

        [$spaced] = $typed->update(new KeyMsg(KeyType::Space));
        $this->assertSame('the ', $spaced->historySearch()?->query(), 'the space is part of the query');
        $this->assertCount(3, $spaced->historySearch()?->matches());

        [$worded] = $spaced->update(new KeyMsg(KeyType::Char, 'r'));
        $this->assertSame(
            ['wrap up the report'],
            $worded->historySearch()?->matches(),
            'the typed space narrows: "the r" only matches the report',
        );

        [$erased] = $worded->update(new KeyMsg(KeyType::Backspace));
        $this->assertSame('the ', $erased->historySearch()?->query());
        $this->assertCount(3, $erased->historySearch()?->matches());
    }

    public function testEnterOnAnEmptyResultSetChangesNothing(): void
    {
        $chat = $this->searchableChat();
        [$open] = $chat->update($this->ctrlG());
        [$q] = $open->update(new KeyMsg(KeyType::Char, 'q'));
        [$qz] = $q->update(new KeyMsg(KeyType::Char, 'z'));
        [$qzz] = $qz->update(new KeyMsg(KeyType::Char, 'z'));

        $this->assertSame([], $qzz->historySearch()?->matches() ?? ['not empty'], 'fixture: no match');

        [$kept] = $qzz->update(new KeyMsg(KeyType::Enter));

        $this->assertNotNull($kept->historySearch(), 'Enter with nothing to accept leaves the search open');
        $this->assertSame('', $kept->inputBuf, 'and touches no draft');
    }

    private function searchableChat(string $draft = ''): Chat
    {
        $history = new PromptHistory($this->historyPath);
        $history->append('fix the parser');
        $history->append('wrap up the report');
        $history->append('fix the lexer');

        return (new Chat(
            inputBuf: $draft,
            backend: new EchoBackend(),
            promptHistory: $history,
            projectRoot: \getcwd(),
        ))->withSize(100, 30);
    }

    public function testAPasteLandsInSearchQueryAndNeverInTheDraftBehindIt(): void
    {
        $chat = $this->searchableChat('mid-sentence ');
        [$searching] = $chat->update($this->ctrlG());
        $this->assertNotNull($searching->historySearch(), 'fixture: the search is up');

        // A bracketed paste arrives on its own Msg channel, beside the key
        // routing the overlay owns — without the intercept at the PasteMsg
        // arm it would type into the draft the search is only borrowing.
        [$pasted] = $searching->update(new PasteMsg("wrap up\nthe report"));

        $this->assertSame('wrap up the report', $pasted->historySearch()?->query(), 'the paste joins the query on one line');
        $this->assertSame(['wrap up the report'], $pasted->historySearch()?->matches());
        $this->assertSame('mid-sentence ', $pasted->inputBuf, 'the draft behind the overlay is untouched');
    }

    public function testAnIncomingWatcherPromptDoesNotSubmitBeneathTheOpenSearch(): void
    {
        // rev-w2b MAJOR: with the search up, the overlay owns the keys, but
        // an AiCommentWatcher prompt arriving as an InitialPromptMsg was
        // submitted as a LIVE turn — inFlight=true while the overlay held
        // the keyboard. The session-picker bail at submitInitialPrompt()
        // gains the same shape: the words wait in the draft, no turn starts.
        $chat = $this->searchableChat();
        [$searching] = $chat->update($this->ctrlG());
        $this->assertNotNull($searching->historySearch(), 'fixture: the search is up');

        [$fed, $cmd] = $searching->update(new InitialPromptMsg('watcher surfaced prompt'));

        $this->assertNull($cmd, 'no command drives a turn beneath the overlay');
        $this->assertFalse($this->readInFlight($fed), 'the chat is not in flight');
        $this->assertNotNull($fed->historySearch(), 'the overlay keeps the keyboard');
        $this->assertSame('watcher surfaced prompt', $fed->inputBuf, 'the words wait in the draft (picker precedent)');
    }

    public function testTheSamePromptStillSubmitsOnceTheSearchIsClosed(): void
    {
        // Polarity: the bail must not EAT watcher prompts. With the overlay
        // down the same message takes the Enter road — the box takes it and
        // submit() sends it.
        $chat = $this->searchableChat();
        [$searching] = $chat->update($this->ctrlG());
        [$closed] = $searching->update(new KeyMsg(KeyType::Escape));
        $this->assertNull($closed->historySearch(), 'fixture: the overlay is down');

        [, $cmd] = $closed->update(new InitialPromptMsg('watcher surfaced prompt'));

        $this->assertNotNull($cmd, 'the prompt still becomes a turn once the chat owns the keys back');
    }

    public function testTheWatcherTickStandsDownWhileTheSearchIsUpAndReArmsAfter(): void
    {
        // The deferral conjunct inside subscriptions(): no poll is armed at
        // all while the overlay owns the keys — and nothing is lost, because
        // leaving the overlay re-arms the tick on the next reconcile.
        $root = \sys_get_temp_dir() . '/w2b-watch-' . \uniqid('hs', true);
        \mkdir($root, 0o700, true);
        try {
            $history = new PromptHistory($this->historyPath);
            $history->append('a stored prompt');
            $chat = (new Chat(
                backend: new EchoBackend(),
                promptHistory: $history,
                projectRoot: $root,
                workspace: WorkspaceContext::new(root: $root, userConfig: ['watchFiles' => true]),
            ))->withSize(100, 30);

            $this->assertTrue($chat->subscriptions()?->has(AiCommentWatcher::SUBSCRIPTION) ?? false, 'fixture: watching an idle chat');

            [$searching] = $chat->update($this->ctrlG());
            $this->assertNotNull($searching->historySearch(), 'fixture: the search is up');
            $this->assertFalse($searching->subscriptions()?->has(AiCommentWatcher::SUBSCRIPTION) ?? false, 'no poll armed while the overlay owns the keys');

            [$closed] = $searching->update(new KeyMsg(KeyType::Escape));
            $this->assertNull($closed->historySearch(), 'fixture: closed');
            $this->assertTrue($closed->subscriptions()?->has(AiCommentWatcher::SUBSCRIPTION) ?? false, 'leaving the overlay re-arms the tick — no watcher-prompt loss');
        } finally {
            @\rmdir($root);
        }
    }

    private function ctrlG(): KeyMsg
    {
        return new KeyMsg(KeyType::Char, 'g', ctrl: true);
    }

    private function readLastEscapeStamp(Chat $chat): ?float
    {
        $property = new \ReflectionProperty($chat, 'lastEscapeAt');
        $property->setAccessible(true);

        return $property->getValue($chat);
    }

    private function readInFlight(Chat $chat): bool
    {
        $property = new \ReflectionProperty($chat, 'inFlight');
        $property->setAccessible(true);

        return (bool) $property->getValue($chat);
    }
}
