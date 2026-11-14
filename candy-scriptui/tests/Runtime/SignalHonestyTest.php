<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Runtime;

use SugarCraft\ScriptUi\Runtime\InteractiveRuntime;
use PHPUnit\Framework\TestCase;

/**
 * scriptui-07 (candy-scriptui-036): when ext-pcntl is absent the signal
 * chain the interactive options request silently vanishes — sugar-core
 * installs no handler, so SIGINT/SIGTERM kill the process at default
 * disposition (no 130/143 mapping, no teardown). The contract is now
 * honest: the manifest suggests ext-pcntl, and a run that requested
 * signal-mapped cancellation says so once on STDERR.
 */
final class SignalHonestyTest extends TestCase
{
    protected function setUp(): void
    {
        // F13-W (CI-watch 2026-11-13): the pcntl warning latch is process-wide
        // once-per-run state. On the Windows CI arms (no pcntl) an earlier
        // arg-less call anywhere in this PHPUnit process consumes it before
        // this file runs, so the first-notice leg below could only observe
        // null — cross-test leakage, not a product bug. The reset seam pins
        // the latch open at test start; production keeps once-per-run.
        InteractiveRuntime::resetPcntlWarningLatch();
    }

    public function testComposerSuggestsExtPcntl(): void
    {
        // scriptui-53 KEEP (manifest-pin): the suggest entry IS the contract
        // — it is an install-time declaration no runtime probe can observe.
        $manifest = \json_decode(
            (string) \file_get_contents(__DIR__ . '/../../composer.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR
        );

        $this->assertIsArray($manifest);
        $suggest = $manifest['suggest'] ?? [];
        $this->assertArrayHasKey('ext-pcntl', $suggest);
        $this->assertStringContainsString('130', (string) $suggest['ext-pcntl']);
    }

    public function testNoticeNamesTheMissingSignalMappingAndFiresOnce(): void
    {
        $notice = InteractiveRuntime::signalNotice(pcntlLoaded: false);

        $this->assertIsString($notice);
        $this->assertNotSame('runtime.pcntl_missing', $notice, 'the notice must be localized, not a raw key');
        $this->assertMatchesRegularExpression('/pcntl|signal/iu', $notice);

        // One latch per run: a pcntl-less session must not spam the
        // diagnostic channel on every prompt.
        $this->assertNull(InteractiveRuntime::signalNotice(pcntlLoaded: false));

        // ...and the reset seam (F13-W) re-opens the arm: the notice is once
        // per RUN, not once per process forever — what makes setUp above a
        // hermetic contract instead of a one-shot lucky order.
        InteractiveRuntime::resetPcntlWarningLatch();
        $this->assertIsString(
            InteractiveRuntime::signalNotice(pcntlLoaded: false),
            'resetPcntlWarningLatch() must re-arm the notice'
        );
    }

    public function testNoNoticeWhenPcntlIsLoaded(): void
    {
        $this->assertNull(InteractiveRuntime::signalNotice(pcntlLoaded: true));
    }

    public function testEveryCancellationOptionPassesThroughTheWarningArm(): void
    {
        // scriptui-53 KEEP (count-pin, -51 grep-pin family): the warning is
        // only emitted on a pcntl-less host running the live arm —
        // extension_loaded('pcntl') is the truth of this box, so no
        // behaviour run here can enumerate the construction sites.
        $src = (string) \file_get_contents(__DIR__ . '/../../src/Runtime/InteractiveRuntime.php');

        // Each catchInterrupts: true construction is wrapped by the
        // honesty arm — a future option builder that forgets it goes red.
        // The trailing comma restricts the pattern to the live named
        // arguments (backtick mentions in prose have none).
        $this->assertSame(
            substr_count($src, 'catchInterrupts: true,'),
            substr_count($src, 'self::warnIfPcntlAbsent(new ProgramOptions'),
            'every signal-catching ProgramOptions must be guarded by warnIfPcntlAbsent()'
        );
    }
}
