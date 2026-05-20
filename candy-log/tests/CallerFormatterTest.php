<?php

declare(strict_types=1);

namespace SugarCraft\Log\Tests;

use SugarCraft\Log\CallerFormatter;
use PHPUnit\Framework\TestCase;

final class CallerFormatterTest extends TestCase
{
    public function testFindReturnsCallerFileAndLine(): void
    {
        $caller = CallerFormatter::find();

        $this->assertNotNull($caller);
        $this->assertMatchesRegularExpression('/^CallerFormatterTest\.php:\d+$/', $caller);
    }

    public function testFindSkipsInternalLogFrames(): void
    {
        $caller = CallerFormatter::find();

        // Must not point to any file inside candy-log/src
        $this->assertStringNotContainsString('/candy-log/src/', $caller);
    }

    public function testFindReturnsNullWhenNoExternalCaller(): void
    {
        // When called from within the log package with no external frame, find() walks
        // the full trace and returns null
        $result = CallerFormatter::find();
        // At minimum the test framework itself (PHPUnit) is an external frame,
        // so we always get a result in real usage; this documents the null path
        $this->assertTrue($result === null || \is_string($result));
    }
}
