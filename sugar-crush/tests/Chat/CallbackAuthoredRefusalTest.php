<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\AssistantMsg;
use SugarCraft\Crush\Chat;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Permissions\DenialKind;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\ToolResult;

/**
 * E348: A TOOL CALLBACK THAT *RETURNS* A `ToolResult` MAY ASSERT ITS OWN
 * REFUSAL, AND THAT IS THE DECISION — NOT AN OVERSIGHT NOBODY HAS GOT TO.
 *
 * E308 closed the FORGERY route, which was `Chat::invokeTool()`'s
 * `catch (\Throwable $e)` putting `$e->getMessage()` verbatim where
 * {@see Chat::isDeniedResult()} reads. The text a throw produces now opens
 * `Error: <tool> failed with <class>:`, which is on no roster.
 *
 * THE PASS-THROUGH BRANCH ABOVE THAT CATCH WAS NEVER CLOSED, and E348 recorded
 * that the tree said nothing about whether that was reasoned or merely
 * unnoticed. A callback returning `ToolResult::error($name, 'Permission
 * denied: …')` has its `error` field carried through verbatim, so it is drawn
 * struck through in the TUI and listed in a `--output-format json` document's
 * `refusals` array as a call that never ran.
 *
 * IT IS REASONED, AND HERE IS THE REASONING. The two branches differ in what
 * the text IS, not in how much it is trusted:
 *
 *  - An exception message is not a claim about anything. `RuntimeException`
 *    carries whatever string was nearest — an OS error, an HTTP body, a
 *    library's own prose — and the tool that threw it did not choose to say
 *    "this call was blocked". Reading a roster prefix out of it is reading a
 *    coincidence.
 *  - A RETURNED `ToolResult::error()` is a deliberate act by the callback. An
 *    MCP tool whose server answered with a permission failure, a `Skill` that
 *    a policy stopped, a wrapper enforcing its own gate — each of those really
 *    did have the call blocked, and each is a producer the roster exists to
 *    describe. Refusing to believe it would mean a refusal is only real when
 *    THIS process made it, which is false the moment a tool is out-of-process.
 *
 * WHAT WOULD CHANGE THE ANSWER, stated so this is a position and not a shrug:
 * a typed field on `ToolResult` — a `DenialKind` rather than a spelled prefix
 * — would let a callback DECLARE a refusal instead of writing the words, and
 * the free-text route could then stop being honoured. That is a change to a
 * model-visible string and to the shape of a public DTO, and it is a separate
 * step. Until it happens the prose IS the interface, and this file is what
 * says so.
 *
 * DRIVEN THROUGH THE LIVE ROUTE for the same reason
 * {@see \SugarCraft\Crush\Tests\ChatTest::testAToolWhoseExceptionOpensWithARosterPrefixIsNotReportedAsARefusal()}
 * is: the claim is about which field the classifier reads at the end of the
 * real path, and a test that called the private method would be asserting the
 * method exists.
 *
 * @see Chat::isDeniedResult()
 */
final class CallbackAuthoredRefusalTest extends TestCase
{
    /** @return iterable<string,array{0:DenialKind}> */
    public static function denialKinds(): iterable
    {
        foreach (DenialKind::cases() as $kind) {
            yield $kind->name => [$kind];
        }
    }

    /**
     * THE DECISION, per roster kind: a callback that RETURNS the prefix is
     * believed.
     *
     * The cases come from the enum rather than from three literals, so a
     * fourth kind arrives here on its own and a respelling cannot leave one
     * uncovered.
     *
     * @dataProvider denialKinds
     */
    public function testACallbackThatReturnsARosterPrefixIsHonouredAsARefusal(DenialKind $kind): void
    {
        $declared = $kind->reason('the server that owns this tool blocked the call');

        $result = $this->resultOfCallbackReturning(
            static fn (array $args): ToolResult => ToolResult::error('remote', $declared, 'ignored-id'),
        );

        self::assertTrue($result->isError());
        self::assertTrue(
            Chat::isDeniedResult($result),
            'a callback DECLARED a refusal with ' . $kind->value . ' and it was reported as an ordinary failure',
        );
        self::assertSame($declared, $result->error, 'the reason the callback authored was rewritten on the way out');
    }

    /**
     * THE KNOWN-NEGATIVE IN THE SAME SHAPE (rule 15/E228). Every assertion
     * above is that something IS classified as a refusal, which a classifier
     * that answered `true` unconditionally would satisfy. A callback returning
     * an ordinary failure through the identical route must NOT be.
     */
    public function testACallbackThatReturnsAnOrdinaryFailureIsNotARefusal(): void
    {
        $result = $this->resultOfCallbackReturning(
            static fn (array $args): ToolResult => ToolResult::error('remote', 'exit status 1', 'ignored-id'),
        );

        self::assertTrue($result->isError());
        self::assertFalse(Chat::isDeniedResult($result));
    }

    /**
     * THE BOUNDARY THE DECISION RESTS ON, asserted rather than described: the
     * SAME text, through the THROW branch of the same method, is not honoured.
     *
     * Without this, "returning is believed" is not a distinction — it is just
     * a restatement of "the classifier reads the error field". This is the
     * assertion that makes the two branches observably different, and it is
     * what would go red if somebody "simplified" the catch back to
     * `$e->getMessage()`.
     */
    public function testTheSameTextThrownRatherThanReturnedIsNotHonoured(): void
    {
        $declared = DenialKind::Refused->reason('the server that owns this tool blocked the call');

        $returned = $this->resultOfCallbackReturning(
            static fn (array $args): ToolResult => ToolResult::error('remote', $declared, 'ignored-id'),
        );
        $thrown = $this->resultOfCallbackReturning(
            static function (array $args) use ($declared): ToolResult {
                throw new \RuntimeException($declared);
            },
        );

        self::assertTrue(Chat::isDeniedResult($returned));
        self::assertFalse(
            Chat::isDeniedResult($thrown),
            'the throw branch stopped wrapping, so E308 is back and this test is the one that noticed',
        );
    }

    /**
     * Run one tool call whose registered callback is $callback, and hand back
     * the `ToolResult` the turn ended with.
     */
    private function resultOfCallbackReturning(\Closure $callback): ToolResult
    {
        $toolCall = new ToolCall('remote', []);
        $message = Message::assistant('Calling remote tool...')->withToolCalls([$toolCall]);

        $chat = (new Chat(history: [Message::user('test')], inFlight: true))
            ->registerTool('remote', $callback);

        [$afterPlaceholders, $cmd] = $chat->update(new AssistantMsg($message));
        self::assertInstanceOf(\Closure::class, $cmd);

        $asyncCmd = $cmd();
        self::assertInstanceOf(AsyncCmd::class, $asyncCmd);

        $loop = \React\EventLoop\Loop::get();
        $resolved = null;
        $asyncCmd->promise->then(function ($msg) use (&$resolved, $loop): void {
            $resolved = $msg;
            $loop->stop();
        });

        if ($resolved === null) {
            $safety = $loop->addTimer(10.0, static function () use ($loop): void { $loop->stop(); });
            $loop->run();
            $loop->cancelTimer($safety);
        }

        self::assertInstanceOf(
            \SugarCraft\Crush\ToolResultsMsg::class,
            $resolved,
            'tool execution did not complete within the test timeout',
        );

        [$final] = $afterPlaceholders->update($resolved);

        return $final->history[2]->toolResults[0];
    }
}
