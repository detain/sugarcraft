<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Backend\QueueMode;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Commands\CommandRegistry;
use SugarCraft\Crush\Host\Commands\BtwHostCommand;
use SugarCraft\Crush\Host\SubmitOptions;
use SugarCraft\Crush\Host\TurnController;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\SideQuestionAnsweredMsg;
use SugarCraft\Crush\ToolResult;
use SugarCraft\Crush\Usage;

/**
 * Roadmap 5.14b: `/btw <question>` asks the title model a side question from
 * a snapshot of the transcript, and neither the question nor the answer enters
 * what the agent is sent — idle or while a turn runs.
 *
 * @see BtwHostCommand
 */
final class BtwCommandTest extends TestCase
{
    public function testASideQuestionIsAnsweredAndKeptOutOfTheModelHistory(): void
    {
        $title = $this->titleBackend('It edited Chat.php and ran the tests.');
        $chat = $this->chat($title, [
            Message::user('fix the bug'),
            Message::system('Bash')->withToolResults([ToolResult::ok('Bash', 'OK (3 tests)', 'c1')]),
            Message::assistant('Fixed.'),
        ]);
        $wireBefore = Message::agentVisible($chat->history);

        [$asked, $cmd] = $this->submit($chat, '/btw what did you change?');

        self::assertFalse($asked->inFlight);
        self::assertSame('', $asked->inputBuf);
        $echo = $asked->history[\count($asked->history) - 1];
        self::assertSame('/btw what did you change?', $echo->content);
        self::assertTrue($echo->uiOnly);

        $answer = $this->resolve($cmd);
        self::assertInstanceOf(SideQuestionAnsweredMsg::class, $answer);
        [$answered] = $asked->update($answer);
        $last = $answered->history[\count($answered->history) - 1];
        self::assertSame(Role::Assistant, $last->role);
        self::assertTrue($last->uiOnly, 'the answer is shown, never sent');
        self::assertStringContainsString('It edited Chat.php and ran the tests.', $last->content);
        self::assertSame($wireBefore, Message::agentVisible($answered->history), 'the model history is untouched');
        self::assertEqualsWithDelta(0.001, $answered->spentUsd(), 1e-9, 'the side call is accounted');

        self::assertCount(1, $title->asked);
        $request = $title->asked[0][1]->content;
        self::assertStringContainsString('Tool result (Bash): OK (3 tests)', $request);
        self::assertStringContainsString("## Question\nwhat did you change?", $request);
        self::assertStringContainsString('do not call any tool', $request);
    }

    public function testItRunsWhileATurnIsInFlightAndLeavesTheTurnRunning(): void
    {
        $title = $this->titleBackend('Halfway through the refactor.');
        $busy = $this->chat($title, [Message::user('refactor it')], inFlight: true);

        [$asked, $cmd] = $this->submit($busy, '/btw how far along is it?');

        self::assertTrue($asked->inFlight, 'the running turn keeps running');
        self::assertSame('/btw how far along is it?', $asked->history[\count($asked->history) - 1]->content);
        self::assertNotNull($cmd);

        [$answered] = $asked->update($this->resolve($cmd));
        self::assertTrue($answered->inFlight);
        self::assertStringContainsString('Halfway through the refactor.', $answered->history[\count($answered->history) - 1]->content);
    }

    public function testAnyOtherCommandIsStillRefusedMidTurn(): void
    {
        $turns = TurnController::new();
        $enter = SubmitOptions::new();

        self::assertSame(TurnController::ROUTE_SIDE_QUESTION, $turns->midTurnRoute('/btw what now?', false, $enter));
        self::assertSame(TurnController::ROUTE_SIDE_QUESTION, $turns->midTurnRoute('/btw:what now?', false, $enter));
        self::assertSame(TurnController::ROUTE_REFUSE_COMMAND, $turns->midTurnRoute('/btwx', false, $enter));
        self::assertSame(TurnController::ROUTE_REFUSE_COMMAND, $turns->midTurnRoute('/compact', false, $enter));
        self::assertNotSame(TurnController::ROUTE_SIDE_QUESTION, $turns->midTurnRoute('btw what now?', false, $enter->withDelivery(QueueMode::Followup)));
    }

    public function testWithNoTitleModelNothingIsAsked(): void
    {
        foreach ([false, true] as $inFlight) {
            [$next, $cmd] = $this->submit($this->chat(null, [], $inFlight), '/btw anything?');

            self::assertNull($cmd);
            self::assertSame($inFlight, $next->inFlight);
            self::assertStringContainsString('needs a title model', $next->history[\count($next->history) - 1]->content);
        }
    }

    public function testABareBtwPrintsItsUsage(): void
    {
        [$next, $cmd] = $this->submit($this->chat($this->titleBackend('x')), '/btw');

        self::assertNull($cmd);
        self::assertStringStartsWith('Usage: /btw <question>', $next->history[\count($next->history) - 1]->content);
    }

    public function testAnAnswerForAnotherSessionIsDropped(): void
    {
        $chat = $this->chat($this->titleBackend('x'));

        [$after] = $chat->update(new SideQuestionAnsweredMsg('another-session', 'stale', Usage::new(5, 0.002)));

        self::assertSame($chat->history, $after->history);
        self::assertEqualsWithDelta(0.002, $after->spentUsd(), 1e-9, 'still accounted');
    }

    public function testAtTheSpendCapNothingIsAsked(): void
    {
        $title = $this->titleBackend('x');
        $chat = new Chat(backend: new EchoBackend(), titleBackend: $title, maxCostUsd: 0.0005);
        [$spent] = $chat->update(new SideQuestionAnsweredMsg(null, 'earlier', Usage::new(10, 0.001)));

        [$next, $cmd] = $this->submit($spent, '/btw again?');

        self::assertNull($cmd);
        self::assertSame([], $title->asked);
        self::assertStringContainsString('spend cap is reached', $next->history[\count($next->history) - 1]->content);
    }

    public function testAReadOnlyWindowRunsIt(): void
    {
        $chat = (new \ReflectionMethod(Chat::class, 'mutate'))->invoke($this->chat($this->titleBackend('x')), ['readOnlySession' => true]);

        [, $cmd] = $this->submit($chat, '/btw what is this session about?');

        self::assertNotNull($cmd, 'a side question writes nothing the other window owns');
    }

    public function testTheAnswerRowIsSanitisedAndBounded(): void
    {
        $row = BtwHostCommand::answerRow(new SideQuestionAnsweredMsg(null, "<think>secret</think>\x1b[31mred\x1b[0m " . str_repeat('a', 5000)));

        self::assertStringStartsWith("**btw** — not sent to the agent\n\nred a", $row);
        self::assertStringNotContainsString('secret', $row);
        self::assertStringNotContainsString("\x1b", $row);
        self::assertStringEndsWith('…', $row);
    }

    public function testItIsAdvertised(): void
    {
        $rows = array_values(array_filter(CommandRegistry::slashCommands(), static fn($s): bool => $s->name === 'btw'));

        self::assertCount(1, $rows);
        self::assertSame('<question>', $rows[0]->argumentHint);
    }

    /** @param list<Message> $history */
    private function chat(?Backend $title, array $history = [], bool $inFlight = false): Chat
    {
        return (new Chat(
            history: $history,
            inFlight: $inFlight,
            backend: new EchoBackend(),
            titleBackend: $title,
            inFlightCancellation: $inFlight ? new CancellationToken() : null,
        ))->withSize(100, 30);
    }

    /** @return array{0: Chat, 1: ?\Closure} */
    private function submit(Chat $chat, string $draft): array
    {
        $drafted = (new \ReflectionMethod(Chat::class, 'mutate'))->invoke($chat, ['inputBuf' => $draft]);

        return $drafted->update(new KeyMsg(KeyType::Enter));
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

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                $this->asked[] = $history;

                return \React\Promise\resolve(Message::assistant($this->reply)->withUsage(Usage::new(20, 0.001)));
            }
        };
    }
}
