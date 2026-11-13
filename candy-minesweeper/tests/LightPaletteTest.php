<?php

declare(strict_types=1);

namespace SugarCraft\Minesweeper\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Minesweeper\Difficulty;
use SugarCraft\Minesweeper\Game;
use SugarCraft\Minesweeper\Renderer;
use SugarCraft\Minesweeper\Stats;
use SugarCraft\Style\Style;

/**
 * msw-06 (gate G2, ruling arms a+c): COLORFGBG declares the terminal's
 * background index, and index 7 is the classic light grey/white. Every
 * dark-host ink that would wash out there has a darkened twin, so a light
 * host must NEVER see a sub-4.5:1 foreground and a dark host must keep
 * seeing byte-identical frames (the goldens pin the dark path; these legs
 * own the light one and the probe itself).
 */
final class LightPaletteTest extends TestCase
{
    private string|false $savedColorfBg;

    protected function setUp(): void
    {
        $this->savedColorfBg = getenv('COLORFGBG');
    }

    protected function tearDown(): void
    {
        $this->savedColorfBg === false
            ? putenv('COLORFGBG')
            : putenv('COLORFGBG=' . $this->savedColorfBg);
    }

    /** Probe the private rung directly — the matrix is bigger than what renders can witness. */
    private static function probe(): bool
    {
        $m = new \ReflectionMethod(Renderer::class, 'lightBackground');
        $m->setAccessible(true);
        return (bool) $m->invoke(null);
    }

    public function testProbeMatrix(): void
    {
        $cases = [
            '0;7'     => true,   // bottom field is 7 regardless of fg
            '15;7'    => true,   // the canonical xterm light report
            '7;7'     => true,
            '7'       => true,   // single field is still the bottom field
            '15;0'    => false,  // the canonical dark report
            '0;0'     => false,
            '15;15'   => false,  // "default" background, treated as dark
            '8'       => false,
            ''        => false,  // declared-empty is malformed, not light
            'nope'    => false,  // declared-garbage cannot crash the probe
            '15;7 '   => false,  // trailing space is not the digits-only bottom field
        ];
        foreach ($cases as $value => $expectLight) {
            putenv('COLORFGBG=' . $value);
            $this->assertSame($expectLight, self::probe(), "COLORFGBG={$value}");
        }
        putenv('COLORFGBG');
        $this->assertFalse(self::probe(), 'unset must stay dark');
    }

    /**
     * Deterministic exploded Easy fixture: opener → a revealed digit (if the
     * flood left one), a flag planted on a safe cell (wrong once lost), then
     * detonation, so one frame exercises digits, F, ✗, missed mines, the
     * hidden dot, the boom banner, the help footer and a toast.
     */
    private function explodedFixture(): Game
    {
        $g = Game::withDifficulty(Difficulty::Easy, static fn(int $max): int => 0);
        [$g, ] = $g->update(new KeyMsg(KeyType::Space));

        $b = $g->board;
        // Flag one hidden safe cell (it becomes a wrong flag on the loss).
        for ($y = 0; $y < $b->height; $y++) {
            for ($x = 0; $x < $b->width; $x++) {
                $c = $b->cell($x, $y);
                if ($c !== null && !$c->mine && !$c->revealed) {
                    $g = self::driveTo($g, $x, $y);
                    [$g, ] = $g->update(new KeyMsg(KeyType::Char, 'f'));
                    break 2;
                }
            }
        }
        // Flag one mine (it stays a CORRECT gold F through the loss), then…
        $b = $g->board;
        for ($y = 0; $y < $b->height; $y++) {
            for ($x = 0; $x < $b->width; $x++) {
                $c = $b->cell($x, $y);
                if ($c !== null && $c->mine && !$c->revealed && !$c->flagged) {
                    $g = self::driveTo($g, $x, $y);
                    [$g, ] = $g->update(new KeyMsg(KeyType::Char, 'f'));
                    break 2;
                }
            }
        }
        // …detonate a DIFFERENT unrevealed mine.
        $b = $g->board;
        for ($y = 0; $y < $b->height; $y++) {
            for ($x = 0; $x < $b->width; $x++) {
                $c = $b->cell($x, $y);
                if ($c !== null && $c->mine && !$c->revealed && !$c->flagged) {
                    $g = self::driveTo($g, $x, $y);
                    [$g, ] = $g->update(new KeyMsg(KeyType::Space));
                    break 2;
                }
            }
        }
        $this->assertTrue($g->board->exploded, 'sanity: the fixture detonated');
        return new Game(
            $g->board,
            $g->cursorX,
            $g->cursorY,
            notice: 'toast line',
            stats: new Stats(),
            frameCols: 120,
        );
    }

    private static function driveTo(Game $g, int $x, int $y): Game
    {
        while ($g->cursorX !== $x) {
            [$g, ] = $g->update(new KeyMsg($g->cursorX < $x ? KeyType::Right : KeyType::Left));
        }
        while ($g->cursorY !== $y) {
            [$g, ] = $g->update(new KeyMsg($g->cursorY < $y ? KeyType::Down : KeyType::Up));
        }
        return $g;
    }

    public function testUnsetAndDarkReportsRenderByteIdentically(): void
    {
        putenv('COLORFGBG=15;0');
        $dark = $this->explodedFixture()->view();
        putenv('COLORFGBG');
        $unset = $this->explodedFixture()->view();
        $this->assertSame($dark, $unset, 'a host that never declares light must see the shipped dark bytes');
    }

    public function testDarkHostKeepsTheShippedInks(): void
    {
        putenv('COLORFGBG=0;0');
        $view = $this->explodedFixture()->view();
        // The help footer always paints #7d6e98 = 125;110;152 on dark hosts.
        $this->assertStringContainsString('38;2;125;110;152', $view, 'dark host keeps the grape footer');
        $this->assertStringNotContainsString('38;2;91;74;127', $view, 'the light-only twin must not leak onto dark hosts');
    }

    public function testLightHostEveryInkClearsContrastBar(): void
    {
        putenv('COLORFGBG=15;7');
        $view = $this->explodedFixture()->view();

        preg_match_all('/38;2;(\d+);(\d+);(\d+)/', $view, $m, PREG_SET_ORDER);
        $this->assertNotEmpty($m, 'sanity: the fixture paints 24-bit ink');
        foreach ($m as $ink) {
            $ratio = self::contrastOnWhite((int) $ink[1], (int) $ink[2], (int) $ink[3]);
            $this->assertGreaterThanOrEqual(
                4.5,
                $ratio,
                \sprintf('#%02x%02x%02x washes out on white at %.2f:1', (int) $ink[1], (int) $ink[2], (int) $ink[3], $ratio),
            );
        }
    }

    public function testLightHostSwapsToTheDarkenedTwins(): void
    {
        putenv('COLORFGBG=15;7');
        $view = $this->explodedFixture()->view();
        $this->assertStringContainsString('38;2;91;74;127', $view, 'help/footer rides #5b4a7f');
        $this->assertStringContainsString('38;2;133;77;14', $view, 'toast rides #854d0e');
        $this->assertStringContainsString('38;2;190;18;60', $view, 'the boom banner rides #be123c');
        $this->assertStringContainsString('38;2;180;83;9', $view, 'a flag rides #b45309');
        $this->assertStringNotContainsString('38;2;125;110;152', $view, 'dark grape must not survive on a light host');
        $this->assertStringNotContainsString('38;2;253;230;138', $view, 'lemon toast/flag must not survive on a light host');
    }

    public function testColourStripStillWinsOverTheLightArm(): void
    {
        // Armed exactly as the binary arms a colour-refused boot: the board
        // knob AND the process-global style downgrade — the light arm may
        // not smuggle colour past either of them.
        putenv('COLORFGBG=15;7');
        Renderer::setBoardColourOverride(false);
        Style::setDefaultProfile(ColorProfile::Ascii);
        try {
            $view = $this->explodedFixture()->view();
        } finally {
            Style::setDefaultProfile(null);
            Renderer::setBoardColourOverride(true);
        }
        $this->assertStringNotContainsString('38;2;', $view, 'an explicit no-colour host paints no 24-bit ink light or dark');
    }

    /** WCAG 2.1 contrast of an 8-bit colour against #ffffff. */
    private static function contrastOnWhite(int $r, int $g, int $b): float
    {
        $channel = static function (int $v): float {
            $s = $v / 255;
            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        };
        $luminance = 0.2126 * $channel($r) + 0.7152 * $channel($g) + 0.0722 * $channel($b);
        return (1.0 + 0.05) / ($luminance + 0.05);
    }
}
