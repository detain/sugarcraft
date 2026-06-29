<?php

declare(strict_types=1);

namespace SugarCraft\Post\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Post\Attachment;
use SugarCraft\Post\Email;
use SugarCraft\Post\ResendTransport;

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
}
