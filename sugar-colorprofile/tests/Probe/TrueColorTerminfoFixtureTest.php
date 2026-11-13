<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile\Tests\Probe;

use PHPUnit\Framework\TestCase;
use SugarCraft\ColorProfile\Probe\Capability;
use SugarCraft\ColorProfile\Probe\TerminalProbe;

/**
 * Fixture-based coverage of the terminfo TrueColor (Tc/RGB) probe.
 *
 * Clones the proven shape of {@see SixelTerminfoFixtureTest}: synthetic
 * `infocmp -1` stdout injected through the runCommand seam, terminfo phase
 * armed, interactive escape-query phase disabled — the capability name (or
 * its absence) is the only variable.
 *
 * Regression target (fix DM14): the upgrade used to run /\bTc\b/ and
 * /\bRGB\b/ against the WHOLE dump, so the words appearing anywhere — inside
 * a `des=` description value, a capability value like `sit1=\E[H\E[2J`, a
 * comment line — fabricated TrueColor on terminals that never advertised it.
 * Detection now uses the same name-position grammar as the Sixel leg, which
 * ALSO fixes the systematic under-detection: `RGB` is the name but `rgb=` is
 * how infocmp prints the string capability, and neither bare-word pattern
 * saw the lowercase name before.
 *
 * @covers \SugarCraft\ColorProfile\Probe\TerminalProbe::checkTerminfo
 */
final class TrueColorTerminfoFixtureTest extends TestCase
{
    /** @var array<string,string|null> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        $keys = [
            'CLICOLOR_FORCE', 'FORCE_COLOR', 'NO_COLOR', 'CLICOLOR', 'TERM', 'COLORTERM',
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
     * Probe on an xterm baseline (chain rung = 16 colors, so the terminfo
     * leg is what decides) whose dump is exactly the fixture.
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
     * Representative xterm-256color-shaped dump WITHOUT the trailing `rgb=`
     * graft the sixel fixture uses — here the rgb name IS the variable under
     * test, so the base body stays clean.
     */
    private function dump(string $capabilityLine): string
    {
        return "#\tReconstructed via infocmp from file: /usr/share/terminfo/x/xterm-256color\n"
            . "xterm-256color|xterm with 256 colors,\n"
            . "\tam,\n\tbce,\n\tnpc,\n\txenl,\n"
            . "\tcolors#0x100,\n\tcols#80,\n\tit#8,\n\tlines#24,\n"
            . "\tbel=^G,\n\tblink=\\E[5m,\n\tsetab=\\E[48;5;%p1%dm,\n"
            . ($capabilityLine === '' ? "\tdes=generic terminal,\n" : $capabilityLine . "\n");
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function positiveProvider(): array
    {
        return [
            'boolean Tc' => ['	Tc,'],
            'uppercase boolean TC' => ['	TC,'],
            'boolean RGB name' => ['	RGB,'],
            'string rgb= capability' => ['	rgb=\\E[38;2;%p1;%p2;%p3m,'],
            'numeric RGB#1' => ['	RGB#1,'],
            'colon indented :Tc' => [':	Tc,'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function negativeProvider(): array
    {
        return [
            'bare dump, no tc/rgb names' => [''],
            'Tc inside a des value' => ['	des=xterm with Tc support,'],
            'RGB inside an alt name value' => ['	AX, des=wide RGB,'],
            'Tc inside a comment' => ['#	Tc,\n\tdes=plain,'],
            'letters tc glued in another cap name' => ['	ntc,'],
            // CP-27: the retired underscore-fold made these two mint TrueColor.
            'underscore-spliced t_c is not Tc' => ['	t_c,'],
            'underscore-spliced r_gb is not RGB' => ['	r_gb,'],
        ];
    }

    public function testUnderscoreFoldDoesNotMintTrueColor(): void
    {
        // Named pin for the retired behavior (finding -069): hasCapName used
        // to str_replace('_','') the name-position token, so `t_c,` and
        // `r_gb,` — glued-letter fakes that are NOT any terminfo(5) capability
        // name — upgraded the rung to truecolor. A capability must be its
        // OWN name-position token; the roster lists real snake spellings
        // (sixel_screen_mode) by name instead of folding the world.
        $spliced = $this->probeWithInfocmp($this->dump("\tt_c,"))->runProbe();
        $this->assertFalse($spliced->hasTrueColor(), 't_c must not forge Tc');

        $splicedRgb = $this->probeWithInfocmp($this->dump("\tr_gb,"))->runProbe();
        $this->assertFalse($splicedRgb->hasTrueColor(), 'r_gb must not forge RGB');
    }

    /**
     * @dataProvider positiveProvider
     */
    public function testTrueColorNamePositionIsDetected(string $capabilityLine): void
    {
        $report = $this->probeWithInfocmp($this->dump($capabilityLine))->runProbe();

        $this->assertTrue($report->hasTrueColor(), 'dump: ' . $capabilityLine);
        $this->assertSame('terminfo:Tc|RGB', $report->source(Capability::TrueColor));
    }

    /**
     * @dataProvider negativeProvider
     */
    public function testTrueColorIsNotFabricatedFromIncidentalText(string $capabilityLine): void
    {
        $report = $this->probeWithInfocmp($this->dump($capabilityLine))->runProbe();

        $this->assertFalse($report->hasTrueColor(), 'must not upgrade on text-only hits: ' . $capabilityLine);
        $this->assertNull($report->source(Capability::TrueColor));
        // The honest answer stands: 16-color rung from the env ladder.
        $this->assertTrue($report->has(Capability::Color16));
    }
}
