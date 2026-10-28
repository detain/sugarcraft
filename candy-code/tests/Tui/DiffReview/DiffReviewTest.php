<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui\DiffReview;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tui\DiffReview\DiffReview;
use SugarCraft\Code\Tui\DiffReview\DiffReviewAction;
use SugarCraft\Code\Tui\DiffReview\DiffReviewActionKind;
use SugarCraft\Code\Tui\DiffReview\DiffReviewSource;
use SugarCraft\Code\Tui\DiffReview\OpenDiffReviewMsg;
use SugarCraft\Code\Workspace\GitRunner;
use SugarCraft\Code\Workspace\WorkspaceCheckpointer;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * C-TUI-2.d: the diff-review state machine — cursor and body motion clamp
 * rather than wrap, the base stepper reloads within the checkpoint history,
 * the turn view exists only where there is a pair to diff — and the source
 * that builds the same state from a real session store.
 *
 * @see DiffReview
 * @see DiffReviewSource
 */
final class DiffReviewTest extends TestCase
{
    use HomeSandboxTrait;

    private string $tmp;

    private string $repo;

    private EnhancedSessionStore $store;

    protected function setUp(): void
    {
        if (!GitRunner::available()) {
            self::markTestSkipped('git is not installed');
        }
        $this->tmp = (string) realpath(sys_get_temp_dir()) . '/sc_dreview_' . bin2hex(random_bytes(6));
        mkdir($this->tmp . '/db', 0o700, true);
        $this->useHomeSandbox($this->tmp . '/home');
        WorkspaceCheckpointer::forgetDisabled();
        EnhancedSessionStore::forgetAnnouncedSnapshots();

        $this->repo = $this->tmp . '/repo';
        mkdir($this->repo, 0o700, true);
        $this->gitIn($this->repo, 'init', '-q');
        file_put_contents($this->repo . '/tracked.txt', "original\n");
        $this->gitIn($this->repo, 'add', '.');
        $this->gitIn($this->repo, 'commit', '-q', '-m', 'base');

        $this->store = new EnhancedSessionStore($this->tmp . '/db/session.db');
        $this->store->createSession('s', 'echo', 'echo');
    }

    protected function tearDown(): void
    {
        WorkspaceCheckpointer::forgetDisabled();
        EnhancedSessionStore::forgetAnnouncedSnapshots();
        $this->restoreHomeSandbox();
        self::rmrf($this->tmp);
    }

    public function testTheCursorWalksTheFileListAndStopsAtTheEnds(): void
    {
        $review = DiffReview::open(self::pairRows(), 1, 3, false, 0);

        [$at, $action] = $review->update(new KeyMsg(KeyType::Down));
        self::assertNull($action);
        self::assertSame(1, $at->selectedIndex());
        [$stuck, $action] = $at->update(new KeyMsg(KeyType::Down));
        self::assertSame($at, $stuck, 'motion clamps at the last file, it never wraps');
        self::assertNull($action);
        [$back, $action] = $stuck->update(new KeyMsg(KeyType::Char, 'j'));
        self::assertSame($stuck, $back);
        [$first, $action] = $back->update(new KeyMsg(KeyType::Char, 'k'));
        self::assertSame(0, $first->selectedIndex());
        [$floor, $action] = $first->update(new KeyMsg(KeyType::Up));
        self::assertSame($first, $floor, 'and clamps at the first file too');
    }

    public function testMotionWithNoFilesIsANoOpButEscapeStillCloses(): void
    {
        $review = DiffReview::open([], 1, 1, false, 0);

        foreach ([new KeyMsg(KeyType::Down), new KeyMsg(KeyType::PageUp), new KeyMsg(KeyType::Home), new KeyMsg(KeyType::End)] as $key) {
            [$same, $action] = $review->update($key);
            self::assertSame($review, $same);
            self::assertNull($action);
        }
        self::assertSame([], $review->fileLabels());
        self::assertNull($review->selectedFile());
        self::assertSame([], $review->bodyLines());

        [$same, $action] = $review->update(new KeyMsg(KeyType::Escape));
        self::assertSame($review, $same);
        self::assertInstanceOf(DiffReviewAction::class, $action);
        self::assertSame(DiffReviewActionKind::Close, $action->kind);
    }

    public function testPagingMovesTheBodyAndClampsToItsLastLine(): void
    {
        $review = DiffReview::open([self::longRow('big.txt', 50)], 1, 1, false, 0);
        $last = count($review->bodyLines()) - 1;

        [$one, $action] = $review->update(new KeyMsg(KeyType::PageDown));
        self::assertNull($action);
        self::assertSame(DiffReview::PAGE_LINES, $one->scroll());
        [$two, $action] = $one->update(new KeyMsg(KeyType::PageDown));
        self::assertSame(2 * DiffReview::PAGE_LINES, $two->scroll());
        [$cap, $action] = $two->update(new KeyMsg(KeyType::PageDown));
        self::assertSame($last, $cap->scroll(), 'never past the last body line');
        [$same, $action] = $cap->update(new KeyMsg(KeyType::PageDown));
        self::assertSame($cap, $same);

        [$top, $action] = $cap->update(new KeyMsg(KeyType::Home));
        self::assertSame(0, $top->scroll());
        [$end, $action] = $top->update(new KeyMsg(KeyType::End));
        self::assertSame($last, $end->scroll());
        [$half, $action] = $end->update(new KeyMsg(KeyType::PageUp));
        self::assertSame($last - DiffReview::PAGE_LINES, $half->scroll());
    }

    public function testSelectingAnotherFileResetsTheBodyScroll(): void
    {
        $review = DiffReview::open([self::longRow('a.txt', 50), self::longRow('b.txt', 5)], 1, 1, false, 0);
        [$scrolled, $action] = $review->update(new KeyMsg(KeyType::PageDown));

        [$moved, $action] = $scrolled->update(new KeyMsg(KeyType::Down));
        self::assertSame(1, $moved->selectedIndex());
        self::assertSame(0, $moved->scroll(), 'a scroll remembered across a different patch is meaningless');
    }

    public function testBaseSteppingIsClampedAndReloadsTheNewBase(): void
    {
        $review = DiffReview::open(self::pairRows(), 2, 3, false, 0);

        [$back, $action] = $review->update(new KeyMsg(KeyType::Char, '-'));
        self::assertSame(3, $back->stepsBack());
        self::assertInstanceOf(DiffReviewAction::class, $action);
        self::assertSame(DiffReviewActionKind::Reload, $action->kind);
        self::assertSame(3, $action->stepsBack);
        self::assertFalse($action->turnOnly);
        [$atFloor, $action] = $back->update(new KeyMsg(KeyType::Char, '-'));
        self::assertSame($back, $atFloor, 'there is no fourth checkpoint back');
        self::assertNull($action);

        [$forth, $action] = $atFloor->update(new KeyMsg(KeyType::Char, '+'));
        self::assertSame(2, $forth->stepsBack());
        [$front, $action] = $forth->update(new KeyMsg(KeyType::Char, '='));
        self::assertSame(1, $front->stepsBack(), 'the most recent base');
        [$edge, $action] = $front->update(new KeyMsg(KeyType::Char, '+'));
        self::assertSame($front, $edge, 'the newest base is as far forward as the list goes');
        self::assertNull($action);

        [$scrolled, $action] = $front->update(new KeyMsg(KeyType::PageDown));
        self::assertSame(count($scrolled->bodyLines()) - 1, $scrolled->scroll(), 'a short body pages to its own end');
        [$reset, $action] = $scrolled->update(new KeyMsg(KeyType::Char, '-'));
        self::assertSame(2, $reset->stepsBack());
        self::assertSame(0, $reset->selectedIndex());
        self::assertSame(0, $reset->scroll(), 'a new base means a new file list');
    }

    public function testSteppingTheBaseWithoutBasesIsANoOp(): void
    {
        $review = DiffReview::open(self::pairRows(), 1, 0, false, 0);

        [$same, $action] = $review->update(new KeyMsg(KeyType::Char, '-'));
        self::assertSame($review, $same);
        self::assertNull($action);
    }

    public function testTheTurnViewNeedsAPairAndClampsWhenItFlips(): void
    {
        $review = DiffReview::open(self::pairRows(), 3, 3, false, 0);

        [$on, $action] = $review->update(new KeyMsg(KeyType::Char, 't'));
        self::assertTrue($on->turnOnly());
        self::assertSame(2, $on->stepsBack(), 'a turn needs both sides, so the oldest single turn is back to the second checkpoint');
        self::assertInstanceOf(DiffReviewAction::class, $action);
        self::assertSame(2, $action->stepsBack);
        self::assertTrue($action->turnOnly, 'the flip always reloads: the two views come from different sources');
        [$edge, $action] = $on->update(new KeyMsg(KeyType::Char, '-'));
        self::assertSame($on, $edge, 'inside the turn view the bound is bases minus one');
        self::assertNull($action);

        [$off, $action] = $on->update(new KeyMsg(KeyType::Char, 't'));
        self::assertFalse($off->turnOnly());
        self::assertSame(2, $off->stepsBack());
        self::assertInstanceOf(DiffReviewAction::class, $action);
        self::assertFalse($action->turnOnly);

        $single = DiffReview::open(self::pairRows(), 1, 1, false, 0);
        [$same, $action] = $single->update(new KeyMsg(KeyType::Char, 't'));
        self::assertSame($single, $same, 'one checkpoint is no pair to turn-diff');
        self::assertNull($action);

        $startedTurn = DiffReview::open(self::pairRows(), 1, 1, true, 0);
        [$openedOut, $action] = $startedTurn->update(new KeyMsg(KeyType::Char, 't'));
        self::assertFalse($openedOut->turnOnly());
        self::assertInstanceOf(DiffReviewAction::class, $action, 'turning OFF is always allowed');
    }

    public function testForeignAndModifiedKeysLeaveTheStateAlone(): void
    {
        $review = DiffReview::open(self::pairRows(), 1, 3, false, 0);

        foreach ([
            new KeyMsg(KeyType::Char, 't', ctrl: true),
            new KeyMsg(KeyType::Char, 'j', alt: true),
            new KeyMsg(KeyType::Char, 'x'),
            new KeyMsg(KeyType::Enter),
            new KeyMsg(KeyType::Tab),
        ] as $key) {
            [$same, $action] = $review->update($key);
            self::assertSame($review, $same, $key->string() . ' moves nothing');
            self::assertNull($action);
        }
    }

    public function testOpenNormalisesStrangerStates(): void
    {
        $review = DiffReview::open(self::pairRows(), 9, 2, false, -5);
        self::assertSame(2, $review->stepsBack(), 'past the oldest base lands on it');
        self::assertSame(0, $review->omittedLines());
        self::assertSame(0, $review->selectedIndex());
        self::assertSame(0, $review->scroll());

        $low = DiffReview::open(self::pairRows(), -3, 5, false, 2);
        self::assertSame(1, $low->stepsBack(), 'and before the newest base lands on it');
        self::assertSame(2, $low->omittedLines());
        self::assertSame(5, $low->bases());
    }

    public function testTheTextComesFromTheCatalogue(): void
    {
        $review = DiffReview::open(self::pairRows(), 2, 3, false, 0);
        self::assertSame(Lang::t('tui.diffreview.title', ['steps' => 2]), $review->title());
        self::assertSame('', $review->omittedNotice());

        $turn = DiffReview::open(self::pairRows(), 1, 3, true, 7);
        self::assertSame(
            Lang::t('tui.diffreview.title', ['steps' => 1]) . Lang::t('tui.diffreview.turn_suffix'),
            $turn->title(),
        );
        self::assertSame(Lang::t('tui.diffreview.omitted', ['lines' => 7]), $turn->omittedNotice());
        self::assertSame(Lang::t('tui.diffreview.empty'), $turn->emptyNotice());
        self::assertSame(Lang::t('tui.diffreview.hints'), $turn->hints());
    }

    public function testLabelsAndBodyExposeTheSelectedRows(): void
    {
        $rows = self::pairRows();
        $review = DiffReview::open($rows, 1, 1, false, 0);

        self::assertSame(['M a.txt +1 -1', 'A b.txt +2 -0'], $review->fileLabels());
        self::assertSame(explode("\n", $rows[0]['patch']), $review->bodyLines());
        self::assertSame($rows[0], $review->selectedFile());
        self::assertSame(2, $review->fileCount());

        [$flip, $action] = $review->update(new KeyMsg(KeyType::Down));
        self::assertSame(explode("\n", $rows[1]['patch']), $flip->bodyLines());

        $message = new OpenDiffReviewMsg($flip, '');
        self::assertSame($flip, $message->review);
        self::assertSame('', $message->reason);
        $refused = new OpenDiffReviewMsg(null, 'no checkpoints');
        self::assertNull($refused->review);
        self::assertSame('no checkpoints', $refused->reason);
    }

    public function testTheSourceNeedsACapturedCheckpointAndSaysSo(): void
    {
        self::assertSame(0, DiffReviewSource::baseCount($this->store, 's'));
        self::assertSame(
            'this session has no file checkpoints to review',
            DiffReviewSource::load($this->store, 's', $this->repo, 1),
        );
    }

    public function testTheSourceReadsTheRealCheckpointHistoryBackwards(): void
    {
        $first = $this->store->saveCheckpoint('s', ['inputBuf' => '']);
        self::assertSame('captured', $this->store->captureWorkspace('s', $first, $this->repo)['status']);

        file_put_contents($this->repo . '/a.txt', "turn-one\n");
        $second = $this->store->saveCheckpoint('s', ['inputBuf' => '']);
        self::assertSame('captured', $this->store->captureWorkspace('s', $second, $this->repo)['status']);

        file_put_contents($this->repo . '/b.txt', "turn-two\n");

        self::assertSame(2, DiffReviewSource::baseCount($this->store, 's'));
        $bases = DiffReviewSource::bases($this->store, 's');
        self::assertSame([$second, $first], array_column($bases, 'index'), 'newest first, like /diff counts');

        $againstWorkTree = DiffReviewSource::load($this->store, 's', $this->repo, 1);
        self::assertInstanceOf(DiffReview::class, $againstWorkTree);
        self::assertSame(2, $againstWorkTree->bases());
        self::assertSame(1, $againstWorkTree->stepsBack());
        self::assertSame(['b.txt'], array_column($againstWorkTree->files(), 'path'), 'the newest snapshot plus the later work: only b.txt moved');

        $olderBase = DiffReviewSource::load($this->store, 's', $this->repo, 2);
        self::assertInstanceOf(DiffReview::class, $olderBase);
        self::assertSame(['a.txt', 'b.txt'], array_column($olderBase->files(), 'path'));
        self::assertSame(2, $olderBase->stepsBack());

        $clamped = DiffReviewSource::load($this->store, 's', $this->repo, 9);
        self::assertInstanceOf(DiffReview::class, $clamped);
        self::assertSame(2, $clamped->stepsBack(), 'stepping past the list is clamped, not refused');
    }

    public function testTheTurnViewDiffSTwoCheckpointsAgainstEachOther(): void
    {
        $first = $this->store->saveCheckpoint('s', ['inputBuf' => '']);
        self::assertSame('captured', $this->store->captureWorkspace('s', $first, $this->repo)['status']);

        file_put_contents($this->repo . '/a.txt', "turn-one\n");
        $second = $this->store->saveCheckpoint('s', ['inputBuf' => '']);
        self::assertSame('captured', $this->store->captureWorkspace('s', $second, $this->repo)['status']);

        file_put_contents($this->repo . '/b.txt', "turn-two\n");

        $turn = DiffReviewSource::load($this->store, 's', $this->repo, 1, true);
        self::assertInstanceOf(DiffReview::class, $turn);
        self::assertTrue($turn->turnOnly());
        self::assertSame(['a.txt'], array_column($turn->files(), 'path'), 'the work of that turn, frozen — not the work tree');

        $lonely = DiffReviewSource::load($this->store, 's', $this->repo, 1, false);
        self::assertInstanceOf(DiffReview::class, $lonely);
        $single = new EnhancedSessionStore($this->tmp . '/db/single.db');
        $single->createSession('one', 'echo', 'echo');
        $only = $single->saveCheckpoint('one', ['inputBuf' => '']);
        self::assertSame('captured', $single->captureWorkspace('one', $only, $this->repo)['status']);
        self::assertSame(
            'this session has only one checkpoint; there is no earlier turn to diff against',
            DiffReviewSource::load($single, 'one', $this->repo, 1, true),
        );
    }

    /**
     * @return list<array{status: string, path: string, added: int, removed: int, patch: string}>
     */
    private static function pairRows(): array
    {
        return [
            ['status' => 'M', 'path' => 'a.txt', 'added' => 1, 'removed' => 1, 'patch' => self::patchFor('a.txt', 1, 1)],
            ['status' => 'A', 'path' => 'b.txt', 'added' => 2, 'removed' => 0, 'patch' => self::patchFor('b.txt', 2, 0)],
        ];
    }

    /**
     * @return array{status: string, path: string, added: int, removed: int, patch: string}
     */
    private static function longRow(string $path, int $bodyLines, string $status = 'M'): array
    {
        return [
            'status' => $status,
            'path' => $path,
            'added' => $bodyLines,
            'removed' => 0,
            'patch' => self::patchFor($path, $bodyLines, 0),
        ];
    }

    private static function patchFor(string $path, int $added, int $removed): string
    {
        $body = '';
        for ($i = 0; $i < $removed; $i++) {
            $body .= '-old line ' . $i . "\n";
        }
        for ($i = 0; $i < $added; $i++) {
            $body .= '+new line ' . $i . "\n";
        }

        return 'diff --git a/' . $path . ' b/' . $path . "\n"
            . 'index 1111111..2222222 100644' . "\n"
            . '--- a/' . $path . "\n"
            . '+++ b/' . $path . "\n"
            . '@@ -1 +1,' . max(1, $added) . ' @@' . "\n"
            . rtrim($body, "\n");
    }

    private function gitIn(string $dir, string ...$args): string
    {
        $command = 'git -c user.name=t -c user.email=t@example.invalid -c commit.gpgSign=false -C ' . escapeshellarg($dir);
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        exec($command . ' 2>&1', $out, $code);
        self::assertSame(0, $code, $command . "\n" . implode("\n", $out));

        return implode("\n", $out);
    }

    private static function rmrf(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::rmrf($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
