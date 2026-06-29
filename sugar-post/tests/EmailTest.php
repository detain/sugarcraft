<?php

declare(strict_types=1);

namespace SugarCraft\Post\Tests;

use SugarCraft\Post\Attachment;
use SugarCraft\Post\Email;
use PHPUnit\Framework\TestCase;

final class EmailTest extends TestCase
{
    public function testBasicConstruction(): void
    {
        $email = new Email(
            from:    ['me@example.com'],
            to:      ['you@example.com'],
            subject: 'Test',
            body:    'Hello world',
        );

        $this->assertSame(['me@example.com'], $email->from);
        $this->assertSame(['you@example.com'], $email->to);
        $this->assertSame('Test', $email->subject);
        $this->assertSame('Hello world', $email->body);
        $this->assertSame([], $email->cc);
        $this->assertSame([], $email->bcc);
        $this->assertSame([], $email->attachments);
    }

    public function testTrimsWhitespace(): void
    {
        $email = new Email(
            from:    ['  me@example.com  '],
            to:      ['  you@example.com  ', ' other@example.com  '],
        );

        $this->assertSame('me@example.com', $email->from[0]);
        $this->assertSame('you@example.com', $email->to[0]);
        $this->assertSame('other@example.com', $email->to[1]);
    }

    public function testWithFrom(): void
    {
        $email = (new Email(['old@example.com'], ['you@example.com']))
            ->withFrom('new@example.com');

        $this->assertSame(['new@example.com'], $email->from);
    }

    public function testWithToAddsRecipients(): void
    {
        $email = (new Email(['me@example.com'], ['a@example.com']))
            ->withTo('b@example.com', 'c@example.com');

        $this->assertSame(['a@example.com', 'b@example.com', 'c@example.com'], $email->to);
    }

    public function testWithCc(): void
    {
        $email = (new Email(['me@example.com'], ['you@example.com']))
            ->withCc('cc@example.com');

        $this->assertSame(['cc@example.com'], $email->cc);
    }

    public function testWithBcc(): void
    {
        $email = (new Email(['me@example.com'], ['you@example.com']))
            ->withBcc('bcc@example.com');

        $this->assertSame(['bcc@example.com'], $email->bcc);
    }

    public function testWithSubject(): void
    {
        $email = (new Email(['me@example.com'], ['you@example.com']))
            ->withSubject('New Subject');

        $this->assertSame('New Subject', $email->subject);
    }

    public function testWithBody(): void
    {
        $email = (new Email(['me@example.com'], ['you@example.com']))
            ->withBody('New body text');

        $this->assertSame('New body text', $email->body);
    }

    public function testWithHtmlBody(): void
    {
        $email = (new Email(['me@example.com'], ['you@example.com']))
            ->withHtmlBody('<p>HTML body</p>');

        $this->assertSame('<p>HTML body</p>', $email->htmlBody);
    }

    public function testWithReplyTo(): void
    {
        $email = (new Email(['me@example.com'], ['you@example.com']))
            ->withReplyTo('reply@example.com');

        $this->assertSame('reply@example.com', $email->replyTo);
    }

    public function testWithSignature(): void
    {
        $email = (new Email(['me@example.com'], ['you@example.com']))
            ->withBody('The body')
            ->withSignature('-- The Team');

        $this->assertSame("The body\n\n-- The Team", $email->bodyWithSignature());
    }

    public function testBodyWithSignatureSkipsWhenNoBody(): void
    {
        $email = new Email(['me@example.com'], ['you@example.com']);
        $this->assertNull($email->bodyWithSignature());
    }

    public function testBodyWithSignatureSkipsWhenNoSignature(): void
    {
        $email = (new Email(['me@example.com'], ['you@example.com']))
            ->withBody('Just body');

        $this->assertSame('Just body', $email->bodyWithSignature());
    }

    public function testAllRecipients(): void
    {
        $email = (new Email(
            from:    ['me@example.com'],
            to:      ['a@example.com', 'b@example.com'],
            cc:      ['c@example.com'],
            bcc:     ['d@example.com'],
        ));

        $all = $email->allRecipients();
        $this->assertContains('a@example.com', $all);
        $this->assertContains('b@example.com', $all);
        $this->assertContains('c@example.com', $all);
        $this->assertContains('d@example.com', $all);
    }

    public function testAllRecipientsDeduplicates(): void
    {
        $email = new Email(
            from: ['me@example.com'],
            to:   ['dup@example.com', 'dup@example.com'],
        );

        $all = $email->allRecipients();
        $this->assertCount(1, $all);
    }

    public function testRejectsCrlfInSubject(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CRLF injection detected');

        new Email(
            from:    ['me@example.com'],
            to:      ['you@example.com'],
            subject: "Hello\r\nMalicious-Header: value",
        );
    }

    public function testRejectsCrlfInRecipient(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CRLF injection detected');

        new Email(
            from: ['me@example.com'],
            to:   ["you@example.com\r\nMalicious: value"],
        );
    }

    public function testRejectsInvalidEmail(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid email address');

        new Email(
            from: ['not-an-email'],
            to:   ['you@example.com'],
        );
    }
}
