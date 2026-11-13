<?php

declare(strict_types=1);

namespace SugarCraft\Mail\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Async\CancellationSource;
use SugarCraft\Mail\Email;
use SugarCraft\Mail\SmtpTransport;

/**
 * Tests for SmtpTransport.
 */
final class SmtpTransportTest extends TestCase
{
    // transport-name and TLS-flag projections removed here: the behaviour
    // homes are the security-suite context pins and the later transportName
    // wave (the old arms asserted name() strings — shape tautologies that
    // broke on rename, not on behaviour).

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
        $this->assertStringNotContainsString("\r\r", $mime);
        $this->assertStringContainsString("Line1\r\n", $mime);
    }

    public function testUtf8SubjectIsRfc2047Encoded(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587);

        $reflection = new \ReflectionClass($transport);
        $buildMime = $reflection->getMethod('buildMimeMessage');
        $buildMime->setAccessible(true);

        // Subject with non-ASCII (UTF-8 encoded)
        $email = new Email(
            from:    ['from@example.com'],
            to:      ['to@example.com'],
            subject: 'Héllo Wörld',
        );

        $mime = $buildMime->invoke($transport, $email);

        $this->assertStringContainsString('Subject: =?UTF-8?B?', $mime);
        // Should NOT contain raw non-ASCII in subject header
        $this->assertDoesNotMatchRegularExpression('/Subject: [^\r\n]*[^\x00-\x7F]/', $mime);
    }

    public function testConstructorRefusesIllegalHostShapes(): void
    {
        foreach (['', 'bad host!', 'me@somewhere.example'] as $host) {
            try {
                new SmtpTransport($host);
                $this->fail('host ' . var_export($host, true) . ' should not construct');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('not a valid hostname', $e->getMessage());
            }
        }
    }

    public function testConstructorRefusesOutOfRangePort(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/port 0 is out of range/');
        new SmtpTransport('ok.example', 0);
    }

    public function testConstructorRefusesOutOfRangeTimeout(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/timeout 3601 is not a number of seconds/');
        new SmtpTransport('ok.example', 587, '', '', 3601);
    }

    public function testLegalHostShapesStillConstruct(): void
    {
        foreach (['ok.example', '127.0.0.1', 'localhost', 'smtp.invalid.example'] as $host) {
            $this->assertInstanceOf(SmtpTransport::class, new SmtpTransport($host));
        }
    }

    /**
     * CM-59: the cancellation pre-check runs BEFORE the transport pays for a
     * dial. A closed loopback port makes the counterfactual deterministic:
     * if the guard moved below connect(), the failure would be a connection
     * refusal, not the cancellation sentence - so reordering reddens this
     * pin loudly and stays fully offline either way.
     */
    public function testCancelledTokenRefusesBeforeTheDial(): void
    {
        $source = CancellationSource::new();
        $source->cancel();

        $transport = new SmtpTransport('127.0.0.1', 1, '', '', 2);
        $email = new Email(['from@example.com'], ['to@example.com'], 'Subject', 'Body');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SMTP send cancelled before it started');

        $transport->send($email, $source->token());
    }
}
