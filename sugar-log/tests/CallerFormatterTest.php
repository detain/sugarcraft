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

        // Must not point to any file inside sugar-log/src
        $this->assertStringNotContainsString('/sugar-log/src/', $caller);
    }

    /**
     * Accessor for the private frame walk — bound into class scope so the
     * null path and the cap boundary are testable without fabricating stack
     * depth inside the package (SL-13).
     */
    private static function locator(): \Closure
    {
        return \Closure::bind(
            static fn (array $traces, string $selfDir): ?string => CallerFormatter::locate($traces, $selfDir),
            null,
            CallerFormatter::class,
        );
    }

    public function testLocateReturnsNullWhenEveryFrameIsInternal(): void
    {
        $dir = '/opt/app/sugar-log/src';
        $traces = [
            ['file' => $dir . '/Log.php', 'line' => 240],
            ['file' => $dir . '/Formatter/TextFormatter.php', 'line' => 88],
        ];

        $this->assertNull((self::locator())($traces, $dir));
    }

    public function testLocateStillFindsExternalCallerJustInsideTheFrameCap(): void
    {
        $dir = '/opt/app/sugar-log/src';
        $traces = [];
        for ($i = 0; $i < 19; ++$i) {
            $traces[] = ['file' => "{$dir}/Frame{$i}.php", 'line' => $i];
        }
        $traces[] = ['file' => '/var/app/main.php', 'line' => 77];

        $this->assertSame('main.php:77', (self::locator())($traces, $dir));
    }

    public function testLocateGoesSilentPastTheFrameCap(): void
    {
        $dir = '/opt/app/sugar-log/src';
        $traces = [];
        for ($i = 0; $i < 20; ++$i) {
            $traces[] = ['file' => "{$dir}/Frame{$i}.php", 'line' => $i];
        }
        // Frame 21 is the real caller — but the bounded walk never sees it.
        $traces[] = ['file' => '/var/app/main.php', 'line' => 77];

        $this->assertNull((self::locator())($traces, $dir));
    }
}
