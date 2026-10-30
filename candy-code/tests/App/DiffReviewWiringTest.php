<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseWheelMsg;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Message;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Tests\Support\CheckpointedRepoTrait;
use SugarCraft\Code\Tui\DiffReview\DiffReview;
use SugarCraft\Code\Tui\DiffReview\DiffReviewSource;
use SugarCraft\Code\Tui\DiffReview\OpenDiffReviewMsg;

/**
 * C-TUI-2.e: the wiring around the diff-review shell slot.
 *
 * `Chat::handleDiffCommand()` dials {@see DiffReviewSource} and answers the
 * shell with an {@see OpenDiffReviewMsg}; the shell holds the review as a
 * slot, routes its keys and wheel, redials the store when the base moves,
 * and hands the band back on close. The model itself is pinned in
 * tests/Tui/DiffReview/; the registry rows in KeyBindingDriftTest.
 */
final class DiffReviewWiringTest extends TestCase
{
    use CheckpointedRepoTrait;

    /** Whether this test has already written its two checkpointed turns. */
    private bool $turnsSeeded = false;

    protected function setUp(): void
    {
        $this->setUpCheckpointedRepo();
    }

    protected function tearDown(): void
    {
        $this->tearDownCheckpointedRepo();
    }

    public function testTheBareFormDialsTheReviewAndAnswersWithTheMsg(): void
    {
        $chat = $this->seededChat('/diff');

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertInstanceof(\Closure::class, $cmd, 'the bare /diff must answer with a Cmd');
        $msg = $cmd();
        $this->assertInstanceof(OpenDiffReviewMsg::class, $msg);
        $this->assertInstanceof(DiffReview::class, $msg->review, 'the msg carries a loaded review');
        $this->assertNotNull($msg->review);
        $this->assertSame(1, $msg->review->stepsBack());
        $this->assertSame(1, $msg->review->fileCount(), 'one file moved since the near base');
        $this->assertSame('', $next->inputBuf, 'the command clears the box like every other slash');
    }

    public function testThePrintFormKeepsTodayFencedRows(): void
    {
        $chat = $this->seededChat('/diff --print');

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertNull($cmd, '--print stays on the rows path');
        $this->assertStringContainsString('1 file changed since checkpoint 1', self::reply($next));
        $this->assertStringContainsString('```diff', self::reply($next));
    }

    public function testANumberedBaseOpensTheReviewThatFarBack(): void
    {
        $chat = $this->seededChat('/diff 2');

        [, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertInstanceof(\Closure::class, $cmd);
        $msg = $cmd();
        $this->assertInstanceof(OpenDiffReviewMsg::class, $msg);
        $this->assertNotNull($msg->review);
        $this->assertSame(2, $msg->review->stepsBack());
        $this->assertSame(2, $msg->review->fileCount(), 'against the oldest base both files moved');
    }

    public function testAWindowWithNoCheckpointsStillPrintsTheHostRows(): void
    {
        $chat = $this->chatAt('/diff', []);

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertNull($cmd, 'with nothing to open, the rows path answers');
        $this->assertStringContainsString('No checkpoints available', self::reply($next));
    }

    public function testUsageNoiseStillPrintsTheHostRows(): void
    {
        foreach (['/diff 0', '/diff last', '/diff 2 3'] as $input) {
            $chat = $this->seededChat($input);

            [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
            $this->assertNull($cmd, "'{$input}' belongs to the rows path");
            $this->assertStringStartsWith('Usage: /diff', self::reply($next));
        }
    }

    public function testTheShellOpensTheSlotRoutesKeysAndCloses(): void
    {
        $app = $this->reviewApp();
        $this->assertNull($app->diffReview, 'the slot starts empty');

        [$opened, $cmd] = $app->update(new OpenDiffReviewMsg($this->loadedReview(1)));
        $this->assertNull($cmd);
        $this->assertInstanceof(DiffReview::class, $opened->diffReview);
        $this->assertSame(1, $opened->diffReview->fileCount());

        [$moved] = $opened->update(new KeyMsg(KeyType::Down));
        $this->assertSame(0, $moved->diffReview->selectedIndex(), 'one file in, down clamps');

        [$closed] = $opened->update(new KeyMsg(KeyType::Escape));
        $this->assertNull($closed->diffReview, 'Esc hands the band back');
    }

    public function testKeysAndWheelNeverLeakToTheComposerWhileOpen(): void
    {
        $app = $this->reviewApp()->update(new OpenDiffReviewMsg($this->loadedReview(1)))[0];

        [$typed] = $app->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertNotNull($typed->diffReview, 'the review keeps the band on an unclaimed key');
        $this->assertSame(
            $app->chat?->history,
            $typed->chat?->history,
            'the composer under it saw nothing',
        );

        [$wheeled] = $app->update(new MouseWheelMsg(1, 1, MouseButton::WheelDown, MouseAction::Press));
        $this->assertNotNull($wheeled->diffReview);
        $this->assertSame(
            count($wheeled->diffReview->bodyLines()) - 1,
            $wheeled->diffReview->scroll(),
            'the wheel pages the body (clamped to the patch end)',
        );

        [$clicked] = $app->update(new MouseClickMsg(1, 1, MouseButton::Left, MouseAction::Press));
        $this->assertSame($wheeled, $clicked, 'the band is keyboard-only: clicks are inert');
    }

    public function testTheBaseKeyRedialsTheStoreForTheNewWindow(): void
    {
        $app = $this->reviewApp()->update(new OpenDiffReviewMsg($this->loadedReview(1)))[0];

        [$moved] = $app->update(new KeyMsg(KeyType::Char, '-'));
        $this->assertNotNull($moved->diffReview, 'the band stays up across the reload');
        $this->assertSame(2, $moved->diffReview->stepsBack());
        $this->assertSame(2, $moved->diffReview->fileCount(), 'the redial came from the real store');
        $this->assertStringContainsString('-base', implode("\n", $moved->diffReview->bodyLines()));
    }

    public function testTheReasonHalfOfTheMsgAnswersTheFooter(): void
    {
        $app = $this->reviewApp();

        [$noted] = $app->update(new OpenDiffReviewMsg(null, 'this session has no file checkpoints to review'));
        $this->assertNull($noted->diffReview, 'a refused open leaves no slot');
        $this->assertStringContainsString('no file checkpoints', (string) $noted->status);

        [$quiet] = $app->update(new OpenDiffReviewMsg(null, ''));
        $this->assertSame($app, $quiet, 'an empty reason is a no-op, not a silent footer');
    }

    // ── fixtures ─────────────────────────────────────────────────────────

    /**
     * The two-turn session the welded fixtures use — file.txt edited at each
     * turn, new.txt born at the second — written exactly once per test, so
     * every helper sees the same two file checkpoints.
     */
    private function seedTurns(): void
    {
        if ($this->turnsSeeded) {
            return;
        }
        $this->turnsSeeded = true;

        $this->turnCheckpoint([], 'first');
        file_put_contents($this->repo . '/file.txt', "turn one\n");
        file_put_contents($this->repo . '/new.txt', "created\n");

        $this->turnCheckpoint([
            Message::user('first'),
            Message::assistant('one'),
        ], 'second');
        file_put_contents($this->repo . '/file.txt', "turn two\n");
    }

    /** A window on the seeded session with `$input` armed in the box. */
    private function seededChat(string $input): Chat
    {
        $this->seedTurns();

        return $this->chatAt($input, [
            Message::user('first'),
            Message::assistant('one'),
            Message::user('second'),
        ]);
    }

    /** The review for base `$stepsBack`, straight from the real store. */
    private function loadedReview(int $stepsBack): DiffReview
    {
        $this->seedTurns();
        $loaded = DiffReviewSource::load($this->store, 'undo-session', $this->repo, $stepsBack);
        $this->assertInstanceof(DiffReview::class, $loaded);

        return $loaded;
    }

    /** An App whose chat holds the seeded sandbox session (slot empty). */
    private function reviewApp(): App
    {
        return App::new($this->createMock(ProviderInterface::class), 'test-model')
            ->withChat($this->seededChat(''));
    }
}
