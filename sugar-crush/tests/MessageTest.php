<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use SugarCraft\Crush\Attachment;
use SugarCraft\Crush\AttachmentType;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;
use PHPUnit\Framework\TestCase;

final class MessageTest extends TestCase
{
    public function testFactoriesSetTheRoleField(): void
    {
        $this->assertSame(Role::User,      Message::user('hi')->role);
        $this->assertSame(Role::Assistant, Message::assistant('hi')->role);
        $this->assertSame(Role::System,    Message::system('hi')->role);
    }

    public function testToWireMatchesProviderShape(): void
    {
        $m = Message::user('hello world', 1700000000);
        $this->assertSame(
            ['role' => 'user', 'content' => 'hello world'],
            $m->toWire(),
        );
    }

    public function testCreatedAtIsUsedWhenProvided(): void
    {
        $m = Message::assistant('reply', 12345);
        $this->assertSame(12345, $m->createdAt);
    }

    public function testAttachFileAddsAttachment(): void
    {
        $m = Message::user('hello');
        $m2 = $m->attachFile('/path/to/file.txt');
        $this->assertNotSame($m, $m2);
        $this->assertCount(1, $m2->attachments);
        $this->assertSame('/path/to/file.txt', $m2->attachments[0]->path);
        $this->assertSame(AttachmentType::File, $m2->attachments[0]->type);
    }

    public function testAttachImageAddsImageAttachment(): void
    {
        $m = Message::user('hello');
        $m2 = $m->attachImage('/path/to/image.png');
        $this->assertCount(1, $m2->attachments);
        $this->assertSame('/path/to/image.png', $m2->attachments[0]->path);
        $this->assertSame(AttachmentType::Image, $m2->attachments[0]->type);
    }

    public function testAttachMultipleAttachments(): void
    {
        $m = Message::user('hello')
            ->attachFile('/path/to/file.txt')
            ->attachImage('/path/to/image.png');
        $this->assertCount(2, $m->attachments);
        $this->assertSame(AttachmentType::File, $m->attachments[0]->type);
        $this->assertSame(AttachmentType::Image, $m->attachments[1]->type);
    }

    public function testToWireIncludesAttachments(): void
    {
        $m = Message::user('hello')
            ->attachFile('/path/to/file.txt')
            ->attachImage('/path/to/image.png');
        $wire = $m->toWire();
        $this->assertArrayHasKey('attachments', $wire);
        $this->assertCount(2, $wire['attachments']);
        $this->assertSame('File', $wire['attachments'][0]['type']);
        $this->assertSame('/path/to/file.txt', $wire['attachments'][0]['path']);
        $this->assertSame('Image', $wire['attachments'][1]['type']);
        $this->assertSame('/path/to/image.png', $wire['attachments'][1]['path']);
    }

    public function testToWireOmitsAttachmentsWhenEmpty(): void
    {
        $m = Message::user('hello');
        $wire = $m->toWire();
        $this->assertArrayNotHasKey('attachments', $wire);
    }

    public function testToolRunningSetsPendingToolCallIdToTheCallId(): void
    {
        $call = new \SugarCraft\Crush\ToolCall('bash', ['command' => 'ls'], 'call_1');
        $m = Message::toolRunning($call);

        $this->assertSame('call_1', $m->pendingToolCallId);
        $this->assertSame(Role::System, $m->role);
        $this->assertStringContainsString('bash', $m->content);
    }

    public function testToolRunningFallsBackToNameWhenCallHasNoId(): void
    {
        $call = new \SugarCraft\Crush\ToolCall('bash', []);
        $m = Message::toolRunning($call);

        $this->assertSame('bash', $m->pendingToolCallId);
    }

    public function testWithToolResultsClearsPendingToolCallId(): void
    {
        $call = new \SugarCraft\Crush\ToolCall('bash', [], 'call_1');
        $result = \SugarCraft\Crush\ToolResult::ok('bash', 'ok', 'call_1');

        $m = Message::toolRunning($call)->withToolResults([$result]);

        $this->assertNull($m->pendingToolCallId);
        $this->assertSame([$result], $m->toolResults);
    }

    public function testDescribeToolCallFormatsArguments(): void
    {
        $call = new \SugarCraft\Crush\ToolCall('bash', ['command' => 'ls -la']);
        $this->assertSame('bash(command: "ls -la")', Message::describeToolCall($call));
    }

    public function testDescribeToolCallWithNoArguments(): void
    {
        $call = new \SugarCraft\Crush\ToolCall('bash', []);
        $this->assertSame('bash()', Message::describeToolCall($call));
    }

    public function testAssistantFactoryAcceptsOptionalReasoning(): void
    {
        $m = Message::assistant('answer', 12345, reasoning: 'thinking...');

        $this->assertSame('thinking...', $m->reasoning);
        $this->assertNull(Message::assistant('answer')->reasoning);
    }

    public function testWithReasoningReturnsNewInstanceWithReasoningSet(): void
    {
        $m = Message::assistant('answer');
        $m2 = $m->withReasoning('because');

        $this->assertNull($m->reasoning);
        $this->assertSame('because', $m2->reasoning);
        $this->assertNotSame($m, $m2);
    }

    public function testFluentWithersPreserveReasoning(): void
    {
        $m = Message::assistant('answer', reasoning: 'because')
            ->attachFile('/tmp/f.txt')
            ->withToolCalls([]);

        $this->assertSame('because', $m->reasoning);
    }

    /**
     * W1.G2 reachability fix: an image-bearing tool result (e.g. Doctor's
     * capability swatch) is threaded onto the root Message at the
     * EngineBackend conversion seam via withImage() -- mirroring
     * withReasoning()'s role for the parallel `reasoning` field.
     */
    public function testImageFieldsDefaultToNullAndHasImageIsFalse(): void
    {
        $m = Message::assistant('answer');

        $this->assertNull($m->imageBytes);
        $this->assertNull($m->imageProtocol);
        $this->assertFalse($m->hasImage());
    }

    public function testWithImageReturnsNewInstanceWithImageSet(): void
    {
        $m = Message::assistant('answer');
        $m2 = $m->withImage("\x89PNGfake", 'kitty');

        $this->assertFalse($m->hasImage());
        $this->assertTrue($m2->hasImage());
        $this->assertSame("\x89PNGfake", $m2->imageBytes);
        $this->assertSame('kitty', $m2->imageProtocol);
        $this->assertNotSame($m, $m2);
    }

    public function testFluentWithersPreserveImage(): void
    {
        $m = Message::assistant('answer')
            ->withImage('bytes', 'sixel')
            ->attachFile('/tmp/f.txt')
            ->withToolCalls([])
            ->withReasoning('because');

        $this->assertTrue($m->hasImage());
        $this->assertSame('bytes', $m->imageBytes);
        $this->assertSame('sixel', $m->imageProtocol);
    }
}
