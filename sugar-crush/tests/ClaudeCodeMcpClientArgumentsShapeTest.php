<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\ClaudeCodeMcpClient;

/**
 * Audit MCP-1 follow-up: {@see ClaudeCodeMcpClient::callTool()} sent
 * `'arguments' => $params ?? []`, and PHP's `[]` encodes as a JSON ARRAY. The
 * schema types `arguments` as an object, and the official SDK servers reject
 * the array form ("expected record, received array") — so every argument-less
 * `claude-mcp` tool call failed. Same defect class the stdio and HTTP clients
 * were fixed for.
 *
 * The child decodes WITHOUT assoc, so a JSON `{}` and a JSON `[]` stay
 * distinct, and answers each `tools/call` with the shape it actually received.
 */
final class ClaudeCodeMcpClientArgumentsShapeTest extends TestCase
{
    private const FIXTURE = <<<'PHP'
        <?php
        $born = microtime(true);
        while (($line = fgets(STDIN)) !== false) {
            if (microtime(true) - $born > 20.0) {
                exit(0);
            }
            $msg = json_decode($line);
            if (!$msg instanceof stdClass || ($msg->method ?? null) !== 'tools/call' || !isset($msg->id)) {
                continue;
            }
            $params = $msg->params ?? null;
            $shape = !$params instanceof stdClass ? 'params-not-object'
                : (!property_exists($params, 'arguments') ? 'absent'
                : ($params->arguments instanceof stdClass ? 'object' : gettype($params->arguments)));
            echo json_encode(['jsonrpc' => '2.0', 'id' => $msg->id, 'result' => ['shape' => $shape]]), "\n";
            fflush(STDOUT);
        }
        PHP;

    private string $script = '';

    private ?ClaudeCodeMcpClient $client = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->script = (string) tempnam(sys_get_temp_dir(), 'cc_args_shape_');
        file_put_contents($this->script, self::FIXTURE);

        $this->client = new ClaudeCodeMcpClient(PHP_BINARY, [$this->script]);
        $this->client->connect();
    }

    protected function tearDown(): void
    {
        $this->client?->disconnect();
        $this->client = null;
        if ($this->script !== '' && is_file($this->script)) {
            unlink($this->script);
        }

        parent::tearDown();
    }

    /** @param array<string, mixed>|null $params */
    private function shapeOf(?array $params): mixed
    {
        \assert($this->client !== null);
        $reply = $params === null ? $this->client->callTool('noop') : $this->client->callTool('noop', $params);

        self::assertIsArray($reply->result, 'the fixture answered without a result');

        return $reply->result['shape'] ?? null;
    }

    public function testOmittedArgumentsReachTheWireAsAnObject(): void
    {
        self::assertSame('object', $this->shapeOf(null));
    }

    public function testAnEmptyArgumentMapReachesTheWireAsAnObject(): void
    {
        self::assertSame('object', $this->shapeOf([]));
    }

    public function testANonEmptyArgumentMapKeepsItsObjectShape(): void
    {
        self::assertSame('object', $this->shapeOf(['path' => 'README.md']));
    }
}
