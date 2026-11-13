<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile\Tests\Probe;

use PHPUnit\Framework\TestCase;
use SugarCraft\ColorProfile\ColorProfile;
use SugarCraft\ColorProfile\Probe\Capability;
use SugarCraft\ColorProfile\Probe\ProbeReport;
use SugarCraft\ColorProfile\Probe\TerminalProbe;

/**
 * @covers \SugarCraft\ColorProfile\Probe\ProbeReport
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

    /**
     * CP-23 negative roster pin, beside the all() shape above: the two
     * @deprecated RESERVED cases have NO producer anywhere. Every advertised
     * environment the phase-3 TERM_PROGRAM table knows must still never
     * yield them — a future query leg that starts minting them flips this
     * pin and the README footnote together, never silently. TERM stays
     * unset so the terminfo phase (and any host database) is not part of
     * the proof.
     */
    public function testTheReservedQueryCapabilitiesAreNeverProduced(): void
    {
        // The constructor's interactive flag ANDs with the real
        // isInteractive() (TTY), so only the fixture convention — override
        // the seam — arms phase 3 on a test runner. Mutation-checked pin:
        // a row planted in the TERM_PROGRAM table reddens this (verified).
        foreach (['iTerm.app', 'Hyper', 'WezTerm', 'vscode', 'Ghostty', 'Apple_Terminal'] as $termProgram) {
            $probe = new class([
                'COLORTERM'    => 'truecolor',
                'TERM_PROGRAM' => $termProgram,
            ], true) extends TerminalProbe {
                protected function isInteractive(): bool
                {
                    return true;
                }
            };

            $all = $probe->runProbe()->all();
            $this->assertNotEmpty($all, $termProgram . ': the advertised table answered at all');
            $this->assertNotContains(Capability::BracketedPaste, $all, $termProgram);
            $this->assertNotContains(Capability::FocusEvents, $all, $termProgram);
        }
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

    public function testNoColorCapabilityBridgesToTheColorlessRungNotNoTTY(): void
    {
        // CP-20 (sugar-colorprofile-023): the bridge used to fold the
        // operator's opt-out back onto the hardware-absence name — the one
        // vocabulary distinction renderers had left. NoColor now lands on
        // the Ascii rung CP-05 built for it: decoration survives, and the
        // WHY stays reachable through DetectionChain::userOptedOut().
        $report = new ProbeReport([
            Capability::NoColor->value => 'env:NO_COLOR',
        ]);

        $this->assertSame(ColorProfile::Ascii, $report->toColorProfile());
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

    public function testRawMapReadAnswersThroughTheDeprecatedShim(): void
    {
        // CP-51 (sugar-colorprofile-064): the map itself is private; the
        // legacy property read still answers — value-identical to
        // allWithSource() — while raising exactly one E_USER_DEPRECATED.
        $report = new ProbeReport([Capability::TrueColor->value => 'env:COLORTERM']);
        $raised = [];
        set_error_handler(static function (int $severity, string $message) use (&$raised): bool {
            if ($severity === \E_USER_DEPRECATED) {
                $raised[] = $message;

                return true;
            }

            return false;
        });

        try {
            $this->assertSame(
                [Capability::TrueColor->value => 'env:COLORTERM'],
                $report->capabilities,
                'the shim answers the raw map unchanged',
            );
        } finally {
            restore_error_handler();
        }

        $this->assertCount(1, $raised, 'one deprecation per legacy read');
        $this->assertStringContainsString('allWithSource()', $raised[0]);
    }

    public function testDirectWriteToTheRawMapThrows(): void
    {
        // The finding's write-side half: even pre-CP-51 a readonly class
        // refused writes; post-CP-51 the property is additionally private,
        // so a planted assignment must be an engine-level Error, never a
        // silent no-op — the map's only doors are the constructor and reads.
        $report = new ProbeReport([]);

        $this->expectException(\Error::class);
        /** @phpstan-ignore-next-line intentionally invalid */
        $report->capabilities = ['bogus' => 'x'];
    }

    public function testShimCoversOnlyTheLegacyMapName(): void
    {
        $report = new ProbeReport([]);

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Undefined property');
        /** @phpstan-ignore-next-line intentionally invalid */
        $unknown = $report->capabilitiesTwo;
    }
}
