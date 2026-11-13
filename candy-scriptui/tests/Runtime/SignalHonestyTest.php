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
