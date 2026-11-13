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
            static fn (array $traces, string $selfDir, bool $caseFoldPaths = false): ?string => CallerFormatter::locate($traces, $selfDir, $caseFoldPaths),
            null,
            CallerFormatter::class,
        );
    }

    /**
     * A sibling directory whose name merely starts with our own text is an
     * external caller; the old strpos() prefix check false-skipped it, so
     * real callers in e.g. sugar-log/src-vendor/ reported no caller (SL-29).
     */
    public function testLocateKeepsSiblingDirectoryPrefixFramesAsExternal(): void
    {
        $dir = '/opt/app/sugar-log/src';
        $traces = [
            ['file' => $dir . '/Log.php', 'line' => 240],
            ['file' => '/opt/app/sugar-log/src-vendor/Legacy.php', 'line' => 9],
        ];

        $this->assertSame('Legacy.php:9', (self::locator())($traces, $dir));
    }

    /**
     * Windows arm, pinned on Linux through the case-fold seam: frames arrive
     * in the case the drive path was opened with, so a differently-cased
     * internal frame must still be skipped and the external frame reported
     * with its own bytes (SL-29).
     */
    public function testLocateCaseFoldsBothSidesWhenAsked(): void
    {
        $dir = '/opt/app/sugar-log/src';
        $traces = [
            ['file' => '/OPT/APP/SUGAR-LOG/SRC/Log.php', 'line' => 240],
            ['file' => '/var/app/main.php', 'line' => 77],
        ];
        $folded = (self::locator())($traces, $dir, true);

        $this->assertSame('main.php:77', $folded);
        // The fold is opt-in: byte-exact mode keeps the differently-cased
        // internal frame external (discriminates the fold arm from the plain arm).
        $this->assertNotSame($folded, (self::locator())($traces, $dir, false));
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
