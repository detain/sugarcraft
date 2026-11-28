<?php

declare(strict_types=1);

namespace SugarCraft\Log\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Log\Formatter\JsonFormatter;
use SugarCraft\Log\Formatter\LogfmtFormatter;
use SugarCraft\Log\Formatter\TextFormatter;
use SugarCraft\Log\Level;
use SugarCraft\Log\LogFilter;
use SugarCraft\Log\LogFormat;
use SugarCraft\Log\Logger;
use SugarCraft\Log\LogReader;
use SugarCraft\Log\LogRecord;
use SugarCraft\Log\PartsOrder;
use SugarCraft\Log\Tests\Support\CountingReadStream;

final class LogReaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/sugar-log-reader-' . \getmypid() . '-' . \spl_object_id($this);
        \mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        CountingReadStream::unregister();
        foreach (\scandir($this->dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $this->dir . '/' . $entry;
            \is_dir($path) ? @\rmdir($path) : @\unlink($path);
        }
        @\rmdir($this->dir);
    }

    private function file(string $name, string $bytes): string
    {
        $path = $this->dir . '/' . $name;
        \file_put_contents($path, $bytes);

        return $path;
    }

    /**
     * @param list<LogRecord> $records
     * @return list<string>
     */
    private static function lines(array $records): array
    {
        return \array_map(static fn (LogRecord $r): string => $r->line, $records);
    }

    public function testTailWithFewerLinesThanTheFileHasReturnsTheLastOnesOldestFirst(): void
    {
        $path = $this->file('five.log', "one\ntwo\nthree\nfour\nfive\n");

        $this->assertSame(['four', 'five'], self::lines(LogReader::new($path)->tail(2)));
    }

    public function testTailWithMoreLinesThanTheFileHasReturnsEveryLine(): void
    {
        $path = $this->file('three.log', "one\ntwo\nthree\n");

        $this->assertSame(['one', 'two', 'three'], self::lines(LogReader::new($path)->tail(10)));
        $this->assertSame(['one', 'two', 'three'], self::lines(LogReader::new($path)->tail(3)));
    }

    public function testTailOfAnEmptyFileIsEmpty(): void
    {
        $path = $this->file('empty.log', '');

        $this->assertSame([], LogReader::new($path)->tail(5));
        $this->assertSame([], LogReader::new($path, LogFormat::Json)->tail(5));
    }

    public function testTailOfAFileNobodyHasWrittenYetIsEmpty(): void
    {
        $this->assertSame([], LogReader::new($this->dir . '/missing.log')->tail(5));
    }

    public function testTailZeroIsEmptyAndNegativeIsRefused(): void
    {
        $path = $this->file('one.log', "one\n");
        $this->assertSame([], LogReader::new($path)->tail(0));

        $this->expectException(\InvalidArgumentException::class);
        LogReader::new($path)->tail(-1);
    }

    public function testTailOfADirectoryIsRefused(): void
    {
        \mkdir($this->dir . '/sub');

        $this->expectException(\RuntimeException::class);
        LogReader::new($this->dir . '/sub')->tail(1);
    }

    public function testBlankLinesDoNotCountAndCarriageReturnsAndAnUnterminatedLastLineAreHandled(): void
    {
        $path = $this->file('mixed.log', "one\r\n\n  \ntwo\r\n\nthree-in-flight");

        $this->assertSame(['two', 'three-in-flight'], self::lines(LogReader::new($path)->tail(2)));
        $this->assertSame(['one', 'two', 'three-in-flight'], self::lines(LogReader::new($path)->tail(9)));
    }

    public function testTailJoinsLinesAcrossChunkBoundaries(): void
    {
        $all = [];
        for ($i = 0; $i < 2000; ++$i) {
            $all[] = \sprintf('line %04d %s', $i, \str_repeat('x', $i % 37));
        }
        $long = \str_repeat('L', LogReader::CHUNK_BYTES * 2 + 5);
        $all[] = $long;
        $path = $this->file('big.log', \implode("\n", $all) . "\n");

        $this->assertSame(\array_slice($all, -1500), self::lines(LogReader::new($path)->tail(1500)));
        $this->assertSame([$long], self::lines(LogReader::new($path)->tail(1)));
        $this->assertSame($all, self::lines(LogReader::new($path)->tail(5000)));
    }

    public function testTailReadsTheEndOfTheFileNotTheWholeOfIt(): void
    {
        $line = \str_repeat('y', 99) . "\n";
        $path = $this->file('huge.log', \str_repeat($line, 20000) . "last\n"); // ~2 MB
        CountingReadStream::register();

        $records = LogReader::new(CountingReadStream::PROTOCOL . '://' . $path)->tail(3);

        $this->assertSame([\rtrim($line), \rtrim($line), 'last'], self::lines($records));
        $this->assertLessThan(4 * LogReader::CHUNK_BYTES, CountingReadStream::$bytesRead);
    }

    public function testJsonRecordsCarryTheEnvelopeAndTheContext(): void
    {
        $path = $this->dir . '/app.json';
        $logger = Logger::new(formatter: new JsonFormatter(), level: Level::Debug, stream: $path, prefix: 'svc');
        $logger->debug('booting', ['n' => 1]);
        $logger->with(['user' => 'chef'])->warn('slow', ['ms' => 950, 'level' => 'forged']);

        $records = LogReader::new($path, LogFormat::Json)->tail(10);

        $this->assertCount(2, $records);
        [$first, $second] = $records;
        $this->assertSame(Level::Debug, $first->level);
        $this->assertSame('booting', $first->message);
        $this->assertSame('svc', $first->prefix);
        $this->assertNotNull($first->time);
        $this->assertSame(['n' => 1], $first->fields);

        $this->assertSame(Level::Warn, $second->level);
        $this->assertSame('slow', $second->message);
        $this->assertSame(['user' => 'chef', 'ms' => 950, 'ctx_level' => 'forged'], $second->fields);
    }

    public function testLogfmtRecordsUnescapeQuotedValues(): void
    {
        $path = $this->dir . '/app.logfmt';
        $logger = Logger::new(formatter: new LogfmtFormatter(false), level: Level::Debug, stream: $path);
        $logger->error("two\nlines \"quoted\"", ['path' => 'C:\\tmp dir', 'n' => 42, 'ok' => true]);

        [$record] = LogReader::new($path, LogFormat::Logfmt)->tail(1);

        $this->assertSame(Level::Error, $record->level);
        $this->assertSame("two\nlines \"quoted\"", $record->message);
        $this->assertNull($record->time);
        $this->assertSame(['path' => 'C:\\tmp dir', 'n' => '42', 'ok' => 'true'], $record->fields);
    }

    public function testTextRecordsKeepTheLineAndLiftTheLevel(): void
    {
        $path = $this->dir . '/app.txt';
        $logger = Logger::new(formatter: new TextFormatter(useColors: false), level: Level::Debug, stream: $path);
        $logger->warn('disk info error words', ['free' => '2%']);
        \file_put_contents($path, "a plain line about info\n", \FILE_APPEND);

        [$warn, $plain] = LogReader::new($path, LogFormat::Text)->tail(2);

        $this->assertSame(Level::Warn, $warn->level);
        $this->assertStringContainsString('disk info error words', $warn->message);
        $this->assertSame($warn->line, $warn->message);
        $this->assertSame([], $warn->fields);
        $this->assertNull($plain->level, 'a lower-case message word is not a level');
    }

    public function testColoredTextRecordsStillLiftTheLevel(): void
    {
        $before = \getenv('CLICOLOR_FORCE');
        \putenv('CLICOLOR_FORCE=1');
        try {
            $path = $this->dir . '/colored.txt';
            $logger = Logger::new(formatter: new TextFormatter(useColors: true), level: Level::Debug, stream: $path);
            $logger->info('warming up');
            $logger->error('burnt', ['tray' => 2]);
        } finally {
            $before === false ? \putenv('CLICOLOR_FORCE') : \putenv("CLICOLOR_FORCE={$before}");
        }
        $this->assertStringContainsString("\x1b[", (string) \file_get_contents($path), 'the fixture must be colored');

        $records = LogReader::new($path, LogFormat::Text)->tail(50);

        $this->assertSame([Level::Info, Level::Error], \array_map(static fn (LogRecord $r): ?Level => $r->level, $records));
        $this->assertStringContainsString("\x1b[", $records[1]->line, 'the record keeps the line as written');
        $this->assertCount(1, LogFilter::new()->withMinLevel(Level::Warn)->apply($records));
    }

    public function testTextLevelIsLookedForWhereTheWritersPartsOrderPutsIt(): void
    {
        $messageFirst = PartsOrder::messageFirst();
        $path = $this->dir . '/message-first.txt';
        $logger = Logger::new(
            formatter: new TextFormatter(useColors: false),
            level: Level::Debug,
            stream: $path,
            partsOrder: $messageFirst,
        );
        $logger->warn('Upload ERR retry ERROR', ['n' => 1]);
        $this->assertStringStartsWith('Upload ERR retry ERROR WRN ', (string) \file_get_contents($path));

        [$record] = LogReader::new($path, LogFormat::Text, $messageFirst)->tail(1);
        $this->assertSame(Level::Warn, $record->level, 'a label word in the message precedes the level');
        $this->assertSame([], LogFilter::new()->withMinLevel(Level::Error)->apply([$record]));

        $default = $this->dir . '/default.txt';
        Logger::new(formatter: new TextFormatter(useColors: false), level: Level::Debug, stream: $default)
            ->info('FATAL ERROR FTL ERR in the message');
        [$info] = LogReader::new($default, LogFormat::Text)->tail(1);
        $this->assertSame(Level::Info, $info->level, 'the level written first wins; full labels are message words');

        $noLevel = new PartsOrder([PartsOrder::PART_TIMESTAMP, PartsOrder::PART_MESSAGE]);
        $bare = $this->dir . '/no-level.txt';
        Logger::new(formatter: new TextFormatter(useColors: false), level: Level::Debug, stream: $bare, partsOrder: $noLevel)
            ->error('disk ERR full');
        $reader = LogReader::new($bare, LogFormat::Text);
        $this->assertSame(Level::Error, $reader->tail(1)[0]->level, 'read with the wrong order, the message word is taken');
        $this->assertNull($reader->withPartsOrder($noLevel)->tail(1)[0]->level, 'no level part, no level');
    }

    public function testTailOfOneVeryLongLineCostsItsLengthNotItsSquare(): void
    {
        // About 1,000 chunks: re-joining the whole carry on every step took
        // seconds here; touching each byte a bounded number of times takes
        // tens of milliseconds.
        $long = \str_repeat('z', 8_000_000);
        $path = $this->file('long-line.log', "first\n{$long}\n");

        $started = \hrtime(true);
        $records = LogReader::new($path)->tail(1);
        $seconds = (\hrtime(true) - $started) / 1e9;

        $this->assertCount(1, $records);
        $this->assertSame($long, $records[0]->line);
        $this->assertLessThan(1.0, $seconds, 'tail(1) of one 8 MB line must not be quadratic in its length');
    }

    public function testLinesTheFormatCannotParseComeBackRaw(): void
    {
        $json = $this->file('torn.json', "{\"level\":\"INFO\",\"msg\":\"ok\"}\n{\"level\":\"IN\n[1,2]\n");
        $records = LogReader::new($json, LogFormat::Json)->tail(5);
        $this->assertSame([Level::Info, null, null], \array_map(static fn (LogRecord $r): ?Level => $r->level, $records));
        $this->assertSame('{"level":"IN', $records[1]->message);

        $logfmt = $this->file('foreign.logfmt', "not logfmt at all\nlevel=INFO msg=\"unterminated\n");
        foreach (LogReader::new($logfmt, LogFormat::Logfmt)->tail(5) as $record) {
            $this->assertNull($record->level);
            $this->assertSame($record->line, $record->message);
            $this->assertSame([], $record->fields);
        }
    }

    public function testNewDefaultsToTextAndWithersReturnNewReaders(): void
    {
        $reader = LogReader::new('/a.log');
        $this->assertSame('/a.log', $reader->path);
        $this->assertSame(LogFormat::Text, $reader->format);

        $moved = $reader->withPath('/b.log');
        $json = $reader->withFormat(LogFormat::from('json'));

        $this->assertNotSame($reader, $moved);
        $this->assertNotSame($reader, $json);
        $this->assertSame('/a.log', $reader->path, 'withPath must not touch the original');
        $this->assertSame(LogFormat::Text, $reader->format, 'withFormat must not touch the original');
        $this->assertSame('/b.log', $moved->path);
        $this->assertSame(LogFormat::Text, $moved->format);
        $this->assertSame(LogFormat::Json, $json->format);
        $this->assertSame('/a.log', $json->path);

        $this->assertEquals(PartsOrder::new(), $reader->partsOrder);
        $syslog = PartsOrder::syslog();
        $reordered = $reader->withPartsOrder($syslog);
        $this->assertNotSame($reader, $reordered);
        $this->assertSame($syslog, $reordered->partsOrder);
        $this->assertEquals(PartsOrder::new(), $reader->partsOrder, 'withPartsOrder must not touch the original');
        $this->assertSame($syslog, LogReader::new('/c.log', LogFormat::Text, $syslog)->partsOrder);
    }
}
