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
}
