<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Tui\Renderer;
use SugarCraft\Style\Style;

/**
 * rv-devops-1 / rv-a11y-M1: the NO_COLOR / TERM=dumb / CLICOLOR_FORCE family
 * must apply to the WHOLE frame, not just PaneFrame's border glyphs. The
 * ladder itself lives in candy-core's ColorProfile::detect() (unit-pinned by
 * PaneFrameTest::testDetectionRoutesStandardCapabilityVariables); these tests
 * pin WHEN sugar-crush consults it and what the negotiated frame bytes look
 * like end-to-end through the real render path.
 */
final class AmbientColorProfileTest extends TestCase
{
    private ProviderInterface $provider;

    protected function setUp(): void
    {
        parent::setUp();
        Renderer::resetSizeCache();
        Renderer::resetColorProfileCacheForTesting();
        $this->provider = $this->createMock(ProviderInterface::class);
        $this->provider->method('name')->willReturn('TestProvider');
    }

    protected function tearDown(): void
    {
        Renderer::resetColorProfileCacheForTesting();
        Renderer::resetSizeCache();
        parent::tearDown();
    }

    private function shellFrame(): string
    {
        Renderer::setSize(120, 40);
        return Renderer::render(App::new($this->provider, 'test-model'), 120, 40);
    }

    /**
     * Every colour-bearing SGR parameter in $frame: 38/48/58 introduce a
     * direct-colour or palette form, 30-37/40-47/90-97/100-107 are the
     * named palette slots. Attribute one-numerals (1,3,4,5,7,9,53…) are NOT
     * colour parameters, so the check reads whole semicolon-separated
     * fields, never substrings.
     *
     * @return list<string> the offending SGR tokens, empty when the frame is colour-free
     */
    private static function colourSgrTokens(string $frame): array
    {
        $offending = [];
        foreach (explode("\x1b[",$frame) as $chunk) {
            if (!preg_match('/^([0-9;:]*)m/', $chunk, $m)) {
                continue;
            }
            foreach (preg_split('/[;:]/', $m[1]) as $param) {
                if ($param === '') {
                    continue;
                }
                $n = (int) $param;
                if ($n === 38 || $n === 48 || $n === 58
                    || ($n >= 30 && $n <= 37) || ($n >= 40 && $n <= 47)
                    || ($n >= 90 && $n <= 97) || ($n >= 100 && $n <= 107)) {
                    $offending[] = "\x1b[" . $m[1] . 'm';
                    break;
                }
            }
        }
        return $offending;
    }

    // =========================================================================
    // Negotiation timing (when the ladder is consulted)
    // =========================================================================

    public function testNegotiationOnAPipedStdoutLeavesTheAmbientAlone(): void
    {
        // The suite runs piped, so this is also the proof the whole existing
        // snapshot/golden corpus stays on today's TrueColor bytes.
        self::assertNull(Renderer::negotiateColorProfile(['NO_COLOR' => '1'], false));

        Renderer::applyAmbientColorProfile();
        self::assertNull(Style::getDefaultProfile(), 'piped stdout must not set an ambient profile');
    }

    public function testNegotiationRoutesTheEnvironmentLadderOnATty(): void
    {
        self::assertSame(ColorProfile::Ascii, Renderer::negotiateColorProfile(['NO_COLOR' => '1', 'TERM' => 'xterm'], true));
        self::assertSame(ColorProfile::NoTty, Renderer::negotiateColorProfile(['TERM' => 'dumb'], true));
        self::assertSame(ColorProfile::TrueColor, Renderer::negotiateColorProfile(['CLICOLOR_FORCE' => '1', 'TERM' => 'xterm'], true));
        // candy-core's ladder orders NO_COLOR ahead of the force vars; pin the
        // precedence as-is rather than relitigating it here.
        self::assertSame(ColorProfile::Ascii, Renderer::negotiateColorProfile(['NO_COLOR' => '1', 'CLICOLOR_FORCE' => '1'], true));
        self::assertSame(ColorProfile::TrueColor, Renderer::negotiateColorProfile(['COLORTERM' => 'truecolor', 'TERM' => 'xterm'], true));
        self::assertSame(ColorProfile::Ansi256, Renderer::negotiateColorProfile(['TERM' => 'xterm-256color'], true));
    }

    // =========================================================================
    // Frame bytes under a negotiated profile
    // =========================================================================

    public function testMonochromeAmbientFrameCarriesZeroColourSgr(): void
    {
        // Positive control first: today's default frame does contain colours,
        // so a passing negative below cannot be vacuous.
        $colourful = $this->shellFrame();
        self::assertStringContainsString('38;2;', $colourful);
        self::assertNotSame([], self::colourSgrTokens($colourful));

        Style::setDefaultProfile(ColorProfile::Ascii);
        $frame = $this->shellFrame();

        self::assertSame([], self::colourSgrTokens($frame), 'NO_COLOR-style frame must emit no colour SGR');
        // Text and structure survive: the frame is still the full 120x40 shell.
        self::assertStringContainsString('welcome to sugarcrush', strtolower($frame));
        self::assertStringContainsString('▢ chat', $frame);
    }

    public function testNoTtyAmbientFrameCarriesNoEscapeAtAll(): void
    {
        Style::setDefaultProfile(ColorProfile::NoTty);
        $frame = $this->shellFrame();

        self::assertStringNotContainsString("\x1b[", $frame);
        self::assertNotSame('', $frame);
    }

    public function testNullAmbientIsByteIdenticalToExplicitTrueColorAmbient(): void
    {
        Renderer::setSize(120, 40);
        $app = App::new($this->provider, 'test-model');
        $withTrueColorAmbient = Renderer::render($app, 120, 40);

        Style::setDefaultProfile(ColorProfile::TrueColor);
        $sameAppSecondPass = Renderer::render($app, 120, 40);

        self::assertSame($withTrueColorAmbient, $sameAppSecondPass);
        self::assertStringContainsString('38;2;', $sameAppSecondPass);
    }
}
