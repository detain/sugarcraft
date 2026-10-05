<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\ChildChannel;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Permissions\ApprovalVerdict;
use SugarCraft\Crush\Permissions\PermissionDecision;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Providers\EchoProvider;
use SugarCraft\Crush\Tests\Backend\ForkChannelEofIsUnansweredTest;
use SugarCraft\Crush\Tools\BuiltIn\AskUserTool;
use SugarCraft\Crush\Tools\Catalog\ToolCatalog;
use SugarCraft\Crush\Tools\Catalog\ToolPermissionClass;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * Roadmap 5.7-2: `AskUser` puts one question to the person at the keyboard
 * through the turn's approver (the 1.C ask channel) and reads the modal's
 * reply as the answer.
 */
final class AskUserToolTest extends TestCase
{
    /** @var list<resource> */
    private array $open = [];

    protected function tearDown(): void
    {
        foreach ($this->open as $socket) {
            if (\is_resource($socket)) {
                \fclose($socket);
            }
        }
        parent::tearDown();
    }

    public function testItIsACatalogedNoAskToolTheGateAllowsInEveryMode(): void
    {
        self::assertSame(ToolPermissionClass::NoAsk, ToolCatalog::permissionOf(AskUserTool::NAME));
        self::assertSame(AskUserTool::NAME, AskUserTool::new()->name());

        foreach (PermissionMode::cases() as $mode) {
            self::assertSame(
                PermissionDecision::Allow,
                (new PermissionGate($mode))->evaluate(new \SugarCraft\Crush\ToolCall(AskUserTool::NAME, ['question' => 'Which?'])),
                "the gate must let the question run under {$mode->value}: the call is itself the prompt",
            );
        }
    }

    public function testWithNoApproverItFailsClosedAndTellsTheModelToDecide(): void
    {
        $result = AskUserTool::new()->execute(['question' => 'Postgres or SQLite?']);

        self::assertTrue($result->isError());
        self::assertStringContainsString('no interactive user is attached', $result->content());
        self::assertStringContainsString('state the assumption', $result->content());
    }

    public function testARunMarkedWithoutAUserNeverAsksEvenWithAnApprover(): void
    {
        $asked = false;
        $tool = AskUserTool::new()
            ->withoutInteractiveUser('this is a test run')
            ->withPermissionApprover(static function () use (&$asked): ApprovalVerdict {
                $asked = true;

                return ApprovalVerdict::once();
            });

        $result = $tool->execute(['question' => 'Go?']);

        self::assertFalse($asked, 'a run with nobody at the keyboard put the question anyway');
        self::assertTrue($result->isError());
        self::assertStringContainsString('this is a test run', $result->content());
    }

    public function testDontAskModePutsNothingToTheUser(): void
    {
        $asked = false;
        $engine = EngineBackend::new(new EchoProvider(), 'echo')->withPermissionGate(new PermissionGate(PermissionMode::DontAsk));
        $tool = AskUserTool::new()
            ->withPermissionApprover(static function () use (&$asked): bool {
                $asked = true;

                return true;
            })
            ->withEngine($engine);

        $result = $tool->execute(['question' => 'Go?']);

        self::assertFalse($asked);
        self::assertTrue($result->isError());
        self::assertStringContainsString('`dont-ask`', $result->content());
    }

    public function testTheApproverIsShownTheQuestionWithTheKeysFirstAndTheCallsOwnId(): void
    {
        $seen = null;
        $tool = AskUserTool::new()->withPermissionApprover(static function (ToolCall $call, HookResult $ask) use (&$seen): ApprovalVerdict {
            $seen = [$call, $ask];

            return ApprovalVerdict::once();
        });

        $tool->execute(['id' => 'call_7', 'question' => "Which store?\x1b[31m", 'options' => ['SQLite', 'Postgres']]);

        self::assertNotNull($seen);
        [$call, $ask] = $seen;
        self::assertSame('call_7', $call->id());
        self::assertSame(AskUserTool::NAME, $call->name());
        self::assertSame('Which store?[31m', $call->arguments()['question'], 'control bytes reach no screen');
        self::assertSame(['SQLite', 'Postgres'], $call->arguments()['options']);
        self::assertStringStartsWith('The agent asks: ', $call->arguments()['description']);
        self::assertTrue($ask->isAsk());
        self::assertSame([], $ask->askedBy, 'a tool question offers no session-wide "always"');

        $lines = explode("\n", $ask->message);
        self::assertStringContainsString('y = option 1', $lines[0], 'the keys lead, so a clipped prompt keeps them');
        self::assertContains('1. SQLite (recommended)', $lines);
        self::assertContains('2. Postgres', $lines);
    }

    public function testYesTakesTheRecommendedOptionOrYes(): void
    {
        self::assertSame('The user chose option 1: SQLite', $this->answered(ApprovalVerdict::once(), ['SQLite', 'Postgres']));
        self::assertSame('The user answered: yes', $this->answered(ApprovalVerdict::once(), []));
        // A bool approver (the console prompt's contract) grants exactly as `y` does.
        self::assertSame('The user answered: yes', $this->answered(true, []));
    }

    public function testATypedNoteIsTheAnswerAndANumberPicksThatOption(): void
    {
        self::assertSame('The user answered: use MariaDB', $this->answered(ApprovalVerdict::rejectedByUser('use MariaDB'), ['SQLite', 'Postgres']));
        self::assertSame('The user chose option 2: Postgres', $this->answered(ApprovalVerdict::rejectedByUser(' 2 '), ['SQLite', 'Postgres']));
        self::assertSame('The user chose option 2: Postgres', $this->answered(ApprovalVerdict::rejectedByUser('postgres'), ['SQLite', 'Postgres']));
        self::assertSame('The user answered: 9', $this->answered(ApprovalVerdict::rejectedByUser('9'), ['SQLite', 'Postgres']));
    }

    public function testNoDeclinesAndIsNotAnError(): void
    {
        $result = AskUserTool::answer(ApprovalVerdict::reject(), ['A', 'B']);

        self::assertFalse($result->isError());
        self::assertStringStartsWith('The user declined the question', $result->content());
    }

    public function testNobodyAnsweringAndAHarnessRefusalAreErrorsNotAnswers(): void
    {
        $unanswered = AskUserTool::answer(ApprovalVerdict::unanswered(ChildChannel::PARENT_GONE), []);
        self::assertTrue($unanswered->isError());
        self::assertStringContainsString(ChildChannel::PARENT_GONE, $unanswered->content());

        $harness = AskUserTool::answer(ApprovalVerdict::reject(ChildChannel::GRANDCHILD_REFUSAL), []);
        self::assertTrue($harness->isError(), 'a reason the harness wrote is not the user saying it');
        self::assertStringContainsString('could not be put to the user', $harness->content());

        $throwing = AskUserTool::new()
            ->withPermissionApprover(static fn (): never => throw new \RuntimeException('modal gone'))
            ->execute(['question' => 'Go?']);
        self::assertTrue($throwing->isError());
        self::assertStringContainsString('modal gone', $throwing->content());
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function malformed(): iterable
    {
        yield 'no question' => [[], '`question`'];
        yield 'blank question' => [['question' => '  '], '`question`'];
        yield 'one option' => [['question' => 'Q', 'options' => ['only']], '2 to 6'];
        yield 'seven options' => [['question' => 'Q', 'options' => ['a', 'b', 'c', 'd', 'e', 'f', 'g']], '2 to 6'];
        yield 'duplicate option' => [['question' => 'Q', 'options' => ['Same', 'same']], 'listed twice'];
        yield 'non-string option' => [['question' => 'Q', 'options' => ['a', 3]], 'non-empty string'];
        yield 'map options' => [['question' => 'Q', 'options' => ['x' => 'a']], 'list of strings'];
    }

    /**
     * @dataProvider malformed
     *
     * @param array<string, mixed> $args
     */
    public function testMalformedArgumentsAskNothing(array $args, string $why): void
    {
        $asked = false;
        $result = AskUserTool::new()
            ->withPermissionApprover(static function () use (&$asked): bool {
                $asked = true;

                return true;
            })
            ->execute($args);

        self::assertFalse($asked);
        self::assertTrue($result->isError());
        self::assertStringContainsString($why, $result->content());
    }

    /**
     * End to end over the real 1.C channel: the question leaves as an `ask`
     * frame naming the tool and its arguments, and the parent's `ask_reply`
     * with a typed note comes back as the answer.
     */
    public function testTheQuestionCrossesTheFrameChannelAndTheNoteComesBackAsTheAnswer(): void
    {
        $pair = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        \array_push($this->open, ...$pair);
        [$parent, $child] = $pair;

        $asked = null;
        $channel = ChildChannel::new(
            $child,
            static function (array $frame) use ($child, $parent, &$asked): void {
                $asked = $frame;
                $body = \serialize($frame);
                \fwrite($child, \pack('N', \strlen($body)) . $body);
                $reply = \serialize(['kind' => ChildChannel::ASK_REPLY, 'askId' => $frame['askId'], 'reply' => 'reject', 'note' => '2']);
                \fwrite($parent, \pack('N', \strlen($reply)) . $reply);
            },
            ForkChannelEofIsUnansweredTest::drain(),
            'plan',
        );

        $result = AskUserTool::new()
            ->withPermissionApprover($channel->approver())
            ->execute(['id' => 'call_9', 'question' => 'Which store?', 'options' => ['SQLite', 'Postgres']]);

        self::assertIsArray($asked);
        self::assertSame(ChildChannel::ASK, $asked['kind']);
        self::assertSame(AskUserTool::NAME, $asked['tool']);
        self::assertSame('call_9', $asked['toolCallId']);
        self::assertSame('plan', $asked['mode']);
        self::assertSame(['once', 'reject'], $asked['suggestions'], 'no "always" for a question');
        self::assertFalse($result->isError());
        self::assertSame('The user chose option 2: Postgres', $result->content());
    }

    public function testItIsWithheldFromSubAgentsAsAnEngineBoundTool(): void
    {
        self::assertInstanceOf(\SugarCraft\Crush\Tools\DelegatesToEngine::class, AskUserTool::new());
        self::assertNotInstanceOf(\SugarCraft\Crush\Tools\ParallelSafe::class, AskUserTool::new());
    }

    /**
     * @param list<string> $options
     */
    private function answered(mixed $verdict, array $options): string
    {
        $result = AskUserTool::new()
            ->withPermissionApprover(static fn (): mixed => $verdict)
            ->execute(['question' => 'Which?', 'options' => $options]);
        self::assertFalse($result->isError(), $result->content());

        return $result->content();
    }
}
