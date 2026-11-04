<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server\Http;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Server\Http\Responses;

/**
 * The HTTP response shapes' own failure leg: what happens when the body
 * handed to {@see Responses::json()} cannot be encoded at all.
 */
final class ResponsesTest extends TestCase
{
    public function testABodyThatCannotBeEncodedRefusesWithTheErrorShape(): void
    {
        // CWPD-12: `(string) json_encode(...)` turns refusal into an EMPTY
        // 200 — the client sees `Content-Type: application/json` with zero
        // bytes and cannot parse what never existed. An unencodable body is
        // a server-side bug, and a server-side bug answers 500 with the
        // refusal envelope the guards use, built from a literal (the
        // offending body is precisely what cannot be encoded, so it must
        // not be re-encoded into the message).
        $handle = \fopen('php://memory', 'r');
        try {
            $response = Responses::json(200, ['handle' => $handle]);
        } finally {
            \fclose($handle);
        }

        self::assertSame(500, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString(
            '{"error":{"kind":"server_error","message":"the response could not be encoded"}}',
            (string) $response->getBody(),
        );
    }

    public function testAnEncodableBodyStillRidesTheRequestedStatusVerbatim(): void
    {
        // The negative leg must not eat honest traffic: a normal body still
        // gets its status, its headers and its exact JSON.
        $response = Responses::json(201, ['ok' => true]);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('{"ok":true}', (string) $response->getBody());
    }
}
