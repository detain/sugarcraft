<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plugin;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Plugin\Response;

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
        $this->expectExceptionMessageMatches('/Invalid JSON/');

        Response::fromJson('not valid json');
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
