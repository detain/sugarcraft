<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tools\BuiltIn\Read;

/**
 * Audit 0.12: Read pages a file by line — `offset`/`limit`, every line numbered
 * `N: `, a continuation footer naming the next call, and a default page of 2,000
 * lines / 50 KiB (or `maxBytes`, when smaller).
 */
final class ReadPagingTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush_readpaging_' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->dir . '/*') as $file) {
            if (is_string($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->dir);
    }

    private function file(string $contents): string
    {
        $path = $this->dir . '/f.txt';
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * @param array<string, mixed> $args
     */
    private function read(string $path, array $args = [], ?Read $tool = null): string
    {
        return ($tool ?? new Read())->execute(['file_path' => $path] + $args)->content();
    }

    /**
     * @param array<string, mixed> $args
     */
    private function readError(string $path, array $args): string
    {
        $result = (new Read())->execute(['file_path' => $path] + $args);
        self::assertTrue($result->isError(), 'expected an error for ' . json_encode($args));

        return $result->content();
    }

    /** @return list<string> */
    private static function lines(int $count): array
    {
        return array_map(static fn(int $i): string => "line {$i}", range(1, $count));
    }

    public function testAWholeFileComesBackNumberedWithNoFooter(): void
    {
        $path = $this->file("alpha\n\nbeta\n");

        self::assertSame("1: alpha\n2: \n3: beta", $this->read($path));
    }

    public function testAFinalLineWithoutANewlineIsALine(): void
    {
        $path = $this->file("a\nb");

        self::assertSame("1: a\n2: b", $this->read($path));
    }

    public function testOffsetAndLimitReturnThatWindowAndNameTheNextCall(): void
    {
        $path = $this->file(implode("\n", self::lines(10)) . "\n");

        self::assertSame(
            "4: line 4\n5: line 5\n6: line 6\n\n[lines 4-6 of 10 — call Read with offset=7 to continue]",
            $this->read($path, ['offset' => 4, 'limit' => 3]),
        );
        self::assertSame(
            "2: line 2\n\n[line 2 of 10 — call Read with offset=3 to continue]",
            $this->read($path, ['offset' => 2, 'limit' => 1]),
        );
        self::assertSame("9: line 9\n10: line 10", $this->read($path, ['offset' => 9]));
    }

    public function testNumericStringArgumentsAreAccepted(): void
    {
        $path = $this->file(implode("\n", self::lines(5)));

        self::assertSame(
            "3: line 3\n\n[line 3 of 5 — call Read with offset=4 to continue]",
            $this->read($path, ['offset' => '3', 'limit' => ' 1 ']),
        );
    }

    public function testAnOffsetPastTheEndIsAnErrorNamingTheLineCount(): void
    {
        $path = $this->file(implode("\n", self::lines(5)) . "\n");

        $error = $this->readError($path, ['offset' => 6]);

        self::assertStringContainsString('offset 6 is past the end', $error);
        self::assertStringContainsString('which has 5 lines', $error);
    }

    public function testInvalidOffsetsAndLimitsAreErrors(): void
    {
        $path = $this->file("x\n");

        foreach ([['offset' => 0], ['offset' => -1], ['offset' => 'two'], ['offset' => 1.5], ['limit' => 0], ['limit' => [3]]] as $args) {
            self::assertStringContainsString('must be a positive integer', $this->readError($path, $args));
        }
    }

    public function testAnEmptyFileIsAnEmptyReadButHasNoSecondLine(): void
    {
        $path = $this->file('');

        self::assertSame('', $this->read($path));
        self::assertStringContainsString('which has 0 lines', $this->readError($path, ['offset' => 2]));
    }

    public function testTheDefaultPageIsTwoThousandLines(): void
    {
        $path = $this->file(implode("\n", self::lines(2500)) . "\n");

        $page = $this->read($path);

        self::assertStringStartsWith("1: line 1\n", $page);
        self::assertStringContainsString("\n2000: line 2000\n\n", $page);
        self::assertStringNotContainsString('2001: ', $page);
        self::assertStringEndsWith('[lines 1-2000 of 2500 — call Read with offset=2001 to continue]', $page);
    }

    public function testTheDefaultPageIsAlsoBoundedInBytes(): void
    {
        // 2,000 lines of 100 bytes would be ~200 KB; the page must stop near 50 KiB.
        $line = str_repeat('y', 99);
        $path = $this->file(str_repeat($line . "\n", 2000));

        $page = $this->read($path);
        [$body, $footer] = explode("\n\n[", $page, 2);

        self::assertLessThanOrEqual(51200, strlen($body));
        self::assertGreaterThan(51200 - 110, strlen($body), 'the byte budget is spent, not merely respected');
        self::assertMatchesRegularExpression('/^lines 1-(\d+) of 2000 — call Read with offset=(\d+) to continue\]$/', $footer);
    }

    public function testASmallerMaxBytesIsTheHardCeilingOnThePage(): void
    {
        $path = $this->file(implode("\n", self::lines(100)));

        $page = $this->read($path, [], new Read(maxBytes: 100));
        [$body] = explode("\n\n[", $page, 2);

        self::assertLessThanOrEqual(100, strlen($body));
        self::assertStringEndsWith('to continue]', $page);
    }

    public function testPagingThroughAFileReassemblesItExactly(): void
    {
        $contents = '';
        for ($i = 1; $i <= 333; $i++) {
            $contents .= str_repeat(chr(97 + $i % 26), $i % 37) . "\n";
        }
        $contents .= 'tail without newline';
        $path = $this->file($contents);
        $tool = new Read(maxBytes: 700);

        $rebuilt = [];
        $offset = 1;
        $calls = 0;
        while (true) {
            $page = $this->read($path, ['offset' => $offset, 'limit' => 50], $tool);
            $calls++;
            $parts = explode("\n\n[", $page, 2);
            foreach (explode("\n", $parts[0]) as $row) {
                self::assertSame(1, preg_match('/^(\d+): (.*)$/s', $row, $m), "unnumbered row: $row");
                self::assertSame(count($rebuilt) + 1, (int) $m[1], 'line numbers are consecutive across pages');
                $rebuilt[] = $m[2];
            }
            if (!isset($parts[1])) {
                break;
            }
            self::assertSame(1, preg_match('/offset=(\d+) to continue\]$/', $parts[1], $m));
            $offset = (int) $m[1];
            self::assertLessThan(100, $calls, 'paging must make progress');
        }

        self::assertSame($contents, implode("\n", $rebuilt));
        self::assertGreaterThan(7, $calls, 'the byte ceiling, not just the line limit, split the file');
    }

    public function testALineLongerThanAPageIsCutMarkedAndSkippedPast(): void
    {
        $long = str_repeat('z', 300) . "\u{2014}" . str_repeat('w', 300);
        $path = $this->file($long . "\nnext\n");
        $tool = new Read(maxBytes: 302);

        $first = $this->read($path, [], $tool);

        self::assertStringStartsWith('1: zzz', $first);
        self::assertStringContainsString('[line 1 truncated: ', $first);
        self::assertStringContainsString('of 603 bytes shown]', $first);
        self::assertTrue(mb_check_encoding($first, 'UTF-8'), 'a cut never splits a UTF-8 sequence');
        self::assertStringEndsWith('[line 1 of 2 — call Read with offset=2 to continue]', $first);

        self::assertSame('2: next', $this->read($path, ['offset' => 2], $tool));
    }

    public function testTheSchemaOffersOffsetAndLimitWithoutRequiringThem(): void
    {
        $schema = (new Read())->inputSchema();

        self::assertSame('integer', $schema['properties']['offset']['type']);
        self::assertSame('integer', $schema['properties']['limit']['type']);
        self::assertNotContains('offset', $schema['required']);
        self::assertNotContains('limit', $schema['required']);
    }
}
