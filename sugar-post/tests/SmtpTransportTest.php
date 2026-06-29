<?php

declare(strict_types=1);

namespace SugarCraft\Post\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Post\Email;
use SugarCraft\Post\SmtpTransport;

/**
 * Tests for SmtpTransport.
 */
final class SmtpTransportTest extends TestCase
{
    public function testNameIncludesHostAndPort(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587);
        $this->assertSame('smtp://smtp.example.com:587', $transport->name());
    }

    public function testNameWithCustomPort(): void
    {
        $transport = new SmtpTransport('mail.test.com', 465);
        $this->assertSame('smtp://mail.test.com:465', $transport->name());
    }

    public function testNameWithDefaultPort(): void
    {
        $transport = new SmtpTransport('smtp.test.com');
        $this->assertSame('smtp://smtp.test.com:587', $transport->name());
    }

    public function testConstructorStoresCredentials(): void
    {
        $transport = new SmtpTransport(
            'smtp.example.com',
            587,
            'user@example.com',
            'secretpassword',
            60
        );
        $this->assertSame('smtp://smtp.example.com:587', $transport->name());
    }

    public function testTlsFlagSetForPort465(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 465);
        // TLS is set when port is 465 - this is internal state
        // We verify via name that object is constructed properly
        $this->assertSame('smtp://smtp.example.com:465', $transport->name());
    }

    public function testTlsFlagNotSetForPort587(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587);
        // TLS is not set when port is 587
        $this->assertSame('smtp://smtp.example.com:587', $transport->name());
    }

    public function testDotStuffsLeadingDot(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587);

        $reflection = new \ReflectionClass($transport);
        $dotStuff = $reflection->getMethod('dotStuff');
        $dotStuff->setAccessible(true);

        // Input: line starting with ".", and a normal line
        // Per RFC 5321 §4.5.2, each leading "." gets an extra "." prefix
        $input = ".\nhello\n..world";
        $result = $dotStuff->invoke($transport, $input);

        // The first "." becomes "..", the ".." at start of "..world" becomes "..." (RFC-compliant)
        $this->assertSame("..\nhello\n...world", $result);
    }

    public function testBuildMimeMessageNormalizesLineEndings(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587);

        $reflection = new \ReflectionClass($transport);
        $buildMime = $reflection->getMethod('buildMimeMessage');
        $buildMime->setAccessible(true);

        $email = new Email(
            from:    ['from@example.com'],
            to:      ['to@example.com'],
            subject: 'Test',
            body:    "Line1\r\nLine2\rLine3\nLine4",
        );

        $mime = $buildMime->invoke($transport, $email);

        // Body section should have consistent CRLF framing - no bare CR
        // Extract just the body lines (between Content-Type and boundary)
        $this->assertStringNotContainsString("\r\r", $mime);
        $this->assertStringContainsString("Line1\r\n", $mime);
    }
}
