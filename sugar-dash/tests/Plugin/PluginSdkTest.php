<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plugin;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Plugin\ExternalModule;
use SugarCraft\Dash\Plugin\Request;
use SugarCraft\Dash\Plugin\Response;

final class PluginSdkTest extends TestCase
{
    public function testExternalModuleCanBeCreated(): void
    {
        $module = new ExternalModule('test-plugin', 'echo', ['hello']);

        $this->assertSame('test-plugin', $module->name());
        $this->assertSame([30, 4], $module->minSize());
    }

    public function testRequestFromJson(): void
    {
        $json = '{"type":"init","data":{}}';
        $request = Request::fromJson($json);

        $this->assertSame('init', $request->type);
        $this->assertSame([], $request->data);
    }

    public function testRequestToJson(): void
    {
        $request = new Request('view', ['width' => 80, 'height' => 24]);
        $json = $request->toJson();

        $decoded = json_decode($json, true);
        $this->assertSame('view', $decoded['type']);
        $this->assertSame(80, $decoded['data']['width']);
        $this->assertSame(24, $decoded['data']['height']);
    }

    public function testResponseFromJson(): void
    {
        $json = '{"type":"update","data":{"state":{"tick":1}}}';
        $response = Response::fromJson($json);

        $this->assertSame('update', $response->type);
        $this->assertSame(1, $response->data['state']['tick']);
    }

    public function testResponseToJson(): void
    {
        $response = new Response('view', ['content' => 'Hello']);
        $json = $response->toJson();

        $decoded = json_decode($json, true);
        $this->assertSame('view', $decoded['type']);
        $this->assertSame('Hello', $decoded['data']['content']);
    }

    public function testResponseInit(): void
    {
        $response = Response::init('my-module', [20, 4], 5);
        $decoded = json_decode($response->toJson(), true);

        $this->assertSame('init', $decoded['type']);
        $this->assertSame('my-module', $decoded['data']['name']);
        $this->assertSame([20, 4], $decoded['data']['minSize']);
        $this->assertSame(5, $decoded['data']['interval']);
    }

    public function testResponseUpdate(): void
    {
        $response = Response::update(['tick' => 42]);
        $decoded = json_decode($response->toJson(), true);

        $this->assertSame('update', $decoded['type']);
        $this->assertSame(42, $decoded['data']['state']['tick']);
    }

    public function testResponseView(): void
    {
        $response = Response::view('Rendered content');
        $decoded = json_decode($response->toJson(), true);

        $this->assertSame('view', $decoded['type']);
        $this->assertSame('Rendered content', $decoded['data']['content']);
    }

    public function testResponseError(): void
    {
        $response = Response::error('Something went wrong');
        $decoded = json_decode($response->toJson(), true);

        $this->assertSame('error', $decoded['type']);
        $this->assertSame('Something went wrong', $decoded['data']['message']);
    }
}
