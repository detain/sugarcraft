<?php

declare(strict_types=1);

namespace SugarCraft\Post\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Post\Email;

/**
 * Immutability and coercion tests for Email with*() builders.
 *
 * Every with*() must return a new Email instance, leaving the original
 * completely unchanged.  This guards against accidental shared-mutation
 * bugs in the Mutable trait or mutate() implementation.
 */
final class EmailImmutabilityTest extends TestCase
{
    public function testWithFromReturnsNewInstance(): void
    {
        $a = Email::new('a@x', 'b@x');
        $b = $a->withFrom('c@x');

        $this->assertNotSame($a, $b);
        $this->assertSame(['a@x'], $a->from);
        $this->assertSame(['c@x'], $b->from);
    }

    public function testWithToReturnsNewInstance(): void
    {
        $a = Email::new('a@x', 'b@x');
        $b = $a->withTo('c@x');

        $this->assertNotSame($a, $b);
        $this->assertSame(['b@x'], $a->to);
        $this->assertSame(['b@x', 'c@x'], $b->to);
    }

    public function testWithSubjectReturnsNewInstance(): void
    {
        $a = Email::new('a@x', 'b@x', 'Original');
        $b = $a->withSubject('Changed');

        $this->assertNotSame($a, $b);
        $this->assertSame('Original', $a->subject);
        $this->assertSame('Changed', $b->subject);
    }

    public function testWithBodyReturnsNewInstance(): void
    {
        $a = Email::new('a@x', 'b@x', null, 'Original');
        $b = $a->withBody('Changed');

        $this->assertNotSame($a, $b);
        $this->assertSame('Original', $a->body);
        $this->assertSame('Changed', $b->body);
    }

    public function testWithHtmlBodyReturnsNewInstance(): void
    {
        $a = Email::new('a@x', 'b@x');
        $b = $a->withHtmlBody('<p>HTML</p>');

        $this->assertNotSame($a, $b);
        $this->assertNull($a->htmlBody);
        $this->assertSame('<p>HTML</p>', $b->htmlBody);
    }

    public function testWithCcReturnsNewInstance(): void
    {
        $a = Email::new('a@x', 'b@x');
        $b = $a->withCc('cc@x');

        $this->assertNotSame($a, $b);
        $this->assertSame([], $a->cc);
        $this->assertSame(['cc@x'], $b->cc);
    }

    public function testWithBccReturnsNewInstance(): void
    {
        $a = Email::new('a@x', 'b@x');
        $b = $a->withBcc('bcc@x');

        $this->assertNotSame($a, $b);
        $this->assertSame([], $a->bcc);
        $this->assertSame(['bcc@x'], $b->bcc);
    }

    public function testWithReplyToReturnsNewInstance(): void
    {
        $a = Email::new('a@x', 'b@x');
        $b = $a->withReplyTo('reply@x');

        $this->assertNotSame($a, $b);
        $this->assertNull($a->replyTo);
        $this->assertSame('reply@x', $b->replyTo);
    }

    public function testWithSignatureReturnsNewInstance(): void
    {
        $a = Email::new('a@x', 'b@x', null, 'body');
        $b = $a->withSignature('-- sig');

        $this->assertNotSame($a, $b);
        $this->assertNull($a->signature);
        $this->assertSame('-- sig', $b->signature);
    }

    public function testWithAttachmentReturnsNewInstance(): void
    {
        $tmp = \tempnam(\sys_get_temp_dir(), 'sp-immut-');
        \file_put_contents($tmp, 'data');
        try {
            $a = Email::new('a@x', 'b@x');
            $b = $a->withAttachment('doc.pdf', $tmp);

            $this->assertNotSame($a, $b);
            $this->assertSame([], $a->attachments);
            $this->assertCount(1, $b->attachments);
        } finally {
            @\unlink($tmp);
        }
    }

    public function testWithInlineAttachmentReturnsNewInstance(): void
    {
        $tmp = \tempnam(\sys_get_temp_dir(), 'sp-immut-');
        \file_put_contents($tmp, 'img');
        try {
            $a = Email::new('a@x', 'b@x');
            $b = $a->withInlineAttachment($tmp, 'img-cid-001');

            $this->assertNotSame($a, $b);
            $this->assertSame([], $a->attachments);
            $this->assertCount(1, $b->attachments);
            $this->assertSame('img-cid-001', $b->attachments[0]->cid);
        } finally {
            @\unlink($tmp);
        }
    }

    public function testWithToDeduplicates(): void
    {
        $a = Email::new('a@x', 'b@x');
        $b = $a->withTo('b@x', 'c@x');

        // b@x was already present; deduplicated to one entry.
        $this->assertContains('b@x', $b->to);
        $this->assertContains('c@x', $b->to);
        $this->assertCount(2, $b->to);
    }

    public function testWithCcDeduplicates(): void
    {
        $a = Email::new('a@x', 'b@x')->withCc('cc@x');
        $b = $a->withCc('cc@x');

        $this->assertCount(1, $b->cc);
    }

    public function testWithBccDeduplicates(): void
    {
        $a = Email::new('a@x', 'b@x')->withBcc('bcc@x');
        $b = $a->withBcc('bcc@x');

        $this->assertCount(1, $b->bcc);
    }

    public function testChainedWithersProduceCorrectFinalState(): void
    {
        $email = Email::new('a@x', 'b@x')
            ->withSubject('Subject')
            ->withBody('Body')
            ->withCc('cc@x')
            ->withBcc('bcc@x')
            ->withReplyTo('reply@x')
            ->withSignature('-- Team');

        $this->assertSame(['a@x'], $email->from);
        $this->assertSame(['b@x'], $email->to);
        $this->assertSame('Subject', $email->subject);
        $this->assertSame('Body', $email->body);
        $this->assertSame(['cc@x'], $email->cc);
        $this->assertSame(['bcc@x'], $email->bcc);
        $this->assertSame('reply@x', $email->replyTo);
        $this->assertSame('-- Team', $email->signature);
    }
}
