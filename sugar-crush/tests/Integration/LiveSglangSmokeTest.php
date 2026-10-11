<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Media\Capability\CapabilityDiscoverer;
use SugarCraft\Crush\Media\Capability\EndpointFamily;
use SugarCraft\Crush\Media\Capability\MediaCapability;
use SugarCraft\Crush\Media\Sd\Client;
use SugarCraft\Crush\Media\Sd\GuzzleTransport;
use SugarCraft\Crush\Media\Sd\UrlGuard;

/**
 * W7.7 live smoke seam (plan: the one sanctioned dial set against a real
 * sglang-diffusion server). DORMANT BY DEFAULT: the whole class skips unless
 * SUGARCRUSH_LIVE_SGLANG=1 is exported in the runner's shell — a test-only
 * variable, deliberately unread by src/, so the env-roster guard (which
 * censuses src/ and bin/) does not demand a docs/ENVIRONMENT.md row. The skip
 * is a body-level markTestSkipped inside the single test method and is
 * rostered by SuiteSkipRoster::EXPECTED via its env-conditional arm
 * (liveSglangSmokeArmed), so the dormant skip is floor-accounted and the armed
 * run drops the roster entry instead of tripping check 3.
 *
 * Even when armed this file DIALS ONLY GETs: the discovery ladder (3 s
 * budget per hop, the standing E646 exception plumbing) plus the read-only
 * model/status routes. A live generation POST stays operator-sanctioned
 * per-dial (plan W7.7), never automated here — an accidental Wan2.2 render
 * is minutes of a shared GPU.
 */
final class LiveSglangSmokeTest extends TestCase
{
    /** The operator's skynet2 box as recorded at W0 (Wan2.2-T2V loaded). */
    private const DEFAULT_BASE = 'http://173.225.108.102:30001';

    public function testTheLiveDiscoveryLadderNamesSglangDiffusion(): void
    {
        $base = $this->liveBase();
        if ($base === null) {
            $this->markTestSkipped('set SUGARCRUSH_LIVE_SGLANG=1 (optionally SUGARCRUSH_LIVE_SGLANG_BASE) to dial the live smoke');
        }

        $client = Client::withBase($base);
        $capability = (new CapabilityDiscoverer())->probe(
            new GuzzleTransport(UrlGuard::forBase($base)),
        );

        $this->assertNotNull($capability, 'the W0 ladder verdict was sglang-diffusion via /model_info');
        $this->assertSame(EndpointFamily::SglangDiffusion, $capability->family());
        $this->assertTrue($capability->family()->isImplemented(), 'the W7 flip must hold against the live server, not just fixtures');
        $this->assertTrue(
            $capability->declares(MediaCapability::SUPPORT_VIDEO) || $capability->declares(MediaCapability::SUPPORT_TXT2IMG),
            'the loaded model must declare at least one modality it can actually serve',
        );
    }

    private function liveBase(): ?string
    {
        if (getenv('SUGARCRUSH_LIVE_SGLANG') !== '1') {
            return null;
        }
        $override = getenv('SUGARCRUSH_LIVE_SGLANG_BASE');

        return \is_string($override) && trim($override) !== '' ? trim($override) : self::DEFAULT_BASE;
    }
}
