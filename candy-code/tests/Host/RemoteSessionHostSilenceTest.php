<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PHPUnit\Framework\TestCase;
use Ratchet\RFC6455\Messaging\Frame;
use React\Stream\ThroughStream;
use SugarCraft\Code\Host\RemoteSessionHost;

/**
 * S-H04: a frame the host cannot use is no longer invisible. A malformed
 * payload logs once per stream on the `host` channel (never per frame), and a
 * throwing event listener is logged while its siblings still hear.
 */
final class RemoteSessionHostSilenceTest extends TestCase
{
    private string $log = '';

    private string|false $previous = false;

    protected function setUp(): void
    {
        $this->log = \sys_get_temp_dir() . '/s-h04-' . \bin2hex(\random_bytes(6)) . '.log';
        \touch($this->log);
        $this->previous = \ini_set('error_log', $this->log);
    }

    protected function tearDown(): void
    {
        if ($this->previous !== false) {
            \ini_set('error_log', $this->previous);
        }
        @\unlink($this->log);
    }

    private function frame(string $payload): string
    {
        return (new Frame($payload, true, Frame::OP_TEXT))->getContents();
    }

    private function logged(): string
    {
        return (string) \file_get_contents($this->log);
    }

    public function testMalformedFrameLogsOncePerStream(): void
    {
        $stream = new ThroughStream();
        $host = RemoteSessionHost::overStream($stream);

        $stream->emit('data', [$this->frame('{not json')]);
        $stream->emit('data', [$this->frame('{still bad')]);
        $stream->emit('data', [$this->frame('"a string"')]);

        $this->assertSame(1, \substr_count($this->logged(), 'host: dropped malformed frame'));
        $this->assertNotNull($host);
    }

    public function testThrowingListenerIsLoggedAndSiblingsStillHear(): void
    {
        $stream = new ThroughStream();
        $host = RemoteSessionHost::overStream($stream);
        $heard = 0;
        $host->onEvent(static function (): void {
            throw new \RuntimeException('listener boom');
        });
        $host->onEvent(static function () use (&$heard): void {
            $heard++;
        });

        $stream->emit('data', [$this->frame(\json_encode([
            'jsonrpc' => '2.0',
            'method' => \SugarCraft\Code\Protocol\EventEnvelope::METHOD,
            'params' => ['sessionId' => 's', 'seq' => 1],
        ], \JSON_THROW_ON_ERROR))]);

        $this->assertSame(1, $heard);
        $this->assertStringContainsString('host: event listener threw', $this->logged());
        $this->assertStringContainsString('listener boom', $this->logged());
    }
}
