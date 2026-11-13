<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Plugin;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Plugin\Response;

final class ResponseTest extends TestCase
{
    public function testConstruction(): void
    {
        $response = new Response('init', ['name' => 'test']);

        $this->assertSame('init', $response->type);
        $this->assertSame(['name' => 'test'], $response->data);
    }

    public function testConstructionWithEmptyData(): void
    {
        $response = new Response('update');

        $this->assertSame('update', $response->type);
        $this->assertSame([], $response->data);
    }

    public function testInitFactory(): void
    {
        $response = Response::init('MyModule', [80, 24], 60);

        $this->assertSame('init', $response->type);
        $this->assertSame([
            'name' => 'MyModule',
            'minSize' => [80, 24],
            'interval' => 60,
        ], $response->data);
    }

    public function testInitFactoryWithDefaultInterval(): void
    {
        $response = Response::init('StaticModule', [40, 10]);

        $this->assertSame('init', $response->type);
        $this->assertSame(0, $response->data['interval']);
    }

    public function testUpdateFactory(): void
    {
        $state = ['counter' => 42];
        $response = Response::update($state);

        $this->assertSame('update', $response->type);
        $this->assertSame(['state' => $state], $response->data);
    }

    public function testViewFactory(): void
    {
        $content = "Hello\nWorld";
        $response = Response::view($content);

        $this->assertSame('view', $response->type);
        $this->assertSame(['content' => $content], $response->data);
    }

    public function testErrorFactory(): void
    {
        $message = 'Something went wrong';
        $response = Response::error($message);

        $this->assertSame('error', $response->type);
        $this->assertSame(['message' => $message], $response->data);
    }

    public function testFromJson(): void
    {
        $json = '{"type":"view","data":{"content":"hello"}}';
        $response = Response::fromJson($json);

        $this->assertSame('view', $response->type);
        $this->assertSame(['content' => 'hello'], $response->data);
    }

    public function testFromJsonWithDefaults(): void
    {
        $json = '{"type":"unknown","data":{}}';
        $response = Response::fromJson($json);

        $this->assertSame('unknown', $response->type);
        $this->assertSame([], $response->data);
    }

    public function testFromJsonWithMissingFields(): void
    {
        $json = '{"foo":"bar"}';
        $response = Response::fromJson($json);

        $this->assertSame('unknown', $response->type);
        $this->assertSame([], $response->data);
    }

    public function testFromJsonInvalidJsonThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid plugin JSON/');

        Response::fromJson('not valid json');
    }

    public function testFromJsonRefusesNonStringTypeAsProtocolError(): void
    {
        // strict_types would make this a TypeError out of the DTO ctor;
        // the boundary parse must refuse it as a protocol violation instead.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/"type" must be a string, got int/');

        Response::fromJson('{"type":123}');
    }

    public function testFromJsonRefusesNonArrayDataAsProtocolError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/"data" must be an object, got string/');

        Response::fromJson('{"type":"view","data":"nope"}');
    }

    public function testFromJsonRefusalNeverEchoesRawControlBytes(): void
    {
        $hostile = "{\"type\":\x1b[31;1mPWNED\x1b[0m}";

        try {
            Response::fromJson($hostile);
            $this->fail('expected InvalidArgumentException for unparsable wire line');
        } catch (\InvalidArgumentException $e) {
            $message = $e->getMessage();
            $this->assertStringNotContainsString("\x1b", $message, 'ESC must not survive into a refusal message');
            $this->assertSame(0, preg_match('/[^\x20-\x7E]/', $message), 'refusal stays printable');
            $this->assertLessThanOrEqual(220, strlen($message));
        }
    }

    public function testFromJsonClipsOversizedWireLines(): void
    {
        $huge = '{"foo":"' . str_repeat('A', 5000);

        $this->expectException(\InvalidArgumentException::class);

        try {
            Response::fromJson($huge);
        } catch (\InvalidArgumentException $e) {
            $this->assertLessThanOrEqual(200, strlen($e->getMessage()), 'preview stays bounded');
            throw $e;
        }
    }

    public function testToJsonThrowsOnUnencodablePayload(): void
    {
        // JSON_THROW_ON_ERROR: silent false (→ downstream TypeError on a
        // string return type) is the failure mode being cured. D-103 DISCLOSED
        // FLIP: invalid UTF-8 used to be the trigger — that throw was the 209
        // poison (Response::error on a raw-byte message died INSIDE run()'s
        // catch, killing the reply channel). It is substituted now, so the
        // fail-closed leg moves to inf, which stays genuinely unencodable.
        $response = new Response('view', ['content' => \INF]);

        $this->expectException(\JsonException::class);
        $response->toJson();
    }

    public function testInvalidUtf8IsSubstitutedNotFatal(): void
    {
        $json = (new Response('view', ['content' => "\xB1\x31"]))->toJson();
        $decoded = \json_decode($json, true);

        $this->assertSame('view', $decoded['type']);
        $this->assertStringContainsString("\u{FFFD}", $decoded['data']['content']);
    }

    public function testToJson(): void
    {
        $response = Response::init('Test', [80, 24], 30);
        $json = $response->toJson();

        $decoded = json_decode($json, true);
        $this->assertSame('init', $decoded['type']);
        $this->assertSame('Test', $decoded['data']['name']);
        $this->assertSame([80, 24], $decoded['data']['minSize']);
        $this->assertSame(30, $decoded['data']['interval']);
    }

    public function testToJsonRoundTrip(): void
    {
        $original = Response::view("Line1\nLine2");
        $json = $original->toJson();
        $restored = Response::fromJson($json);

        $this->assertSame($original->type, $restored->type);
        $this->assertSame($original->data, $restored->data);
    }
}
