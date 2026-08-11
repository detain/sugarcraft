<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Events;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Events\ToolStarted;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * @see ToolStarted
 * @see ToolFinished
 */
final class ToolEventsTest extends TestCase
{
    public function testToolStartedCarriesIdNameAndArguments(): void
    {
        $event = new ToolStarted('id-1', 'Bash', ['command' => 'ls']);

        $this->assertSame('id-1', $event->toolCallId);
        $this->assertSame('Bash', $event->toolName);
        $this->assertSame(['command' => 'ls'], $event->arguments);
    }

    public function testToolStartedDefaultsToNoArguments(): void
    {
        $this->assertSame([], (new ToolStarted('id-2', 'Read'))->arguments);
    }

    public function testToolStartedFromCallCopiesTheCall(): void
    {
        $event = ToolStarted::fromCall(new ToolCall('call_7', 'Grep', ['pattern' => 'foo']));

        $this->assertSame('call_7', $event->toolCallId);
        $this->assertSame('Grep', $event->toolName);
        $this->assertSame(['pattern' => 'foo'], $event->arguments);
    }

    public function testToolFinishedCarriesTheWholeResult(): void
    {
        $result = new ToolResult(toolCallId: 'call_8', content: 'done');
        $event = new ToolFinished('call_8', 'Edit', $result);

        $this->assertSame('call_8', $event->toolCallId);
        $this->assertSame('Edit', $event->toolName);
        $this->assertSame($result, $event->result);
    }

    public function testToolFinishedFromResultTakesItsIdFromTheCall(): void
    {
        $event = ToolFinished::fromResult(
            new ToolCall('call_9', 'Edit', []),
            // A real tool cannot know its call id, so it invents one; the
            // event must still correlate to the model's original.
            new ToolResult(toolCallId: 'whatever-the-tool-said', content: 'File updated'),
        );

        $this->assertSame('call_9', $event->toolCallId);
        $this->assertSame('Edit', $event->toolName);
        $this->assertSame('File updated', $event->result->content());
    }
}
