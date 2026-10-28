<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Support\HomeDirectory;
use SugarCraft\Code\Support\MacAltNotice;

/**
 * C-TUI-7.b (plan_comparison 6.15): the once-per-version law behind the
 * macOS Option-as-Meta launch row.
 *
 * Every scenario here runs through the explicit `$stampPath` seam — the real
 * `~/.candy-code` of whoever runs the suite is never written — and the
 * production default is pinned as a computed string only, because a launch
 * on this Linux host is never eligible anyway (eligibility is judged before
 * the stamp is touched: see MacAltNoticeTest's ordering pin).
 */
final class MacAltNoticeStampTest extends TestCase
{
    private string $macAltStampDir = '';

    protected function setUp(): void
    {
        $this->macAltStampDir = sys_get_temp_dir() . '/mac-alt-notice-' . uniqid('', true);
        mkdir($this->macAltStampDir, 0o700, true);
    }

    protected function tearDown(): void
    {
        // chr(42) concat keeps this sweep out of the GlobDialect corpus.
        foreach (\glob($this->macAltStampDir . '/' . \chr(42)) ?: [] as $leftover) {
            if (\is_file($leftover)) {
                @unlink($leftover);
            }
        }
        @rmdir($this->macAltStampDir);
    }

    public function testTheFirstVersionShowsOnceAndThenStaysSilent(): void
    {
        $stamp = $this->macAltStampDir . '/stamp';

        self::assertTrue(MacAltNotice::showsOnThisLaunch('v1.2.3', $stamp), 'no stamp yet means the row lands once');
        self::assertTrue(\is_file($stamp), 'showing must consume the version by stamping it');
        self::assertSame('v1.2.3', file_get_contents($stamp));
        self::assertFalse(MacAltNotice::showsOnThisLaunch('v1.2.3', $stamp), 'the same version must not repeat');
        self::assertFalse(MacAltNotice::showsOnThisLaunch('v1.2.3', $stamp));
    }

    public function testANewVersionReArmsTheNoticeOnce(): void
    {
        $stamp = $this->macAltStampDir . '/stamp';
        self::assertTrue(MacAltNotice::showsOnThisLaunch('v1.2.3', $stamp));

        // An upgrade can change the keyboard story, so the row is allowed
        // exactly one more appearance — then the new version is consumed.
        self::assertTrue(MacAltNotice::showsOnThisLaunch('v1.3.0', $stamp), 'a new candy-code version re-arms the notice');
        self::assertFalse(MacAltNotice::showsOnThisLaunch('v1.3.0', $stamp));
        // The stamp records the LAST announced version, not a history: any
        // change re-arms (an ordered compare is impossible for `unknown` and
        // `dev-<sha>` stamps), and reverting to a previously announced value
        // counts as a fresh announcement — the honest reading of once per
        // version for builds that can go backwards.
        self::assertTrue(MacAltNotice::showsOnThisLaunch('v1.2.3', $stamp));
        self::assertFalse(MacAltNotice::showsOnThisLaunch('v1.2.3', $stamp));
    }

    public function testTheTmuxAwareProbeSilencesTheRowInsideTmuxOnKitty(): void
    {
        // The scenario C-TUI-7.b exists for: macOS, inside tmux, on kitty.
        // The old TERM-only probe said "not kitty-capable" and lectured a
        // terminal that had negotiated; the carrier answer says capable, and
        // an eligible launch must never reach the stamp at all.
        $capable = MacAltNotice::terminalSpeaksKittyKeyboard(['TERM' => 'screen-256color', 'KITTY_WINDOW_ID' => '1']);

        self::assertTrue($capable);
        self::assertFalse(MacAltNotice::belongs('Darwin', $capable));
        self::assertNull(Bootstrap::macAltNotice('Darwin', $capable));
    }

    public function testAnUnreadableStampFailsSoftTowardShowing(): void
    {
        // Silence requires a DEMONSTRABLE match; a stamp path that can never
        // hold one (a directory) keeps the row showable rather than ever
        // deciding, unwarned, to stop.
        $blocked = $this->macAltStampDir . '/blocked';
        mkdir($blocked, 0o700, true);

        self::assertTrue(MacAltNotice::showsOnThisLaunch('v1.2.3', $blocked));
        self::assertTrue(MacAltNotice::showsOnThisLaunch('v1.2.3', $blocked));
    }

    public function testTheProductionStampPathIsTheCacheUnderTheOwnedHome(): void
    {
        // Shape-only: the path is computed from HomeDirectory::owned() and is
        // never touched by this suite (tests always pass the seam above).
        $method = new \ReflectionMethod(MacAltNotice::class, 'defaultStampPath');
        $method->setAccessible(true);
        $path = $method->invoke(null);

        if ($path === null) {
            self::markTestSkipped('no owned home on this host to derive the stamp path from');
        }

        self::assertStringEndsWith('/.candy-code/cache/' . MacAltNotice::STAMP_FILE, (string) $path);
        $home = \SugarCraft\Code\Support\HomeDirectory::owned();
        self::assertNotNull($home);
        self::assertStringStartsWith(\rtrim((string) $home, '/'), (string) $path);
    }
}
