<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Session\SessionStore;
use SugarCraft\Crush\Tui\SessionPicker;
use SugarCraft\Crush\Theme;

/**
 * @internal
 */
final class SessionPickerTest extends TestCase
{
    /** @return list<array{sessionId: string, sessionName: string, summary: string, gitBranch: string|null, lastActivity: string}> */
    private function makeSessions(int $count = 3): array
    {
        $sessions = [];
        for ($i = 0; $i < $count; $i++) {
            $sessions[] = [
                'sessionId' => "session-$i",
                'sessionName' => "Session $i",
                'summary' => "Summary $i",
                'gitBranch' => $i % 2 === 0 ? 'main' : 'feature-x',
                'lastActivity' => '2024-01-01T00:00:00Z',
            ];
        }
        return $sessions;
    }

    public function testNew(): void
    {
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions);
        $this->assertSame(0, $picker->selectedIndex());
        $this->assertNull($picker->branchFilter());
    }

    public function testFilteredSessionsWithNoFilter(): void
    {
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions);
        $this->assertSame($sessions, $picker->filteredSessions());
    }

    public function testFilteredSessionsWithBranchFilter(): void
    {
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions)->withBranchFilter('main');
        $filtered = $picker->filteredSessions();
        $this->assertCount(2, $filtered);
        foreach ($filtered as $s) {
            $this->assertSame('main', $s['gitBranch']);
        }
    }

    public function testSelectedSession(): void
    {
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions)->withSelectedIndex(1);
        $this->assertSame('session-1', $picker->selectedSession()['sessionId']);
    }

    public function testSelectedSessionReturnsNullWhenEmpty(): void
    {
        $picker = SessionPicker::new([]);
        $this->assertNull($picker->selectedSession());
    }

    public function testSelectedSessionReturnsNullWhenIndexOutOfBounds(): void
    {
        // E744 WS5 removed the hand-poked invalid cursor this test used to
        // build by reflection: the selection index now lives on the ItemList
        // model, which CLAMPS, so a cursor beyond the last row is no longer
        // constructible — it can only be reasoned about. The bound the poke
        // forced is now reachable solely through the empty row set (cursor
        // parked at zero over zero rows), which selectedSession() still
        // answers with null.
        $picker = SessionPicker::new($this->makeSessions())->withBranchFilter('no-such-branch');
        $this->assertSame(0, $picker->selectedIndex());
        $this->assertCount(0, $picker->filteredSessions());
        $this->assertNull($picker->selectedSession());
    }

    public function testSelectedIndex(): void
    {
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions)->withSelectedIndex(2);
        $this->assertSame(2, $picker->selectedIndex());
    }

    public function testBranchFilter(): void
    {
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions)->withBranchFilter('feature-x');
        $this->assertSame('feature-x', $picker->branchFilter());
    }

    public function testWithSelectedIndexClampsToZero(): void
    {
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions)->withSelectedIndex(-1);
        $this->assertSame(0, $picker->selectedIndex());
    }

    public function testWithSelectedIndexClampsToMax(): void
    {
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions)->withSelectedIndex(99);
        $this->assertSame(2, $picker->selectedIndex());
    }

    public function testWithBranchFilterResetsIndex(): void
    {
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions)->withSelectedIndex(2)->withBranchFilter('main');
        // Filter reduces to 2 items (indices 0 and 2 originally), but index resets to 0
        $this->assertSame(0, $picker->selectedIndex());
        $this->assertSame('main', $picker->branchFilter());
    }

    public function testWithSessionsResetsIndex(): void
    {
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions)->withSelectedIndex(2)->withSessions($this->makeSessions(5));
        $this->assertSame(0, $picker->selectedIndex());
    }

    public function testWithSessionsPreservesBranchFilter(): void
    {
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions)->withBranchFilter('feature-x')->withSessions($this->makeSessions(5));
        $this->assertSame('feature-x', $picker->branchFilter());
    }

    public function testRenderReturnsString(): void
    {
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions);
        $output = $picker->render(80, 24, Theme::byName('dark'));
        $this->assertIsString($output);
        $this->assertNotEmpty($output);
    }

    public function testRenderWithNoSessions(): void
    {
        $picker = SessionPicker::new([]);
        $output = $picker->render(80, 24, Theme::byName('dark'));
        $this->assertIsString($output);
        $this->assertStringContainsString('(no sessions)', $output);
    }

    public function testHandleKeyUp(): void
    {
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions)->withSelectedIndex(1);
        [$newPicker, $action] = $picker->handleKey('up');
        $this->assertSame('browse', $action);
        $this->assertSame(0, $newPicker->selectedIndex());
    }

    public function testHandleKeyDown(): void
    {
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions);
        [$newPicker, $action] = $picker->handleKey('down');
        $this->assertSame('browse', $action);
        $this->assertSame(1, $newPicker->selectedIndex());
    }

    public function testHandleKeyDownClampsAtLastRow(): void
    {
        // E744 WS5 divergence, disclosed in the picker docblock: the adopted
        // ItemList CLAMPS at the end where the hand-rolled picker WRAPPED —
        // arriving on the last row is the load-more signal (pinned in
        // SessionPickerWidgetTest), never a jump back to row zero. A picker
        // with no more pages upstream answers the end press quietly: the
        // widened tuple's third slot is the widget's navigation Cmd, and
        // clamped-and-unchanged raises nothing.
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions)->withSelectedIndex(2); // last index
        [$newPicker, $action, $cmd] = $picker->handleKey('down');
        $this->assertSame('browse', $action);
        $this->assertSame(2, $newPicker->selectedIndex()); // stays clamped
        $this->assertNull($cmd);
    }

    public function testHandleKeyUpClampsAtFirstRow(): void
    {
        // E744 WS5: clamp, not wrap — see testHandleKeyDownClampsAtLastRow.
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions)->withSelectedIndex(0); // first index
        [$newPicker, $action, $cmd] = $picker->handleKey('up');
        $this->assertSame('browse', $action);
        $this->assertSame(0, $newPicker->selectedIndex()); // stays clamped
        $this->assertNull($cmd);
    }

    public function testHandleKeyEnterWithSelection(): void
    {
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions)->withSelectedIndex(0);
        [$newPicker, $action] = $picker->handleKey('enter');
        $this->assertSame('resume', $action);
        $this->assertSame($picker, $newPicker);
    }

    public function testHandleKeyEnterWithNoSelection(): void
    {
        $picker = SessionPicker::new([]);
        [$newPicker, $action] = $picker->handleKey('enter');
        $this->assertNull($action);
    }

    public function testHandleKeySpace(): void
    {
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions)->withSelectedIndex(0);
        [$newPicker, $action] = $picker->handleKey(' ');
        $this->assertSame('preview', $action);
    }

    public function testHandleKeyEscape(): void
    {
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions);
        [$newPicker, $action] = $picker->handleKey('escape');
        $this->assertSame('close', $action);
    }

    public function testHandleKeyUnrecognized(): void
    {
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions);
        [$newPicker, $action] = $picker->handleKey('unknown');
        $this->assertNull($action);
        $this->assertSame($picker, $newPicker);
    }

    public function testIsEmptyWhenNoSessions(): void
    {
        $picker = SessionPicker::new([]);
        $this->assertTrue($picker->isEmpty());
    }

    public function testIsEmptyWhenHasSessions(): void
    {
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions);
        $this->assertFalse($picker->isEmpty());
    }

    public function testIsEmptyWithBranchFilterHidingAll(): void
    {
        $sessions = $this->makeSessions();
        // All sessions have gitBranch, so filtering by a non-existent branch should be empty
        $picker = SessionPicker::new($sessions)->withBranchFilter('nonexistent-branch');
        $this->assertTrue($picker->isEmpty());
    }

    public function testCount(): void
    {
        $sessions = $this->makeSessions(5);
        $picker = SessionPicker::new($sessions);
        $this->assertSame(5, $picker->count());
    }

    public function testCountWithBranchFilter(): void
    {
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions)->withBranchFilter('main');
        $this->assertSame(2, $picker->count()); // 2 sessions with 'main' branch
    }

    public function testCountWhenEmpty(): void
    {
        $picker = SessionPicker::new([]);
        $this->assertSame(0, $picker->count());
    }

    public function testImmutability(): void
    {
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions);
        $picker2 = $picker->withSelectedIndex(1);
        $this->assertNotSame($picker, $picker2);
        $this->assertSame(0, $picker->selectedIndex());
        $this->assertSame(1, $picker2->selectedIndex());
    }

    public function testHandleKeyK(): void
    {
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions)->withSelectedIndex(1);
        [$newPicker, $action] = $picker->handleKey('k');
        $this->assertSame('browse', $action);
        $this->assertSame(0, $newPicker->selectedIndex());
    }

    public function testHandleKeyJ(): void
    {
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions);
        [$newPicker, $action] = $picker->handleKey('j');
        $this->assertSame('browse', $action);
        $this->assertSame(1, $newPicker->selectedIndex());
    }

    public function testHandleKeyCtrlB(): void
    {
        $sessions = $this->makeSessions();

        // When branch filter is already set, ctrl+b clears it (toggle off)
        $picker = SessionPicker::new($sessions)->withBranchFilter('main');
        $this->assertSame('main', $picker->branchFilter());
        [$newPicker, $action] = $picker->handleKey('ctrl+b');
        $this->assertSame('browse', $action);
        $this->assertNull($newPicker->branchFilter());
    }

    // ---------------------------------------------------------------
    // B1: the Ctrl+B branch filter had nothing to compare against
    // ---------------------------------------------------------------

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->tempDirs) as $dir) {
            $this->removeTree($dir);
        }
        $this->tempDirs = [];
    }

    /**
     * Regression (audit B1): Chat built every picker row with
     * `'gitBranch' => null`, so a branch filter, whatever it was set to,
     * always showed "(no sessions)". The rows now carry the stored branch.
     */
    public function testChatPickerRowsCarryTheRecordedBranchSoTheFilterMatches(): void
    {
        $store = new SessionStore(':memory:');
        $store->createSession('on-main', 'p', 'm', null, 'Main work', '/w', 'main');
        $store->createSession('on-feature', 'p', 'm', null, 'Feature work', '/w', 'feature-x');
        $store->createSession('nowhere', 'p', 'm', null, 'No repo');

        $chat = new Chat(history: [Message::user('hi')], sessionStore: $store, currentSessionId: 'on-main');
        [$opened] = $chat->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));
        $picker = $opened->sessionPicker();
        $this->assertNotNull($picker);

        $byId = array_column($picker->filteredSessions(), 'gitBranch', 'sessionId');
        $this->assertSame(['nowhere' => null, 'on-feature' => 'feature-x', 'on-main' => 'main'], $this->sorted($byId));

        $filtered = $picker->withBranchFilter('feature-x');
        $this->assertSame(['on-feature'], array_column($filtered->filteredSessions(), 'sessionId'));
        $this->assertFalse($filtered->isEmpty());
    }

    public function testAStoredBranchIsSanitizedBeforeItReachesThePicker(): void
    {
        $store = new SessionStore(':memory:');
        $store->createSession('s', 'p', 'm', null, 'x', '/w', "evil\x1b[31m");

        $chat = new Chat(history: [Message::user('hi')], sessionStore: $store, currentSessionId: 's');
        [$opened] = $chat->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));

        $branch = $opened->sessionPicker()->filteredSessions()[0]['gitBranch'];
        $this->assertIsString($branch);
        $this->assertStringNotContainsString("\x1b", $branch);
    }

    public function testGitBranchAtReadsTheCheckedOutBranchFromHead(): void
    {
        $repo = $this->tempDir();
        mkdir($repo . '/.git', 0700);
        file_put_contents($repo . '/.git/HEAD', "ref: refs/heads/feat/login\n");
        mkdir($repo . '/src/deep', 0700, true);

        $this->assertSame('feat/login', SessionStore::gitBranchAt($repo));
        $this->assertSame('feat/login', SessionStore::gitBranchAt($repo . '/src/deep'), 'found from a subdirectory');

        file_put_contents($repo . '/.git/HEAD', str_repeat('a', 40) . "\n");
        $this->assertNull(SessionStore::gitBranchAt($repo), 'a detached HEAD has no branch');
    }

    public function testGitBranchAtFollowsALinkedWorktreesGitFile(): void
    {
        $root = $this->tempDir();
        mkdir($root . '/main/.git/worktrees/wt', 0700, true);
        file_put_contents($root . '/main/.git/HEAD', "ref: refs/heads/master\n");
        file_put_contents($root . '/main/.git/worktrees/wt/HEAD', "ref: refs/heads/fix/wt\n");
        mkdir($root . '/wt', 0700);
        file_put_contents($root . '/wt/.git', "gitdir: {$root}/main/.git/worktrees/wt\n");

        $this->assertSame('fix/wt', SessionStore::gitBranchAt($root . '/wt'));
        $this->assertSame('master', SessionStore::gitBranchAt($root . '/main'));
    }

    public function testGitBranchAtOutsideARepositoryIsNull(): void
    {
        $this->assertNull(SessionStore::gitBranchAt($this->tempDir()));
    }

    // ---------------------------------------------------------------
    // B3: the summary column showed the shared system prompt
    // ---------------------------------------------------------------

    /**
     * Regression (audit B3): the row and footer summary came from
     * `system_prompt`, which is near-identical for every session. They now
     * show the last prompt sent, which dispatchTurn() records.
     */
    public function testThePickerShowsTheLastPromptNotTheSystemPrompt(): void
    {
        $store = new SessionStore(':memory:');
        $store->createSession('s1', 'p', 'm', 'You are a helpful assistant.', 'First', '/work/app', 'main');
        $store->recordTurn('s1', 'make the login controller use the new guard');

        $chat = new Chat(history: [Message::user('hi')], sessionStore: $store, currentSessionId: 's1');
        [$opened] = $chat->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));
        $picker = $opened->sessionPicker();

        $row = $picker->filteredSessions()[0];
        $this->assertSame('make the login controller use the new guard', $row['summary']);
        $this->assertSame('/work/app', $row['cwd']);

        $plain = $this->plain($picker->render(100, 24, Theme::byName('dark')));
        $this->assertStringContainsString('/work/app · main · "make the login controller use the new guard"', $plain);
        $this->assertStringNotContainsString('helpful assistant', $plain);
    }

    public function testASubmittedTurnRecordsThePreviewTheFooterShows(): void
    {
        $store = new SessionStore(':memory:');
        $store->createSession('s1', 'p', 'm', 'system text');
        $chat = new Chat(
            inputBuf: 'why is the PTY test flaky?',
            backend: new \SugarCraft\Crush\Backend\EchoBackend(),
            sessionStore: $store,
            currentSessionId: 's1',
            currentSessionName: 'named',
        );

        $chat->update(new KeyMsg(KeyType::Enter, ''));

        $row = $store->getSession('s1');
        $this->assertSame(1, (int) $row['turns']);
        $this->assertSame('why is the PTY test flaky?', $row['last_preview']);
    }

    public function testTheFooterSaysSoWhenNoPromptWasSentAndNeverOutgrowsTheBox(): void
    {
        $picker = SessionPicker::new([[
            'sessionId' => 's',
            'sessionName' => 'n',
            'summary' => '',
            'gitBranch' => null,
            'lastActivity' => '',
        ]]);
        $this->assertStringContainsString('(no prompt yet)', $this->plain($picker->render(80, 24, Theme::byName('dark'))));

        $wide = SessionPicker::new([[
            'sessionId' => 's',
            'sessionName' => 'n',
            'summary' => str_repeat('漢字', 80),
            'gitBranch' => 'feature/very-long-branch-name',
            'lastActivity' => '',
            'cwd' => '/home/someone/projects/deeply/nested/checkout/of/the/app',
        ]]);
        foreach ([40, 60, 80] as $width) {
            $lines = explode("\n", $this->plain($wide->render($width, 24, Theme::byName('dark'))));
            $footer = $lines[count($lines) - 1];
            $this->assertLessThanOrEqual($width, \SugarCraft\Core\Util\Width::string($footer), "footer at {$width}");
            $this->assertTrue(mb_check_encoding($footer, 'UTF-8'));
            $this->assertStringEndsWith('…', $footer);
        }
    }

    private function plain(string $rendered): string
    {
        return (string) preg_replace('/\x1b\[[0-9;:]*[A-Za-z]/', '', $rendered);
    }

    /**
     * @param array<string, mixed> $map
     *
     * @return array<string, mixed>
     */
    private function sorted(array $map): array
    {
        ksort($map);

        return $map;
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/crush_picker_b1_' . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            @unlink($dir);

            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($dir . '/' . $entry);
            }
        }
        @rmdir($dir);
    }
}
