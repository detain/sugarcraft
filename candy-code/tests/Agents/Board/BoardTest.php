<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Agents\Board;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\Board\ActiveBoard;
use SugarCraft\Code\Agents\Board\Board;
use SugarCraft\Code\Agents\Board\BoardEntry;
use SugarCraft\Code\Agents\Board\BoardKind;
use SugarCraft\Code\Agents\Board\BoardMember;
use SugarCraft\Code\Support\ForkedChild;
use SugarCraft\Code\Support\ToolIpcFiles;

/**
 * Roadmap 4.5: the shared board one parallel `Task` batch posts to — the
 * store (ids, cursors, limits, refusals), its lifetime, and the per-process
 * binding the notice hook reads.
 */
final class BoardTest extends TestCase
{
    use \SugarCraft\Code\Tests\Support\ReapsForkedChildrenTrait;

    /** @var list<Board> */
    private array $boards = [];

    protected function tearDown(): void
    {
        $this->reapTrackedForkedChildren();
        foreach ($this->boards as $board) {
            $board->discard();
        }
        $this->boards = [];
        parent::tearDown();
    }

    public function testCreateSeatsEveryMemberUnderAnIdOfItsAgentAndPlace(): void
    {
        $board = $this->board(['coder', 'coder', 'code reviewer!']);

        $this->assertSame(['coder-1', 'coder-2', 'code-reviewer-3'], array_map(static fn (BoardMember $m): string => $m->id, $board->roster()));
        $this->assertSame('task coder', $board->roster()[0]->task);
        $this->assertSame('', $board->member(), 'the creator\'s view is no member');
        $this->assertSame(['coder-1', 'coder-2', 'code-reviewer-3'], $board->peerIds());
    }

    public function testTheFileIsPrivateAndCarriesTheRuntimePrefixTheSweepReaps(): void
    {
        $board = $this->board(['a', 'b']);

        $this->assertFileExists($board->path());
        $this->assertSame(0o600, fileperms($board->path()) & 0o777);
        $this->assertStringStartsWith(sys_get_temp_dir() . '/' . ToolIpcFiles::RUNTIME_PREFIX, $board->path());
        $this->assertStringEndsWith('.' . Board::EXTENSION, $board->path());
    }

    public function testAViewIsForAMemberOnTheRosterOnly(): void
    {
        $board = $this->board(['a', 'b']);

        $this->assertSame('a-1', $board->forMember('a-1')->member());
        $this->assertSame(['b-2'], $board->forMember('a-1')->peerIds());

        $this->expectException(\InvalidArgumentException::class);
        $board->forMember('c-3');
    }

    public function testPostsAreNumberedInOrderAndReadBackAfterACursor(): void
    {
        $board = $this->board(['a', 'b']);
        $a = $board->forMember('a-1');
        $b = $board->forMember('b-2');

        $first = $a->post(Board::ALL, BoardKind::Info, '  touching src/Auth.php  ');
        $second = $b->post('a-1', BoardKind::Ask, 'which method?', 1);
        $third = $a->post('b-2', BoardKind::Result, 'login()', 2);

        $this->assertSame([1, 2, 3], [$first->id, $second->id, $third->id]);
        $this->assertSame('touching src/Auth.php', $first->body, 'the body is trimmed');
        $this->assertSame('a-1', $first->from);
        $this->assertSame(2, $third->replyTo);
        $this->assertSame(3, $board->head());
        $this->assertSame([2, 3], array_map(static fn (BoardEntry $e): int => $e->id, $board->read(1)));
        $this->assertSame([2], array_map(static fn (BoardEntry $e): int => $e->id, $board->read(1, 1)));
        $this->assertSame([], $board->read(3));
        $this->assertSame('#2 b-2 → a-1 [ASK] (re #1) which method?', $second->render());
    }

    public function testNewsIsWhatPeersPostedForThisMember(): void
    {
        $board = $this->board(['a', 'b', 'c']);
        $a = $board->forMember('a-1');
        $b = $board->forMember('b-2');
        $c = $board->forMember('c-3');

        $a->post(Board::ALL, BoardKind::Info, 'mine');
        $b->post('c-3', BoardKind::Ask, 'for c only');
        $c->post(Board::ALL, BoardKind::Hold, 'wait for me');
        $b->post('a-1', BoardKind::Veto, 'do not rename it');

        $this->assertSame([3, 4], array_map(static fn (BoardEntry $e): int => $e->id, $a->newsFor(0)), 'not its own post, not a post for c');
        $this->assertSame([4], array_map(static fn (BoardEntry $e): int => $e->id, $a->newsFor(3)));
        $this->assertSame([1, 2], array_map(static fn (BoardEntry $e): int => $e->id, $c->newsFor(0)));
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: ?int, 3: string}>
     */
    public static function refusals(): iterable
    {
        yield 'empty body' => ['b-2', '   ', null, 'has no body'];
        yield 'oversized body' => ['b-2', str_repeat('x', Board::MAX_BODY_BYTES + 1), null, 'a post is at most 4096'];
        yield 'to self' => ['a-1', 'hi', null, '"a-1" is you'];
        yield 'to a stranger' => ['z-9', 'hi', null, '"z-9" is not on this board: address one of b-2, or ALL'];
        yield 'reply to nothing' => ['b-2', 'hi', 5, '`reply_to` 5 names no post'];
    }

    /**
     * @dataProvider refusals
     */
    public function testAPostTheBoardCannotTakeIsRefusedAndNothingIsWritten(string $to, string $body, ?int $replyTo, string $why): void
    {
        $board = $this->board(['a', 'b']);

        try {
            $board->forMember('a-1')->post($to, BoardKind::Info, $body, $replyTo);
            $this->fail('the post was accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString($why, $e->getMessage());
        }
        $this->assertSame(0, $board->head());
    }

    public function testTheCreatorsViewCannotPost(): void
    {
        $board = $this->board(['a', 'b']);

        $this->expectException(\LogicException::class);
        $board->post(Board::ALL, BoardKind::Info, 'hi');
    }

    public function testAFullBoardRefusesTheNextPost(): void
    {
        $board = $this->board(['a', 'b']);
        $line = '';
        for ($i = 1; $i <= Board::MAX_ENTRIES; $i++) {
            $line .= json_encode((new BoardEntry($i, 'b-2', Board::ALL, BoardKind::Info, 'x'))->toArray()) . "\n";
        }
        file_put_contents($board->path(), $line);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('the board is full (1000 posts)');
        $board->forMember('a-1')->post(Board::ALL, BoardKind::Info, 'one more');
    }

    public function testAReadIsBoundedByBytesButNeverEmptyWhenThereIsAPost(): void
    {
        $board = $this->board(['a', 'b']);
        $a = $board->forMember('a-1');
        for ($i = 0; $i < 12; $i++) {
            $a->post(Board::ALL, BoardKind::Info, str_repeat('y', Board::MAX_BODY_BYTES));
        }

        $read = $board->read(0, 100);
        $this->assertLessThan(12, \count($read));
        $this->assertGreaterThan(0, \count($read));
        $this->assertLessThanOrEqual(Board::MAX_READ_BYTES, array_sum(array_map(static fn (BoardEntry $e): int => \strlen($e->render()) + 1, $read)));
    }

    public function testATornLineIsSkippedAndTheNextPostStillLands(): void
    {
        $board = $this->board(['a', 'b']);
        $a = $board->forMember('a-1');
        $a->post(Board::ALL, BoardKind::Info, 'first');
        file_put_contents($board->path(), '{"id": 2, "from": "b-', FILE_APPEND);

        $next = $a->post(Board::ALL, BoardKind::Info, 'second');

        $this->assertSame(2, $next->id);
        $this->assertSame(['first', 'second'], array_map(static fn (BoardEntry $e): string => $e->body, $board->entries()));
    }

    public function testConcurrentMembersNeverShareAnId(): void
    {
        if (!\function_exists('pcntl_fork')) {
            $this->markTestSkipped('needs ext-pcntl');
        }
        $board = $this->board(['a', 'b', 'c', 'd']);
        $pids = [];
        foreach (['a-1', 'b-2', 'c-3', 'd-4'] as $id) {
            $pid = $this->forkTracked();
            if ($pid === 0) {
                $view = $board->forMember($id);
                for ($i = 0; $i < 10; $i++) {
                    $view->post(Board::ALL, BoardKind::Info, "{$id} #{$i}");
                }
                ForkedChild::exitNow(0);
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $this->forgetForkedChild($pid);
        }

        $ids = array_map(static fn (BoardEntry $e): int => $e->id, $board->entries());
        $this->assertSame(range(1, 40), $ids);
    }

    public function testTheBoardGoesWithItsLastViewInTheCreatingProcess(): void
    {
        $board = Board::create([BoardMember::new('a', ''), BoardMember::new('b', '')]);
        $this->assertNotNull($board);
        $path = $board->path();
        $member = $board->forMember('a-1');

        unset($board);
        $this->assertFileExists($path, 'a member\'s view still holds it');

        unset($member);
        $this->assertFileDoesNotExist($path);
    }

    public function testAPostAfterTheBatchEndedIsRefusedAndResurrectsNothing(): void
    {
        $board = $this->board(['a', 'b']);
        $a = $board->forMember('a-1');
        $board->discard();

        $caught = null;
        try {
            $a->post(Board::ALL, BoardKind::Result, 'late');
        } catch (\RuntimeException $e) {
            $caught = $e;
        }
        $this->assertNotNull($caught, 'a post to a removed board was accepted');
        $this->assertStringContainsString('the board is gone', $caught->getMessage());
        $this->assertFileDoesNotExist($board->path());
        $this->assertSame([], $a->entries());
    }

    public function testAForkedChildNeverRemovesTheBoardItsSiblingsUse(): void
    {
        if (!\function_exists('pcntl_fork')) {
            $this->markTestSkipped('needs ext-pcntl');
        }
        $board = $this->board(['a', 'b']);

        $pid = $this->forkTracked();
        if ($pid === 0) {
            $board->discard();
            ForkedChild::exitNow(0);
        }
        pcntl_waitpid($pid, $status);
        $this->forgetForkedChild($pid);

        $this->assertFileExists($board->path());
    }

    public function testAnEntryRoundTripsAndAnythingElseIsNoEntry(): void
    {
        $entry = new BoardEntry(3, 'a-1', Board::ALL, BoardKind::Veto, 'no', 2, 1.5);

        $this->assertEquals($entry, BoardEntry::fromArray($entry->toArray()));
        $this->assertNull(BoardEntry::fromArray('x'));
        $this->assertNull(BoardEntry::fromArray(['id' => 0] + $entry->toArray()));
        $this->assertNull(BoardEntry::fromArray(['kind' => 'SHOUT'] + $entry->toArray()));
        $this->assertSame(['INFO', 'ASK', 'RESULT', 'HOLD', 'VETO', 'CLAIM', 'RELEASE'], BoardKind::values());
    }

    public function testTheProcessBindingNestsAndEachSeatRestoresWhatItReplaced(): void
    {
        $board = $this->board(['a', 'b']);
        $member = $board->forMember('a-1');
        $this->assertNull(ActiveBoard::current());

        $outer = ActiveBoard::enter($member);
        ActiveBoard::markNoticed(4);
        ActiveBoard::markNoticed(2);
        $this->assertSame(4, ActiveBoard::noticed(), 'the cursor only moves forward');

        $inner = ActiveBoard::enter(null);
        $this->assertNull(ActiveBoard::current(), 'a nested run is on no board');
        $this->assertSame(0, ActiveBoard::noticed());

        unset($inner);
        $this->assertSame($member, ActiveBoard::current());
        $this->assertSame(4, ActiveBoard::noticed());

        unset($outer);
        $this->assertNull(ActiveBoard::current());
        $this->assertSame(0, ActiveBoard::noticed());
    }

    public function testAClaimCarriesItsPathsAcrossTheWireAndIntoTheRender(): void
    {
        $board = $this->board(['a', 'b']);
        $a = $board->forMember('a-1');

        $claim = $a->post(Board::ALL, BoardKind::Claim, 'touching auth now', null, [' src/Auth.php ', 'src/Auth.php', 'src/Util/Str.php']);

        $this->assertSame(['src/Auth.php', 'src/Util/Str.php'], $claim->paths, 'trimmed and deduped in order');
        $this->assertSame('#1 a-1 → ALL [CLAIM] (src/Auth.php, src/Util/Str.php) touching auth now', $claim->render());

        $back = $board->entries();
        $this->assertCount(1, $back);
        $this->assertSame(BoardKind::Claim, $back[0]->kind);
        $this->assertSame($claim->paths, $back[0]->paths, 'the paths ride the JSONL line');

        $raw = (string) file_get_contents($board->path());
        $this->assertStringContainsString('"paths":["src/Auth.php","src/Util/Str.php"]', $raw);
    }

    public function testALineWrittenBeforeThePairStillReadsWithNoPaths(): void
    {
        $board = $this->board(['a', 'b']);
        file_put_contents(
            $board->path(),
            (string) json_encode(['id' => 1, 'from' => 'b-2', 'to' => Board::ALL, 'kind' => 'INFO', 'body' => 'older', 'reply_to' => null, 'at' => 1.0], JSON_UNESCAPED_SLASHES),
        );

        $entries = $board->entries();

        $this->assertCount(1, $entries);
        $this->assertSame([], $entries[0]->paths, 'a line from before the paths field reads as one with none');
    }

    public function testAGarbledPathsFieldIsCoercedAndNeverAccused(): void
    {
        $board = $this->board(['a', 'b']);
        $row = [
            'id' => 1,
            'from' => 'b-2',
            'to' => Board::ALL,
            'kind' => 'CLAIM',
            'body' => 'claimed',
            'reply_to' => null,
            'at' => 1.0,
            'paths' => ['src/A.php', '  src/A.php  ', '   ', 42, ['src/B.php']],
        ];
        $notAList = ['id' => 2, 'from' => 'b-2', 'to' => Board::ALL, 'kind' => 'CLAIM', 'body' => 'a string', 'paths' => 'src/C.php'];
        file_put_contents(
            $board->path(),
            json_encode($row, JSON_UNESCAPED_SLASHES) . "\n" . json_encode($notAList, JSON_UNESCAPED_SLASHES),
        );

        $entries = $board->entries();

        $this->assertCount(2, $entries);
        $this->assertSame(['src/A.php'], $entries[0]->paths, 'blanks, numbers and nests are dropped and duplicates fold');
        $this->assertSame([], $entries[1]->paths, 'a paths field that is not a list reads as none');
    }

    public function testOutstandingClaimsFollowTheClaimAndReleasePair(): void
    {
        $board = $this->board(['a', 'b']);
        $a = $board->forMember('a-1');
        $b = $board->forMember('b-2');

        $this->assertSame([], $a->outstandingClaims(), 'an empty board claims nothing');

        $wide = $a->post(Board::ALL, BoardKind::Claim, 'two files', null, ['src/A.php', 'src/B.php']);
        $narrow = $b->post('a-1', BoardKind::Claim, 'mine too', null, ['src/C.php']);

        $this->assertSame([$wide->id, $narrow->id], array_map(static fn (BoardEntry $e): int => $e->id, $a->outstandingClaims()), 'oldest first');

        $a->post(Board::ALL, BoardKind::Release, 'done with A', null, ['src/A.php']);

        $claims = $a->outstandingClaims();
        $this->assertCount(2, $claims);
        $this->assertSame([$wide->id, $narrow->id], array_map(static fn (BoardEntry $e): int => $e->id, $claims));
        $this->assertSame(['src/B.php'], $claims[0]->paths, 'the surviving CLAIM is narrowed to what its RELEASE left');

        $b->post('a-1', BoardKind::Release, 'done', null, ['src/C.php']);
        $a->post(Board::ALL, BoardKind::Release, 'done', null, ['src/B.php']);

        $this->assertSame([], $a->outstandingClaims(), 'a cleared path leaves no trace');
        $this->assertSame(5, $board->head(), 'the cleared CLAIMs and their RELEASEs stay on the board as posts');
    }

    public function testOneMembersReleaseClearsOnlyItsOwnClaims(): void
    {
        $board = $this->board(['a', 'b']);
        $a = $board->forMember('a-1');
        $b = $board->forMember('b-2');

        $mine = $a->post(Board::ALL, BoardKind::Claim, 'I am touching this', null, ['src/Shared.php']);
        $theirs = $b->post(Board::ALL, BoardKind::Claim, 'so am I', null, ['src/Shared.php']);

        $this->assertSame(
            [$mine->id, $theirs->id],
            array_map(static fn (BoardEntry $e): int => $e->id, $a->outstandingClaims()),
            'two claims of one path both stand: the board is a notice, never a lock',
        );

        $b->post(Board::ALL, BoardKind::Release, 'I am done', null, ['src/Shared.php']);

        $claims = $a->outstandingClaims();
        $this->assertCount(1, $claims);
        $this->assertSame($mine->id, $claims[0]->id, 'a RELEASE speaks for its own claimant only');
        $this->assertSame(['src/Shared.php'], $claims[0]->paths);
    }

    public function testTheLaterClaimOfAPathIsTheOneOnRecord(): void
    {
        $board = $this->board(['a', 'b']);
        $a = $board->forMember('a-1');

        $first = $a->post(Board::ALL, BoardKind::Claim, 'starting', null, ['src/A.php', 'src/Shared.php']);
        $second = $a->post(Board::ALL, BoardKind::Claim, 'moved on', null, ['src/A.php']);

        $claims = $a->outstandingClaims();
        $this->assertSame([$first->id, $second->id], array_map(static fn (BoardEntry $e): int => $e->id, $claims));
        $this->assertSame(['src/Shared.php'], $claims[0]->paths, 'the superseded CLAIM survives only for the path nobody re-claimed');
        $this->assertSame(['src/A.php'], $claims[1]->paths);

        $a->post(Board::ALL, BoardKind::Release, 'both done', null, ['src/A.php', 'src/Shared.php']);
        $this->assertSame([], $a->outstandingClaims(), 'one RELEASE clears both CLAIMs it names');
    }

    public function testAPathSpelledLikeANumberStillReadsAsAPath(): void
    {
        $board = $this->board(['a', 'b']);
        $a = $board->forMember('a-1');

        $claim = $a->post(Board::ALL, BoardKind::Claim, 'odd names', null, ['404', 'src/2.php', 'src/Ok.php']);

        $claims = $a->outstandingClaims();
        $this->assertCount(1, $claims);
        $this->assertSame($claim->id, $claims[0]->id);
        $this->assertSame(['404', 'src/2.php', 'src/Ok.php'], $claims[0]->paths, 'numeric-looking paths come back as the strings they were posted as');
    }

    /**
     * @return iterable<string, array{0: BoardKind, 1: list<mixed>, 2: string}>
     */
    public static function claimRefusals(): iterable
    {
        yield 'INFO carrying paths' => [BoardKind::Info, ['src/A.php'], 'paths belong to a CLAIM or a RELEASE'];
        yield 'VETO carrying paths' => [BoardKind::Veto, ['src/A.php'], 'say what the VETO is about in its body'];
        yield 'CLAIM with none' => [BoardKind::Claim, [], 'names the paths it is standing on'];
        yield 'RELEASE with none' => [BoardKind::Release, [], 'names the paths it is clearing'];
        yield 'a set too wide' => [BoardKind::Claim, array_fill(0, Board::MAX_CLAIM_PATHS + 1, 'src/A.php'), 'one post carries at most 64'];
        yield 'a path too long' => [BoardKind::Claim, [str_repeat('x', Board::MAX_PATH_BYTES + 1)], 'a path runs 1025 bytes'];
        yield 'a path that is not a string' => [BoardKind::Claim, [['src/A.php']], 'every path must be a string'];
        yield 'a blank path' => [BoardKind::Claim, ['   '], 'name the file, not a blank'];
    }

    /**
     * @dataProvider claimRefusals
     */
    public function testAClaimTheBoardCannotTakeIsRefusedAndNothingIsWritten(BoardKind $kind, array $paths, string $why): void
    {
        $board = $this->board(['a', 'b']);

        try {
            $board->forMember('a-1')->post(Board::ALL, $kind, 'body', null, $paths);
            $this->fail('the post was accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString($why, $e->getMessage());
        }
        $this->assertSame(0, $board->head());
    }

    /**
     * @param list<string> $agents
     */
    private function board(array $agents): Board
    {
        $board = Board::create(array_map(static fn (string $a): BoardMember => BoardMember::new($a, 'task ' . $a), $agents));
        $this->assertNotNull($board);
        $this->boards[] = $board;

        return $board;
    }
}
