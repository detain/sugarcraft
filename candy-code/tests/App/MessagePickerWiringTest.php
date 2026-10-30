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
use SugarCraft\Code\App\Tabs\TabScopedMsg;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Message;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Tests\Support\CheckpointedRepoTrait;
use SugarCraft\Code\Tui\DiffReview\DiffReview;
use SugarCraft\Code\Tui\MessagePicker\MessagePicker;
use SugarCraft\Code\Tui\MessagePicker\OpenMessagePickerMsg;
use SugarCraft\Code\Tui\Renderer as TuiRenderer;

/**
 * C-TUI-3.d: the wiring around the message-picker shell slot.
 *
 * The idle double-`Esc` answers an {@see OpenMessagePickerMsg}; the shell
 * dials the session store's checkpoint prompts into a {@see MessagePicker},
 * routes its keys, hands the band back on Escape, and on Enter runs
 * `Chat::rewindToCheckpoint()` — whose RestoreCheckpoint re-seeds the
 * chosen prompt into the composer. The model itself is pinned in
 * tests/Tui/MessagePicker/; the registry rows in KeyBindingDriftTest.
 */
final class MessagePickerWiringTest extends TestCase
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

    public function testTheIdleDoubleEscapeDialsTheStoreIntoTheSlot(): void
    {
        $app = $this->pickerApp();

        [$armed, $first] = $app->update(new KeyMsg(KeyType::Escape));
        $this->assertNull($first, 'the arming press sends nothing');
        $this->assertNull($armed->messagePicker);

        [, $second] = $armed->update(new KeyMsg(KeyType::Escape));
        $this->assertInstanceof(\Closure::class, $second, 'the pairing press answers with a Cmd');
        $this->assertInstanceof(OpenMessagePickerMsg::class, $second());

        [$opened, $cmd] = $armed->update(new OpenMessagePickerMsg());
        $this->assertNull($cmd);
        $this->assertInstanceof(MessagePicker::class, $opened->messagePicker);
        $rows = $opened->messagePicker->rows();
        $this->assertCount(2, $rows, 'both checkpointed prompts are offered');
        $this->assertSame(1, $rows[0]['stepsBack']);
        $this->assertSame('second', $rows[0]['prompt'], 'the newest checkpoint leads');
        $this->assertSame(2, $rows[1]['stepsBack']);
        $this->assertSame('first', $rows[1]['prompt']);
        $this->assertSame(0, $opened->messagePicker->selectedIndex());
    }

    public function testTheSlotAnswersItsKeysAndEscHandsTheBandBack(): void
    {
        $opened = $this->openSlot($this->pickerApp());

        [$moved] = $opened->update(new KeyMsg(KeyType::Down));
        $this->assertNotNull($moved->messagePicker);
        $this->assertSame(1, $moved->messagePicker->selectedIndex());

        [$closed] = $opened->update(new KeyMsg(KeyType::Escape));
        $this->assertNull($closed->messagePicker, 'Esc hands the band back');
    }

    public function testTheAcceptedRowRewindsTheChatAndSeedsItsPrompt(): void
    {
        $opened = $this->openSlot($this->pickerApp());

        [$settled, $cmd] = $opened->update(new KeyMsg(KeyType::Enter));
        $this->assertNull($settled->messagePicker, 'the accept closes the slot');
        if ($cmd !== null) {
            $out = $cmd();
            $this->assertNull($out[0]->messagePicker, 'the host rows never re-open it');
        }
        $this->assertNotNull($settled->chat);
        $this->assertSame(
            'second',
            $settled->chat->inputBuf,
            'one step back re-seeds the newest checkpoint\'s prompt',
        );
        $history = $settled->chat->history;
        $this->assertCount(4, $history, 'restored turns, then the host rows the command leaves behind');
        $this->assertSame(['User', 'Assistant', 'User', 'Assistant'], array_map(static fn (Message $m): string => $m->role->name, $history));
        $this->assertSame('first', (string) $history[0]->content);
        $this->assertSame('one', (string) $history[1]->content);
        $this->assertStringStartsWith('/rewind 1 --chat', (string) $history[2]->content);
        $this->assertStringStartsWith('Rewound ', (string) $history[3]->content);
    }

    public function testTheSecondRowRewindsOneTurnDeeper(): void
    {
        $opened = $this->openSlot($this->pickerApp());

        [$deeper] = $opened->update(new KeyMsg(KeyType::Down));
        [$settled] = $deeper->update(new KeyMsg(KeyType::Enter));
        $this->assertNull($settled->messagePicker);
        $this->assertNotNull($settled->chat);
        $this->assertSame('first', $settled->chat->inputBuf);
        // The 'first' checkpoint's saved transcript ends on the very prompt
        // its draft re-seeds — the legacy shape checkpointChatState trims,
        // so no line is duplicated between box and transcript.
        $this->assertCount(2, $settled->chat->history, 'only the host command rows remain');
        $this->assertStringStartsWith('/rewind 2 --chat', (string) $settled->chat->history[0]->content);
    }

    public function testTheEmptyBoxEnterIsInert(): void
    {
        $app = $this->pickerApp();
        $opened = $app->openMessagePicker([]);

        [$settled, $cmd] = $opened->update(new KeyMsg(KeyType::Enter));
        $this->assertNotNull($settled->messagePicker, 'the honest empty box stays up');
        $this->assertNull($cmd);

        [$closed] = $settled->update(new KeyMsg(KeyType::Escape));
        $this->assertNull($closed->messagePicker, 'and Escape still hands it back');
    }

    public function testInFlightDoubleEscapeCancelsAndNeverOpens(): void
    {
        $busy = new Chat(
            history: [Message::user('first'), Message::assistant('one')],
            backend: new EchoBackend(),
            inFlight: true,
        );
        $app = $this->appOn($busy);

        [$armed, $first] = $app->update(new KeyMsg(KeyType::Escape));
        $this->assertNull($first, 'the in-flight first press is the soft-cancel, not the picker');
        $this->assertNotNull($armed->chat);
        $this->assertTrue($armed->chat->inFlight, 'the turn rides through the soft cancel');

        [$cancelled, $second] = $armed->update(new KeyMsg(KeyType::Escape));
        $this->assertNull($second, 'the in-flight pairing press cancels; the picker never answers');
        $this->assertNotNull($cancelled->chat);
        $this->assertFalse($cancelled->chat->inFlight, 'the cancel settles exactly as before');
        $this->assertNull($cancelled->messagePicker);
    }

    public function testADoubleTapStraddlingTheStampNeverOpens(): void
    {
        // The first press landed while the turn was still in flight (a
        // POSITIVE stamp); the turn then ended. The next idle press must
        // re-arm, not pair — the sign is the state that armed it.
        $straddling = new Chat(
            history: [Message::user('first'), Message::assistant('one')],
            backend: new EchoBackend(),
            lastEscapeAt: microtime(true),
        );
        $app = $this->appOn($straddling);

        [$next, $cmd] = $app->update(new KeyMsg(KeyType::Escape));
        $this->assertNull($cmd, 'an idle press cannot pair with an in-flight stamp');
        $this->assertNull($next->messagePicker);
    }

    public function testADoubleTapWithATypedDraftDoesNotOpen(): void
    {
        $app = $this->pickerApp('half-typed');

        [$armed, $first] = $app->update(new KeyMsg(KeyType::Escape));
        $this->assertNull($first);

        [, $second] = $armed->update(new KeyMsg(KeyType::Escape));
        $this->assertNull($second, 'a draft under the caret keeps the composer alone');
    }

    public function testKeysAndGesturesNeverLeakToTheComposerWhileOpen(): void
    {
        $opened = $this->openSlot($this->pickerApp());

        [$typed] = $opened->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertNotNull($typed->messagePicker, 'the picker keeps the band on a filter char');
        $this->assertSame('x', $typed->messagePicker->query());
        $this->assertSame(
            $opened->chat?->history,
            $typed->chat?->history,
            'the composer under it saw nothing',
        );

        [$wheeled] = $opened->update(new MouseWheelMsg(1, 1, MouseButton::WheelDown, MouseAction::Press));
        $this->assertSame($opened->messagePicker, $wheeled->messagePicker, 'the band is keyboard-only: wheel is inert');

        [$clicked] = $opened->update(new MouseClickMsg(1, 1, MouseButton::Left, MouseAction::Press));
        $this->assertSame($opened->messagePicker, $clicked->messagePicker, 'clicks are inert too');
    }

    public function testThePickerPaintsOverTheTranscriptLikeTheOtherSlots(): void
    {
        $opened = $this->openSlot($this->pickerApp());
        $frame = TuiRenderer::render($opened, 100, 30);
        $this->assertStringContainsString('Rewind to a message', $frame);
        $this->assertStringContainsString('second', $frame, 'the offered prompts ride the frame');

        $both = $opened->update(new \SugarCraft\Code\Tui\DiffReview\OpenDiffReviewMsg(
            DiffReview::open([[
                'status' => 'M',
                'path' => 'file.txt',
                'added' => 1,
                'removed' => 1,
                'patch' => implode("\n", [
                    'diff --git a/file.txt b/file.txt',
                    '--- a/file.txt',
                    '+++ b/file.txt',
                    '@@ -1 +1 @@',
                    '-gone',
                    '+here',
                ]),
            ]], 1, 2, false, 0),
        ))[0];
        $this->assertNotNull($both->diffReview);
        $this->assertNotNull($both->messagePicker, 'the slots coexist on the state');
        $this->assertStringNotContainsString(
            'Rewind to a message',
            TuiRenderer::render($both, 100, 30),
            'the review is later in the slot chain, so it owns the band when both are set',
        );
    }

    public function testTheScopedFormNeverReachesTheDialArm(): void
    {
        // The dial arm answers the bare Msg only; a TabScoped-wrapped one
        // runs through handleTabScoped(), which either is inert without a
        // deck or routes the inner to the parked Chat — where no arm of
        // its own exists. Either way the foreground slot never pops.
        $app = $this->pickerApp();

        [$next, $cmd] = $app->update(new TabScopedMsg('parked', new OpenMessagePickerMsg()));
        $this->assertNull($next->messagePicker, 'a scoped message must not pop the shell slot');
        $this->assertNull($cmd);
    }

    // ── fixtures ─────────────────────────────────────────────────────────

    /**
     * The two-turn session the welded fixtures use — checkpoints labelled
     * 'first' then 'second' — written exactly once per test.
     */
    private function seedTurns(): void
    {
        if ($this->turnsSeeded) {
            return;
        }
        $this->turnsSeeded = true;

        $this->turnCheckpoint([], 'first');
        $this->turnCheckpoint([
            Message::user('first'),
            Message::assistant('one'),
        ], 'second');
    }

    /** The shell with a store-backed, idle chat under the picker's keys. */
    private function pickerApp(string $input = ''): App
    {
        $this->seedTurns();

        return $this->appOn($this->chatAt($input, [
            Message::user('first'),
            Message::assistant('one'),
        ]));
    }

    /** The shell holding `$chat`, with the mock provider it never calls here. */
    private function appOn(Chat $chat): App
    {
        return App::new($this->createMock(ProviderInterface::class), 'test-model')->withChat($chat);
    }

    /** The shell with the slot dialed open through its own message. */
    private function openSlot(App $app): App
    {
        return $app->update(new OpenMessagePickerMsg())[0];
    }
}
