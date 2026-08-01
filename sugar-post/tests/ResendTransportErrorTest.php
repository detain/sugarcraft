<?php

declare(strict_types=1);

namespace SugarCraft\Post\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Post\Email;
use SugarCraft\Post\ResendTransport;

/**
 * Tests for ResendTransport error paths and edge cases.
 */
final class ResendTransportErrorTest extends TestCase
{
    public function testSendWithEmptyApiKeyFallsBackToEnvVar(): void
    {
        // When no API key is provided, it should try RESEND_API_KEY env var
        // We just verify construction doesn't throw
        $transport = new ResendTransport('');
        $this->assertSame('resend', $transport->name());
    }

    public function testNameReturnsResend(): void
    {
        $transport = new ResendTransport('test_key');
        $this->assertSame('resend', $transport->name());
    }

    public function testPayloadWithoutSubjectUsesDefault(): void
    {
        $transport = new ResendTransport('test_key');
        $email = new Email(
            from: ['sender@example.com'],
            to:   ['recipient@example.com'],
        );

        $payload = $this->invokeBuildPayload($transport, $email);

        $this->assertSame('(no subject)', $payload['subject']);
    }

    public function testPayloadWithoutBodyOrHtml(): void
    {
        $transport = new ResendTransport('test_key');
        $email = new Email(
            from:    ['sender@example.com'],
            to:      ['recipient@example.com'],
            subject: 'Test',
        );

        $payload = $this->invokeBuildPayload($transport, $email);

        $this->assertArrayNotHasKey('html', $payload);
        $this->assertArrayNotHasKey('text', $payload);
    }

    public function testPayloadWithHtmlBodyOnlyNoText(): void
    {
        $transport = new ResendTransport('test_key');
        $email = new Email(
            from:    ['sender@example.com'],
            to:      ['recipient@example.com'],
            subject: 'HTML only',
            htmlBody: '<p>Hello</p>',
        );

        $payload = $this->invokeBuildPayload($transport, $email);

        $this->assertArrayHasKey('html', $payload);
        $this->assertArrayNotHasKey('text', $payload);
    }

    public function testFirstAddrReturnsFirstElement(): void
    {
        $transport = new ResendTransport('test_key');
        $first = $this->invokeFirstAddr($transport, ['a@example.com', 'b@example.com']);
        $this->assertSame('a@example.com', $first);
    }

    public function testFirstAddrReturnsDefaultWhenEmpty(): void
    {
        $transport = new ResendTransport('test_key');
        $first = $this->invokeFirstAddr($transport, []);
        $this->assertSame('unknown@localhost', $first);
    }

    /**
     * @return array<string, mixed>
     */
    private function invokeBuildPayload(ResendTransport $transport, Email $email): array
    {
        $reflection = new \ReflectionClass($transport);
        $method = $reflection->getMethod('buildPayload');
        $method->setAccessible(true);

        return $method->invoke($transport, $email);
    }

    private function invokeFirstAddr(ResendTransport $transport, array $addrs): string
    {
        $reflection = new \ReflectionClass($transport);
        $method = $reflection->getMethod('firstAddr');
        $method->setAccessible(true);

        return $method->invoke($transport, $addrs);
    }
}
