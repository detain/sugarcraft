<?php

declare(strict_types=1);

namespace SugarCraft\Post\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Post\Attachment;
use SugarCraft\Post\Email;
use SugarCraft\Post\SmtpTransport;

/**
 * Snapshot tests for SmtpTransport MIME message building.
 *
 * Tests multipart/mixed boundary, Cc header emission, attachment encoding,
 * dot-stuffing, UTF-8 subject encoding, and Content-Transfer-Encoding.
 */
final class SmtpMimeTest extends TestCase
{
    public function testMultipartMixedBoundaryIsPresent(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587);

        $reflection = new \ReflectionClass($transport);
        $buildMime = $reflection->getMethod('buildMimeMessage');
        $buildMime->setAccessible(true);

        $email = new Email(
            from:    ['from@example.com'],
            to:      ['to@example.com'],
            body:    'Hello world',
        );

        $mime = $buildMime->invoke($transport, $email);

        // Should have a boundary in Content-Type header
        $this->assertMatchesRegularExpression(
            '/Content-Type: multipart\/mixed; boundary="[a-f0-9]{32}"/',
            $mime
        );
    }

    public function testCcHeaderEmittedOnlyWhenCcSet(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587);

        $reflection = new \ReflectionClass($transport);
        $buildMime = $reflection->getMethod('buildMimeMessage');
        $buildMime->setAccessible(true);

        // Without CC
        $emailNoCc = new Email(
            from:    ['from@example.com'],
            to:      ['to@example.com'],
            subject: 'Test',
            body:    'Body',
        );
        $mimeNoCc = $buildMime->invoke($transport, $emailNoCc);
        $this->assertStringNotContainsString("Cc:", $mimeNoCc);

        // With CC
        $emailWithCc = new Email(
            from:    ['from@example.com'],
            to:      ['to@example.com'],
            cc:      ['cc@example.com'],
            subject: 'Test',
            body:    'Body',
        );
        $mimeWithCc = $buildMime->invoke($transport, $emailWithCc);
        $this->assertStringContainsString("Cc: cc@example.com", $mimeWithCc);
    }

    public function testAttachmentIsBase64Encoded(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587);

        $reflection = new \ReflectionClass($transport);
        $buildMime = $reflection->getMethod('buildMimeMessage');
        $buildMime->setAccessible(true);

        $tmp = \tempnam(\sys_get_temp_dir(), 'sp-mime-');
        \file_put_contents($tmp, 'attachment content');
        try {
            $email = new Email(
                from:    ['from@example.com'],
                to:      ['to@example.com'],
                body:    'See attached',
            );
            $email = $email->withAttachment('test.txt', $tmp);

            $mime = $buildMime->invoke($transport, $email);

            // Attachment should be base64 encoded
            $this->assertStringContainsString("Content-Transfer-Encoding: base64", $mime);
            $this->assertStringContainsString("attachment; filename=\"test.txt\"", $mime);
            // Check the base64 encoded content
            $encoded = \chunk_split(\base64_encode('attachment content'), 76, "\n");
            $this->assertStringContainsString($encoded, $mime);
        } finally {
            @\unlink($tmp);
        }
    }

    public function testDotStuffingPreventsLeadingDots(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587);

        $reflection = new \ReflectionClass($transport);
        $dotStuff = $reflection->getMethod('dotStuff');
        $dotStuff->setAccessible(true);

        // Input with leading dots
        $input = ".\nhello\n..world\n...test";
        $stuffed = $dotStuff->invoke($transport, $input);

        // Each leading dot should be doubled
        $this->assertStringStartsWith("..", $stuffed);  // "." -> ".."
        $this->assertStringContainsString("\nhello\n", $stuffed);  // unchanged
        // ".." at start -> "..." (first dot doubled)
        $this->assertStringContainsString("...world", $stuffed);
        // "..." at start -> "...." (first dot doubled)
        $this->assertStringContainsString("....test", $stuffed);
    }

    public function testUtf8SubjectIsRfc2047Encoded(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587);

        $reflection = new \ReflectionClass($transport);
        $buildMime = $reflection->getMethod('buildMimeMessage');
        $buildMime->setAccessible(true);

        $email = new Email(
            from:    ['from@example.com'],
            to:      ['to@example.com'],
            subject: 'Café résumé', // Non-ASCII
            body:    'Hello',
        );

        $mime = $buildMime->invoke($transport, $email);

        $this->assertStringContainsString("Subject: =?UTF-8?B?", $mime);
        // Should NOT contain raw non-ASCII in subject
        $this->assertDoesNotMatchRegularExpression(
            '/Subject: [^\r\n]*[^\x00-\x7F]/',
            $mime
        );
    }

    public function testNonAsciiBodyUses8bitCte(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587);

        $reflection = new \ReflectionClass($transport);
        $buildMime = $reflection->getMethod('buildMimeMessage');
        $buildMime->setAccessible(true);

        $email = new Email(
            from:    ['from@example.com'],
            to:      ['to@example.com'],
            body:    "Café résumé\nNaïve approach", // Non-ASCII
        );

        $mime = $buildMime->invoke($transport, $email);

        $this->assertStringContainsString("Content-Transfer-Encoding: 8bit", $mime);
    }

    public function testAsciiBodyUses7bitCte(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587);

        $reflection = new \ReflectionClass($transport);
        $buildMime = $reflection->getMethod('buildMimeMessage');
        $buildMime->setAccessible(true);

        $email = new Email(
            from:    ['from@example.com'],
            to:      ['to@example.com'],
            body:    "Plain ASCII body", // All ASCII
        );

        $mime = $buildMime->invoke($transport, $email);

        $this->assertStringContainsString("Content-Transfer-Encoding: 7bit", $mime);
    }

    /**
     * Verifies that display-name addresses are preserved in headers (header-formatted)
     * while the envelope uses the bare address only.
     *
     * formatAddressForHeader() must keep "Name <a@b>" intact for the To:/From:/Cc:
     * header lines, while bareAddr() must extract only "a@b" for the SMTP envelope.
     */
    public function testDisplayNameInHeaderBareInEnvelope(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587);

        $reflection = new \ReflectionClass($transport);

        $formatHeader = $reflection->getMethod('formatAddressForHeader');
        $formatHeader->setAccessible(true);

        $bare = $reflection->getMethod('bareAddr');
        $bare->setAccessible(true);

        // formatAddressForHeader preserves the "Name <addr>" form for header use
        $headerFormatted = $formatHeader->invoke($transport, 'Name <a@b>');
        $this->assertSame('Name <a@b>', $headerFormatted);

        // bareAddr extracts only the address portion for the SMTP envelope
        $bareAddr = $bare->invoke($transport, 'Name <a@b>');
        $this->assertSame('a@b', $bareAddr);

        // Non-display-name address passes through both unchanged
        $plain = 'plain@example.com';
        $this->assertSame('plain@example.com', $formatHeader->invoke($transport, $plain));
        $this->assertSame('plain@example.com', $bare->invoke($transport, $plain));
    }

    /**
     * Verifies that a custom HELO host can be supplied via the constructor and is
     * returned by getHeloHost() when set.
     */
    public function testHeloOverride(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587, '', '', 30, 'my-custom-helo');

        $reflection = new \ReflectionClass($transport);
        $getHelo = $reflection->getMethod('getHeloHost');
        $getHelo->setAccessible(true);

        $this->assertSame('my-custom-helo', $getHelo->invoke($transport));
    }
}
