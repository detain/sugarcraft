<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plugin;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Plugin\Request;
use SugarCraft\Dash\Plugin\Response;

/**
 * Tests for plugin communication DTOs and external module integration.
 */
final class ExternalModuleTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Request JSON serialization
    // ═══════════════════════════════════════════════════════════════

    public function testRequestToJsonProducesValidJson(): void
    {
        $request = new Request('update', ['foo' => 'bar']);
        $json = $request->toJson();

        $decoded = json_decode($json, true);
        $this->assertNotNull($decoded);
        $this->assertSame('update', $decoded['type']);
        $this->assertSame('bar', $decoded['data']['foo']);
    }

    public function testRequestFromJsonRoundtrip(): void
    {
        $original = new Request('view', ['width' => 80, 'height' => 24]);
        $json = $original->toJson();
        $restored = Request::fromJson($json);

        $this->assertSame($original->type, $restored->type);
        $this->assertSame($original->data, $restored->data);
    }

    public function testRequestInitFactory(): void
    {
        $request = Request::init();
        $json = $request->toJson();

        $decoded = json_decode($json, true);
        $this->assertSame('init', $decoded['type']);
        $this->assertSame([], $decoded['data']);
    }

    public function testRequestUpdateFactory(): void
    {
        $request = Request::update(['tick' => 42]);
        $json = $request->toJson();

        $decoded = json_decode($json, true);
        $this->assertSame('update', $decoded['type']);
        $this->assertSame(42, $decoded['data']['state']['tick']);
    }

    public function testRequestViewFactory(): void
    {
        $request = Request::view(80, 24, ['tick' => 1]);
        $json = $request->toJson();

        $decoded = json_decode($json, true);
        $this->assertSame('view', $decoded['type']);
        $this->assertSame(80, $decoded['data']['width']);
        $this->assertSame(24, $decoded['data']['height']);
        $this->assertSame(1, $decoded['data']['state']['tick']);
    }

    // ═══════════════════════════════════════════════════════════════
    // Response JSON serialization
    // ═══════════════════════════════════════════════════════════════

    public function testResponseToJsonProducesValidJson(): void
    {
        $response = new Response('update', ['state' => ['tick' => 1]]);
        $json = $response->toJson();

        $decoded = json_decode($json, true);
        $this->assertNotNull($decoded);
        $this->assertSame('update', $decoded['type']);
        $this->assertSame(1, $decoded['data']['state']['tick']);
    }

    public function testResponseFromJsonRoundtrip(): void
    {
        $original = new Response('view', ['content' => 'Hello World']);
        $json = $original->toJson();
        $restored = Response::fromJson($json);

        $this->assertSame($original->type, $restored->type);
        $this->assertSame($original->data, $restored->data);
    }

    public function testResponseInitFactory(): void
    {
        $response = Response::init('my-plugin', [30, 4], 5);
        $json = $response->toJson();

        $decoded = json_decode($json, true);
        $this->assertSame('init', $decoded['type']);
        $this->assertSame('my-plugin', $decoded['data']['name']);
        $this->assertSame([30, 4], $decoded['data']['minSize']);
        $this->assertSame(5, $decoded['data']['interval']);
    }

    public function testResponseUpdateFactory(): void
    {
        $response = Response::update(['tick' => 99]);
        $json = $response->toJson();

        $decoded = json_decode($json, true);
        $this->assertSame('update', $decoded['type']);
        $this->assertSame(99, $decoded['data']['state']['tick']);
    }

    public function testResponseViewFactory(): void
    {
        $response = Response::view("Hello\nWorld");
        $json = $response->toJson();

        $decoded = json_decode($json, true);
        $this->assertSame('view', $decoded['type']);
        $this->assertSame("Hello\nWorld", $decoded['data']['content']);
    }

    public function testResponseErrorFactory(): void
    {
        $response = Response::error('Something went wrong');
        $json = $response->toJson();

        $decoded = json_decode($json, true);
        $this->assertSame('error', $decoded['type']);
        $this->assertSame('Something went wrong', $decoded['data']['message']);
    }

    // ═══════════════════════════════════════════════════════════════
    // External plugin fixture integration
    // ═══════════════════════════════════════════════════════════════

    /**
     * Test that the echo-plugin.sh fixture can be spawned and communicates.
     * Uses proc_open with stream-write pattern (ftell/fseek) as per project conventions.
     */
    public function testExternalModuleSpawnsAndCommunicates(): void
    {
        $pluginPath = __DIR__ . '/../fixtures/echo-plugin.sh';
        if (!file_exists($pluginPath)) {
            $this->markTestSkipped('echo-plugin.sh not found');
        }

        if (!is_executable($pluginPath)) {
            $this->markTestSkipped('echo-plugin.sh is not executable');
        }

        // Create a request and convert to JSON
        $request = new Request('update', ['foo' => 'bar']);
        $requestJson = $request->toJson() . "\n";

        // Open process with stdin/stdout pipes
        $proc = proc_open(
            [$pluginPath],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes
        );

        $this->assertIsResource($proc, 'Process should be started');

        try {
            // Write request to stdin using stream-write pattern
            fwrite($pipes[0], $requestJson);
            fclose($pipes[0]);

            // Read response from stdout
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            // Verify we got some output
            $this->assertNotEmpty($output, 'Should receive response from plugin');

            // Parse the response - the echo plugin transforms the JSON
            // "type":"update" -> "type":"response_method", "data" -> "result"
            $response = Response::fromJson(trim($output));
            $this->assertNotNull($response, 'Response should be parseable');
        } finally {
            proc_close($proc);
        }
    }
}
