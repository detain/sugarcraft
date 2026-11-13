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
        // And the message closes with that same boundary's epilogue token,
        // terminated by CRLF per RFC 2046 §5.1 (B6).
        $this->assertStringEndsWith("--{$boundary}--\r\n", $mime);
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

    // ---- B6 (lib-reviews-1102): line endings, folding, RFC 2231, 8BITMIME ----

    public function testNoBareLineFeedsAnywhereInTheRenderedDocument(): void
    {
        $tmp = \tempnam(\sys_get_temp_dir(), 'cmime');
        \file_put_contents($tmp, 'attachment content');
        try {
            $email = (new Email(
                from:     ['from@example.com'],
                to:       ['to@example.com'],
                subject:  'Mixed shapes',
                body:     "plain\nsecond line",
                htmlBody: "<p>html</p>\n<p>two</p>",
            ))->withAttachment('test.txt', $tmp);

            $mime = $this->render($email);

            // RFC 2046 sect. 2 + 5.1.1: every line in the message, including
            // base64 payload chunks, is CRLF-terminated; the old chunk_split
            // glue leaked bare LFs into a CRLF document.
            $this->assertSame(0, \preg_match('/(?<!\r)\n/', $mime), 'bare LF found in rendered MIME');
        } finally {
            @\unlink($tmp);
        }
    }

    public function testLongSubjectFoldsUnderThe998OctetLimit(): void
    {
        $subject = \str_repeat('word ', 250) . 'end';
        $email = new Email(from: ['a@example.com'], to: ['b@example.com'], subject: $subject, body: 'x');
        $mime  = $this->render($email);

        $this->assertHeaderLinesWithinRfc5322($mime);

        $block = $this->subjectBlock($mime);
        // 1259 octets over the limit -> exactly one continuation line here;
        // any fold at all proves the 998 cap fired (unfold check below is the
        // semantic pin).
        $this->assertGreaterThanOrEqual(1, \substr_count($block, "\r\n"), 'a long subject must fold');
        // Unfolding (RFC 5322 sect. 2.2.3 obs-fold) restores the exact value.
        $this->assertSame('Subject: ' . $subject, \str_replace("\r\n ", ' ', $block));
    }

    /**
     * CM-69 acceptance: a 3000-octet whitespace-free subject token used to be
     * emitted as one knowingly over-long header line (the documented
     * soft-wrap pass-through); it is now refused at build time, naming the
     * field, before any socket exists.
     */
    public function testUnsplittableOversizedHeaderTokenRefusesBeforeTheWire(): void
    {
        $email = new Email(
            from: ['a@example.com'],
            to: ['b@example.com'],
            subject: \str_repeat('x', 3000),
            body: 'x',
        );
        try {
            $this->render($email);
            $this->fail('a 3000-octet unbroken token must not be emitted as one header line');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Subject', $e->getMessage(), 'the refusal names the field');
            $this->assertStringContainsString('998', $e->getMessage());
        }
    }

    public function testCjkSubjectSplitsIntoBoundedEncodedWords(): void
    {
        $subject = \str_repeat("\u{65e5}\u{672c}\u{8a9e}", 40); // 360 bytes, no spaces
        $email = new Email(from: ['a@example.com'], to: ['b@example.com'], subject: $subject, body: 'x');
        $mime  = $this->render($email);

        $this->assertHeaderLinesWithinRfc5322($mime);

        $block = $this->subjectBlock($mime);
        $count = \preg_match_all('/=\?UTF-8\?B\?([A-Za-z0-9+\/=]+)\?=/', $block, $m);
        $this->assertGreaterThan(1, $count, 'a 360-byte subject must split into several encoded-words');
        foreach ($m[0] as $word) {
            $this->assertLessThanOrEqual(75, \strlen($word), 'RFC 2047 sect. 2 encoded-word limit');
        }
        // The chunks concatenated decode back to the exact original.
        $this->assertSame($subject, \base64_decode(\implode('', $m[1])));
    }

    public function testNonAsciiFilenameRidesTheRfc2231Form(): void
    {
        $tmp = \tempnam(\sys_get_temp_dir(), 'cattach');
        \file_put_contents($tmp, 'data');
        try {
            $email = (new Email(from: ['a@example.com'], to: ['b@example.com'], body: 'x'))
                ->withAttachment("r\u{e9}sum\u{e9}-\u{20ac}.txt", $tmp);
            $mime = $this->render($email);

            // RFC 2231 sect. 4 extended form ...
            $this->assertStringContainsString("filename*=UTF-8''r%C3%A9sum%C3%A9-%E2%82%AC.txt", $mime);
            $this->assertStringContainsString("name*=UTF-8''", $mime);
            // ... next to an ASCII compatibility copy with substitution dots.
            $this->assertStringContainsString('filename="r_sum_-_.txt"', $mime);
            // No raw non-ASCII bytes leak into the headers (RFC 2047/2231 domain).
            $this->assertStringNotContainsString("r\u{e9}sum\u{e9}-\u{20ac}", $mime);
        } finally {
            @\unlink($tmp);
        }
    }

    public function testEightBitBodyDowngradesToQuotedPrintableWithoutTheCapability(): void
    {
        $email = new Email(from: ['a@example.com'], to: ['b@example.com'], body: "Caf\u{e9} plain");
        $transport = new SmtpTransport('smtp.example.com', 587);

        // Default (server advertised 8BITMIME or caller rendering): 8-bit rides as-is.
        $this->assertStringContainsString('Content-Transfer-Encoding: 8bit', $transport->buildMimeMessage($email));

        // M-DD: against a server without 8BITMIME the document must be 7-bit safe.
        $mime7 = $transport->buildMimeMessage($email, false);
        $this->assertStringContainsString('Content-Transfer-Encoding: quoted-printable', $mime7);
        $this->assertStringNotContainsString('Content-Transfer-Encoding: 8bit', $mime7);
        $this->assertStringContainsString('=C3=A9', $mime7, 'quoted-printable form (RFC 2045 sect. 6.7)');
        $this->assertHeaderLinesWithinRfc5322($mime7);
    }

    public function testMailFromCarriesBodyEightBitMimeOnlyWhenDeclared(): void
    {
        [$client, $server] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        $transport = new SmtpTransport('smtp.example.com', 587);
        (new \ReflectionProperty($transport, 'socket'))->setValue($transport, $client);

        // Two canned 250 answers for the two MAIL FROM legs (readResponse drains).
        \fwrite($server, "250 ok\r\n250 ok\r\n");

        try {
            $send = new \ReflectionMethod($transport, 'sendMailFrom');

            $send->invoke($transport, 'a@example.com', true);
            $this->assertSame("MAIL FROM:<a@example.com> BODY=8BITMIME\r\n", \fgets($server));

            $send->invoke($transport, 'a@example.com', false);
            $this->assertSame("MAIL FROM:<a@example.com>\r\n", \fgets($server));
        } finally {
            (new \ReflectionProperty($transport, 'socket'))->setValue($transport, null);
            if (\is_resource($client)) { \fclose($client); }
            if (\is_resource($server)) { \fclose($server); }
        }
    }

    public function testEightBitCapabilityIsSnapshottedAfterTheEhloReply(): void
    {
        // Behaviour half: hasExtension() reads lastResponse live, and the AUTH
        // 235 reply clobbers the EHLO capability list — a live read at MAIL
        // FROM time would always answer "no".
        $transport = new SmtpTransport('smtp.example.com', 587);
        (new \ReflectionProperty($transport, 'lastResponse'))->setValue($transport, '235 2.7.0 Authentication successful');
        $has = new \ReflectionMethod($transport, 'hasExtension');
        $this->assertFalse($has->invoke($transport, '8BITMIME'));

        // Structural half: connect() snapshots AFTER the EHLO 250 read.
        $src  = \file_get_contents(\dirname(__DIR__) . '/src/SmtpTransport.php');
        $from = \strpos($src, 'private function connect(');
        $this->assertNotFalse($from);
        $body = \substr($src, $from, \strpos($src, "\n    }\n", $from) - $from);
        $ehlo = \strpos($body, '$this->readResponse(250)');
        $snap = \strpos($body, '$this->serverSupports8Bit = $this->hasExtension');
        $this->assertNotFalse($ehlo);
        $this->assertNotFalse($snap);
        $this->assertGreaterThan($ehlo, $snap, 'the 8BITMIME snapshot must follow the EHLO reply read');
    }

    public function testEnvelopeAndHeaderAgreeOnTheTrailingAngleAddr(): void
    {
        // RFC 5322 sect. 3.4 puts the route address last; bareAddr() used to
        // grab the FIRST angle pair, desyncing the envelope from the header.
        $transport = new SmtpTransport('smtp.example.com', 587);
        $bare   = new \ReflectionMethod($transport, 'bareAddr');
        $format = new \ReflectionMethod($transport, 'formatAddressForHeader');

        $this->assertSame('real@x.example', $bare->invoke($transport, 'Name <first@x.example> other <real@x.example>'));
        $this->assertSame('real@x.example', $bare->invoke($transport, '<b> John <real@x.example>'));
        $this->assertStringContainsString('<real@x.example>', $format->invoke($transport, 'Name <first@x.example> other <real@x.example>'));
    }

    private function subjectBlock(string $mime): string
    {
        $lines = \explode("\r\n", $mime);
        $start = null;
        foreach ($lines as $i => $line) {
            if (\str_starts_with($line, 'Subject: ')) {
                $start = $i;
                break;
            }
        }
        $this->assertNotNull($start, 'Subject header missing');
        $block = $lines[$start];
        for ($j = $start + 1; isset($lines[$j]) && \str_starts_with($lines[$j], ' '); $j++) {
            $block .= "\r\n" . $lines[$j];
        }
        return $block;
    }

    private function assertHeaderLinesWithinRfc5322(string $mime): void
    {
        foreach (\explode("\r\n", $mime) as $line) {
            if ($line === '') {
                break;
            }
            // RFC 5322 sect. 2.1.1: no more than 998 content octets per line.
            $this->assertLessThanOrEqual(998, \strlen($line), 'header line too long: ' . \substr($line, 0, 40));
        }
    }

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
