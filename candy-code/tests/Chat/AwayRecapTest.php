<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\BlurMsg;
use SugarCraft\Core\Msg\FocusGainedMsg;
use SugarCraft\Core\Msg\FocusLostMsg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Code\App\SideQuestionAnsweredMsg;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Commands\CommandRegistry;
use SugarCraft\Code\Config\Settings\UiSettings;
use SugarCraft\Code\Host\Commands\RecapHostCommand;
use SugarCraft\Code\Message;
use SugarCraft\Code\Role;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tui\AwayTracker;
use SugarCraft\Code\Usage;

/**
 * C-TUI-3.g (GAP-UI-N4): the away recap. A focus regain past three quiet
 * minutes — idle, empty draft, at least three settled user turns, setting
 * on — asks the title model one recap through the SAME side-call envelope as
 * `/btw`, without the echo row. `/recap` asks for one at any time.
 *
 * The stamp is seeded through AwayTracker's injectable clock; the msg pair
 * itself is driven through `update()` so the arms — not just the tracker —
 * are what the tests exercise.
 *
 * One disclosed shape: the resolved answer folds through the shared
 * `SideQuestionAnsweredMsg` handler, whose row carries the `/btw` voice
 * (that file is outside this step's declared set; the seam is reported). The
 * content, the ui-only status and the accounting are pinned here regardless.
 */
final class AwayRecapTest extends TestCase
{
    use HomeSandboxTrait;

    /** Absence length that clears the threshold, in seconds. */
    private const AWAY = 200.0;

    protected function setUp(): void
    {
        AwayTracker::reset();
    }

    protected function tearDown(): void
    {
        AwayTracker::reset();
        UiSettings::forget();
    }

    public function testUnderTheThresholdTheReturnIsSilent(): void
    {
        $title = $this->titleBackend('x');
        $chat = $this->chat($title);

        [$idle, $cmd] = $chat->update(new FocusLostMsg());
        self::assertNull($cmd, 'the loss only stamps time');

        [$back, $cmd] = $idle->update(new FocusGainedMsg());

        self::assertNull($cmd, 'a blink is not an absence');
        self::assertSame([], $title->asked);
        self::assertSame($chat->history, $back->history);
    }

    public function testTheBlurShapeTheDecoderDeliversArmsTheRecap(): void
    {
        // Fix round-1 M1: sugar-core's focus-OUT report (CSI with final 'O')
        // decodes to a BlurMsg — a FocusLostMsg is constructed nowhere in
        // production — so the real-terminal chain is blur, then regain.
        // Driving that first half must arm the stamp itself; the absence is
        // then stretched past the threshold through AwayTracker's own
        // stored clock (the same time seam beAway() seeds) so the regain the
        // decoder pairs with it fires the paid call end to end.
        $title = $this->titleBackend('Paused at the fixture table.');
        $chat = $this->chat($title);

        [$idle, $cmd] = $chat->update(new BlurMsg());
        self::assertNull($cmd, 'the blur only stamps time');

        $tracker = new \ReflectionClass(AwayTracker::class);
        $lostAt = $tracker->getStaticPropertyValue('lostAt');
        self::assertIsFloat($lostAt, 'the blur armed an absence stamp');
        $tracker->setStaticPropertyValue('lostAt', $lostAt - self::AWAY);

        [$back, $cmd] = $idle->update(new FocusGainedMsg());
        self::assertNotNull($cmd, 'a regain after a real blur asks for the recap');

        $answer = $this->resolve($cmd);
        self::assertInstanceOf(SideQuestionAnsweredMsg::class, $answer);
        [$settled] = $back->update($answer);
        $last = $settled->history[\count($settled->history) - 1];
        self::assertSame(Role::Assistant, $last->role);
        self::assertTrue($last->uiOnly);
        self::assertStringContainsString('Paused at the fixture table.', $last->content);
        self::assertCount(1, $title->asked);
    }

    public function testAPastThresholdRegainRecapsOnceWithoutAnEchoRow(): void
    {
        $title = $this->titleBackend('It was mid-refactor of Chat.php, tests owed.');
        $chat = $this->chat($title);
        $wireBefore = Message::agentVisible($chat->history);

        $this->beAway();
        [$away, $cmd] = $chat->update(new FocusGainedMsg());

        self::assertNotNull($cmd, 'the return asks for a recap');
        $answer = $this->resolve($cmd);
        self::assertInstanceOf(SideQuestionAnsweredMsg::class, $answer);

        [$settled] = $away->update($answer);
        $last = $settled->history[\count($settled->history) - 1];
        self::assertSame(Role::Assistant, $last->role);
        self::assertTrue($last->uiOnly, 'the recap is shown, never sent');
        self::assertStringContainsString('It was mid-refactor of Chat.php, tests owed.', $last->content);
        self::assertSame($wireBefore, Message::agentVisible($settled->history), 'the model history is untouched');
        self::assertEqualsWithDelta(0.001, $settled->spentUsd(), 1e-9, 'the recap call is accounted');

        // No `/recap` echo row — the user typed nothing.
        foreach ($settled->history as $message) {
            self::assertThat(
                $message->role !== Role::User || !\str_starts_with($message->content, '/recap'),
                self::isTrue(),
                'the automatic path writes no command echo',
            );
        }

        self::assertCount(1, $title->asked);
        self::assertSame(RecapHostCommand::FRAMING, $title->asked[0][0]->content, 'the recap voice, not the btw one');
        self::assertStringContainsString('where did this session leave off', $title->asked[0][1]->content);
    }

    public function testOneRecapPerAbsence(): void
    {
        $title = $this->titleBackend('x');
        $chat = $this->chat($title);

        $this->beAway();
        [, $cmd] = $chat->update(new FocusGainedMsg());
        self::assertNotNull($cmd);

        // A second regain without a new loss is the same absence already
        // paid for — the bound that keeps the automatic call from spending
        // twice on the user's key.
        [, $again] = $chat->update(new FocusGainedMsg());
        self::assertNull($again);
    }

    public function testMidTurnTheReturnIsSilentAndTheStampIsConsumed(): void
    {
        $title = $this->titleBackend('x');
        $busy = $this->chat($title, inFlight: true);

        $this->beAway();
        [$held, $cmd] = $busy->update(new FocusGainedMsg());

        self::assertNull($cmd, 'a running turn answers itself');
        self::assertTrue($held->inFlight);
        self::assertSame([], $title->asked);

        [, $again] = $held->update(new FocusGainedMsg());
        self::assertNull($again, 'the missed absence is not re-counted once the turn settles');
    }

    public function testADraftOnTheLineKeepsTheReturnSilent(): void
    {
        $title = $this->titleBackend('x');
        $drafted = $this->mutate($this->chat($title), ['inputBuf' => 'half a thought']);

        $this->beAway();
        [, $cmd] = $drafted->update(new FocusGainedMsg());

        self::assertNull($cmd, 'the user was mid-sentence, not away');
        self::assertSame([], $title->asked);
    }

    public function testTwoUserTurnsAreNotAStoryYet(): void
    {
        $short = $this->chat($this->titleBackend('x'), [
            Message::user('one'),
            Message::assistant('a'),
            Message::user('two'),
            Message::assistant('b'),
        ]);

        $this->beAway();
        [, $cmd] = $short->update(new FocusGainedMsg());

        self::assertNull($cmd, 'under the floor a recap would summarise a greeting');
    }

    public function testWithoutATitleModelTheReturnIsSilent(): void
    {
        $chat = $this->chat(null);

        $this->beAway();
        [$back, $cmd] = $chat->update(new FocusGainedMsg());

        self::assertNull($cmd, 'the automatic path says nothing rather than refusing loudly');
        self::assertSame($chat->history, $back->history);
    }

    public function testWithTheSettingOffTheReturnIsSilent(): void
    {
        $home = \sys_get_temp_dir() . '/crush-awayrecap-' . \bin2hex(\random_bytes(6));
        \mkdir($home . '/.candy-code', 0o700, true);
        $this->useHomeSandbox($home);
        try {
            \file_put_contents(
                $home . '/.candy-code/config.json',
                \json_encode(['sessions.awayRecap' => false], JSON_THROW_ON_ERROR),
            );
            UiSettings::forget();

            self::assertFalse(AwayTracker::recapEnabled());

            $title = $this->titleBackend('x');
            $chat = $this->chat($title);
            $this->beAway();

            [, $cmd] = $chat->update(new FocusGainedMsg());

            self::assertNull($cmd);
            self::assertSame([], $title->asked);
        } finally {
            UiSettings::forget();
            $this->restoreHomeSandbox();
            @\unlink($home . '/.candy-code/config.json');
            @\rmdir($home . '/.candy-code');
            @\rmdir($home);
        }
    }

    public function testATypedRecapEchoesAndAnswers(): void
    {
        $title = $this->titleBackend('Paused before the migration step.');
        $chat = $this->chat($title);

        [$asked, $cmd] = $this->submit($chat, '/recap');

        self::assertFalse($asked->inFlight);
        $echo = $asked->history[\count($asked->history) - 1];
        self::assertSame('/recap', $echo->content);
        self::assertTrue($echo->uiOnly);

        $answer = $this->resolve($cmd);
        [$settled] = $asked->update($answer);
        self::assertStringContainsString(
            'Paused before the migration step.',
            $settled->history[\count($settled->history) - 1]->content,
        );
        self::assertCount(1, $title->asked);
    }

    public function testTrailingWordsRideTheTypedCommand(): void
    {
        $title = $this->titleBackend('x');

        [, $cmd] = $this->submit($this->chat($title), '/recap and list the files');

        // The spec takes plain text so `accepts()` routes the trailing words
        // here; the recap question itself is fixed, and they change nothing
        // about the ask — they are simply not refused.
        self::assertNotNull($cmd);
        $this->resolve($cmd);
        self::assertCount(1, $title->asked);
    }

    public function testATypedRecapAtTheSpendCapRefuses(): void
    {
        $title = $this->titleBackend('x');
        $chat = new Chat(backend: new EchoBackend(), titleBackend: $title, maxCostUsd: 0.0005);
        [$spent] = $chat->update(new SideQuestionAnsweredMsg(null, 'earlier', Usage::new(10, 0.001)));

        [$next, $cmd] = $this->submit($spent, '/recap');

        self::assertNull($cmd);
        self::assertSame([], $title->asked);
        self::assertStringContainsString('so the recap was not asked', $next->history[\count($next->history) - 1]->content);
    }

    public function testAReadOnlyWindowRunsTheTypedRecap(): void
    {
        $chat = $this->mutate($this->chat($this->titleBackend('x')), ['readOnlySession' => true]);

        [, $cmd] = $this->submit($chat, '/recap');

        self::assertNotNull($cmd, 'a recap writes nothing the other window owns');
    }

    public function testItIsAdvertisedInSessionCategoryWithoutAHint(): void
    {
        $rows = \array_values(\array_filter(
            CommandRegistry::slashCommands(),
            static fn ($s): bool => $s->name === 'recap',
        ));

        self::assertCount(1, $rows);
        self::assertNull($rows[0]->argumentHint, 'it takes no argument at all');
        self::assertSame('Session', $rows[0]->category);
    }

    /**
     * Seed an absence longer than the threshold; the regain msgs the tests
     * then drive close it the way the terminal would.
     */
    private function beAway(): void
    {
        AwayTracker::focusLost(\microtime(true) - self::AWAY);
    }

    /** @param list<Message> $history */
    private function chat(?Backend $title, array $history = [], bool $inFlight = false): Chat
    {
        if ($history === []) {
            $history = [
                Message::user('first question'),
                Message::assistant('an answer'),
                Message::user('second question'),
                Message::assistant('another answer'),
                Message::user('third question'),
                Message::assistant('a third answer'),
            ];
        }

        return (new Chat(
            history: $history,
            inFlight: $inFlight,
            backend: new EchoBackend(),
            titleBackend: $title,
        ))->withSize(100, 30);
    }

    /** @return array{0: Chat, 1: ?\Closure} */
    private function submit(Chat $chat, string $draft): array
    {
        return $this->mutate($chat, ['inputBuf' => $draft])->update(new KeyMsg(KeyType::Enter));
    }

    /** @param array<string, mixed> $changes */
    private function mutate(Chat $chat, array $changes): Chat
    {
        return (new \ReflectionMethod(Chat::class, 'mutate'))->invoke($chat, $changes);
    }

    private function resolve(?\Closure $cmd): mixed
    {
        self::assertNotNull($cmd);
        $async = $cmd();
        self::assertInstanceOf(AsyncCmd::class, $async);
        $resolved = null;
        $async->promise->then(static function (mixed $msg) use (&$resolved): void {
            $resolved = $msg;
        });

        return $resolved;
    }

    private function titleBackend(string $reply): Backend
    {
        return new class ($reply) implements Backend {
            /** @var list<list<Message>> */
            public array $asked = [];

            public function __construct(private readonly string $reply)
            {
            }

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                return Message::assistant($this->reply);
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?\SugarCraft\Code\Backend\CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                $this->asked[] = $history;

                return \React\Promise\resolve(Message::assistant($this->reply)->withUsage(Usage::new(20, 0.001)));
            }
        };
    }
}
