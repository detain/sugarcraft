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

    public function testMalformedUtf8WireLineIsRefusedBoundedly(): void
    {
        // sugar-dashboard-008 reproduction, retried against the live PHP.
        // A reply line carrying RAW invalid-UTF-8 bytes is refused by the
        // JSON parser itself (PHP ≥ 8.3, the library's floor), so the
        // poisoned state never reaches the store seam — the bounded,
        // visible disable IS the cure now. Pin that the brick cannot
        // return: prior state kept, panel disabled, no throw.
        $module = $this->module('poison');
        $module->init();

        $state = $module->update(['note' => 'host']);
        $this->assertSame(['note' => 'host'], $state, 'refused bytes must not enter host state');

        $view = $module->view($state, 80, 24);
        $this->assertStringContainsString('disabled:', $view);
        $this->assertStringContainsString('protocol error', $view);
    }

    public function testLoneSurrogateEscapeReplyIsRefusedAtDecode(): void
    {
        // The finding's literal probe vector (a \ud800 escape in an
        // otherwise-ASCII line). json_decode on supported PHP rejects
        // unpaired surrogates, so the reply never yields an
        // un-re-encodable string.
        $module = $this->module('surrogate');
        $module->init();

        $state = $module->update(['note' => 'host']);
        $this->assertSame(['note' => 'host'], $state);

        $view = $module->view($state, 80, 24);
        $this->assertStringContainsString('disabled:', $view);
    }

    public function testStoreSeamSanitiserSubstitutesInvalidBytes(): void
    {
        // Whitebox pin of the belt leg: IF bytes ever arrive past the
        // parser (patch-version drift, a future ingestion path), the
        // store seam must hand back a permanently re-encodable state —
        // never the landmine that bricked every later round-trip.
        $poisoned = ['note' => "pre\xED\xA0\x80post", 'deep' => ['x' => "\xF4\x90\x80\x80"]];

        $sanitize = new \ReflectionMethod(ExternalModule::class, 'sanitizeEncodedState');
        $sanitize->setAccessible(true);
        $clean = $sanitize->invoke(null, $poisoned);

        $this->assertTrue(mb_check_encoding((string) $clean['note'], 'UTF-8'));
        $this->assertTrue(mb_check_encoding((string) $clean['deep']['x'], 'UTF-8'));
        $encoded = json_encode($clean, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString("\u{FFFD}", (string) $clean['note']);
        $this->assertIsString($encoded);
    }

    public function testUnencodableSendDisablesThePanelInsteadOfThrowing(): void
    {
        $module = $this->module('counter');
        $module->init();

        // inf/nan cannot be JSON-encoded at all — the sanitiser has no
        // bytes to substitute. The send-seam guard must catch the throw,
        // disable the panel, and keep update()/view() exception-free.
        $state = ['v' => INF];
        $returned = $module->update($state);
        $this->assertSame($state, $returned, 'update() keeps the host state and must not throw');

        $view = $module->view($state, 80, 24);
        $this->assertStringContainsString('disabled: state not encodable', $view);
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

    public function testResizeRendersFromCacheAndTheUpdateSeamRefreshesIt(): void
    {
        // D-101 contract flip (DISCLOSED): a resize used to pay a fresh
        // synchronous trip on the paint path, so a live drag hammered the
        // child once per repaint and a slow plugin could stall or be
        // deadline-disabled from view(). Paint is now inert — the counter
        // mode keeps the trip count visible: nothing but the seams talks.
        $module = $this->module('counter');
        $module->init();

        $first = $module->view(['tick' => 1], 80, 24);
        $this->assertStringContainsString('render #1', $first, 'cold first frame still renders');

        $again = $module->view(['tick' => 1], 80, 24);
        $this->assertSame($first, $again, 'unchanged render served from cache');

        $resized = $module->view(['tick' => 1], 100, 24);
        $this->assertSame($first, $resized, 'a resize must not pay a paint-path trip');

        $byPaint = $module->view(['tick' => 2], 100, 24);
        $this->assertSame($first, $byPaint, 'view() never conversations; only seams do');

        // The seam catches up: the state advanced and the pending size
        // differs, so exactly one render trip rides the next update().
        $next = $module->update(['tick' => 2]);
        $this->assertSame(['tick' => 2], $next);
        $refreshed = $module->view(['tick' => 2], 100, 24);
        $this->assertStringContainsString('render #2', $refreshed, 'resize + state refresh cost exactly one seam trip');
        $this->assertStringContainsString('tick:2', $refreshed, 'the fresh frame carries the advanced state');

        // Steady paints (even resized) stay cache-only...
        $this->assertSame($refreshed, $module->view(['tick' => 2], 120, 40));

        // ...and the next seam pays for it once.
        $module->update(['tick' => 3]);
        $after = $module->view(['tick' => 3], 120, 40);
        $this->assertStringContainsString('render #3', $after);
        $this->assertStringContainsString('tick:3', $after);
    }

    public function testDisabledReasonCarriesThePluginsOwnStderrLine(): void
    {
        // sugar-dashboard-203: the child's stderr was drained and thrown
        // away, so a panel disabled itself with only the host's half of the
        // story. The wept fixture complains on stderr, THEN breaks protocol;
        // the complaint must ride the disable reason.
        $module = $this->module('wept');
        $module->init();

        $state = $module->update(['x' => 1]);
        $this->assertSame(['x' => 1], $state, 'the refusal keeps the host state');

        $view = $module->view(['x' => 1], 80, 24);
        $this->assertStringContainsString('unexpected response: nonsense', $view);
        $this->assertStringContainsString('plugin stderr: config load fai', $view, 'the visible clip keeps the seam honest about what it carries');

        $error = (new \ReflectionProperty(ExternalModule::class, 'lastError'))->getValue($module);
        $this->assertIsString($error);
        $this->assertStringContainsString('/etc/wept.conf (request: update)', $error, 'the stored reason holds the full stderr line');
    }

    public function testAdvertisedMinSizeSurvivesInitAndChromeClipsOnGlyphBoundaries(): void
    {
        // sugar-dashboard-105: init() validated the plugin's minSize into its
        // return array and then THREW THE ANSWER AWAY — minSize() kept
        // answering the hardcoded placeholder forever — and the chrome clip
        // was a byte substr() that split multi-byte clusters mid-glyph.
        // First-frame order probe: the layout may ask before the handshake.
        $module = $this->module('sizes', 'sized');
        $this->assertSame([30, 4], $module->minSize(), 'pre-handshake advertises the placeholder budget');

        $meta = $module->init();
        $this->assertSame([77, 23], $meta['minSize'], 'the validated reply reaches the caller');
        $this->assertSame([77, 23], $module->minSize(), 'and is kept, not dropped');

        $name = (string) $meta['name'];
        $this->assertTrue(
            mb_check_encoding($name, 'UTF-8'),
            'a cell-budgeted clip never paints a broken cluster (' . bin2hex((string) substr($name, -2)) . ')'
        );
        $this->assertStringEndsWith('...', $name, 'the long name really was clipped');
        $this->assertLessThanOrEqual(67, \SugarCraft\Core\Util\Width::of($name), 'chrome stays inside the cell budget plus the ellipsis');
    }
}
