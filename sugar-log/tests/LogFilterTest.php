<?php

declare(strict_types=1);

namespace SugarCraft\Log\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Log\Level;
use SugarCraft\Log\LogFilter;
use SugarCraft\Log\LogRecord;

final class LogFilterTest extends TestCase
{
    /**
     * @param array<string, mixed> $fields
     */
    private static function record(?Level $level, string $message, array $fields = []): LogRecord
    {
        return new LogRecord($message, $level, $message, null, null, null, $fields);
    }

    /**
     * @param list<LogRecord> $records
     * @return list<string>
     */
    private static function messages(array $records): array
    {
        return \array_map(static fn (LogRecord $r): string => $r->message, $records);
    }

    /**
     * @return list<LogRecord>
     */
    private static function sample(): array
    {
        return [
            self::record(Level::Debug, 'd', ['svc' => 'api']),
            self::record(Level::Info, 'i', ['svc' => 'db', 'n' => 42]),
            self::record(Level::Warn, 'w', ['svc' => 'api', 'n' => '42']),
            self::record(Level::Error, 'e', ['svc' => 'api', 'ok' => true]),
            self::record(null, 'unknown', ['svc' => 'api']),
            self::record(Level::Fatal, 'f'),
        ];
    }

    public function testANewFilterKeepsEverythingInOrder(): void
    {
        $filter = LogFilter::new();

        $this->assertNull($filter->minLevel);
        $this->assertSame([], $filter->fields);
        $this->assertSame(['d', 'i', 'w', 'e', 'unknown', 'f'], self::messages($filter->apply(self::sample())));
    }

    public function testMinLevelKeepsThatLevelAndAboveAndDropsUnknownLevels(): void
    {
        $filter = LogFilter::new()->withMinLevel(Level::Warn);

        $this->assertSame(['w', 'e', 'f'], self::messages($filter->apply(self::sample())));
        $this->assertFalse($filter->matches(self::record(null, 'x')));
        $this->assertSame(
            ['d', 'i', 'w', 'e', 'unknown', 'f'],
            self::messages($filter->withMinLevel(null)->apply(self::sample())),
            'null drops the level condition',
        );
    }

    public function testFieldKeepsRecordsCarryingThatValue(): void
    {
        $filter = LogFilter::new()->withField('svc', 'api');

        $this->assertSame(['d', 'w', 'e', 'unknown'], self::messages($filter->apply(self::sample())));
    }

    public function testFieldComparesScalarsByTheirWrittenForm(): void
    {
        $this->assertSame(['i', 'w'], self::messages(LogFilter::new()->withField('n', 42)->apply(self::sample())));
        $this->assertSame(['i', 'w'], self::messages(LogFilter::new()->withField('n', '42')->apply(self::sample())));
        $this->assertSame(['e'], self::messages(LogFilter::new()->withField('ok', 'true')->apply(self::sample())));
        $this->assertTrue(LogFilter::new()->withField('v', null)->matches(self::record(Level::Info, 'x', ['v' => 'null'])));
        $this->assertFalse(LogFilter::new()->withField('v', null)->matches(self::record(Level::Info, 'x')), 'absent is not null');
        $this->assertTrue(LogFilter::new()->withField('a', [1, 2])->matches(self::record(Level::Info, 'x', ['a' => [1, 2]])));
        $this->assertFalse(LogFilter::new()->withField('a', [1, 2])->matches(self::record(Level::Info, 'x', ['a' => '[1,2]'])));
    }

    public function testConditionsCombineAndARepeatedKeyReplacesItsCondition(): void
    {
        $filter = LogFilter::new()->withMinLevel(Level::Info)->withField('svc', 'db')->withField('svc', 'api');

        $this->assertSame(['svc' => 'api'], $filter->fields);
        $this->assertSame(['w', 'e'], self::messages($filter->apply(self::sample())));
        $this->assertSame(['w'], self::messages($filter->withField('n', 42)->apply(self::sample())));
    }

    public function testApplyAcceptsAnyIterable(): void
    {
        $generator = (static function (): \Generator {
            yield from self::sample();
        })();

        $this->assertSame(['e', 'f'], self::messages(LogFilter::new()->withMinLevel(Level::Error)->apply($generator)));
    }

    public function testEveryWitherReturnsANewFilterAndLeavesTheOriginal(): void
    {
        $base = LogFilter::new();
        $leveled = $base->withMinLevel(Level::Error);
        $fielded = $base->withField('svc', 'api');
        $cleared = $leveled->withMinLevel(null);

        $this->assertNotSame($base, $leveled);
        $this->assertNotSame($base, $fielded);
        $this->assertNotSame($leveled, $cleared);
        $this->assertNull($base->minLevel);
        $this->assertSame([], $base->fields);
        $this->assertSame(Level::Error, $leveled->minLevel);
        $this->assertSame([], $leveled->fields);
        $this->assertNull($fielded->minLevel);
        $this->assertSame(['svc' => 'api'], $fielded->fields);
        $this->assertNull($cleared->minLevel);
        $this->assertSame(Level::Error, $leveled->minLevel);
    }
}
