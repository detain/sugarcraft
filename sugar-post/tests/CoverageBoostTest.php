<?php

declare(strict_types=1);

namespace SugarCraft\Post\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Post\Attachment;
use SugarCraft\Post\Email;
use SugarCraft\Post\Mailer;
use SugarCraft\Post\Transport;

/**
 * Coverage-push tests for sugar-post. Targets `Mailer` (via stub Transport),
 * `Attachment` factory variants, and the remaining `Email::with*` paths
 * the existing suite skipped (subject / body / signature on the constructor
 * vs. wither vs. round-trip into transport).
 */
final class CoverageBoostTest extends TestCase
{
    public function testMailerThrowsWhenNoRecipients(): void
    {
        $email = new Email(from: ['a@x'], to: []); // no recipients via ctor
        $mailer = new Mailer(new StubTransport());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('recipient');
        $mailer->send($email);
    }

    public function testMailerThrowsWhenNoFrom(): void
    {
        $email = Email::make('a@x', 'b@x')->withFrom(''); // empty from
        $email = new Email(from: [], to: ['b@x']);
        $mailer = new Mailer(new StubTransport());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('from');
        $mailer->send($email);
    }

    public function testMailerForwardsToTransport(): void
    {
        $email = Email::make('from@x', 'to@x', 'subj', 'body');
        $stub = new StubTransport();
        (new Mailer($stub))->send($email);
        $this->assertCount(1, $stub->sent);
        $this->assertSame($email, $stub->sent[0]);
    }

    public function testMailerTransportNameDelegates(): void
    {
        $stub = new StubTransport('stub');
        $this->assertSame('stub', (new Mailer($stub))->transportName());
    }

    public function testEmailAcceptsCcBccViaConstructor(): void
    {
        $e = new Email(
            from: ['a@x'], to: ['b@x'],
            cc: ['c@x', 'd@x '], bcc: [' e@x'],
        );
        // trims
        $this->assertSame(['c@x', 'd@x'], $e->cc);
        $this->assertSame(['e@x'], $e->bcc);
    }

    public function testEmailWithCcReplaces(): void
    {
        $e = Email::make('a@x', 'b@x')->withCc('c@x', 'd@x');
        $this->assertSame(['c@x', 'd@x'], $e->cc);
    }

    public function testEmailWithBccReplaces(): void
    {
        $e = Email::make('a@x', 'b@x')->withBcc('e@x');
        $this->assertSame(['e@x'], $e->bcc);
    }

    public function testEmailWithSignatureAppendsAtRender(): void
    {
        $e = Email::make('a@x', 'b@x', 'subj', 'hi')->withSignature('-- ada');
        $this->assertSame('-- ada', $e->signature);
    }

    public function testEmailWithAttachmentInline(): void
    {
        $tmp = \tempnam(\sys_get_temp_dir(), 'sp-');
        \file_put_contents($tmp, 'data');
        try {
            $e = Email::make('a@x', 'b@x')->withInlineAttachment($tmp, 'cid-1');
            $this->assertCount(1, $e->attachments);
            $this->assertSame('cid-1', $e->attachments[0]->cid);
        } finally {
            @\unlink($tmp);
        }
    }

    public function testAttachmentFromContent(): void
    {
        $a = Attachment::fromContent('hi', 'note.txt', 'text/plain');
        $this->assertSame('note.txt', $a->filename);
        $this->assertSame('text/plain', $a->mimeType);
        $this->assertSame('hi', $a->getContent());
        $this->assertNull($a->cid);
    }

    public function testAttachmentInlineFromPath(): void
    {
        $tmp = \tempnam(\sys_get_temp_dir(), 'sp-');
        \file_put_contents($tmp, 'imgdata');
        try {
            $a = Attachment::inline($tmp, 'logo');
            $this->assertSame('logo', $a->cid);
            $this->assertSame('imgdata', $a->getContent());
        } finally {
            @\unlink($tmp);
        }
    }

    public function testAttachmentWithCidClonesCid(): void
    {
        $a  = Attachment::fromContent('x', 'a.txt');
        $a2 = $a->withCid('id-2');
        $this->assertNull($a->cid);
        $this->assertSame('id-2', $a2->cid);
    }

    public function testAttachmentFromPathReadsFilename(): void
    {
        $tmp = \tempnam(\sys_get_temp_dir(), 'sp-');
        \file_put_contents($tmp, 'hi');
        try {
            $a = Attachment::fromPath($tmp);
            $this->assertSame(\basename($tmp), $a->filename);
            $this->assertSame('hi', $a->getContent());
        } finally {
            @\unlink($tmp);
        }
    }
}

/**
 * Minimal in-memory Transport for tests; records send() calls.
 */
final class StubTransport implements Transport
{
    /** @var list<Email> */
    public array $sent = [];

    public function __construct(private readonly string $label = 'stub') {}

    public function send(Email $email): void { $this->sent[] = $email; }
    public function name(): string { return $this->label; }
}
