<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Files\Support\TarReader;
use SugarCraft\Files\Tests\Support\TarBuilder;

/** CF-33: the forward-only tar reader - name resolution, metering, corruption. */
final class TarReaderTest extends TestCase
{
    /** @return resource */
    private function stream(string $bytes)
    {
        $fh = fopen('php://memory', 'w+b');
        fwrite($fh, $bytes);
        rewind($fh);
        return $fh;
    }

    /** @return list<array{name:string,size:int,mtime:int,type:string}> */
    private function all(string $bytes, bool $seekable = true): array
    {
        $r = new TarReader($this->stream($bytes), $seekable);
        $out = [];
        while (($rec = $r->next()) !== null) {
            $out[] = $rec;
        }
        return $out;
    }

    public function testRecordsTypesSizesAndMtimes(): void
    {
        $recs = $this->all(TarBuilder::build([
            ['name' => 'a.txt', 'body' => str_repeat('x', 700), 'mtime' => 123],
            ['name' => 'd/', 'type' => '5'],
        ]));
        $this->assertSame(['a.txt', 700, 123, '0'], [$recs[0]['name'], $recs[0]['size'], $recs[0]['mtime'], $recs[0]['type']]);
        $this->assertSame('5', $recs[1]['type']);
    }

    public function testBodyReadAndDiscardStayAligned(): void
    {
        $r = new TarReader($this->stream(TarBuilder::build([
            ['name' => 'one', 'body' => 'AAAAAAAAAA'],
            ['name' => 'two', 'body' => 'BB'],
        ])), true);
        $this->assertSame('one', $r->next()['name']);
        $this->assertSame('AAAA', $r->read(4));
        $this->assertTrue($r->hasBody());
        $this->assertSame('two', $r->next()['name'], 'unread body is skipped to the next header');
        $this->assertSame('BB', $r->read(100));
        $this->assertFalse($r->hasBody());
    }

    public function testNonSeekableStreamsAreMetered(): void
    {
        $bytes = TarBuilder::build([['name' => 'big', 'body' => str_repeat('z', 5000)]]);
        $r = new TarReader($this->stream($bytes), false, 2000);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('scan limit');
        $r->next();
        $r->next();
    }

    public function testGnuLongNamePaxPathAndUstarPrefix(): void
    {
        $long = str_repeat('d/', 80) . 'file.txt';
        $recs = $this->all(TarBuilder::build([
            ['name' => $long, 'longname' => true, 'body' => 'a'],
            ['name' => 'pax/' . str_repeat('p', 150), 'pax' => true, 'body' => 'b'],
            ['name' => 'leaf.txt', 'prefix' => 'pre/fix', 'body' => 'c'],
        ]));
        $this->assertSame($long, $recs[0]['name']);
        $this->assertSame('pax/' . str_repeat('p', 150), $recs[1]['name']);
        $this->assertSame('pre/fix/leaf.txt', $recs[2]['name']);
    }

    public function testChecksumMismatchAndTruncationThrow(): void
    {
        $good = TarBuilder::build([['name' => 'a', 'body' => str_repeat('x', 600)]]);
        $bad = $good;
        $bad[0] = 'Z';
        try {
            $this->all($bad);
            $this->fail('checksum must be verified');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('checksum', $e->getMessage());
        }
        $this->expectException(\RuntimeException::class);
        $this->all(substr($good, 0, 700));
    }

    public function testEmptyAndEndMarkerOnlyArchivesYieldNothing(): void
    {
        $this->assertSame([], $this->all(''));
        $this->assertSame([], $this->all(str_repeat("\0", 1024)));
    }
}
