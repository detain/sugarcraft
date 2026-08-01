<?php

declare(strict_types=1);

namespace SugarCraft\Post\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Post\Attachment;
use SugarCraft\Post\Email;

/**
 * Edge case tests for Email attachment methods.
 */
final class EmailAttachmentTest extends TestCase
{
    public function testWithAttachmentThrowsWhenPathIsNull(): void
    {
        $email = new Email(['me@example.com'], ['you@example.com']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('path');

        $email->withAttachment('document.pdf', null);
    }

    public function testWithAttachmentAddsAttachment(): void
    {
        $tmp = \tempnam(\sys_get_temp_dir(), 'sp-email-');
        \file_put_contents($tmp, 'test content');
        try {
            $email = new Email(['me@example.com'], ['you@example.com']);
            $email = $email->withAttachment('doc.pdf', $tmp);

            $this->assertCount(1, $email->attachments);
            $this->assertSame('doc.pdf', $email->attachments[0]->filename);
        } finally {
            @\unlink($tmp);
        }
    }

    public function testWithInlineAttachmentAddsInlineAttachment(): void
    {
        $tmp = \tempnam(\sys_get_temp_dir(), 'sp-email-');
        \file_put_contents($tmp, "\x89PNG");
        try {
            $email = new Email(['me@example.com'], ['you@example.com']);
            $email = $email->withInlineAttachment($tmp, 'logo-001', 'logo.png');

            $this->assertCount(1, $email->attachments);
            $this->assertSame('logo-001', $email->attachments[0]->cid);
            $this->assertSame('logo.png', $email->attachments[0]->filename);
        } finally {
            @\unlink($tmp);
        }
    }

    public function testWithInlineAttachmentWithMinimalArgs(): void
    {
        $tmp = \tempnam(\sys_get_temp_dir(), 'sp-email-');
        \file_put_contents($tmp, "\x89PNG");
        try {
            $email = new Email(['me@example.com'], ['you@example.com']);
            // Use default CID format (no filename specified)
            $email = $email->withInlineAttachment($tmp, 'cid-001');

            $this->assertCount(1, $email->attachments);
            $this->assertSame('cid-001', $email->attachments[0]->cid);
        } finally {
            @\unlink($tmp);
        }
    }

    public function testWithToDoesNotDuplicateRecipients(): void
    {
        $email = (new Email(['me@example.com'], ['a@example.com']))
            ->withTo('a@example.com', 'b@example.com');

        $this->assertSame(['a@example.com', 'b@example.com'], $email->to);
    }

    public function testWithCcDoesNotDuplicateRecipients(): void
    {
        $email = (new Email(['me@example.com'], ['you@example.com']))
            ->withCc('a@example.com', 'a@example.com');

        $this->assertSame(['a@example.com'], $email->cc);
    }

    public function testWithBccDoesNotDuplicateRecipients(): void
    {
        $email = (new Email(['me@example.com'], ['you@example.com']))
            ->withBcc('a@example.com', 'a@example.com');

        $this->assertSame(['a@example.com'], $email->bcc);
    }

    public function testMultipleWithCallsReturnNewInstances(): void
    {
        $email1 = new Email(['me@example.com'], ['you@example.com']);
        $email2 = $email1->withSubject('Subject 1');
        $email3 = $email2->withSubject('Subject 2');

        $this->assertNull($email1->subject);
        $this->assertSame('Subject 1', $email2->subject);
        $this->assertSame('Subject 2', $email3->subject);
    }

    public function testWithFromReturnsNewInstance(): void
    {
        $email1 = new Email(['old@example.com'], ['you@example.com']);
        $email2 = $email1->withFrom('new@example.com');

        $this->assertSame(['old@example.com'], $email1->from);
        $this->assertSame(['new@example.com'], $email2->from);
    }

    public function testSanitizeAddressListFiltersEmptyStrings(): void
    {
        $email = new Email(
            ['me@example.com', '', 'other@example.com'],
            ['you@example.com'],
        );

        $this->assertCount(2, $email->from);
        $this->assertSame('me@example.com', $email->from[0]);
        $this->assertSame('other@example.com', $email->from[1]);
    }

    public function testAllRecipientsCombinesToCcBcc(): void
    {
        $email = new Email(
            from: ['me@example.com'],
            to:   ['to@example.com'],
            cc:   ['cc@example.com'],
            bcc:  ['bcc@example.com'],
        );

        $all = $email->allRecipients();

        $this->assertContains('to@example.com', $all);
        $this->assertContains('cc@example.com', $all);
        $this->assertContains('bcc@example.com', $all);
    }
}
