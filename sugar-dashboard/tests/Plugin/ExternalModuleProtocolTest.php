<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Plugin;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Plugin\ExternalModule;

/**
 * Trust-boundary tests driving ExternalModule against the hostile-plugin
 * fixture: every misbehaviour a third-party binary can produce must end in
 * a bounded, visible DISABLE for that plugin — never an exception in the
 * host loop, never an unbounded wait, never a silent blank panel, and never
 * a leaked environment or forged zone sentinel in chrome.
 */
final class ExternalModuleProtocolTest extends TestCase
{
    private string $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = __DIR__ . '/../fixtures/hostile-plugin.php';

        if (!is_executable($this->fixture)) {
            $this->markTestSkipped('hostile-plugin.php fixture is not executable');
        }
    }

    private function module(string $mode, string $name = 'hostile'): ExternalModule
    {
        return new ExternalModule($name, $this->fixture, ['--mode=' . $mode]);
    }

    public function testGarbageWireLineDisablesThePluginWithoutThrowing(): void
    {
        $module = $this->module('garbage');
        $module->init();

        // update() must survive the unparsable answer and hand back the prior state.
        $state = $module->update(['tick' => 3]);
        $this->assertSame(['tick' => 3], $state, 'malformed plugin must not corrupt host state');

        // The failure is visible in the panel, printable, and names itself.
        $view = $module->view($state, 80, 24);
        $this->assertStringContainsString('hostile disabled:', $view);
        $this->assertStringContainsString('protocol error', $view);
        $this->assertSame(0, preg_match('/[^\x20-\x7E\n]/', $view), 'refusal chrome stays printable');
    }

    public function testFloodLineTriggersTheOverflowGuard(): void
    {
        // The old fgets() cap made this guard unreachable: strlen() could
        // never reach MAX_LINE_BYTES. A plugin now gets a full cap-sized
        // chunk refused as a protocol error instead of the host eating
        // memory or spinning on the remainder.
        $module = $this->module('flood');
        $module->init();

        $started = hrtime(true);
        $state = $module->update(['x' => 1]);
        $elapsedSeconds = (hrtime(true) - $started) / 1e9;

        $this->assertSame(['x' => 1], $state);
        $this->assertLessThan(5.5, $elapsedSeconds, 'overflow refusal is immediate, not a full read timeout');

        $view = $module->view($state, 80, 24);
        $this->assertStringContainsString('exceeds maximum length', $view);
    }

    public function testPluginChildDoesNotInheritParentSecretEnvironment(): void
    {
        putenv('DASHBOARD_TEST_LEAK_CANARY=secret-value-42');

        try {
            $module = $this->module('env');
            $meta = $module->init();

            $this->assertStringNotContainsString('secret-value-42', (string) $meta['name']);
            $this->assertSame('canary-absent', $meta['name'], 'allow-listed env must drop unknown parent vars');
        } finally {
            putenv('DASHBOARD_TEST_LEAK_CANARY');
        }
    }

    public function testPluginSuppliedNameIsClippedAndSanitizedForChrome(): void
    {
        $module = $this->module('name', 'configured');
        $meta = $module->init();

        $name = (string) $meta['name'];
        $this->assertStringNotContainsString("\x1b", $name, 'ESC stripped from plugin-advertised name');
        $this->assertStringNotContainsString("\xEE\x80\x80", $name, 'zone sentinels stripped from plugin name');
        $this->assertLessThanOrEqual(67, strlen($name), 'name clipped to MAX_LABEL_BYTES plus the ellipsis');
    }

    public function testDeafPluginIsGivenUpOnWithinTheReadDeadline(): void
    {
        // The child never reads stdin and never answers. The handshake
        // already fits the pipe buffer, so the read deadline is what must
        // fire: bounded (≈ READ_TIMEOUT_SECS), not the child's 60 s sleep.
        $module = $this->module('deaf', 'deaf');

        $started = hrtime(true);
        $meta = $module->init();
        $elapsedSeconds = (hrtime(true) - $started) / 1e9;

        $this->assertSame(0, $meta['interval'], 'refused init yields a disabled, zero-interval module');
        $this->assertLessThan(15.0, $elapsedSeconds, 'init gave up bounded, never waiting on the child sleep');

        $view = $module->view([], 80, 24);
        $this->assertStringContainsString('deaf', $view);
        $this->assertStringContainsString('disabled', $view);
    }

    public function testViewIsCachedUntilStateOrDimensionsActuallyChange(): void
    {
        // Every-frame synchronous round-trips used to stall input on healthy
        // but slow plugins; the counter mode makes the number of WIRE trips
        // visible: identical renders must reuse the cache, any real change
        // (new state or a resize) must pay exactly one fresh trip.
        $module = $this->module('counter');
        $module->init();

        $first = $module->view(['tick' => 1], 80, 24);
        $this->assertStringContainsString('render #1', $first);

        $again = $module->view(['tick' => 1], 80, 24);
        $this->assertSame($first, $again, 'unchanged render served from cache');

        $resized = $module->view(['tick' => 1], 100, 24);
        $this->assertStringContainsString('render #2', $resized, 'resize pays a fresh trip');

        $advanced = $module->view(['tick' => 2], 100, 24);
        $this->assertStringContainsString('render #3', $advanced, 'state change pays a fresh trip');
        $this->assertStringContainsString('tick:2', $advanced);
    }
}
