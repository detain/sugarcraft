<?php

declare(strict_types=1);

namespace SugarCraft\Palette\Tests\Probe;

use PHPUnit\Framework\TestCase;
use SugarCraft\Palette\Probe\Capability;
use SugarCraft\Palette\Probe\InfocmpBinary;
use SugarCraft\Palette\Probe\ProbeReport;

/**
 * Audit #4-C fix pins: ProbeReport parses capability keys at the boundary
 * (constructor), and the infocmp sniff lives in exactly one class.
 */
final class ProbeReportValidationTest extends TestCase
{
    public function testConstructorRejectsUnknownCapabilityKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown capability key(s): not-a-capability');

        new ProbeReport(['not-a-capability' => 'env:WHATEVER']);
    }

    public function testConstructorNamesEveryUnknownKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown capability key(s): bogus-one, bogus-two');

        new ProbeReport(['bogus-one' => 'fallback', 'bogus-two' => 'fallback']);
    }

    public function testConstructorAcceptsAllKnownCapabilities(): void
    {
        $caps = [];
        foreach (Capability::cases() as $case) {
            $caps[$case->value] = 'env:TEST';
        }

        $report = new ProbeReport($caps);

        $this->assertSame(array_values(Capability::cases()), $report->all());
    }

    public function testAllNeverThrowsOnceConstructed(): void
    {
        // Parse-don't-validate: if the ctor accepted the map, all() cannot explode later.
        $report = new ProbeReport([Capability::TrueColor->value => 'terminfo:Tc']);

        $this->assertSame([Capability::TrueColor], $report->all());
    }

    public function testEmptyReportConstructsAndEnumerates(): void
    {
        $this->assertSame([], (new ProbeReport([]))->all());
    }

    public function testInfocmpBinaryAnswersConsistentlyAcrossCalls(): void
    {
        InfocmpBinary::reset();

        $first = InfocmpBinary::path();
        $this->assertContains($first, [null, '/usr/bin/infocmp', '/bin/infocmp']);
        $this->assertSame($first, InfocmpBinary::path(), 'memoised answer must not drift');

        if ($first !== null) {
            $this->assertFileExists($first);
        }
    }

    public function testSingleSniffLaw(): void
    {
        // The two hardcoded candidate paths may appear only inside InfocmpBinary.
        $sources = [
            __DIR__ . '/../../src/Probe.php',
            __DIR__ . '/../../src/AsyncProbe.php',
            __DIR__ . '/../../src/Probe/TerminalProbe.php',
        ];

        foreach ($sources as $file) {
            $body = (string) file_get_contents($file);
            $this->assertStringNotContainsString(
                '/usr/bin/infocmp',
                $body,
                basename($file) . ' must delegate to InfocmpBinary::path() instead of sniffing itself',
            );
        }

        $helper = (string) file_get_contents(__DIR__ . '/../../src/Probe/InfocmpBinary.php');
        $this->assertStringContainsString('/usr/bin/infocmp', $helper);
        $this->assertStringContainsString('/bin/infocmp', $helper);
    }
}
