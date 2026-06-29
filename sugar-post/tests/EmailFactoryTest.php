<?php

declare(strict_types=1);

namespace SugarCraft\Post\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Post\Email;
use SugarCraft\Post\ResendTransport;

/**
 * Tests for Email::new() static factory and withAttachment().
 */
final class EmailFactoryTest extends TestCase
{
    public function testNewCreatesEmail(): void
    {
        $email = Email::new('from@example.com', 'to@example.com', 'Subject', 'Body');

        $this->assertSame(['from@example.com'], $email->from);
        $this->assertSame(['to@example.com'], $email->to);
        $this->assertSame('Subject', $email->subject);
        $this->assertSame('Body', $email->body);
    }

    public function testNewWithMinimalArgs(): void
    {
        $email = Email::new('a@b.com', 'c@d.com');

        $this->assertSame(['a@b.com'], $email->from);
        $this->assertSame(['c@d.com'], $email->to);
        $this->assertNull($email->subject);
        $this->assertNull($email->body);
    }

    public function testWithAttachmentAddsAttachment(): void
    {
        $tmp = \tempnam(\sys_get_temp_dir(), 'sp-');
        \file_put_contents($tmp, 'attach-me');
        try {
            $email = Email::new('a@b.com', 'c@d.com')
                ->withAttachment('document.pdf', $tmp);

            $this->assertCount(1, $email->attachments);
            $this->assertSame('document.pdf', $email->attachments[0]->filename);
            $this->assertNull($email->attachments[0]->cid);
        } finally {
            @\unlink($tmp);
        }
    }

    public function testWithAttachmentUsesPathForMimeDetection(): void
    {
        $tmp = \tempnam(\sys_get_temp_dir(), 'sp-');
        \file_put_contents($tmp, 'plain text content');
        try {
            $email = Email::new('a@b.com', 'c@d.com')
                ->withAttachment('readme.txt', $tmp);

            $this->assertCount(1, $email->attachments);
            $this->assertSame('text/plain', $email->attachments[0]->mimeType);
        } finally {
            @\unlink($tmp);
        }
    }

    public function testWithAttachmentNullPathThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('path must be provided');

        Email::new('a@b.com', 'c@d.com')
            ->withAttachment('data.json');
    }
}
