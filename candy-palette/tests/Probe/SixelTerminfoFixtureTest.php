<?php

declare(strict_types=1);

namespace SugarCraft\Palette\Tests\Probe;

use PHPUnit\Framework\TestCase;
use SugarCraft\Palette\Probe\Capability;
use SugarCraft\Palette\Probe\TerminalProbe;

/**
 * Fixture-based coverage of the terminfo Sixel capability probe.
 *
 * The probe shells out to `infocmp -1 <TERM>`, whose output depends on the
 * host terminfo database, so these tests never exec it: they inject synthetic
 * `infocmp -1` stdout through the existing {@see TerminalProbe::runCommand()}
 * seam (the same anonymous-subclass override the sibling tests use). That
 * keeps the assertions independent of whatever terminal is installed while
 * still exercising the real parsing in {@see TerminalProbe::checkTerminfo()}.
 *
 * Regression target: the probe used to match `/\bsixel\b/` against the whole
 * dump. Real `infocmp -1` emits capability names such as `Sixel#1` (numeric),
 * `Sixel=true` (string), `SixelScreenMode` and the `Ss1`/`Ss2` user caps — all
 * of which that case-sensitive, bare-word pattern could never see — while a
 * lowercase "sixel" sitting inside an unrelated `des=` value or a `#` comment
 * matched spuriously. Sixel was systematically UNDER-detected on real entries
 * and could OVER-fire on descriptions. The fix inspects only the
 * capability-name position of each line.
 *
 * @covers \SugarCraft\Palette\Probe\TerminalProbe::checkTerminfo
 */
final class SixelTerminfoFixtureTest extends TestCase
{
    /** @var array<string,string|null> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        $keys = [
            'CLICOLOR_FORCE', 'NO_COLOR', 'CLICOLOR', 'TERM', 'COLORTERM',
            'WT_SESSION', 'GOOGLE_CLOUD_SHELL', 'TMUX', 'STY', 'TERM_PROGRAM',
        ];
        foreach ($keys as $key) {
            $this->savedEnv[$key] = $_ENV[$key] ?? null;
            unset($_ENV[$key]);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach ($this->savedEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $value;
                putenv("{$key}={$value}");
            }
        }
    }

    /**
     * Build a probe whose `infocmp -1` output is the supplied fixture and
     * whose interactive escape-query phase is disabled, so the terminfo
     * capability name (or its absence) is the only variable.
     *
     * @param string $infocmpOutput Synthetic `infocmp -1` stdout.
     */
    private function probeWithInfocmp(string $infocmpOutput): TerminalProbe
    {
        return new class ($infocmpOutput) extends TerminalProbe {
            public function __construct(private readonly string $output)
            {
                parent::__construct(['TERM' => 'xterm'], false);
            }

            protected function infocmpAvailable(): bool
            {
                return true;
            }

            protected function isInteractive(): bool
            {
                return false;
            }

            protected function runCommand(string $cmd): ?string
            {
                return str_contains($cmd, 'infocmp') ? $this->output : null;
            }
        };
    }

    /**
     * A realistic xterm-256color dump is long: keep one representative body
     * and graft the Sixel capability under test into it.
     *
     * @return string
     */
    private function infocmpDump(string $capabilityLine): string
    {
        $head = "#\tReconstructed via infocmp from file: /usr/share/terminfo/x/xterm-256color\n"
            . "xterm-256color|xterm with 256 colors,\n"
            . "\tam,\n\tbce,\n\tnpc,\n\txenl,\n"
            . "\tcolors#0x100,\n\tcols#80,\n\tit#8,\n\tlines#24,\n"
            . "\tbel=^G,\n\tblink=\\E[5m,\n\tsetab=\\E[48;5;%p1%dm,\n";

        return $capabilityLine === ''
            ? $head . "\tdes=generic terminal,\n"
            : $head . $capabilityLine . "\n\trgb=\\E[38;2;%p1;%p2;%p3m,\n";
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function positiveProvider(): array
    {
        return [
            'numeric Sixel#1' => ['	Sixel#1,'],
            'string Sixel=true' => ['	Sixel=true,'],
            'lowercase boolean sixel' => ['	sixel,'],
            'capitalised boolean Sixel' => ['	Sixel,'],
            'abbreviated boolean sixl' => ['	sixl,'],
            'SixelScreenMode string' => ['	SixelScreenMode=1,'],
            'Ss1 user capability' => ['	Ss1,'],
            'Ss2 user capability' => ['	Ss2,'],
            'colon numeric :Sixel#1' => [':	Sixel#1,'],
        ];
    }

    /**
     * @dataProvider positiveProvider
     */
    public function testSixelCapabilityIsDetected(string $capabilityLine): void
    {
        $report = $this->probeWithInfocmp($this->infocmpDump($capabilityLine))->runProbe();

        $this->assertTrue(
            $report->has(Capability::Sixel),
            "Expected Sixel for infocmp line: {$capabilityLine}"
        );
        $this->assertSame('terminfo:sixel', $report->source(Capability::Sixel));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function negativeProvider(): array
    {
        return [
            // No Sixel cap at all.
            'plain xterm, no sixel' => [''],
            // The literal word "sixel" only inside an unrelated capability VALUE.
            'sixel buried in des= value' => ["\tdes=xterm with sixel graphics support,"],
            // The literal word only inside an infocmp comment header.
            'sixel only in comment' => ["#\tsixel mentioned here as a comment\n\tSixel_Disabled=false,"],
            // A capability NAME that merely starts with sixel but is not one we accept.
            'sixelish is not sixel' => ["\tsixelish=true,"],
            // Ss0/Ss9 user caps are not sixel-advertising (only Ss1/Ss2 are).
            'Ss0 not a sixel cap' => ["\tSs0,"],
        ];
    }

    /**
     * @dataProvider negativeProvider
     */
    public function testSixelNotFalselyDetected(string $infocmpBody): void
    {
        $report = $this->probeWithInfocmp($this->infocmpDump($infocmpBody))->runProbe();

        $this->assertFalse(
            $report->has(Capability::Sixel),
            "Sixel must NOT be detected for: {$infocmpBody}"
        );
    }

    /**
     * The truecolor Tc/RGB terminfo upgrade must stay intact after the sixel
     * fix (the brief: "Keep the existing truecolor Tc/RGB detection behavior
     * intact"), and both capabilities can coexist on one entry.
     */
    public function testTruecolorUpgradePreservedAlongsideSixel(): void
    {
        $dump = $this->infocmpDump("\tSixel#1,") . "\tTc,\n";
        $report = $this->probeWithInfocmp($dump)->runProbe();

        $this->assertTrue($report->has(Capability::Sixel));
        $this->assertTrue($report->has(Capability::TrueColor));
        $this->assertSame('terminfo:Tc|RGB', $report->source(Capability::TrueColor));
    }
}
