<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Support\LineIo;

/**
 * VCR-S26 (finding 081) — the streaming line reader must reproduce the
 * exact line shapes the old preg_split whole-buffer produced: numbering
 * counts every physical line, CRLF is stripped as one boundary, and only
 * an unterminated final line carries the torn flag.
 */
final class LineIoTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = (string) realpath(sys_get_temp_dir()) . '/vrclineio-' . bin2hex(random_bytes(4));
        if (!mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            $this->fail("Failed to create temp dir: {$this->dir}");
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink((string) $f);
        }
        @rmdir($this->dir);
    }

    public function testPlainLinesNumberingAndTornFlag(): void
    {
        $path = $this->write("alpha\n\nbravo");
        $tuples = iterator_to_array(LineIo::lines($path, 'nope'));

        // Blank line keeps its number (2), only the unterminated last is torn.
        $this->assertSame([[1, 'alpha', false], [2, '', false], [3, 'bravo', true]], $tuples);
    }

    public function testNewlineTerminatedFileHasNoTornLine(): void
    {
        $path = $this->write("alpha\nbravo\n");
        $tuples = iterator_to_array(LineIo::lines($path, 'nope'));

        $this->assertSame([[1, 'alpha', false], [2, 'bravo', false]], $tuples);
    }

    public function testCrlfIsOneBoundary(): void
    {
        $path = $this->write("a\r\nb\r\n");
        $tuples = iterator_to_array(LineIo::lines($path, 'nope'));

        $this->assertSame([[1, 'a', false], [2, 'b', false]], $tuples);
    }

    public function testEmptyFileYieldsNothing(): void
    {
        $path = $this->write('');
        $this->assertSame([], iterator_to_array(LineIo::lines($path, 'nope')));
    }

    public function testOpenFailureNamesTheCallersMessage(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot read cassette from /nope/missing.cas');
        iterator_to_array(LineIo::lines('/nope/missing.cas', 'cannot read cassette from /nope/missing.cas'));
    }

    public function testGzLinesReassembleLinesStraddlingChunkEdges(): void
    {
        // A 40 KiB single line spans five 8 KiB gzread chunks; a second
        // line follows terminated with a newline.
        $long = str_repeat('L', 40 * 1024);
        $body = $long . "\ntail\n";
        $path = $this->dir . '/stream.gz';
        $gz = gzopen($path, 'wb6');
        $this->assertNotFalse($gz);
        gzwrite($gz, $body);
        gzclose($gz);

        $tuples = iterator_to_array(LineIo::gzLines($path, 'nope', 1024 * 1024));
        $this->assertSame([[1, $long, false], [2, 'tail', false]], $tuples);
    }

    public function testGzUnterminatedTailIsTorn(): void
    {
        $path = $this->dir . '/torn.gz';
        $gz = gzopen($path, 'wb6');
        $this->assertNotFalse($gz);
        gzwrite($gz, "first\nsecond");
        gzclose($gz);

        $tuples = iterator_to_array(LineIo::gzLines($path, 'nope', 1024 * 1024));
        $this->assertSame([[1, 'first', false], [2, 'second', true]], $tuples);
    }

    public function testGzCeilingDiesWithTheNamedError(): void
    {
        $path = $this->dir . '/big.gz';
        $gz = gzopen($path, 'wb6');
        $this->assertNotFalse($gz);
        gzwrite($gz, str_repeat('B', 100 * 1024));
        gzclose($gz);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('expands beyond 16384 byte cap');
        iterator_to_array(LineIo::gzLines($path, 'nope', 16384));
    }

    private function write(string $contents): string
    {
        $path = $this->dir . '/body-' . bin2hex(random_bytes(4));
        file_put_contents($path, $contents);
        return $path;
    }
}
