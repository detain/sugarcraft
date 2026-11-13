<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PHPUnit\Framework\TestCase;
use Ratchet\RFC6455\Messaging\Frame;
use React\Stream\CompositeStream;
use React\Stream\ThroughStream;
use SugarCraft\Code\Host\RemoteSessionHost;

/**
 * S-H02: the client call layer is sugar-mcp-client's McpCall (envelope + the
 * empty-params `{}` shim) and PendingCalls (the in-flight table). Pinned:
 * the relay shim on both entry points, ids, and transport death failing every
 * pending call.
 */
final class RemoteSessionHostCallLayerTest extends TestCase
{
    /**
     * @return array{0: RemoteSessionHost, 1: ThroughStream, 2: \ArrayObject<int, string>} the host, the
     *         server's side feeding it, and what the host wrote (as frame payloads)
     */
    private function open(): array
    {
        $in = new ThroughStream();
        $out = new ThroughStream();
        $sent = new \ArrayObject();
        $out->on('data', static function (string $bytes) use ($sent): void {
            $frame = new Frame();
            $frame->addBuffer($bytes);
            $sent->append($frame->getPayload());
        });

        return [RemoteSessionHost::overStream(new CompositeStream($in, $out)), $in, $sent];
    }

    public function testAnEmptyParamsArrayLeavesAsAnObject(): void
    {
        [$host, , $sent] = $this->open();
        $host->call('ping');
        $host->callPreserving('relay', []);

        $this->assertStringContainsString('"params":{}', $sent[0]);
        $this->assertStringContainsString('"params":{}', $sent[1]);
        $this->assertStringContainsString('"id":"a1"', $sent[0]);
        $this->assertStringContainsString('"id":"a2"', $sent[1]);
    }

    public function testAForwardedEmptyStdClassStaysAnObjectByteForByte(): void
    {
        [$host, , $sent] = $this->open();
        $host->callPreserving('relay', new \stdClass());

        $this->assertStringContainsString('"params":{}', $sent[0]);
    }

    public function testTheResultOfAPreservingCallKeepsEmptyObjects(): void
    {
        [$host, $stream, $sent] = $this->open();
        $got = null;
        $host->callPreserving('relay', ['x' => 1])->then(static function ($r) use (&$got): void {
            $got = $r;
        });
        $stream->emit('data', [(new Frame('{"jsonrpc":"2.0","id":"a1","result":{"empty":{}}}', true, Frame::OP_TEXT))->getContents()]);

        $this->assertInstanceOf(\stdClass::class, $got);
        $this->assertInstanceOf(\stdClass::class, $got->empty);
    }

    public function testTransportDeathFailsEveryPendingCall(): void
    {
        [$host, $stream] = $this->open();
        $failures = [];
        foreach (['a', 'b'] as $m) {
            $host->call($m)->then(null, static function (\Throwable $e) use (&$failures): void {
                $failures[] = $e->getMessage();
            });
        }
        $stream->close();

        $this->assertCount(2, $failures);
    }
}
