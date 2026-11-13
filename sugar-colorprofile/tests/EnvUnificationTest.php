<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\ColorProfile\ColorProfile;
use SugarCraft\ColorProfile\DetectionChain;
use SugarCraft\ColorProfile\Env;
use SugarCraft\ColorProfile\Palette;
use SugarCraft\ColorProfile\Probe;
use SugarCraft\ColorProfile\Probe\Capability;
use SugarCraft\ColorProfile\Probe\ProbeReport;
use SugarCraft\ColorProfile\Probe\TerminalProbe;

/**
 * The one-environment-one-answer law, pinned across every detection surface.
 *
 * Before this family of fixes, Palette layered its injected env map over the
 * ambient process env while DetectionChain replaced it wholesale, Probe and
 * TerminalProbe disagreed on whether a term name is case-sensitive, and
 * TERM=dumb vs COLORTERM resolved differently on the two surfaces. Each test
 * here re-proves the unified rule (fixes DM1/DM3/DM6/DM13/MT1):
 *
 *  - a non-empty injected map is a COMPLETE hermetic replacement;
 *  - the chain is the single color-depth ladder for every surface;
 *  - the ladder is case-insensitive on TERM and honours dumb BEFORE COLORTERM;
 *  - a probe run overlays are scoped to that run, never sticky.
 */
final class EnvUnificationTest extends TestCase
{
    /** Chain sources that are owner-declared suppressions (CP-05 colorless rung). */
    private const SUPPRESSION_SOURCES = ['env:NO_COLOR', 'env:CLICOLOR=0', 'env:TERM=dumb'];

    /** @var array<string,string|false|null> */
    private array $saved = [];

    private const AMBIENT_KEYS = [
        'CLICOLOR_FORCE', 'FORCE_COLOR', 'NO_COLOR', 'CLICOLOR', 'TERM', 'COLORTERM',
        'TERM_PROGRAM', 'WT_SESSION', 'GOOGLE_CLOUD_SHELL', 'TMUX', 'STY',
        'REDUCE_MOTION', 'PREFERS_REDUCED_MOTION',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::AMBIENT_KEYS as $key) {
            $this->saved[$key] = getenv($key);
            unset($_ENV[$key]);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach (self::AMBIENT_KEYS as $key) {
            $value = $this->saved[$key] ?? false;
            if ($value === false || $value === null) {
                unset($_ENV[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $value;
                putenv("{$key}={$value}");
            }
        }
        parent::tearDown();
    }

    private function arm(string $key, string $value): void
    {
        // The house dual-arm: every real consumer sees the ambient layer via
        // $_ENV OR the process env, so a hermetic test must poison BOTH.
        $_ENV[$key] = $value;
        putenv("{$key}={$value}");
    }

    /** @param array<string,string|null> $env */
    private function silentProbe(array $env): TerminalProbe
    {
        // Non-interactive, terminfo-free probe: the env ladder alone decides.
        return new class ($env) extends TerminalProbe {
            public function __construct(array $env)
            {
                parent::__construct($env, false);
            }

            protected function infocmpAvailable(): bool
            {
                return false;
            }

            protected function isInteractive(): bool
            {
                return false;
            }
        };
    }

    public function testHermeticMapReplacesAmbientEnv(): void
    {
        // A hostile-ish ambient suppression must NOT leak into a hermetic map.
        $this->arm('NO_COLOR', '1');
        $this->arm('CLICOLOR_FORCE', '1');

        $this->assertSame(
            ColorProfile::Ansi256,
            Palette::detect(null, ['TERM' => 'xterm-256color']),
            'injected map is a complete replacement: neither ambient leg may apply',
        );
        $this->assertSame(
            DetectionChain::LEVEL_ANSI256,
            DetectionChain::detect(['TERM' => 'xterm-256color'])->level(),
        );
        $report = $this->silentProbe(['TERM' => 'xterm-256color'])->runProbe();
        $this->assertTrue($report->hasColor256());
        $this->assertFalse($report->hasNoColor());
    }

    public function testRunProbeOverlayIsScopedToItsRun(): void
    {
        // MT1: the old runProbe() merged the overlay into the instance
        // permanently, so a second call inherited the first call's NO_COLOR.
        $probe = $this->silentProbe(['TERM' => 'xterm']);

        $suppressed = $probe->runProbe(['NO_COLOR' => '1']);
        $this->assertTrue($suppressed->hasNoColor());

        $clean = $probe->runProbe();
        $this->assertFalse($clean->hasNoColor(), 'per-run overlay must not poison later runs');
        $this->assertTrue($clean->has(Capability::Color16));
    }

    /**
     * @return array<string, array{0: array<string,string>, 1: string}>
     */
    public static function parityProvider(): array
    {
        return [
            'uppercase 256color term' => [['TERM' => 'XTERM-256COLOR'], DetectionChain::LEVEL_ANSI256],
            'uppercase kitty' => [['TERM' => 'XTERM-KITTY'], DetectionChain::LEVEL_ANSI256],
            'uppercase dumb' => [['TERM' => 'DUMB'], DetectionChain::LEVEL_ASCII],
            'DUMB smuggles no COLORTERM' => [['TERM' => 'DUMB', 'COLORTERM' => 'truecolor'], DetectionChain::LEVEL_ASCII],
            'dumb lowercase' => [['TERM' => 'dumb'], DetectionChain::LEVEL_ASCII],
            'force color beats dumb' => [['FORCE_COLOR' => '3', 'TERM' => 'dumb'], DetectionChain::LEVEL_TRUECOLOR],
            'falsy force floors ascii' => [['FORCE_COLOR' => 'false'], DetectionChain::LEVEL_ASCII],
            'empty NO_COLOR is unset' => [['NO_COLOR' => '', 'TERM' => 'xterm'], DetectionChain::LEVEL_ANSI],
            'mixed-case truecolor COLORTERM' => [['COLORTERM' => 'TrueColor', 'TERM' => 'xterm'], DetectionChain::LEVEL_TRUECOLOR],
        ];
    }

    /**
     * The three surfaces that used to disagree must now answer identically on
     * every shape in the provider (fixes DM1/DM6/DM13).
     *
     * @param array<string,string> $env
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('parityProvider')]
    public function testAllSurfacesAgreeOnOneLadder(array $env, string $expectedLevel): void
    {
        $chain = DetectionChain::detect($env);
        $this->assertSame($expectedLevel, $chain->level());

        $this->assertSame(
            $chain->toColorProfile(),
            Palette::detect(null, $env),
            'Palette must emit exactly the chain answer',
        );

        $report = $this->silentProbe($env)->runProbe();
        // CP-05 Shape B: the colorless rung surfaces differently by which leg
        // answers it — an owner-declared suppression (NoColor capability, its
        // own env: source, byte-identical report identity to the old NONE
        // routing) versus the falsy-FORCE_COLOR floor (BasicAscii capability).
        $isSuppression = $expectedLevel === DetectionChain::LEVEL_ASCII
            && in_array($chain->source(), self::SUPPRESSION_SOURCES, true);
        $expectedCap = match (true) {
            $expectedLevel === DetectionChain::LEVEL_TRUECOLOR => Capability::TrueColor,
            $expectedLevel === DetectionChain::LEVEL_ANSI256 => Capability::Color256,
            $expectedLevel === DetectionChain::LEVEL_ANSI => Capability::Color16,
            $expectedLevel === DetectionChain::LEVEL_NONE => Capability::NoColor,
            $isSuppression => Capability::NoColor,
            default => null, // falsy-force ascii: floor-only answer
        };
        if ($expectedCap !== null) {
            $this->assertTrue($report->has($expectedCap), "terminal probe lost {$expectedCap->value} for the same env");
            $this->assertSame(
                $chain->source(),
                $report->source($expectedCap),
                'the shared ladder must also share its provenance string',
            );
        } else {
            $this->assertFalse($report->has(Capability::Color16), 'ascii floor must not claim a color rung');
            $this->assertTrue($report->has(Capability::BasicAscii));
        }
    }

    public function testSynchronousProbeHonoursForceColorFromAmbientEnv(): void
    {
        // DM6: Probe used to pre-check only CLICOLOR_FORCE, so FORCE_COLOR=3
        // answered truecolor on Palette but ansi on Probe. Both now ride the
        // chain — prove it end-to-end on the ambient layer (Probe has no
        // injected map: that is the one surface the resolution reads ambient).
        $this->arm('FORCE_COLOR', '3');
        $this->arm('TERM', 'xterm');
        Probe::_reset();

        $this->assertSame(ColorProfile::TrueColor, Probe::colorProfile());
    }

    public function testItermLegIsGatedByTheStreamLikeEveryOtherRung(): void
    {
        // DM4: the iTerm special case used to short-circuit BEFORE the TTY
        // gate, so a piped iTerm process emitted full 24-bit SGR. Env-only
        // detection (stream null) keeps answering truecolor; a real non-TTY
        // handle must now suppress it like every other profile.
        $env = ['TERM_PROGRAM' => 'iTerm.app', 'TERM' => 'xterm'];
        $this->assertSame(ColorProfile::TrueColor, Palette::detect(null, $env));

        $pipe = fopen('php://memory', 'rb');
        $this->assertNotFalse($pipe);
        try {
            $this->assertSame(
                ColorProfile::NoTTY,
                Palette::detect($pipe, $env),
                'non-TTY handle must gate the iTerm leg too',
            );
        } finally {
            fclose($pipe);
        }
    }

    public function testHostileTermValueIsSanitizedInProvenance(): void
    {
        // DM19: TERM is attacker-influenced over SSH. An escape-sequence blob
        // may never ride a source string into diagnostics, on either side.
        $hostile = "xterm\x1b[2J\x1b]0;pwned\x07" . str_repeat('evil', 2000);
        $chain = DetectionChain::detect(['TERM' => $hostile]);

        $this->assertSame(DetectionChain::LEVEL_ANSI, $chain->level());
        $this->assertLessThanOrEqual(128, strlen($chain->source()));
        $this->assertStringNotContainsString("\x1b", $chain->source());
        $this->assertStringNotContainsString("\x07", $chain->source());

        $belt = new ProbeReport([Capability::Color16->value => 'env:TERM=' . $hostile]);
        $source = $belt->source(Capability::Color16);
        $this->assertIsString($source);
        $this->assertLessThanOrEqual(128, strlen($source));
        $this->assertStringNotContainsString("\x1b", $source);
    }

    public function testEscapeLoadedColortermDoesNotUpgrade(): void
    {
        // Matching is exact-equality on the LOWERCASED value, so a COLORTERM
        // carrying escape bytes or suffix junk is malformed and must NOT
        // promote the rung — fail closed to the ladder's honest answer.
        $chain = DetectionChain::detect(['COLORTERM' => "truecolor\x1b[31m", 'TERM' => 'xterm']);
        $this->assertSame(DetectionChain::LEVEL_ANSI, $chain->level());
        $this->assertStringNotContainsString("\x1b", $chain->source());
    }

    public function testSanitizeRepairsOrphanLeadBytesIntoValidUtf8(): void
    {
        // [M8] The old byte-wise control sweep stripped the 0x82 inside `€`
        // (E2 82 AC) and let orphaned lead bytes of a truncated sequence
        // survive — the result failed mb_check_encoding() and broke
        // json_encode() of any probe report carrying it.
        $orphan = Env::sanitize("abc\xE2\x80");
        $this->assertTrue(
            mb_check_encoding($orphan, 'UTF-8'),
            'orphan lead bytes must not survive: ' . bin2hex($orphan),
        );
        $this->assertNotFalse(json_encode(['term' => $orphan]));

        $euro = Env::sanitize("\u{20AC}");
        $this->assertSame(
            "\u{20AC}",
            $euro,
            'C1 is matched by CODE POINT now: a valid character merely CONTAINING a 0x82 byte survives',
        );

        $mixed = Env::sanitize("caf\u{E9}\x1b[31m tail");
        $this->assertTrue(mb_check_encoding($mixed, 'UTF-8'));
        $this->assertStringNotContainsString("\x1b", $mixed);
        $this->assertStringContainsString('caf', $mixed);
    }

    public function testSanitizeCapLandsOnCharacterBoundary(): void
    {
        // The 128-byte cap used plain substr(), which could split a
        // multibyte sequence straddling the boundary — the same invalidity
        // the sweep itself used to introduce.
        $straddle = str_repeat('a', 126) . "\u{20AC}x";
        $capped = Env::sanitize($straddle);
        $this->assertLessThanOrEqual(128, strlen($capped));
        $this->assertTrue(mb_check_encoding($capped, 'UTF-8'), 'cap must not orphan a straddling sequence');
        $this->assertSame(str_repeat('a', 126), $capped, 'the cap backs off to the character boundary');
    }

    public function testSanitizeIsByteIdenticalForCleanInput(): void
    {
        // Regression: the common path stays a plain control sweep plus cap.
        $this->assertSame('xterm-256color', Env::sanitize('xterm-256color'));
        $this->assertSame('', Env::sanitize("\x00\x1F\x7F"));
        $this->assertSame('', Env::sanitize(''));
        $this->assertSame(str_repeat('b', 128), Env::sanitize(str_repeat('b', 200)));
        $this->assertSame('', Env::sanitize("\u{0085}"), 'C1 code points (NEL) still go');
    }

    public function testAmbientMergeLetsGetenvWinOnSharedKeys(): void
    {
        // resolve() merges as array_merge($_ENV, getenv()): the getenv()
        // side is the right operand, so on a key BOTH sources carry the
        // putenv()-made value wins outright — not merely where $_ENV
        // lacks the key (the reading an earlier docblock claimed). setUp
        // cleared the ambient TERM, so this is a clean dual-write.
        $_ENV['TERM'] = 'xterm';
        putenv('TERM=screen');
        try {
            $resolved = Env::resolve([]);
            $this->assertSame('screen', $resolved['TERM'], 'getenv wins every shared key');
            // The chain reads through the same merge, so its TERM agrees.
            $this->assertSame('screen', DetectionChain::detect([])->term());
        } finally {
            putenv('TERM');
            unset($_ENV['TERM']);
        }
    }
}
