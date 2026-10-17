<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use SugarCraft\Core\ProgressReporter;
use SugarCraft\Core\Progress\CallbackProgressReporter;
use SugarCraft\Core\Progress\SilentProgressReporter;
use PHPUnit\Framework\TestCase;

final class ProgressReporterTest extends TestCase
{
    public function testCallbackProgressReporterCallsClosure(): void
    {
        $calls = [];

        $reporter = CallbackProgressReporter::new(
            function (int $current, int $total, ?string $label) use (&$calls): void {
                $calls[] = ['current' => $current, 'total' => $total, 'label' => $label];
            }
        );

        $reporter->report(5, 10, 'Test operation');
        $reporter->report(10, 10, 'Complete');

        $this->assertCount(2, $calls);
        $this->assertSame(['current' => 5, 'total' => 10, 'label' => 'Test operation'], $calls[0]);
        $this->assertSame(['current' => 10, 'total' => 10, 'label' => 'Complete'], $calls[1]);
    }

    public function testSilentProgressReporterDoesNothing(): void
    {
        $reporter = new SilentProgressReporter();

        // Should not throw
        $reporter->report(0, 100);
        $reporter->report(50, 100, 'Middle');
        $reporter->report(100, 100, 'Done');

        $this->assertTrue(true); // If we get here, no exception was thrown
    }

    public function testProgressReporterInterfaceContract(): void
    {
        $callbackReporter = CallbackProgressReporter::new(
            static function (int $current, int $total, ?string $label): void {
                // No-op
            }
        );
        $silentReporter = new SilentProgressReporter();

        $this->assertInstanceOf(ProgressReporter::class, $callbackReporter);
        $this->assertInstanceOf(ProgressReporter::class, $silentReporter);
    }
}
