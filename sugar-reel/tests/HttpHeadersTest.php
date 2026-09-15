<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Reel\Decode\FfmpegDecoder;
use SugarCraft\Reel\Source\HttpHeaders;

/**
 * Unit tests for the authenticated-stream request-header path.
 *
 * Two guarantees are load-bearing here:
 *  1. A CR or LF in a header name/value is request smuggling and MUST be
 *     rejected at the boundary, before anything reaches ffmpeg.
 *  2. Validated headers become real ffmpeg input options ONLY for a network
 *     source — a local file must not gain them (ffmpeg rejects them there).
 *
 * buildCommand() is a pure static, so the flag assertions need no ffmpeg binary
 * and no process — the whole security contract is provable without a network.
 *
 * @covers \SugarCraft\Reel\Source\HttpHeaders
 */
final class HttpHeadersTest extends TestCase
{
    // -------------------------------------------------------------------------
    // parse() — reject injection at the boundary
    // -------------------------------------------------------------------------

    /**
     * @testdox a CR in a header value is rejected (request smuggling)
     */
    public function testCarriageReturnInValueRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CR, LF and NUL are rejected');
        HttpHeaders::parse(['Authorization' => "Bearer abc\r\nX-Injected: yes"]);
    }

    /**
     * @testdox a LF in a header value is rejected
     */
    public function testLineFeedInValueRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CR, LF and NUL are rejected');
        HttpHeaders::parse(['Cookie' => "sid=1\nInjected: 1"]);
    }

    /**
     * @testdox a NUL in a header value is rejected (argv truncation)
     */
    public function testNullByteInValueRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CR, LF and NUL are rejected');
        HttpHeaders::parse(['Authorization' => "Bearer\0evil"]);
    }

    /**
     * @testdox a NUL in a header name is rejected
     */
    public function testNullByteInNameRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid HTTP header name');
        HttpHeaders::parse(["X\0Foo" => 'value']);
    }

    /**
     * @testdox a CR/LF in a header NAME is rejected
     */
    public function testLineBreakInNameRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid HTTP header name');
        HttpHeaders::parse(["Bad\rName" => 'value']);
    }

    /**
     * @testdox a trailing LF on a header name is rejected, not silently trimmed
     */
    public function testTrailingLineBreakOnNameRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid HTTP header name');
        HttpHeaders::parse(["X-Foo\n" => 'value']);
    }

    /**
     * @testdox a colon inside a header name is rejected (it would declare a second field)
     */
    public function testColonInNameRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty token');
        HttpHeaders::parse(['X-Foo: bar' => 'value']);
    }

    /**
     * @testdox an empty header name is rejected
     */
    public function testEmptyNameRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty token');
        HttpHeaders::parse(['' => 'value']);
    }

    /**
     * @testdox a field line with no colon is rejected
     */
    public function testMalformedFieldLineRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('expected "Name: value"');
        HttpHeaders::parse(['not-a-header-line']);
    }

    /**
     * @testdox control characters in a rejected name come back ESCAPED, never raw, in the message
     *
     * quote() exists so the rejection notice cannot paint the terminal it is
     * printed to (ESC is exactly the C0 that survives log pipelines). Reverting
     * it to a bare \r\n\0 str_replace turns this red on both assertions.
     */
    public function testControlCharInNameIsEscapedInMessage(): void
    {
        try {
            HttpHeaders::parse(["X\x1bY" => 'v']);
            $this->fail('ESC is not an RFC 7230 token character');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('X\\x1bY', $e->getMessage(), 'the offender is shown escaped');
            $this->assertStringNotContainsString("\x1b", $e->getMessage(), 'never raw in the message');
        }
    }

    /**
     * @testdox control characters in a rejected field line are escaped in the message too
     */
    public function testControlCharInFieldLineIsEscapedInMessage(): void
    {
        try {
            HttpHeaders::parse(["no\x07colon"]);
            $this->fail('a field line with no colon is malformed regardless of payload');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('no\\x07colon', $e->getMessage());
            $this->assertStringNotContainsString("\x07", $e->getMessage());
        }
    }

    /**
     * @testdox a value containing a colon (a URL) survives when given as a field line
     */
    public function testColonInFieldValueSurvives(): void
    {
        $headers = HttpHeaders::parse(['Referer: https://host/path?a=b']);
        $pairs = $headers->pairs();
        $this->assertSame('Referer', $pairs[0]['name']);
        $this->assertSame('https://host/path?a=b', $pairs[0]['value']);
    }

    // -------------------------------------------------------------------------
    // Emitters — the wire shape
    // -------------------------------------------------------------------------

    /**
     * @testdox User-Agent is pulled out of the -headers blob and surfaced via userAgent()
     */
    public function testUserAgentExtractedFromBlob(): void
    {
        $headers = HttpHeaders::parse([
            'Authorization' => 'Bearer token123',
            'User-Agent' => 'reel-client/1.0',
        ]);

        $this->assertSame('reel-client/1.0', $headers->userAgent());

        $blob = $headers->toFfmpegHeaderString();
        $this->assertNotNull($blob);
        $this->assertStringContainsString("Authorization: Bearer token123\r\n", $blob);
        // User-Agent must NOT be duplicated into the blob — ffmpeg writes its own line.
        $this->assertStringNotContainsString('User-Agent', $blob);
    }

    /**
     * @testdox each non-User-Agent header is terminated with CRLF exactly as sent
     */
    public function testHeaderStringCrlfTerminated(): void
    {
        $blob = HttpHeaders::parse(['X-A' => '1', 'X-B' => '2'])->toFfmpegHeaderString();
        $this->assertSame("X-A: 1\r\nX-B: 2\r\n", $blob);
    }

    /**
     * @testdox an empty header set yields none() semantics
     */
    public function testEmptySet(): void
    {
        $this->assertTrue(HttpHeaders::parse([])->isEmpty());
        $this->assertTrue(HttpHeaders::none()->isEmpty());
        $this->assertNull(HttpHeaders::none()->toFfmpegHeaderString());
        $this->assertNull(HttpHeaders::none()->userAgent());
    }

    // -------------------------------------------------------------------------
    // buildCommand() — headers reach the wire only for a network source
    // -------------------------------------------------------------------------

    /**
     * @testdox an http source with headers gains -headers and -user_agent, before -i
     */
    public function testNetworkCommandCarriesHeaders(): void
    {
        $headers = HttpHeaders::parse([
            'Authorization' => 'Bearer signed-token',
            'User-Agent' => 'reel/2.0',
        ]);
        $cmd = FfmpegDecoder::buildCommand(
            '/usr/bin/ffmpeg',
            'https://cdn.example/stream?sig=x',
            80,
            48,
            24.0,
            0.0,
            false,
            null,
            null,
            $headers,
        );

        $this->assertContains('-headers', $cmd);
        $this->assertContains('-user_agent', $cmd);

        // The Authorization rides in the blob; the UA is its own option, not in the blob.
        $headersIdx = array_search('-headers', $cmd, true);
        $this->assertIsInt($headersIdx);
        $this->assertStringContainsString('Authorization: Bearer signed-token', (string) $cmd[$headersIdx + 1]);
        $this->assertStringNotContainsString('User-Agent', (string) $cmd[$headersIdx + 1]);

        $uaIdx = array_search('-user_agent', $cmd, true);
        $this->assertIsInt($uaIdx);
        $this->assertSame('reel/2.0', $cmd[$uaIdx + 1]);

        // Input options must precede -i.
        $inputIdx = array_search('-i', $cmd, true);
        $this->assertIsInt($inputIdx);
        $this->assertLessThan($inputIdx, $headersIdx, '-headers must come before -i');
        $this->assertLessThan($inputIdx, $uaIdx, '-user_agent must come before -i');
    }

    /**
     * @testdox a local-file command omits -headers/-user_agent even when headers are supplied
     */
    public function testLocalCommandOmitsHeaders(): void
    {
        $headers = HttpHeaders::parse(['Authorization' => 'Bearer x']);
        $cmd = FfmpegDecoder::buildCommand(
            '/usr/bin/ffmpeg',
            '/tmp/movie.mkv',
            80,
            48,
            24.0,
            0.0,
            false,
            null,
            null,
            $headers,
        );

        $this->assertNotContains('-headers', $cmd, 'ffmpeg rejects -headers on a file input');
        $this->assertNotContains('-user_agent', $cmd);
    }

    /**
     * @testdox a network command with NO headers adds neither flag
     */
    public function testNetworkCommandWithoutHeaders(): void
    {
        $cmd = FfmpegDecoder::buildCommand(
            '/usr/bin/ffmpeg',
            'https://cdn.example/s.mkv',
            80,
            48,
            24.0,
        );

        $this->assertContains('-reconnect', $cmd);
        $this->assertNotContains('-headers', $cmd);
        $this->assertNotContains('-user_agent', $cmd);
    }
}
