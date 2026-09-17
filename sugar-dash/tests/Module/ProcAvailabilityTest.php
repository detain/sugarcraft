<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Module;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Module\ProcAvailability;

/**
 * Tests for ProcAvailability — the E731 COMP-2 probe-once door memo.
 */
final class ProcAvailabilityTest extends TestCase
{
    private string $tempPath;

    protected function setUp(): void
    {
        ProcAvailability::resetForTesting();
        $this->tempPath = sys_get_temp_dir() . '/dash-probe-' . uniqid('', true) . '.proc';
    }

    protected function tearDown(): void
    {
        ProcAvailability::resetForTesting();
        if (is_file($this->tempPath)) {
            @unlink($this->tempPath);
        }
    }

    public function testHasProbesOnceAndKeepsTheAnswerAcrossFilesystemChange(): void
    {
        file_put_contents($this->tempPath, 'x');
        $this->assertTrue(ProcAvailability::has($this->tempPath));

        unlink($this->tempPath);
        // Probe-once (option b): the memoized door survives the filesystem
        // changing underneath — that staleness IS the degradation contract.
        $this->assertTrue(ProcAvailability::has($this->tempPath));

        // The reset seam returns to a cold ambient probe, which now sees truth.
        ProcAvailability::resetForTesting();
        $this->assertFalse(ProcAvailability::has($this->tempPath));
    }

    public function testMarkForTestingOverridesTheAmbientProbe(): void
    {
        file_put_contents($this->tempPath, 'x');
        // Injection wins over a genuinely readable file in BOTH directions —
        // this is the sanctioned seam for the non-Linux render pins (the /proc
        // paths are hardcoded, so is_readable() is unfakeable any other way).
        ProcAvailability::markForTesting($this->tempPath, false);
        $this->assertFalse(ProcAvailability::has($this->tempPath));

        ProcAvailability::markForTesting('/proc/definitely-not-a-real-file', true);
        $this->assertTrue(ProcAvailability::has('/proc/definitely-not-a-real-file'));

        ProcAvailability::resetForTesting();
        $this->assertFalse(ProcAvailability::has('/proc/definitely-not-a-real-file'));
    }

    public function testMissingPathIsUnavailableOnAColdProbe(): void
    {
        // Non-vacuity for the memo default: never-created path, cold probe.
        $this->assertFalse(ProcAvailability::has($this->tempPath));
    }

    public function testSentinelVocabularyIsFixed(): void
    {
        // The visible sentinel spelling and the negative in-model percentage
        // are the COMP-2 (b) contract; views and history gates key off them.
        $this->assertSame('n/a', ProcAvailability::UNAVAILABLE_SENTINEL);
        $this->assertSame(-1.0, ProcAvailability::UNMEASURED);
    }
}
