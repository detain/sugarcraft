<?php

declare(strict_types=1);

namespace SugarCraft\Mail\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mail\Attachment;
use SugarCraft\Mail\Email;
use SugarCraft\Mail\SmtpTransport;

/**
 * Additional MIME building edge case tests for SmtpTransport.
 */
final class SmtpMimeEdgeTest extends TestCase
{
    public function testBuildMimeMessageWithHtmlOnlyNoPlainBody(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587);

        $mime = $this->buildMime($transport, new Email(
            from:    ['from@example.com'],
            to:      ['to@example.com'],
            subject: 'HTML Email',
            htmlBody: '<p>Hello World</p>',
        ));

        // M-R (lib-reviews-1102): a lone HTML body is a single part — the old
        // code wrapped it in an EMPTY multipart/alternative whose boundary was
        // never opened. Flipping the pinned defect shape:
        $this->assertStringNotContainsString('multipart/alternative', $mime);
        // Should NOT have a text/plain part when body is null
        $this->assertStringNotContainsString('Content-Type: text/plain', $mime);
        // Should have HTML content
        $this->assertStringContainsString('Content-Type: text/html', $mime);
    }

    public function testBuildMimeMessageWithReplyToHeader(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587);

        $mime = $this->buildMime($transport, new Email(
            from:    ['from@example.com'],
            to:      ['to@example.com'],
            replyTo: 'replyto@example.com',
        ));

        $this->assertStringContainsString('Reply-To: replyto@example.com', $mime);
    }

    public function testBuildMimeMessageWithCc(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587);

        $mime = $this->buildMime($transport, new Email(
            from:    ['from@example.com'],
            to:      ['to@example.com'],
            cc:      ['cc1@example.com', 'cc2@example.com'],
        ));

        $this->assertStringContainsString('Cc: cc1@example.com, cc2@example.com', $mime);
    }

    public function testBuildMimeMessageWithEmptyCcDoesNotEmitHeader(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587);

        $mime = $this->buildMime($transport, new Email(
            from:    ['from@example.com'],
            to:      ['to@example.com'],
            cc:      [],
        ));

        $this->assertStringNotContainsString('Cc:', $mime);
    }

    public function testBuildMimeMessageWithSubject(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587);

        $mime = $this->buildMime($transport, new Email(
            from:    ['from@example.com'],
            to:      ['to@example.com'],
            subject: 'Test Subject',
        ));

        $this->assertStringContainsString('Subject: Test Subject', $mime);
    }

    public function testBuildMimeMessageWithNullSubject(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587);

        $mime = $this->buildMime($transport, new Email(
            from: ['from@example.com'],
            to:   ['to@example.com'],
        ));

        // Subject header should not be present when null
        $this->assertStringNotContainsString('Subject:', $mime);
    }

    public function testBuildAttachmentsWithInlineCid(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587);

        $tmp = \tempnam(\sys_get_temp_dir(), 'sp-mime-inline-');
        \file_put_contents($tmp, "\x89PNG");
        try {
            $email = new Email(
                from: ['from@example.com'],
                to:   ['to@example.com'],
            );
            $email = $email->withInlineAttachment($tmp, 'logo-cid-001', 'logo.png');

            $mime = $this->buildMime($transport, $email);

            // Should have inline content-disposition with cid
            $this->assertStringContainsString('inline; filename="logo.png"', $mime);
            $this->assertStringContainsString('Content-ID: <logo-cid-001>', $mime);
        } finally {
            @\unlink($tmp);
        }
    }

    public function testBuildAttachmentsWithMultipleAttachments(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587);

        $tmp1 = \tempnam(\sys_get_temp_dir(), 'sp-mime-att1-');
        $tmp2 = \tempnam(\sys_get_temp_dir(), 'sp-mime-att2-');
        \file_put_contents($tmp1, 'content1');
        \file_put_contents($tmp2, 'content2');
        try {
            $email = new Email(
                from: ['from@example.com'],
                to:   ['to@example.com'],
            );
            $email = $email->withAttachment('doc1.txt', $tmp1);
            $email = $email->withAttachment('doc2.txt', $tmp2);

            $mime = $this->buildMime($transport, $email);

            // Should have both attachments
            $this->assertStringContainsString('filename="doc1.txt"', $mime);
            $this->assertStringContainsString('filename="doc2.txt"', $mime);
        } finally {
            @\unlink($tmp1);
            @\unlink($tmp2);
        }
    }


    public function testBackslashFilenamesCannotOpenQuoteInThePlainBranch(): void
    {
        // CM-47: a printable name without a double quote used to be emitted
        // verbatim into the quoted-string — `report\` shipped
        // filename="report\", and the swallowed closing quote lets the rest
        // of the parameter list hide behind an open quoted-string (RFC 2045
        // section 3). Backslashes are now doubled in the plain branch; both
        // the embedded and the trailing-backslash shape are pinned.
        $transport = new SmtpTransport('smtp.example.com', 587);
        $email = new Email(
            from:        ['from@example.com'],
            to:          ['to@example.com'],
            attachments: [
                Attachment::fromContent('one', 'report\\.txt'),
                Attachment::fromContent('two', 'report\\'),
            ],
        );

        $mime = $this->buildMime($transport, $email);

        // Wire bytes must read filename="report\\.txt" and filename="report\\".
        $this->assertStringContainsString('filename="report\\\\.txt"', $mime);
        $this->assertStringContainsString('filename="report\\\\"', $mime);
    }

    public function testBuildMimeMessageEndsWithBoundaryTerminator(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587);

        $mime = $this->buildMime($transport, new Email(
            from: ['from@example.com'],
            to:   ['to@example.com'],
            body: 'Test body',
        ));

        // Should end with --boundary-- to close the multipart/mixed
        // RFC 2046 §5.1: the close-delimiter terminates a line, so the
        // document ends with CRLF AFTER the final "--" (B6 flip: the old pin
        // asserted the bare tail and sendData bolted the CRLF on at wire time).
        $this->assertMatchesRegularExpression('/--[a-f0-9]{32}--\r\n$/', $mime);
    }

    public function testBuildMimeMessageWithBodyAndHtml(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587);

        $mime = $this->buildMime($transport, new Email(
            from:    ['from@example.com'],
            to:      ['to@example.com'],
            body:    'Plain text version',
            htmlBody: '<p>HTML version</p>',
        ));

        // Should have both text and html parts
        $this->assertStringContainsString('Content-Type: text/plain', $mime);
        $this->assertStringContainsString('Content-Type: text/html', $mime);
        // Both should be in multipart/alternative
        $this->assertStringContainsString('multipart/alternative', $mime);
    }

    public function testReadResponseRefusesAnEmptyReplyLine(): void
    {
        // Behavioural pin: a reply stream whose first line is empty trips
        // the named empty_response refusal — not a reflection-shape assert.
        // (A bare LF answers '' from stream_get_line; a CRLF line lands in
        // the unexpected-code guard after the CR is trimmed.)
        $transport = new SmtpTransport('smtp.example.com', 587);

        $stream = \fopen('php://memory', 'r+');
        \fwrite($stream, "\n");
        \rewind($stream);

        $socketProp = new \ReflectionProperty($transport, 'socket');
        $socketProp->setAccessible(true);
        $socketProp->setValue($transport, $stream);

        $read = new \ReflectionMethod($transport, 'readResponseAny');
        $read->setAccessible(true);

        try {
            $read->invoke($transport, [250]);
            $this->fail('an empty reply line must refuse');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Server sent empty response', $e->getMessage());
        } finally {
            \fclose($stream);
        }
    }

    public function testSendRawRefusesAFailedWrite(): void
    {
        // Behavioural pin: fwrite on a read-only memory stream returns false
        // without warning — sendRaw must surface it as the named write_error
        // refusal rather than continue as if the bytes had shipped. (A
        // short-count write answers the incomplete_write arm instead; both
        // refusals are reachable only through the real I/O path.)
        $transport = new SmtpTransport('smtp.example.com', 587);

        $stream = \fopen('php://memory', 'r');

        $socketProp = new \ReflectionProperty($transport, 'socket');
        $socketProp->setAccessible(true);
        $socketProp->setValue($transport, $stream);

        $send = new \ReflectionMethod($transport, 'sendRaw');
        $send->setAccessible(true);

        try {
            $send->invoke($transport, "MAIL FROM:<a@b.c>\r\n");
            $this->fail('a failed write must refuse');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Failed to write to SMTP server', $e->getMessage());
        } finally {
            \fclose($stream);
        }
    }

    /**
     * Helper to build MIME message via reflection.
     */
    private function buildMime(SmtpTransport $transport, Email $email): string
    {
        $reflection = new \ReflectionClass($transport);
        $method = $reflection->getMethod('buildMimeMessage');
        $method->setAccessible(true);

        return $method->invoke($transport, $email);
    }
}
