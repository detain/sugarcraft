<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseReleaseMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Session\SessionKind;
use SugarCraft\Crush\Session\SessionStore;
use SugarCraft\Crush\Theme;
use SugarCraft\Crush\Tui\SessionPicker;
use SugarCraft\Mouse\Zone;

/**
 * The session picker's row actions, carried out through Chat against a real
 * store (Appendix P §3.2): inline rename, the two-press delete and what it
 * does to children, pin, fork, archive / unarchive, the children toggle, the
 * Space preview, the branch filter's cached branch, and the mouse cluster.
 *
 * Fixture: three sessions created oldest first, so the list (newest first)
 * reads s3, s2, s1 and the current session s1 is the LAST row — the
 * highlighted row 0 is always someone else's.
 *
 * @internal
 */
final class SessionPickerActionsTest extends TestCase
{
    private SessionStore $store;

    protected function setUp(): void
    {
        $this->store = new SessionStore(':memory:');
        foreach (['s1' => 'First', 's2' => 'Second', 's3' => 'Third'] as $id => $name) {
            $this->store->createSession($id, 'p', 'm', null, $name);
        }
        // Process-wide click state: the zone scan and the press tracker.
        Renderer::scanner()->clear();
        (new \ReflectionProperty(Chat::class, 'clickTracker'))->setValue(null, null);
    }

    /** @param ?string $first the row expected highlighted on open; null skips the check */
    private function open(?Chat $chat = null, ?string $first = 's3'): Chat
    {
        $chat ??= (new Chat(history: [Message::user('hi')], backend: new EchoBackend(), sessionStore: $this->store, currentSessionId: 's1'))
            ->withSize(100, 30);
        [$open] = $chat->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));
        $this->assertNotNull($open->sessionPicker(), 'fixture: Ctrl+R opens the picker');
        if ($first !== null) {
            $this->assertSame($first, $open->sessionPicker()->selectedSession()['sessionId'] ?? null, "fixture: {$first} is highlighted");
        }

        return $open;
    }

    /** @param list<KeyMsg|string> $keys */
    private function press(Chat $chat, array $keys): Chat
    {
        foreach ($keys as $key) {
            [$chat] = $chat->update(\is_string($key) ? self::key($key) : $key);
        }

        return $chat;
    }

    private static function key(string $name): KeyMsg
    {
        return match ($name) {
            'enter' => new KeyMsg(KeyType::Enter),
            'escape' => new KeyMsg(KeyType::Escape),
            'down' => new KeyMsg(KeyType::Down),
            'tab' => new KeyMsg(KeyType::Tab),
            'space' => new KeyMsg(KeyType::Space, ' '),
            'backspace' => new KeyMsg(KeyType::Backspace),
            default => new KeyMsg(KeyType::Char, $name),
        };
    }

    /** @return list<string> */
    private static function ids(Chat $chat): array
    {
        return array_column($chat->sessionPicker()?->filteredSessions() ?? [], 'sessionId');
    }

    // ── rename ───────────────────────────────────────────────────────────

    public function testInlineRenameSavesAUserTitleAndKeepsTheListOpen(): void
    {
        $renaming = $this->press($this->open(), ['r']);
        $this->assertSame(['id' => 's3', 'title' => 'Third'], $renaming->sessionPicker()?->renameTarget(), 'prefilled with the title');

        $saved = $this->press($renaming, ['backspace', 'backspace', 'backspace', 'backspace', 'backspace', 'N', 'e', 'w', 'enter']);

        $row = $this->store->getSession('s3');
        $this->assertSame('New', $row['name']);
        $this->assertSame('user', $row['title_source'], 'the auto-titler may not overwrite it');
        $this->assertNotNull($saved->sessionPicker(), 'the list stays up');
        $this->assertFalse($saved->sessionPicker()->isRenaming());
        $this->assertSame('New', $saved->sessionPicker()->selectedSession()['sessionName'] ?? null, 'the highlight follows the renamed row');
    }

    public function testEscCancelsTheRename(): void
    {
        $cancelled = $this->press($this->open(), ['r', 'x', 'escape']);

        $this->assertSame('Third', $this->store->getSession('s3')['name']);
        $this->assertFalse($cancelled->sessionPicker()?->isRenaming());
        $this->assertNotNull($cancelled->sessionPicker(), 'Esc leaves the rename, not the list');
    }

    public function testABlankTitleIsRefused(): void
    {
        $refused = $this->press($this->open(), ['r', 'backspace', 'backspace', 'backspace', 'backspace', 'backspace', 'enter']);

        $this->assertSame('Third', $this->store->getSession('s3')['name']);
        $this->assertStringContainsString('cannot be blank', (string) $refused->sessionPicker()?->notice());
    }

    public function testRenamingTheCurrentSessionRenamesItOnScreenToo(): void
    {
        $open = $this->open();
        $onCurrent = $this->press($open, ['down', 'down']);
        $this->assertSame('s1', $onCurrent->sessionPicker()?->selectedSession()['sessionId'] ?? null);

        $saved = $this->press($onCurrent, ['r', '!', 'enter']);

        $this->assertSame('First!', $this->store->getSession('s1')['name']);
        $this->assertSame('First!', $saved->currentSessionName());
    }

    public function testTitlesAreSanitizedBeforeTheyAreStored(): void
    {
        $saved = $this->press($this->open(), ['r', "\u{E000}", 'enter']);
        $this->assertNotNull($saved->sessionPicker());

        $this->assertStringNotContainsString("\u{E000}", (string) $this->store->getSession('s3')['name']);
    }

    // ── delete ───────────────────────────────────────────────────────────

    public function testDeleteNeedsTwoPresses(): void
    {
        $armed = $this->press($this->open(), ['d']);
        $this->assertSame('s3', $armed->sessionPicker()?->armedDeleteId());
        $this->assertNotNull($this->store->getSession('s3'), 'one press deletes nothing');

        $deleted = $this->press($armed, ['d']);
        $this->assertNull($this->store->getSession('s3'));
        $this->assertSame(['s2', 's1'], self::ids($deleted), 'and the list is re-read');
        $this->assertStringContainsString('Deleted', (string) $deleted->sessionPicker()?->notice());
    }

    public function testAnyOtherKeyStandsTheDeleteDown(): void
    {
        $disarmed = $this->press($this->open(), ['d', 'down', 'd']);

        $this->assertNotNull($this->store->getSession('s3'));
        $this->assertSame('s2', $disarmed->sessionPicker()?->armedDeleteId(), 'the second d armed the new row instead');
        $this->assertNotNull($this->store->getSession('s2'));
    }

    public function testTheCurrentSessionCannotBeDeleted(): void
    {
        $refused = $this->press($this->open(), ['down', 'down', 'd', 'd']);

        $this->assertNotNull($this->store->getSession('s1'));
        $this->assertNull($refused->sessionPicker()?->armedDeleteId());
        $this->assertStringContainsString('session on screen', (string) $refused->sessionPicker()?->notice());
    }

    public function testDeleteTakesSubAgentChildrenAndDetachesBranches(): void
    {
        $sub = $this->store->createChildSession('s3', SessionKind::Subagent, 'explore', 'call-1', 'p', 'm');
        $branch = $this->store->forkSession('s3');
        $open = $this->press($this->open(null, $branch), ['down']);
        $this->assertSame('s3', $open->sessionPicker()?->selectedSession()['sessionId'] ?? null, 'fixture: the branch is newest');

        $this->press($open, ['d', 'd']);

        $this->assertNull($this->store->getSession('s3'));
        $this->assertNull($this->store->getSession($sub), 'a sub-agent child goes with its parent');
        $this->assertNotNull($this->store->getSession($branch), 'a branch is a conversation of its own and stays');
        $this->assertNull($this->store->getSession($branch)['parent_id'], 'detached');
    }

    public function testShiftDDeletesTheBranchesToo(): void
    {
        $branch = $this->store->forkSession('s3');
        $open = $this->press($this->open(null, $branch), ['down']);

        $this->press($open, ['d', 'D']);

        $this->assertNull($this->store->getSession('s3'));
        $this->assertNull($this->store->getSession($branch));
    }

    // ── pin, archive, fork ───────────────────────────────────────────────

    public function testPinMovesTheRowIntoThePinnedGroupAndTheHighlightFollows(): void
    {
        $open = $this->press($this->open(), ['down']);
        $this->assertSame('s2', $open->sessionPicker()?->selectedSession()['sessionId'] ?? null);

        $pinned = $this->press($open, ['p']);

        $this->assertSame(1, (int) $this->store->getSession('s2')['pinned']);
        $this->assertSame('s2', self::ids($pinned)[0], 'pinned rows list first');
        $this->assertSame('s2', $pinned->sessionPicker()?->selectedSession()['sessionId'] ?? null);
        $this->assertStringContainsString('Pinned', $pinned->sessionPicker()?->render(76, 24, Theme::byName('dark')) ?? '');

        $this->press($pinned, ['p']);
        $this->assertSame(0, (int) $this->store->getSession('s2')['pinned'], 'and p again unpins');
    }

    public function testArchiveHidesTheRowAndUBringsItBack(): void
    {
        $archived = $this->press($this->open(), ['x']);
        $this->assertNotNull($this->store->getSession('s3')['archived_at']);
        $this->assertNotContains('s3', self::ids($archived));

        $shown = $this->press($archived, ['a']);
        $this->assertTrue($shown->sessionPicker()?->showsArchived());
        $this->assertSame('s3', array_reverse(self::ids($shown))[0], 'archived rows list last, in their own group');

        $onIt = $this->press($shown, ['down', 'down']);
        $this->assertSame('s3', $onIt->sessionPicker()?->selectedSession()['sessionId'] ?? null);
        $this->press($onIt, ['u']);
        $this->assertNull($this->store->getSession('s3')['archived_at']);
    }

    public function testTheCurrentSessionCannotBeArchived(): void
    {
        $refused = $this->press($this->open(), ['down', 'down', 'x']);

        $this->assertNull($this->store->getSession('s1')['archived_at']);
        $this->assertStringContainsString('session on screen', (string) $refused->sessionPicker()?->notice());
    }

    public function testForkSwitchesToABranchOfTheHighlightedSession(): void
    {
        $forked = $this->press($this->open(), ['f']);

        $this->assertNull($forked->sessionPicker());
        $id = $forked->currentSessionId();
        $this->assertNotNull($id);
        $this->assertNotContains($id, ['s1', 's2', 's3']);
        $row = $this->store->getSession($id);
        $this->assertSame('s3', $row['parent_id']);
        $this->assertSame('branch', $row['kind']);
        $this->assertSame('Third (branch)', $forked->currentSessionName());
    }

    public function testForkIsRefusedMidTurn(): void
    {
        $chat = (new Chat(history: [Message::user('hi')], backend: new EchoBackend(), sessionStore: $this->store, currentSessionId: 's1', inFlight: true))
            ->withSize(100, 30);
        $refused = $this->press($this->open($chat), ['f']);

        $this->assertSame('s1', $refused->currentSessionId());
        $this->assertCount(3, $this->store->listSessions());
    }

    // ── children, preview, resume ────────────────────────────────────────

    public function testTabShowsSubAgentRowsAndEnterOnOnePreviewsInsteadOfSwitching(): void
    {
        $sub = $this->store->createChildSession('s3', SessionKind::Subagent, 'explore', 'call-1', 'p', 'm', 'Map the flow');
        $shown = $this->press($this->open(), ['tab']);
        $this->assertSame(['s3', $sub, 's2', 's1'], self::ids($shown));

        $entered = $this->press($shown, ['down', 'enter']);

        $this->assertSame('s1', $entered->currentSessionId(), 'a sub-agent record is not switched to');
        $this->assertNotNull($entered->sessionPicker());
        $this->assertSame($sub, $entered->sessionPicker()->preview()['id'] ?? null);
    }

    public function testSpaceLoadsTheLastMessagesIntoTheFooter(): void
    {
        $dir = sys_get_temp_dir() . '/crush_picker_preview_' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        try {
            $store = new EnhancedSessionStore($dir . '/s.db');
            $store->createSession('old', 'p', 'm', null, 'Old');
            $store->saveTranscript('old', [
                ['role' => 'user', 'content' => "first\nquestion"],
                ['role' => 'assistant', 'content' => "an \x1b[31manswer"],
            ]);
            $store->createSession('now', 'p', 'm', null, 'Now');
            $chat = (new Chat(history: [Message::user('hi')], sessionStore: $store, currentSessionId: 'now'))->withSize(100, 30);
            [$open] = $chat->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));
            $open = $this->press($open, ['down']);
            $this->assertSame('old', $open->sessionPicker()?->selectedSession()['sessionId'] ?? null);

            $previewed = $this->press($open, ['space']);

            $this->assertSame(['you: first question', 'ai:  an answer'], $previewed->sessionPicker()?->preview()['lines'] ?? null);
            $plain = (string) preg_replace('/\x1b\[[0-9;:]*[A-Za-z]/', '', $previewed->sessionPicker()->render(76, 24, Theme::byName('dark')));
            $this->assertStringContainsString('you: first question', $plain);

            $moved = $this->press($previewed, ['k']);
            $this->assertNull($moved->sessionPicker()?->preview(), 'the preview belongs to its row');
        } finally {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }

    public function testResumeReadsTheStoredNameNotTheDisplayLabel(): void
    {
        $this->store->createSession('s4', 'p', 'm');
        $resumed = $this->press($this->open(null, 's4'), ['enter']);
        $this->assertSame('s4', $resumed->currentSessionId());
        $this->assertNull($resumed->currentSessionName(), 'an unnamed row shows "(untitled …)" but stays unnamed');
    }

    // ── the branch filter reads HEAD once ───────────────────────────────

    public function testCtrlBFiltersToTheBranchReadWhenThePickerOpened(): void
    {
        $repo = sys_get_temp_dir() . '/crush_picker_branch_' . bin2hex(random_bytes(4));
        mkdir($repo . '/.git', 0700, true);
        file_put_contents($repo . '/.git/HEAD', "ref: refs/heads/feat/x\n");
        try {
            $this->store->createSession('on-x', 'p', 'm', null, 'On x', $repo, 'feat/x');
            $chat = (new Chat(history: [Message::user('hi')], sessionStore: $this->store, currentSessionId: 's1', projectRoot: $repo))
                ->withSize(100, 30);
            [$open] = $chat->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));
            $this->assertSame('feat/x', $open->sessionPicker()?->currentBranch());

            // Switching branches after the picker opened changes nothing: the
            // answer was read once, so Ctrl+B runs no git and reads no file.
            file_put_contents($repo . '/.git/HEAD', "ref: refs/heads/other\n");
            [$filtered] = $open->update(new KeyMsg(KeyType::Char, 'b', ctrl: true));

            $this->assertSame('feat/x', $filtered->sessionPicker()?->branchFilter());
            $this->assertSame(['on-x'], self::ids($filtered));
        } finally {
            @unlink($repo . '/.git/HEAD');
            @rmdir($repo . '/.git');
            @rmdir($repo);
        }
    }

    public function testCtrlBOutsideAGitBranchSaysSo(): void
    {
        $chat = (new Chat(history: [Message::user('hi')], sessionStore: $this->store, currentSessionId: 's1', projectRoot: sys_get_temp_dir()))
            ->withSize(100, 30);
        [$open] = $chat->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));
        $this->assertNull($open->sessionPicker()?->currentBranch(), 'fixture: the temp dir is not a work tree');

        [$after] = $open->update(new KeyMsg(KeyType::Char, 'b', ctrl: true));

        $this->assertNull($after->sessionPicker()?->branchFilter());
        $this->assertStringContainsString('git branch', (string) $after->sessionPicker()?->notice());
    }

    // ── mouse ────────────────────────────────────────────────────────────

    public function testClickingTheClusterRunsTheSameActionsAsTheKeys(): void
    {
        $open = $this->open();

        $pinned = $this->click($open, Renderer::SESSION_ACT_ZONE_PREFIX . '0:pin');
        $this->assertSame(1, (int) $this->store->getSession('s3')['pinned']);

        $row = $pinned->sessionPicker()?->selectedIndex();
        $armed = $this->click($pinned, Renderer::SESSION_ACT_ZONE_PREFIX . $row . ':delete');
        $this->assertSame('s3', $armed->sessionPicker()?->armedDeleteId(), 'the first ✕ arms');
        $deleted = $this->click($armed, Renderer::SESSION_ACT_ZONE_PREFIX . $row . ':delete');
        $this->assertNull($this->store->getSession('s3'), 'and the second deletes');
        $this->assertNotNull($deleted->sessionPicker());
    }

    public function testOnlyTheHighlightedRowCarriesTheCluster(): void
    {
        Renderer::render($this->open());

        $this->assertInstanceOf(Zone::class, Renderer::scanner()->get(Renderer::SESSION_ACT_ZONE_PREFIX . '0:rename'));
        $this->assertNull(Renderer::scanner()->get(Renderer::SESSION_ACT_ZONE_PREFIX . '1:rename'));
    }

    private function click(Chat $chat, string $zoneId): Chat
    {
        Renderer::render($chat);
        $zone = Renderer::scanner()->get($zoneId);
        $this->assertInstanceOf(Zone::class, $zone, "no '{$zoneId}' zone in the frame");

        [$pressed] = $chat->update(new MouseClickMsg($zone->startCol, $zone->startRow, MouseButton::Left, MouseAction::Press));
        [$released] = $pressed->update(new MouseReleaseMsg($zone->startCol, $zone->startRow, MouseButton::Left, MouseAction::Release));

        return $released;
    }
}
