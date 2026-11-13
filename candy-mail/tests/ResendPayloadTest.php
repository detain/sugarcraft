<?php

declare(strict_types=1);

namespace SugarCraft\Mail\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mail\Attachment;
use SugarCraft\Mail\Email;
use SugarCraft\Mail\ResendTransport;

/**
 * Snapshot tests for ResendTransport payload building.
 *
 * Tests that the payload contains expected keys and values
 * for from, to, subject, html, text, cc, bcc, reply_to, attachments.
 */
final class ResendPayloadTest extends TestCase
{
    public function testPayloadContainsFromToSubject(): void
    {
        $transport = new ResendTransport('test_key');
        $email = new Email(
            from:    ['sender@example.com'],
            to:      ['recipient@example.com'],
            subject: 'Test Subject',
        );

        $payload = $this->invokeBuildPayload($transport, $email);

        $this->assertSame('sender@example.com', $payload['from']);
        $this->assertSame(['recipient@example.com'], $payload['to']);
        $this->assertSame('Test Subject', $payload['subject']);
    }

    public function testPayloadWithHtmlBody(): void
    {
        $transport = new ResendTransport('test_key');
        $email = new Email(
            from:    ['sender@example.com'],
            to:      ['recipient@example.com'],
            subject: 'HTML Email',
            body:    'Plain text version',
            htmlBody: '<p>HTML version</p>',
        );

        $payload = $this->invokeBuildPayload($transport, $email);

        $this->assertSame('<p>HTML version</p>', $payload['html']);
        $this->assertSame('Plain text version', $payload['text']);
    }

    public function testPayloadWithPlainBodyOnly(): void
    {
        $transport = new ResendTransport('test_key');
        $email = new Email(
            from:    ['sender@example.com'],
            to:      ['recipient@example.com'],
            subject: 'Plain Email',
            body:    'Just plain text',
        );

        $payload = $this->invokeBuildPayload($transport, $email);

        $this->assertArrayNotHasKey('html', $payload);
        $this->assertSame('Just plain text', $payload['text']);
    }

    public function testPayloadWithCc(): void
    {
        $transport = new ResendTransport('test_key');
        $email = new Email(
            from:    ['sender@example.com'],
            to:      ['recipient@example.com'],
            cc:      ['cc1@example.com', 'cc2@example.com'],
        );

        $payload = $this->invokeBuildPayload($transport, $email);

        $this->assertSame('cc1@example.com, cc2@example.com', $payload['cc']);
    }

    public function testPayloadWithBcc(): void
    {
        $transport = new ResendTransport('test_key');
        $email = new Email(
            from:    ['sender@example.com'],
            to:      ['recipient@example.com'],
            bcc:     ['bcc@example.com'],
        );

        $payload = $this->invokeBuildPayload($transport, $email);

        $this->assertSame('bcc@example.com', $payload['bcc']);
    }

    public function testPayloadWithReplyTo(): void
    {
        $transport = new ResendTransport('test_key');
        $email = new Email(
            from:    ['sender@example.com'],
            to:      ['recipient@example.com'],
            replyTo: 'replyto@example.com',
        );

        $payload = $this->invokeBuildPayload($transport, $email);

        $this->assertSame('replyto@example.com', $payload['reply_to']);
    }

    public function testPayloadWithAttachments(): void
    {
        $transport = new ResendTransport('test_key');

        $tmp = \tempnam(\sys_get_temp_dir(), 'sp-resend-');
        \file_put_contents($tmp, 'file content');
        try {
            $email = new Email(
                from:    ['sender@example.com'],
                to:      ['recipient@example.com'],
                body:    'See attachment',
            );
            $email = $email->withAttachment('document.pdf', $tmp);

            $payload = $this->invokeBuildPayload($transport, $email);

            $this->assertArrayHasKey('attachments', $payload);
            $this->assertCount(1, $payload['attachments']);
            $this->assertSame('document.pdf', $payload['attachments'][0]['filename']);
            $this->assertSame(\base64_encode('file content'), $payload['attachments'][0]['content']);
        } finally {
            @\unlink($tmp);
        }
    }

    public function testPayloadCarriesTheDetectedContentTypePerAttachment(): void
    {
        $transport = new ResendTransport('test_key');
        $email = new Email(
            from:        ['sender@example.com'],
            to:          ['recipient@example.com'],
            attachments: [Attachment::fromContent("a,b\n1,2", 'report.csv', 'text/csv')],
        );

        $payload = $this->invokeBuildPayload($transport, $email);

        $this->assertSame('text/csv', $payload['attachments'][0]['content_type']);
        $this->assertSame('report.csv', $payload['attachments'][0]['filename']);
    }

    public function testInlineAttachmentRefusesOnThisTransport(): void
    {
        // The JSON payload carries no Content-ID field, so a cid part could
        // never resolve at the reader — building it must refuse, not drop.
        $transport = new ResendTransport('test_key');
        $tmp = \tempnam(\sys_get_temp_dir(), 'sp-inline-');
        \file_put_contents($tmp, 'PNGFAKE');
        try {
            $email = (new Email(
                from:     ['sender@example.com'],
                to:       ['recipient@example.com'],
                htmlBody: '<p><img src="cid:hero"></p>',
            ))->withInlineAttachment($tmp, 'hero');

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Content-ID');
            $this->invokeBuildPayload($transport, $email);
        } finally {
            @\unlink($tmp);
        }
    }

    public function testPayloadNoCcWhenCcEmpty(): void
    {
        $transport = new ResendTransport('test_key');
        $email = new Email(
            from:    ['sender@example.com'],
            to:      ['recipient@example.com'],
        );

        $payload = $this->invokeBuildPayload($transport, $email);

        $this->assertArrayNotHasKey('cc', $payload);
        $this->assertArrayNotHasKey('bcc', $payload);
    }

    public function testPayloadWithSignature(): void
    {
        $transport = new ResendTransport('test_key');
        $email = (new Email(
            from:    ['sender@example.com'],
            to:      ['recipient@example.com'],
            body:    'Body text',
        ))->withSignature('-- Signature');

        $payload = $this->invokeBuildPayload($transport, $email);

        // bodyWithSignature() appends signature to body
        $this->assertStringContainsString('Body text', $payload['text']);
        $this->assertStringContainsString('-- Signature', $payload['text']);
    }

    /**
     * Invoke the protected buildPayload method via reflection.
     *
     * @return array<string, mixed>
     */
    private function invokeBuildPayload(ResendTransport $transport, Email $email): array
    {
        $reflection = new \ReflectionClass($transport);
        $method = $reflection->getMethod('buildPayload');
        $method->setAccessible(true);

        return $method->invoke($transport, $email);
    }

    public function testPayloadCarriesCustomHeaders(): void
    {
        $email = new Email(
            from:    ['a@b.example'],
            to:      ['c@d.example'],
            subject: 'H',
            body:    'text',
            headers: ['X-Priority' => '3 (normal)', 'In-Reply-To' => '<m1@refs.test>'],
        );
        $transport = new ResendTransport('re_test_key');
        $payload = $this->invokeBuildPayload($transport, $email);
        $this->assertSame(['X-Priority' => '3 (normal)', 'In-Reply-To' => '<m1@refs.test>'], $payload['headers']);

        $plain = $this->invokeBuildPayload($transport, new Email(from: ['a@b.example'], to: ['c@d.example'], body: 'x'));
        $this->assertArrayNotHasKey('headers', $plain);
    }
}
