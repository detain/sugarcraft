<?php

declare(strict_types=1);

namespace SugarCraft\Palette\Tests\Probe;

use PHPUnit\Framework\TestCase;
use SugarCraft\Palette\Probe\Capability;
use SugarCraft\Palette\Probe\ProbeReport;

/**
 * @covers \SugarCraft\Palette\Probe\ProbeReport
 */
final class ProbeReportTest extends TestCase
{
    public function testAllReturnsAllCapabilities(): void
    {
        $report = new ProbeReport([
            Capability::TrueColor->value => 'env:COLORTERM',
            Capability::BasicAscii->value => 'fallback:basic-ascii',
        ]);

        $all = $report->all();

        $this->assertCount(2, $all);
        $this->assertContains(Capability::TrueColor, $all);
        $this->assertContains(Capability::BasicAscii, $all);
    }

    public function testAllWithSourceReturnsMap(): void
    {
        $report = new ProbeReport([
            Capability::TrueColor->value => 'env:COLORTERM',
            Capability::BasicAscii->value => 'fallback:basic-ascii',
        ]);

        $withSource = $report->allWithSource();

        $this->assertCount(2, $withSource);
        $this->assertArrayHasKey('truecolor', $withSource);
        $this->assertArrayHasKey('basic-ascii', $withSource);
        $this->assertSame('env:COLORTERM', $withSource['truecolor']);
    }

    public function testHasTrueColorTrue(): void
    {
        $report = new ProbeReport([
            Capability::TrueColor->value => 'env:COLORTERM',
        ]);

        $this->assertTrue($report->hasTrueColor());
    }

    public function testHasTrueColorFalse(): void
    {
        $report = new ProbeReport([]);

        $this->assertFalse($report->hasTrueColor());
    }

    public function testHasColor256True(): void
    {
        $report = new ProbeReport([
            Capability::Color256->value => 'env:TERM=xterm-256color',
        ]);

        $this->assertTrue($report->hasColor256());
    }

    public function testHasColor256False(): void
    {
        $report = new ProbeReport([]);

        $this->assertFalse($report->hasColor256());
    }

    public function testHasNoColorTrue(): void
    {
        $report = new ProbeReport([
            Capability::NoColor->value => 'env:NO_COLOR',
        ]);

        $this->assertTrue($report->hasNoColor());
    }

    public function testHasNoColorFalse(): void
    {
        $report = new ProbeReport([]);

        $this->assertFalse($report->hasNoColor());
    }

    public function testColorDescriptionTrueColor(): void
    {
        $report = new ProbeReport([
            Capability::TrueColor->value => 'env:COLORTERM',
        ]);

        $desc = $report->colorDescription();
        $this->assertStringContainsString('TrueColor', $desc);
        $this->assertStringContainsString('24-bit', $desc);
    }

    public function testColorDescriptionColor256(): void
    {
        $report = new ProbeReport([
            Capability::Color256->value => 'env:TERM=xterm-256color',
        ]);

        $desc = $report->colorDescription();
        $this->assertStringContainsString('256', $desc);
    }

    public function testColorDescriptionColor16(): void
    {
        $report = new ProbeReport([
            Capability::Color16->value => 'fallback:color16-default',
        ]);

        $desc = $report->colorDescription();
        $this->assertStringContainsString('16', $desc);
    }

    public function testColorDescriptionNoColor(): void
    {
        $report = new ProbeReport([
            Capability::NoColor->value => 'env:NO_COLOR',
        ]);

        $desc = $report->colorDescription();
        $this->assertStringContainsString('No color', $desc);
    }

    public function testColorDescriptionBasicAscii(): void
    {
        $report = new ProbeReport([
            Capability::BasicAscii->value => 'fallback:basic-ascii',
        ]);

        $desc = $report->colorDescription();
        $this->assertStringContainsString('ASCII', $desc);
    }

    public function testHasReturnsTrueForDetectedCapability(): void
    {
        $report = new ProbeReport([
            Capability::Color256->value => 'env:TERM=xterm-256color',
        ]);

        $this->assertTrue($report->has(Capability::Color256));
    }

    public function testHasReturnsFalseForUndetectedCapability(): void
    {
        $report = new ProbeReport([
            Capability::Color256->value => 'env:TERM=xterm-256color',
        ]);

        $this->assertFalse($report->has(Capability::TrueColor));
    }

    public function testSourceReturnsStringForDetectedCapability(): void
    {
        $report = new ProbeReport([
            Capability::TrueColor->value => 'env:COLORTERM=truecolor',
        ]);

        $source = $report->source(Capability::TrueColor);
        $this->assertSame('env:COLORTERM=truecolor', $source);
    }

    public function testSourceReturnsNullForUndetectedCapability(): void
    {
        $report = new ProbeReport([]);

        $source = $report->source(Capability::TrueColor);
        $this->assertNull($source);
    }

    public function testDetectedAtIsSet(): void
    {
        $before = new \DateTimeImmutable();
        $report = new ProbeReport([]);
        $after = new \DateTimeImmutable();

        $this->assertGreaterThanOrEqual($before, $report->detectedAt);
        $this->assertLessThanOrEqual($after, $report->detectedAt);
    }

    public function testDetectedAtWithCustomValue(): void
    {
        $customTime = new \DateTimeImmutable('2024-01-01 12:00:00');
        $report = new ProbeReport([], $customTime);

        $this->assertSame($customTime, $report->detectedAt);
    }
}
