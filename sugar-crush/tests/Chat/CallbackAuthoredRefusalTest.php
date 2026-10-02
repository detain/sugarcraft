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
 * E348, REVISED BY AUDIT F-P8: A TOOL CALLBACK THAT RETURNS A `ToolResult`
 * MAY DECLARE ITS OWN REFUSAL — BY THE TYPED FIELD, NO LONGER BY THE WORDS.
 *
 * E308 closed one FORGERY route, which was `Chat::invokeTool()`'s
 * `catch (\Throwable $e)` putting `$e->getMessage()` verbatim where
 * {@see Chat::isDeniedResult()} read. The text a throw produces now opens
 * `Error: <tool> failed with <class>:`, which is on no roster.
 *
 * WHAT THIS FILE USED TO PIN: that the pass-through branch above that catch
 * honoured a returned `ToolResult::error($name, 'Permission denied: …')` as a
 * refusal, because a returned result is a deliberate act — an MCP tool whose
 * server refused, a `Skill` a policy stopped. It recorded what would change
 * the answer: "a typed field on `ToolResult` — a `DenialKind` rather than a
 * spelled prefix — would let a callback DECLARE a refusal instead of writing
 * the words, and the free-text route could then stop being honoured."
 *
 * WHAT IS TRUE NOW: that field exists ({@see ToolResult::$denial}), because
 * the free-text route was a forgery route on the ENGINE path too (audit
 * F-P8): a Bash child printing `Permission denied: …` and exiting non-zero,
 * or an MCP server's error body, was reported as a call that never ran. So:
 *
 *  - A callback that DECLARES a refusal — {@see ToolResult::denied()}, PHP
 *    code in this process choosing to say "blocked" — is honoured, kind and
 *    reason intact, across the fork boundary Chat's tool path uses.
 *  - A callback that only SPELLS one — an `error` that opens with a roster
 *    prefix — is an ordinary failure, exactly like the same text thrown.
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
     * THE DECISION, per roster kind: a callback that DECLARES a refusal with
     * the typed field is believed, and the kind and reason survive the route.
     *
     * The cases come from the enum rather than from three literals, so a
     * fourth kind arrives here on its own.
     *
     * @dataProvider denialKinds
     */
    public function testACallbackThatReturnsARosterPrefixIsHonouredAsARefusal(DenialKind $kind): void
    {
        $detail = 'the server that owns this tool blocked the call';

        $result = $this->resultOfCallbackReturning(
            static fn (array $args): ToolResult => ToolResult::denied('remote', $kind, $detail, 'ignored-id'),
        );

        self::assertTrue($result->isError());
        self::assertTrue(
            Chat::isDeniedResult($result),
            'a callback DECLARED a refusal of kind ' . $kind->name . ' and it was reported as an ordinary failure',
        );
        self::assertSame($kind, $result->denial, 'the declared kind did not survive the tool path');
        self::assertSame($kind->reason($detail), $result->error, 'the reason the callback authored was rewritten on the way out');
    }

    /**
     * AUDIT F-P8, the forgery: the same words WITHOUT the declaration are an
     * ordinary failure. This is what a tool wrapping a shell or a remote
     * server returns when the thing it wraps printed a refusal-shaped line.
     *
     * @dataProvider denialKinds
     */
    public function testACallbackThatOnlySpellsARosterPrefixIsNotARefusal(DenialKind $kind): void
    {
        $spelled = $kind->reason('rm -rf was blocked by policy');

        $result = $this->resultOfCallbackReturning(
            static fn (array $args): ToolResult => ToolResult::error('remote', $spelled, 'ignored-id'),
        );

        self::assertTrue($result->isError());
        self::assertFalse(
            Chat::isDeniedResult($result),
            'error text opening with ' . $kind->value . ' was reported as a refusal of a call that ran',
        );
        self::assertNull($result->denial);
        self::assertSame($spelled, $result->error, 'the failure text the model needs was rewritten');
    }

    /**
     * THE KNOWN-NEGATIVE IN THE SAME SHAPE (rule 15/E228). A callback
     * returning an ordinary failure through the identical route must NOT be
     * a refusal.
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
     * THE BOUNDARY, asserted rather than described: a DECLARED refusal is
     * honoured, while the same text THROWN is not — so the classifier is not
     * merely "never true". This is also what would go red if somebody
     * "simplified" the catch back to `$e->getMessage()` AND stamped a kind.
     */
    public function testTheSameTextThrownRatherThanReturnedIsNotHonoured(): void
    {
        $detail = 'the server that owns this tool blocked the call';
        $declared = DenialKind::Refused->reason($detail);

        $returned = $this->resultOfCallbackReturning(
            static fn (array $args): ToolResult => ToolResult::denied('remote', DenialKind::Refused, $detail, 'ignored-id'),
        );
        $thrown = $this->resultOfCallbackReturning(
            static function (array $args) use ($declared): ToolResult {
                throw new \RuntimeException($declared);
            },
        );

        self::assertTrue(Chat::isDeniedResult($returned));
        self::assertFalse(
            Chat::isDeniedResult($thrown),
            'the throw branch reported a refusal, so E308 is back and this test is the one that noticed',
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
