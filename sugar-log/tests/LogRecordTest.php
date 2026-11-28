<?php

declare(strict_types=1);

namespace SugarCraft\Log\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Log\Level;
use SugarCraft\Log\LogRecord;

final class LogRecordTest extends TestCase
{
    public function testFieldAccessorsTellAbsentFromNull(): void
    {
        $record = new LogRecord('raw', Level::Info, 'msg', '2026-11-27', 'svc', 'a.php:1', ['k' => 'v', 'n' => null]);

        $this->assertSame('raw', $record->line);
        $this->assertSame(Level::Info, $record->level);
        $this->assertSame('msg', $record->message);
        $this->assertSame('2026-11-27', $record->time);
        $this->assertSame('svc', $record->prefix);
        $this->assertSame('a.php:1', $record->caller);
        $this->assertTrue($record->hasField('k'));
        $this->assertSame('v', $record->field('k'));
        $this->assertTrue($record->hasField('n'));
        $this->assertNull($record->field('n'));
        $this->assertFalse($record->hasField('missing'));
        $this->assertNull($record->field('missing'));
    }

    public function testEnvelopeDefaultsToNullAndNoFields(): void
    {
        $record = new LogRecord('x', null, 'x');

        $this->assertNull($record->time);
        $this->assertNull($record->prefix);
        $this->assertNull($record->caller);
        $this->assertSame([], $record->fields);
    }
}
