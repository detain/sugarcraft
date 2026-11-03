<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\ColorProfileMsg;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Git\App;
use SugarCraft\Git\Renderer;
use SugarCraft\Style\Style;

/**
 * Audit A28: the startup ColorProfileMsg (sugar-core Program dispatches it
 * from ColorProfile::detect() - NO_COLOR / TERM / tty capability) was
 * silently dropped by App::update(), so candy-git painted TrueColor hexes
 * even on terminals that asked for plain ANSI or no colour at all.
 *
 * The profile lives in sugar-style's process-global default (Style::new()
 * reads it), NOT in the model - update() adopts it and returns the same App.
 */
final class ColorProfileTest extends TestCase
{
    protected function tearDown(): void
    {
        // Never leak a mutated global profile into sibling tests.
        Style::setDefaultProfile(null);
    }

    private static function app(): App
    {
        return new App(
            new FixtureGit(
                [['path' => 'src/A.php', 'index_status' => 'M', 'work_status' => ' ']],
                [],
                [],
            ),
            width: 80,
            height: 24,
        );
    }

    public function testAdoptingTheProfileLeavesTheModelUntouched(): void
    {
        $a = self::app();
        [$b, $cmd] = $a->update(new ColorProfileMsg(ColorProfile::Ansi256));

        $this->assertSame($a, $b, 'the model must not change - profile is render state');
        $this->assertNull($cmd);
        $this->assertSame(ColorProfile::Ansi256, Style::defaultProfile());
    }

    public function testDowngradedProfileStripsTrueColorBytesFromTheFrame(): void
    {
        Style::setDefaultProfile(null);
        $trueColor = Renderer::render(self::app());
        $this->assertGreaterThan(0, substr_count($trueColor, "\x1b[38;2;"), 'baseline paints hex colours as 24-bit');

        $a = self::app();
        $a->update(new ColorProfileMsg(ColorProfile::Ascii));
        $ascii = Renderer::render($a);

        $this->assertSame(0, substr_count($ascii, "\x1b[38;2;"), 'no 24-bit SGR may survive an Ascii profile');
        $this->assertStringContainsString('CandyGit', $ascii, 'the frame still renders its content');
    }

    public function testUnknownMessagesStillNoOp(): void
    {
        $stray = new class implements Msg {};
        $a     = self::app();
        [$b, $cmd] = $a->update($stray);

        $this->assertSame($a, $b);
        $this->assertNull($cmd);
        $this->assertNull(Style::defaultProfile(), 'a stray message must not adopt a profile');
    }
}
