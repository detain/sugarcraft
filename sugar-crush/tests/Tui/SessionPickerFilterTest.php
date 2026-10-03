<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Session\SessionKind;
use SugarCraft\Crush\Session\SessionStore;
use SugarCraft\Crush\Theme;
use SugarCraft\Crush\Tui\SessionListAction;
use SugarCraft\Crush\Tui\SessionPicker;
use SugarCraft\Sprinkles\Style;

/**
 * The session picker's `/` filter (Appendix P §3.2): entering and leaving
 * it, fuzzy ranking over title / last prompt / agent / id / branch, the
 * highlight of the matched characters, and the store reads it does (one when
 * it opens) and does not do (none per keystroke).
 *
 * @internal
 */
final class SessionPickerFilterTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return [
            ['sessionId' => 'a1', 'sessionName' => 'Auth refactor', 'title' => 'Auth refactor', 'summary' => 'make the guard stricter', 'gitBranch' => 'main', 'lastActivity' => ''],
            ['sessionId' => 'b2', 'sessionName' => 'Fix flaky PTY test', 'title' => 'Fix flaky PTY test', 'summary' => 'why is it flaky', 'gitBranch' => 'feature/pty', 'lastActivity' => ''],
            ['sessionId' => 'c3', 'sessionName' => 'Session store migration', 'title' => 'Session store migration', 'summary' => 'make the login controller use the new guard', 'gitBranch' => 'main', 'lastActivity' => ''],
            ['sessionId' => 's4', 'sessionName' => 'Map the auth flow', 'title' => 'Map the auth flow', 'summary' => '', 'gitBranch' => null, 'lastActivity' => '', 'kind' => 'subagent', 'parentId' => 'b2', 'agent' => 'explore'],
        ];
    }

    /** @param list<string> $keys */
    private function press(SessionPicker $picker, array $keys): SessionPicker
    {
        foreach ($keys as $key) {
            [$picker] = $picker->handleKey($key);
        }

        return $picker;
    }

    /** @return list<string> */
    private static function ids(SessionPicker $picker): array
    {
        return array_column($picker->filteredSessions(), 'sessionId');
    }

    public function testSlashOpensTheFilterAndReportsItToTheHost(): void
    {
        [$filtering, $action] = SessionPicker::new($this->rows())->handleKey('/');

        $this->assertTrue($filtering->isFiltering());
        $this->assertSame(SessionListAction::Filter, $action);
        $this->assertSame('', $filtering->query());
    }

    public function testTypedTextNarrowsAndRanksTheList(): void
    {
        $picker = $this->press(SessionPicker::new($this->rows()), ['/', 'p', 't', 'y']);

        $this->assertSame('pty', $picker->query());
        $this->assertSame(['b2'], self::ids($picker));
    }

    public function testTheLastPromptIsSearchedToo(): void
    {
        $picker = $this->press(SessionPicker::new($this->rows()), ['/', 'l', 'o', 'g', 'i', 'n']);

        $this->assertSame(['c3'], self::ids($picker), 'only c3 mentions "login", and only in its last prompt');
    }

    public function testTheBestMatchIsFirstAndHighlighted(): void
    {
        $picker = $this->press(SessionPicker::new($this->rows()), ['/', 'a', 'u', 't', 'h']);

        $this->assertSame('a1', self::ids($picker)[0], 'the title match outranks the rest');
        $this->assertSame(0, $picker->selectedIndex(), 'and the best match is highlighted');

        $theme = Theme::byName('dark');
        $hit = Style::new()->foreground($theme->shellWarning)->bold()->underline()->render('Auth');
        $this->assertStringContainsString($hit, $picker->render(76, 24, $theme), 'the matched run is painted apart');
    }

    public function testLettersAreTextInsideTheFilterButMoveOutsideIt(): void
    {
        $outside = $this->press(SessionPicker::new($this->rows()), ['j']);
        $this->assertSame(1, $outside->selectedIndex(), 'j moves the highlight outside the filter');

        $inside = $this->press(SessionPicker::new($this->rows()), ['/', 'j']);
        $this->assertSame('j', $inside->query(), 'and is a query character inside it');
    }

    public function testArrowsStillMoveWhileFiltering(): void
    {
        $picker = $this->press(SessionPicker::new($this->rows()), ['/', 'a']);
        $this->assertGreaterThan(1, $picker->count(), 'fixture: several rows match "a"');

        $this->assertSame(1, $this->press($picker, ['down'])->selectedIndex());
        $this->assertTrue($this->press($picker, ['down'])->isFiltering());
    }

    public function testEscClearsTheFilterThenASecondEscCloses(): void
    {
        $picker = $this->press(SessionPicker::new($this->rows()), ['/', 'p', 't', 'y']);

        [$cleared, $action] = $picker->handleKey('escape');
        $this->assertSame('edit', $action, 'the first Esc does not close');
        $this->assertSame('', $cleared->query());
        $this->assertFalse($cleared->isFiltering());
        $this->assertSame(['a1', 'b2', 'c3'], self::ids($cleared), 'every row is back');

        [, $closing] = $cleared->handleKey('escape');
        $this->assertSame('close', $closing);
    }

    public function testBackspaceErasesAndOnAnEmptyQueryLeavesTheFilter(): void
    {
        $picker = $this->press(SessionPicker::new($this->rows()), ['/', 'p', 't']);

        $erased = $this->press($picker, ['backspace']);
        $this->assertSame('p', $erased->query());

        $left = $this->press($erased, ['backspace', 'backspace']);
        $this->assertFalse($left->isFiltering());
    }

    public function testCtrlAliasesActOnRowsFromInsideTheFilter(): void
    {
        $picker = $this->press(SessionPicker::new($this->rows()), ['/', 'p', 't', 'y']);

        [$renaming] = $picker->handleKey(new KeyMsg(KeyType::Char, 'e', ctrl: true));
        $this->assertTrue($renaming->isRenaming(), 'Ctrl+E renames');

        [$armed] = $picker->handleKey(new KeyMsg(KeyType::Char, 'd', ctrl: true));
        $this->assertSame('b2', $armed->armedDeleteId(), 'Ctrl+D arms the delete');

        [, $pin] = $picker->handleKey(new KeyMsg(KeyType::Char, 'f', ctrl: true));
        $this->assertSame(SessionListAction::Pin, $pin, 'Ctrl+F pins');
    }

    public function testSubAgentRowsAreSearchedOnlyWhileShown(): void
    {
        $hidden = $this->press(SessionPicker::new($this->rows()), ['/', 'f', 'l', 'o', 'w']);
        $this->assertNotContains('s4', self::ids($hidden));

        $shown = $this->press(SessionPicker::new($this->rows())->withShowChildren(), ['/', 'f', 'l', 'o', 'w']);
        $this->assertContains('s4', self::ids($shown));
    }

    public function testASearchSuspendsStorePaging(): void
    {
        $picker = SessionPicker::new($this->rows(), SessionPicker::PAGE_SIZE, true);
        $this->assertTrue($picker->needsStoreFetch(), 'fixture: more rows upstream');

        $this->assertFalse($this->press($picker, ['/', 'a'])->needsStoreFetch(), 'a query ranks what is loaded');
    }

    public function testNoMatchSaysSo(): void
    {
        $picker = $this->press(SessionPicker::new($this->rows()), ['/', 'z', 'z', 'z']);

        $this->assertTrue($picker->isEmpty());
        $this->assertStringContainsString('(no sessions match)', $picker->render(60, 24, Theme::byName('dark')));
    }

    // ---------------------------------------------------------------
    // Through Chat: /sessions <query>, and the one read the filter does
    // ---------------------------------------------------------------

    private function store(int $extra = 0): SessionStore
    {
        $store = new SessionStore(':memory:');
        $store->createSession('a1', 'p', 'm', null, 'Auth refactor');
        $store->createSession('b2', 'p', 'm', null, 'Fix flaky PTY test');
        for ($i = 0; $i < $extra; $i++) {
            $store->createSession('x' . $i, 'p', 'm', null, 'Filler ' . $i);
        }

        return $store;
    }

    public function testSessionsWithAnArgumentOpensFiltered(): void
    {
        [$opened] = (new Chat(inputBuf: '/sessions pty', sessionStore: $this->store(), currentSessionId: 'a1'))
            ->update(new KeyMsg(KeyType::Enter, ''));

        $picker = $opened->sessionPicker();
        $this->assertNotNull($picker);
        $this->assertSame('pty', $picker->query());
        $this->assertTrue($picker->isFiltering());
        $this->assertSame(['b2'], self::ids($picker));
    }

    public function testOpeningTheFilterLoadsTheSearchPageOnceAndTypingReadsNothing(): void
    {
        $store = $this->store(30);
        $chat = new Chat(history: [Message::user('hi')], sessionStore: $store, currentSessionId: 'a1');
        [$open] = $chat->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));
        $this->assertSame(SessionPicker::PAGE_SIZE, $open->sessionPicker()?->fetchLimit(), 'fixture: one page loaded');
        $this->assertCount(SessionPicker::PAGE_SIZE, $open->sessionPicker()?->filteredSessions() ?? []);

        [$filtering] = $open->update(new KeyMsg(KeyType::Char, '/'));
        $this->assertSame(SessionPicker::SEARCH_LIMIT, $filtering->sessionPicker()?->fetchLimit());
        $this->assertCount(32, $filtering->sessionPicker()?->filteredSessions() ?? [], 'every session is now searchable');

        // A row removed behind the picker's back is the probe: a keystroke
        // that re-read the store would lose it from the results.
        $store->deleteSession('x0');
        $typed = $filtering;
        foreach (['f', 'i', 'l', 'l'] as $rune) {
            [$typed] = $typed->update(new KeyMsg(KeyType::Char, $rune));
        }
        $this->assertContains('x0', self::ids($typed->sessionPicker()), 'typing a query must not read the store');
        $this->assertCount(30, $typed->sessionPicker()?->filteredSessions() ?? []);
    }

    public function testChildRowsAreLoadedWithThePageAndShownByTab(): void
    {
        $store = $this->store();
        $child = $store->createChildSession('b2', SessionKind::Subagent, 'explore', 'call-1', 'p', 'm', 'Map the flow');
        $chat = new Chat(history: [Message::user('hi')], sessionStore: $store, currentSessionId: 'a1');
        [$open] = $chat->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));

        $this->assertNotContains($child, self::ids($open->sessionPicker()));
        $row = array_column($open->sessionPicker()->filteredSessions(), null, 'sessionId')['b2'];
        $this->assertSame(1, $row['subagents'], 'the parent carries its sub-agent count');

        [$shown] = $open->update(new KeyMsg(KeyType::Tab));
        $ids = self::ids($shown->sessionPicker());
        $this->assertContains($child, $ids);
        $this->assertSame(array_search('b2', $ids, true) + 1, array_search($child, $ids, true), 'indented right under its parent');
    }
}
