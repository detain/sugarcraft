<?php

declare(strict_types=1);

namespace SugarCraft\Mail\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mail\Email;
use SugarCraft\Mail\ResendTransport;

/**
 * Tests for ResendTransport error paths and edge cases.
 */
final class ResendTransportErrorTest extends TestCase
{
    public function testSendWithEmptyApiKeyFallsBackToEnvVar(): void
    {
        // The old arm only proved construction (name() is a constant). The
        // fallback is observable: an env-fed key lands in the property and
        // masks as '****' through __debugInfo, while an unseeded empty key
        // stays the unmasked '' — so the masked shape after seeding proves
        // the environment value actually stored.
        \putenv('RESEND_API_KEY=re_env_fall_back_key');
        $_ENV['RESEND_API_KEY'] = 're_env_fall_back_key';
        try {
            $transport = new ResendTransport('');
            $this->assertSame('****', $transport->__debugInfo()['apiKey']);
        } finally {
            \putenv('RESEND_API_KEY');
            unset($_ENV['RESEND_API_KEY']);
        }

        $this->assertSame('', (new ResendTransport(''))->__debugInfo()['apiKey']);
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

    public function testFirstAddrRefusesAnEmptyList(): void
    {
        $transport = new ResendTransport('test_key');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('from address');

        $this->invokeFirstAddr($transport, []);
    }

    public function testFirstAddrRefusesAForcedEmptyFirstElement(): void
    {
        $transport = new ResendTransport('test_key');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('from address');

        $this->invokeFirstAddr($transport, ['']);
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

    public function testSendRefusesAnEmptySenderBeforeAnyHttp(): void
    {
        $transport = new ResendTransport('test_key');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('from address');

        $transport->send(new Email(from: [], to: ['recipient@example.com']));
    }

    public function testSendRefusesAnEmptyRecipientListBeforeAnyHttp(): void
    {
        $transport = new ResendTransport('test_key');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('recipient');

        $transport->send(new Email(from: ['sender@example.com'], to: []));
    }
}
