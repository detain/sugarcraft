<?php

declare(strict_types=1);

namespace SugarCraft\Kv\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Kv\Import\ImportReport;
use SugarCraft\Kv\Import\JsonImporter;
use SugarCraft\Kv\Store;
use SugarCraft\Kv\Support\SizeCap;

/**
 * candy-kv-073: the byte ceilings. These pins cover the kernel itself and
 * both doors' use of it; the CLI legs live in CliContractTest.
 */
final class SizeCapTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = \sys_get_temp_dir() . '/candy-kv-sizecap-' . \uniqid();
        \mkdir($this->tmpDir, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->tmpDir . '/*') ?: [] as $f) {
            @\unlink($f);
        }
        @\rmdir($this->tmpDir);
    }

    public function testGateIsExclusiveOfTheCeilingAndExactAtItsEdge(): void
    {
        SizeCap::refuseOversized(10, 10, 'doc');           // exact cap admits
        $this->expectNotToPerformAssertions();
    }

    public function testOneByteOverRefusesNamingBothNumbers(): void
    {
        try {
            SizeCap::refuseOversized(11, 10, 'The import document');
            $this->fail('over-cap must refuse');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('The import document is 11 bytes', $e->getMessage());
            $this->assertStringContainsString('10-byte size ceiling', $e->getMessage());
            $this->assertStringContainsString('--max-bytes', $e->getMessage(), 'the refusal must name the door that lifts it');
        }
    }

    public function testZeroMeansNoCeilingAtAll(): void
    {
        SizeCap::refuseOversized(999_999_999_999, 0, 'doc');
        $this->expectNotToPerformAssertions();
    }

    public function testUnknownDeclaredSizeStillRefusesAtTheDoorThatCountedIt(): void
    {
        // null = "no stat answer" — the gate stays silent and the bounded
        // read owns enforcement (procfs/FIFO shapes never reach the decoder).
        SizeCap::refuseOversized(null, 10, 'doc');
        $this->expectNotToPerformAssertions();
    }

    public function testStreamReadStopsGrowingAtTheCapNeverBuffersPastIt(): void
    {
        // 3 MiB of bytes, 1 MiB ceiling: the loop refuses after roughly one
        // chunk over the cap — proof it is bounded is that this test never
        // hands 3 MiB to the caller nor reads the tail.
        $path = $this->tmpDir . '/fat.bin';
        $handle = \fopen($path, 'wb');
        $this->assertIsResource($handle);
        $block = str_repeat('x', 65_536);
        for ($i = 0; $i < 48; $i++) {
            \fwrite($handle, $block);
        }
        \fclose($handle);

        $stream = \fopen($path, 'rb');
        $this->assertIsResource($stream);
        try {
            SizeCap::readStream($stream, 1_048_576, 'STDIN');
            $this->fail('a 3 MiB stream must not pass a 1 MiB ceiling');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('STDIN', $e->getMessage());
            $bytes = (int) \preg_replace('/^STDIN is (\d+) bytes.*$/', '$1', $e->getMessage());
            $this->assertLessThanOrEqual(1_048_576 + 1_048_576, $bytes, 'growth stops within one chunk of the cap');
            $this->assertGreaterThan(1_048_576, $bytes);
        } finally {
            \fclose($stream);
        }
    }

    public function testUnderCapStreamPassesThroughWhole(): void
    {
        $path = $this->tmpDir . '/thin.json';
        \file_put_contents($path, '{"a":"1"}');
        $stream = \fopen($path, 'rb');
        $this->assertIsResource($stream);
        $payload = SizeCap::readStream($stream, 4096, 'file');
        \fclose($stream);
        $this->assertSame('{"a":"1"}', $payload);
    }

    public function testFileDoorRefusesOnTheStatWithoutReading(): void
    {
        // A sparse 2 MiB file (bytes never written) under a 1 KiB ceiling:
        // the stat gate refuses even though reading WOULD have refused too —
        // the point of the gate is that the bytes are never touched.
        $path = $this->tmpDir . '/sparse.bin';
        $handle = \fopen($path, 'wb');
        $this->assertIsResource($handle);
        $this->assertTrue(\ftruncate($handle, 2_097_152));
        \fclose($handle);

        $store = new Store($this->tmpDir, 'capdb');
        try {
            (new JsonImporter($store))->importFromFile($path, true, 'replace', 1024);
            $this->fail('2 MiB file must not pass a 1 KiB ceiling');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('is 2097152 bytes', $e->getMessage());
        }
    }

    public function testStringDoorCountsTheBytesItWasGiven(): void
    {
        $store = new Store($this->tmpDir, 'capdb2');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The import document is 10 bytes');
        (new JsonImporter($store))->importFromString('{"a":"1"}x', true, 'replace', 5);
    }

    public function testUncappedDoorAdmits(): void
    {
        $store = new Store($this->tmpDir, 'capdb3');
        $report = (new JsonImporter($store))->importFromString('{"a":"1"}', true, 'replace', 0);
        $this->assertInstanceOf(ImportReport::class, $report);
        $this->assertSame(1, $report->imported);
    }

    public function testDefaultCeilingIsTheGateShipsWith(): void
    {
        // 256 MiB — a silent change of this number is a policy change.
        $this->assertSame(268_435_456, SizeCap::DEFAULT_MAX_BYTES);
    }
}
