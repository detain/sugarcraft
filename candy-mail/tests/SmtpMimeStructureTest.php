<?php

declare(strict_types=1);

namespace SugarCraft\Mail\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mail\Attachment;
use SugarCraft\Mail\Email;
use SugarCraft\Mail\SmtpTransport;

/**
 * Structural RFC 2046 pins for the emitted MIME message (docs-fix-1023 C-1).
 *
 * The old defect: buildBody() opened the first part with its OWN random
 * boundary instead of the one declared in Content-Type, so strict parsers
 * found zero valid part delimiters. These tests parse the rendered message
 * and require every delimiter token to be one that was actually declared.
 */
final class SmtpMimeStructureTest extends TestCase
{
    public function testDeclaredBoundaryOpensTheBodyPartOfAPlainEmail(): void
    {
        $mime = $this->render(new Email(
            from: ['from@example.com'],
            to:   ['to@example.com'],
            body: 'Hello world',
        ));

        $boundary = $this->declaredMultipartBoundary($mime);

        // The FIRST delimiter after the header block must be the declared one.
        $this->assertStringContainsString("\r\n\r\n--{$boundary}\r\n", $mime);
        // And the message closes with that same boundary's epilogue token.
        $this->assertStringEndsWith("--{$boundary}--", $mime);
    }

    public function testEveryPartDelimiterWasDeclaredSomewhereInTheMessage(): void
    {
        $email = new Email(
            from:     ['from@example.com'],
            to:       ['to@example.com'],
            body:     'Plain part',
            htmlBody: '<p>Html part</p>',
            attachments: [
                Attachment::fromContent('raw', 'note.txt', 'text/plain'),
            ],
        );
        $mime = $this->render($email);

        $allowed = [$this->declaredMultipartBoundary($mime)];

        // The inner alternative part declares its own boundary in its own
        // header — collect it too if present.
        if (\preg_match('/multipart\/alternative; boundary="([0-9a-f]{32})"/', $mime, $inner) === 1) {
            $allowed[] = $inner[1];
        }

        \preg_match_all('/^--([0-9a-f]{32})(--)?\r?$/m', $mime, $tokens, PREG_SET_ORDER);
        $this->assertNotEmpty($tokens, 'rendered message carries no part delimiters at all');

        foreach ($tokens as $token) {
            $this->assertContains(
                $token[1],
                $allowed,
                'Undeclared boundary ' . $token[1] . ' used as a part delimiter (C-1 shape)',
            );
        }

        // The declared outer boundary must actually open at least one part.
        $this->assertContains($allowed[0], \array_column($tokens, 1));
    }

    public function testDateAndMessageIdHeadersAreEmittedAndUnique(): void
    {
        $first  = $this->render(new Email(from: ['a@example.com'], to: ['b@example.com'], body: 'x'));
        $second = $this->render(new Email(from: ['a@example.com'], to: ['b@example.com'], body: 'x'));

        $this->assertMatchesRegularExpression(
            '/^Date: [A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} [+\-]\d{4}\r?$/m',
            $first,
        );
        $this->assertMatchesRegularExpression('/^Message-ID: <[0-9a-f]{32}@[\w.\-]+>\r?$/m', $first);

        // Message-IDs must differ between renders (uniqueness, not a constant).
        $this->assertNotSame(
            $this->headerLine($first, 'Message-ID:'),
            $this->headerLine($second, 'Message-ID:'),
        );
    }

    public function testHeloHostOutsideTheHostnameGrammarIsRefusedAtConstruction(): void
    {
        // C3 (lib-reviews-1102): heloHost rides `EHLO {host}\r\n` verbatim on
        // the pre-TLS protocol channel — one CRLF pair forges whole SMTP
        // commands. Construction is now the choke; these are the shapes the
        // old "sanitize at Message-ID time" stance let through the door.
        foreach ([
            "ev]il ho@st!",
            "ok.example.com\r\nMAIL FROM:<evil@x>",
            'spaced host',
            'br>acket',
            "null\0byte.example",
        ] as $hostile) {
            try {
                new SmtpTransport('smtp.example.com', 587, '', '', 30, $hostile);
                $this->fail('hostile heloHost accepted at construction: ' . \addcslashes($hostile, "\0..\37"));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('not a valid hostname', $e->getMessage());
            }
        }

        // The legal grammar still passes (letters/digits/dot/hyphen).
        new SmtpTransport('smtp.example.com', 587, '', '', 30, 'mail.example.com');
    }

    public function testMessageIdHostDefensivelyFiltersTheHeloIdentity(): void
    {
        // With the C3 construction gate, hostile values cannot reach the
        // property through the public API; messageIdHost() stays as
        // defense-in-depth. Pin it by writing the property directly past the
        // ctor — simulating any future unguarded assignment.
        $transport = new SmtpTransport('smtp.example.com', 587, '', '', 30, 'legal.example.com');
        $host      = new \ReflectionProperty($transport, 'heloHost');
        $host->setValue($transport, "ev]il ho@st!");

        $filter = new \ReflectionMethod($transport, 'messageIdHost');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9.\-]+$/', $filter->invoke($transport));

        $mime      = $transport->buildMimeMessage(new Email(
            from: ['a@example.com'],
            to:   ['b@example.com'],
            body: 'x',
        ));
        $messageId = $this->headerLine($mime, 'Message-ID:');
        // Exactly one '@', angle brackets balanced, no forged header bytes.
        $this->assertSame(1, \substr_count($messageId, '@'));
        $this->assertMatchesRegularExpression('/^Message-ID: <[0-9a-f]{32}@[A-Za-z0-9.\-]*>$/m', $messageId);
    }

    // -------------------------------------------------------------------------

    private function render(Email $email): string
    {
        return (new SmtpTransport('smtp.example.com', 587))->buildMimeMessage($email);
    }

    private function declaredMultipartBoundary(string $mime): string
    {
        $this->assertSame(
            1,
            \preg_match('/Content-Type: multipart\/mixed; boundary="([0-9a-f]{32})"/', $mime, $match),
            'exactly one multipart/mixed declaration expected',
        );
        return $match[1];
    }

    private function headerLine(string $mime, string $prefix): string
    {
        foreach (\explode("\r\n", $mime) as $line) {
            if (\str_starts_with($line, $prefix)) {
                return $line;
            }
        }
        $this->fail("header line {$prefix} not found");
    }
}
