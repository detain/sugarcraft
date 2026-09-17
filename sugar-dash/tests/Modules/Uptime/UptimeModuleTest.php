<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Modules\Uptime;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Module\ProcAvailability;
use SugarCraft\Dash\Modules\Uptime\TickMsg;
use SugarCraft\Dash\Modules\Uptime\UptimeModule;

/**
 * Tests for UptimeModule — incl. the E731 COMP-2 option (b) sentinel render.
 */
final class UptimeModuleTest extends TestCase
{
    protected function setUp(): void
    {
        ProcAvailability::resetForTesting();
    }

    protected function tearDown(): void
    {
        ProcAvailability::resetForTesting();
    }

    public function testFreshModuleKeepsThePreFirstTickPlaceholder(): void
    {
        // 'N/A' means "not yet sampled" on EVERY platform — COMP-2 (b)
        // deliberately left the property default alone, so the first-render
        // bytes stay identical everywhere (Linux CI included).
        $module = new UptimeModule();
        $this->assertSame('N/A', $module->view());
    }

    public function testViewRendersSentinelWhenProcAbsent(): void
    {
        // Memo injection is the sanctioned seam: the /proc/uptime path is
        // hardcoded, so is_readable() is unfakeable from a test (design note).
        ProcAvailability::markForTesting('/proc/uptime', false);

        $module = new UptimeModule();
        [$next] = $module->update(new TickMsg());

        // Degraded render moved from 'N/A' to the shared visible sentinel.
        $this->assertSame('n/a', $next->view());
    }

    public function testViewRendersFormattedUptimeOnProcHosts(): void
    {
        if (!is_readable('/proc/uptime')) {
            $this->markTestSkipped('readable-branch pin needs a /proc host (Linux)');
        }

        $module = new UptimeModule();
        [$next] = $module->update(new TickMsg());

        // Real formatting preserved byte-for-byte (Xd Yh Zm / Yh Zm / Zm).
        $this->assertMatchesRegularExpression(
            '/^(\d+d \d+h \d+m|\d+h \d+m|\d+m)$/',
            $next->view()
        );
    }
}
