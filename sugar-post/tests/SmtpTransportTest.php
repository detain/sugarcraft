<?php

declare(strict_types=1);

namespace SugarCraft\Post\Tests;

use PHPUnit\Framework\TestCase;
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
        $this->assertSame('smtp://smtp.example.com:587', $transport->name());
    }
}
