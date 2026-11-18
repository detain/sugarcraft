<?php

declare(strict_types=1);

namespace SugarCraft\ServeProtocol\Tests\Discovery;

use PHPUnit\Framework\TestCase;
use SugarCraft\ServeProtocol\Discovery\DiscoveryRecordReader;
use SugarCraft\ServeProtocol\Tests\Support\TempTree;

final class DiscoveryRecordReaderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = TempTree::make();
    }

    protected function tearDown(): void
    {
        TempTree::remove($this->root);
    }

    public function testMissingFileIsNoRecord(): void
    {
        self::assertNull(DiscoveryRecordReader::read($this->root . '/server.json'));
    }

    public function testValidRecordReturnsRawArray(): void
    {
        $path = $this->root . '/server.json';
        \file_put_contents($path, \json_encode([
            'pid' => 4242,
            'procStartTime' => 99,
            'url' => 'http://127.0.0.1:7420',
            'protocol' => ['min' => 1, 'max' => 1],
        ]));

        $record = DiscoveryRecordReader::read($path);

        self::assertSame(4242, $record['pid'] ?? null);
        self::assertSame('http://127.0.0.1:7420', $record['url'] ?? null);
        self::assertSame(['min' => 1, 'max' => 1], $record['protocol'] ?? null);
    }

    public function testSymlinkToRecordIsRefused(): void
    {
        $target = $this->root . '/real.json';
        \file_put_contents($target, '{"pid": 1, "url": "http://x"}');
        $link = $this->root . '/server.json';
        \symlink($target, $link);

        self::assertNull(DiscoveryRecordReader::read($link));
    }

    public function testDirectoryIsNotARecord(): void
    {
        $dir = $this->root . '/server.json';
        \mkdir($dir, 0o700);

        self::assertNull(DiscoveryRecordReader::read($dir));
    }

    public function testEmptyFileIsNoRecord(): void
    {
        $path = $this->root . '/server.json';
        \file_put_contents($path, '');

        self::assertNull(DiscoveryRecordReader::read($path));
    }

    public function testMalformedJsonIsNoRecord(): void
    {
        $path = $this->root . '/server.json';
        \file_put_contents($path, '{"pid": 1, "url": ');

        self::assertNull(DiscoveryRecordReader::read($path));
    }

    /**
     * @dataProvider nonObjectJsonProvider
     */
    public function testNonArrayJsonIsNoRecord(string $json): void
    {
        $path = $this->root . '/server.json';
        \file_put_contents($path, $json);

        self::assertNull(DiscoveryRecordReader::read($path));
    }

    /** @return iterable<string, array{string}> */
    public static function nonObjectJsonProvider(): iterable
    {
        yield 'string' => ['"candy-code"'];
        yield 'integer' => ['42'];
        yield 'boolean' => ['true'];
        yield 'null' => ['null'];
    }

    public function testRecordNestedPastMaxDepthIsRefused(): void
    {
        $path = $this->root . '/server.json';
        $deep = \str_repeat('[', 10) . '1' . \str_repeat(']', 10);
        \file_put_contents($path, '{"pid": 1, "url": "http://x", "extra": ' . $deep . '}');

        self::assertNull(DiscoveryRecordReader::read($path));
    }

    public function testRecordWithinMaxDepthIsAccepted(): void
    {
        $path = $this->root . '/server.json';
        \file_put_contents($path, '{"pid": 1, "url": "http://x", "extra": [[1]]}');

        $record = DiscoveryRecordReader::read($path);

        self::assertSame(1, $record['pid'] ?? null);
        self::assertSame([[1]], $record['extra'] ?? null);
    }

    public function testMaxDepthIsEight(): void
    {
        self::assertSame(8, DiscoveryRecordReader::MAX_DEPTH);
    }

    public function testRecordLargerThanMaxBytesIsRefusedWhateverItsShape(): void
    {
        $path = $this->root . '/server.json';
        $head = '{"pid": 1, "url": "http://x", "pad": "';
        $tail = '"}';
        $pad = \str_repeat('a', DiscoveryRecordReader::MAX_BYTES + 1 - \strlen($head . $tail));
        \file_put_contents($path, $head . $pad . $tail);

        self::assertSame(DiscoveryRecordReader::MAX_BYTES + 1, \filesize($path));
        self::assertNull(DiscoveryRecordReader::read($path));
    }

    public function testRecordAtExactlyMaxBytesIsAccepted(): void
    {
        $path = $this->root . '/server.json';
        $head = '{"pid": 1, "url": "http://x", "pad": "';
        $tail = '"}';
        $pad = \str_repeat('a', DiscoveryRecordReader::MAX_BYTES - \strlen($head . $tail));
        \file_put_contents($path, $head . $pad . $tail);

        self::assertSame(DiscoveryRecordReader::MAX_BYTES, \filesize($path));
        self::assertSame(1, DiscoveryRecordReader::read($path)['pid'] ?? null);
    }

    public function testMaxBytesIs64KiB(): void
    {
        self::assertSame(65536, DiscoveryRecordReader::MAX_BYTES);
    }
}
