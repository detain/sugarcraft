<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Providers\Concerns\JsonFetch;

final class JsonFetchTest extends TestCase
{
    use JsonFetch;

    private function client(Response ...$responses): Client
    {
        return new Client(['handler' => HandlerStack::create(new MockHandler($responses)), 'http_errors' => true]);
    }

    public function testDecodesObjectBody(): void
    {
        self::assertSame(['a' => 1], self::fetchJson($this->client(new Response(200, [], '{"a":1}')), 'x'));
    }

    public function testMalformedJsonThrows(): void
    {
        $this->expectException(\JsonException::class);
        self::fetchJson($this->client(new Response(200, [], '{nope')), 'x');
    }

    public function testScalarDocumentThrows(): void
    {
        $this->expectException(\JsonException::class);
        self::decodeJsonArray('42');
    }

    public function testRefusedStatusThrows(): void
    {
        $this->expectException(ClientException::class);
        self::fetchJson($this->client(new Response(404, [], '{}')), 'x');
    }
}
