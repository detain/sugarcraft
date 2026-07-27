<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plugin;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Plugin\Request;

final class RequestTest extends TestCase
{
    public function testConstruction(): void
    {
        $request = new Request('init', ['key' => 'value']);

        $this->assertSame('init', $request->type);
        $this->assertSame(['key' => 'value'], $request->data);
    }

    public function testConstructionWithEmptyData(): void
    {
        $request = new Request('update');

        $this->assertSame('update', $request->type);
        $this->assertSame([], $request->data);
    }

    public function testInitFactory(): void
    {
        $request = Request::init();

        $this->assertSame('init', $request->type);
        $this->assertSame([], $request->data);
    }

    public function testUpdateFactory(): void
    {
        $state = ['counter' => 42];
        $request = Request::update($state);

        $this->assertSame('update', $request->type);
        $this->assertSame(['state' => $state], $request->data);
    }

    public function testViewFactory(): void
    {
        $state = ['count' => 5];
        $request = Request::view(80, 24, $state);

        $this->assertSame('view', $request->type);
        $this->assertSame([
            'width' => 80,
            'height' => 24,
            'state' => $state,
        ], $request->data);
    }

    public function testFromJson(): void
    {
        $json = '{"type":"update","data":{"state":{"foo":"bar"}}}';
        $request = Request::fromJson($json);

        $this->assertSame('update', $request->type);
        $this->assertSame(['state' => ['foo' => 'bar']], $request->data);
    }

    public function testFromJsonWithDefaults(): void
    {
        $json = '{"type":"unknown","data":{}}';
        $request = Request::fromJson($json);

        $this->assertSame('unknown', $request->type);
        $this->assertSame([], $request->data);
    }

    public function testFromJsonWithMissingFields(): void
    {
        $json = '{"foo":"bar"}';
        $request = Request::fromJson($json);

        $this->assertSame('unknown', $request->type);
        $this->assertSame([], $request->data);
    }

    public function testFromJsonInvalidJsonThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid JSON/');

        Request::fromJson('not valid json');
    }

    public function testToJson(): void
    {
        $request = new Request('init', ['key' => 'value']);
        $json = $request->toJson();

        $decoded = json_decode($json, true);
        $this->assertSame('init', $decoded['type']);
        $this->assertSame(['key' => 'value'], $decoded['data']);
    }

    public function testToJsonRoundTrip(): void
    {
        $original = Request::view(100, 50, ['state' => ['count' => 10]]);
        $json = $original->toJson();
        $restored = Request::fromJson($json);

        $this->assertSame($original->type, $restored->type);
        $this->assertSame($original->data, $restored->data);
    }
}
