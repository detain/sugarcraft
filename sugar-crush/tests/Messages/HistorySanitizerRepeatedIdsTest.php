<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Messages;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\HistorySanitizer;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * Step 0.2's transcript half: history saved before ids were made unique per
 * turn - a suspended delegation's transcript, a resumed session replayed
 * structurally - can hold two different calls under one id (`dsml_call_0` on
 * every response). {@see HistorySanitizer} renames each repeat, and the
 * results that answer it, so every result stays paired with its own call.
 */
final class HistorySanitizerRepeatedIdsTest extends TestCase
{
    public function testARepeatedIdInALaterStepIsRenamedWithItsResult(): void
    {
        $out = HistorySanitizer::sanitize([
            new UserMessage('go'),
            new AssistantMessage('one', [new ToolCall('dsml_call_0', 'Read', ['path' => 'a'])]),
            new ToolResultMessage('dsml_call_0', 'A'),
            new AssistantMessage('two', [new ToolCall('dsml_call_0', 'Read', ['path' => 'b'])]),
            new ToolResultMessage('dsml_call_0', 'B'),
        ]);

        $this->assertSame('dsml_call_0', $out[1]->toolCalls()[0]->id(), 'the first use keeps its id');
        $this->assertSame('dsml_call_0', $out[2]->toolCallId());
        $this->assertSame('dsml_call_0_r2', $out[3]->toolCalls()[0]->id());
        $this->assertSame(['path' => 'b'], $out[3]->toolCalls()[0]->arguments(), 'only the id changes');
        $this->assertSame('dsml_call_0_r2', $out[4]->toolCallId());
        $this->assertSame('B', $out[4]->content());
        $this->assertCount(5, $out, 'nothing is dropped or synthesized once the pairs are told apart');
    }

    public function testTwoCallsSharingAnIdInOneRowAreAnsweredInOrder(): void
    {
        $out = HistorySanitizer::sanitize([
            new AssistantMessage('', [new ToolCall('x', 'Read', []), new ToolCall('x', 'Read', [])]),
            new ToolResultMessage('x', 'first'),
            new ToolResultMessage('x', 'second'),
        ]);

        $this->assertSame(['x', 'x_r2'], array_map(static fn(ToolCall $c): string => $c->id(), $out[0]->toolCalls()));
        $this->assertSame(['x', 'x_r2'], [$out[1]->toolCallId(), $out[2]->toolCallId()]);
    }

    public function testARenameNeverTakesAnIdAnotherCallHolds(): void
    {
        $out = HistorySanitizer::sanitize([
            new AssistantMessage('', [new ToolCall('x', 'Read', []), new ToolCall('x_r2', 'Read', [])]),
            new ToolResultMessage('x', 'a'),
            new ToolResultMessage('x_r2', 'b'),
            new AssistantMessage('', [new ToolCall('x', 'Read', [])]),
            new ToolResultMessage('x', 'c'),
        ]);

        $this->assertSame('x_r3', $out[3]->toolCalls()[0]->id());
        $this->assertSame('x_r3', $out[4]->toolCallId());
    }

    public function testAHistoryWithUniqueIdsComesBackUntouched(): void
    {
        $history = [
            new UserMessage('go'),
            new AssistantMessage('one', [new ToolCall('a', 'Read', [])], 'why'),
            new ToolResultMessage('a', 'A'),
        ];

        $out = HistorySanitizer::sanitize($history);

        $this->assertSame($history, $out);
    }

    public function testTheRenamedStepKeepsItsReasoning(): void
    {
        $out = HistorySanitizer::sanitize([
            new AssistantMessage('', [new ToolCall('x', 'Read', [])]),
            new ToolResultMessage('x', 'a'),
            new AssistantMessage('two', [new ToolCall('x', 'Read', [])], 'thinking'),
            new ToolResultMessage('x', 'b'),
        ]);

        $this->assertSame('thinking', $out[2]->reasoning());
        $this->assertSame('two', $out[2]->content());
    }
}
