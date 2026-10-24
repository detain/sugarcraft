<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg\ColorProfileMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Top\App;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Theme\ThemeConfig;
use SugarCraft\Top\Theme\TtyTheme;
use SugarCraft\Top\Tests\Support\Harness;
use SugarCraft\Top\View\Logo;

/**
 * The ambient colour profile sugar-core hands in through ColorProfileMsg
 * is consumed by App and routed into the Ink (docs-fix-1023 H3): NO_COLOR
 * steps the frame down to decoration-only, TERM=dumb drops colour escapes
 * entirely, and FORCE_COLOR / CLICOLOR_FORCE rescues the terminal out of
 * the refusal — the config depth (tty_mode / truecolor) otherwise stands,
 * because the environment may refuse colour but never chooses its depth.
 *
 * Deliberately NOT a golden test: the goldens would embed the ambient env
 * they were recorded under. These pins assert the resolved profile and the
 * escape strings the Ink hands the view, so the default (no-message) path
 * stays byte-identical to the whole golden corpus.
 */
final class AmbientColorProfileTest extends TestCase
{
    private string $logoEnv;

    protected function setUp(): void
    {
        // The logo re-pick rides the profile swap; pin the legacy banner so
        // no stray CANDY_TOP_LOGO env can make pick() resolve art here
        // (the same law OverlayGoldenTest pins ahead of its datasets).
        $this->logoEnv = (string) (getenv('CANDY_TOP_LOGO') ?: '');
        putenv('CANDY_TOP_LOGO=off');
        Logo::resetForTesting();
    }

    protected function tearDown(): void
    {
        putenv($this->logoEnv === '' ? 'CANDY_TOP_LOGO' : 'CANDY_TOP_LOGO=' . $this->logoEnv);
        Logo::resetForTesting();
    }

    public function testTheFrameStartsOnTheConfigDepthBeforeAnyAmbientMessage(): void
    {
        $app = Harness::app();

        $this->assertSame(ColorProfile::TrueColor, $app->ink->profile());
        $this->assertNotSame('', $app->ink->fg('main_fg'));
    }

    /** NO_COLOR through the real ladder, not a hand-fed tier: the wiring promise. */
    public function testNoColorStepsTheFrameDownToDecorationOnly(): void
    {
        $ambient = ColorProfile::detect([
            'NO_COLOR'     => '1',
            'TERM'         => 'xterm-256color',
            'COLORTERM'    => 'truecolor',
            'TERM_PROGRAM' => '',
        ]);
        $this->assertSame(ColorProfile::Ascii, $ambient, 'the ladder itself must answer Ascii for NO_COLOR');

        [$app] = Harness::app()->update(new ColorProfileMsg($ambient));

        $this->assertSame(ColorProfile::Ascii, $app->ink->profile());
        // Colour escapes are gone; the view still gets its (uncoloured) cells.
        $this->assertSame('', $app->ink->fg('main_fg'));
        $this->assertSame('', $app->ink->bg('main_bg'));
        $this->assertSame('', $app->ink->gradient('cpu', 50));
    }

    public function testDumbTerminalRefusesColourEntirely(): void
    {
        $ambient = ColorProfile::detect(['TERM' => 'dumb']);
        $this->assertSame(ColorProfile::NoTty, $ambient);

        [$app] = Harness::app()->update(new ColorProfileMsg($ambient));

        $this->assertSame(ColorProfile::NoTty, $app->ink->profile());
        $this->assertSame('', $app->ink->fg('main_fg'));
    }

    public function testForceColorRescuesTheRefusalAndTheConfigDepthStands(): void
    {
        $ambient = ColorProfile::detect(['TERM' => 'dumb', 'FORCE_COLOR' => '1']);
        $this->assertSame(ColorProfile::TrueColor, $ambient, 'the ladder itself must rescue force out of NoTty');

        [$app] = Harness::app()->update(new ColorProfileMsg($ambient));

        // Not a veto tier: the config-derived depth (TrueColor here) stands.
        $this->assertSame(ColorProfile::TrueColor, $app->ink->profile());
        $this->assertNotSame('', $app->ink->fg('main_fg'));
    }

    /** The environment may refuse colour, never choose its depth: no upgrades. */
    public function testAForcedTrueColorAmbientNeverUpgradesASteppedDownConfig(): void
    {
        $config = Config::new()->with('color_theme', 'Default')->with('truecolor', false);
        $app = App::start($config, ThemeConfig::new(), Harness::host(), []);

        $this->assertSame(ColorProfile::Ansi256, $app->ink->profile(), 'truecolor=false starts the frame at 256');

        [$app] = $app->update(new ColorProfileMsg(ColorProfile::detect(['TERM' => 'vt100', 'CLICOLOR_FORCE' => '1'])));

        $this->assertSame(ColorProfile::Ansi256, $app->ink->profile(), 'a TrueColor ambient leaves the config depth alone');
    }

    /** An ordinary terminal with no explicit env signal: config depth stands. */
    public function testAMidTierAmbientLeavesTheConfigDepthAlone(): void
    {
        $ambient = ColorProfile::detect(['TERM' => 'xterm-256color']);
        $this->assertSame(ColorProfile::Ansi256, $ambient);

        [$app] = Harness::app()->update(new ColorProfileMsg($ambient));

        $this->assertSame(ColorProfile::TrueColor, $app->ink->profile());
    }

    /** Startup consumption must survive repaints, resizes, theme cycles and config edits. */
    public function testTheVetoSurvivesRepaintsThemeCyclesAndConfigEdits(): void
    {
        [$app] = Harness::app()->update(new ColorProfileMsg(ColorProfile::Ascii));
        [$app] = $app->update(new WindowSizeMsg(120, 40));
        $this->assertSame(ColorProfile::Ascii, $app->ink->profile(), 'a resize repaint must keep the veto');

        $app = $app->withPalette(TtyTheme::new());
        $this->assertSame(ColorProfile::Ascii, $app->ink->profile(), 'theme cycling must keep the veto');

        // A truecolor flip under the veto changes nothing observable: the
        // ambient pins both sides of applyConfig's comparison to Ascii, so
        // no ink swap and no logo re-pick — the one-shot ambient persists.
        [$flipped] = $app->applyConfig($app->config->with('truecolor', false));
        $this->assertSame(ColorProfile::Ascii, $flipped->ink->profile());

        [$tty] = $app->applyConfig($app->config->with('tty_mode', true));
        $this->assertSame(ColorProfile::Ascii, $tty->ink->profile(), 'tty_mode must not out-veto NO_COLOR');
    }

    /** One-shot law: a repeated same-tier message repaints nothing new. */
    public function testARepeatedAmbientMessageIsIdempotent(): void
    {
        [$once] = Harness::app()->update(new ColorProfileMsg(ColorProfile::Ascii));
        [$twice] = $once->update(new ColorProfileMsg(ColorProfile::Ascii));

        $this->assertSame($once->ink, $twice->ink, 'the equality branch must reuse the Ink instance');
        $this->assertSame(ColorProfile::Ascii, $twice->ink->profile());
    }
}
