<?php

declare(strict_types=1);

namespace SugarCraft\Flappy\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\ColorProfileMsg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;
use SugarCraft\Flappy\Game;
use SugarCraft\Flappy\Tests\Support\UsesHermeticConfig;

/**
 * Terminal posture (lib-reviews-1102 M6/M7/M8/M26/m30): resize is data the
 * model carries, an unrenderable terminal is refused instead of shredded
 * with escape codes, the ASCII tier draws plain glyphs, and no status row
 * may exceed the live viewport.
 */
final class TerminalPostureTest extends TestCase
{
    use UsesHermeticConfig;

    private function sized(int $cols, int $rows): Game
    {
        $g = Game::start(static fn(int $_max): int => 0, $this->hermeticConfigDir());
        [$g,] = $g->update(new WindowSizeMsg($cols, $rows));
        return $g;
    }

    public function testWindowSizeMsgIsCarriedAsData(): void
    {
        $g = $this->sized(100, 40);
        $this->assertSame(100, $g->frameCols);
        $this->assertSame(40, $g->frameRows);
        // Exactly the minimum frame still renders the world, not the notice.
        $min = $this->sized(Game::MIN_COLS, Game::MIN_ROWS);
        $this->assertStringContainsString('score:', $min->view());
        $this->assertFalse($min->tooSmall());
    }

    public function testDefaultFrameBytesAreUntouched(): void
    {
        // Goldens run with a null profile and the default 62x21 seed; a
        // resize to exactly that must be byte-identical to no message at all.
        $plain = Game::start(static fn(int $_max): int => 0, $this->hermeticConfigDir());
        $sized = $this->sized(Game::MIN_COLS, Game::MIN_ROWS);
        $this->assertSame($plain->view(), $sized->view());
    }

    public function testTooSmallViewportGetsNoticeAndFreezesTheWorld(): void
    {
        $g = $this->sized(40, 10);
        $view = $g->view();
        $this->assertStringContainsString('needs a 62x21 terminal', $view);
        $this->assertStringContainsString('40x10', $view);
        $this->assertStringNotContainsString('score:', $view);
        // M6: nothing to see, nothing to simulate — the tick stands down.
        $this->assertSame([], $g->subscriptions()->all());
    }

    public function testGrowingBackResumesTheCadence(): void
    {
        [$g,] = $this->sized(40, 10)->update(new WindowSizeMsg(80, 24));
        $this->assertSame(80, $g->frameCols);
        $ids = [];
        foreach ($g->subscriptions()->all() as $s) {
            $ids[] = $s->id;
        }
        $this->assertSame([Game::TICK_SUBSCRIPTION], $ids);
        $this->assertStringContainsString('score:', $g->view());
    }

    public function testNoTtyProfileRefusesToPlay(): void
    {
        // flappy-02 (finding -002): the refusal frame carries a quit Cmd —
        // this test used to BAKE the hang in (assert view + empty subs and
        // nothing else, i.e. a game only Ctrl+C ends).
        [$g, $cmd] = Game::start(static fn(int $_max): int => 0, $this->hermeticConfigDir())
            ->update(new ColorProfileMsg(ColorProfile::NoTty));
        $this->assertStringContainsString('interactive terminal', $g->view());
        $this->assertStringNotContainsString("\x1b[", $g->view());
        $this->assertSame([], $g->subscriptions()->all());
        $this->assertNotNull($cmd, 'NoTty must dispatch a quit, not hang until killed');
        $this->assertInstanceOf(QuitMsg::class, $cmd());
    }

    public function testAsciiProfileDrawsPlainGlyphs(): void
    {
        $g = Game::start(static fn(int $_max): int => 0, $this->hermeticConfigDir());
        [$g,] = $g->update(new WindowSizeMsg(80, 24));
        [$g,] = $g->update(new ColorProfileMsg(ColorProfile::Ascii));
        $view = $g->view();
        $this->assertStringNotContainsString("\x1b[", $view);
        $this->assertStringContainsString('+------', $view);
        $this->assertStringNotContainsString('▓', $view);
        $this->assertStringNotContainsString('💥', $view);
        $this->assertStringContainsString('score:', $view);
    }

    public function testColourTiersRenderTheColourPath(): void
    {
        $control = Game::start(static fn(int $_max): int => 0, $this->hermeticConfigDir());
        foreach ([ColorProfile::Ansi, ColorProfile::Ansi256, ColorProfile::TrueColor] as $tier) {
            [$g,] = $control->update(new ColorProfileMsg($tier));
            // Byte-identical to the null-profile default: tiering only
            // changes posture for NoTty/Ascii; colour depth is delegated to
            // sugar-style's own degradation.
            $this->assertSame($control->view(), $g->view());
        }
    }

    public function testStatusLineNeverExceedsTheViewport(): void
    {
        $wide = new Game(
            bird: $this->sized(62, 24)->bird,
            pipes: [],
            score: 9999999,
            highScores: [987654],
            started: true,
            configDir: $this->hermeticConfigDir(),
        );
        $crashed = new Game(
            bird: $wide->bird,
            pipes: [],
            score: 9999999,
            crashed: true,
            highScores: [987654],
            newRecord: true,
            configDir: $this->hermeticConfigDir(),
        );
        foreach ([$wide, $crashed] as $g) {
            foreach (explode("\n", $g->view()) as $line) {
                $this->assertLessThanOrEqual($g->frameCols, Width::of($line));
            }
        }
        // The long hint survives on a comfortably wide frame:
        $roomy = new Game(
            bird: $wide->bird,
            pipes: [],
            score: 9999999,
            started: true,
            configDir: $this->hermeticConfigDir(),
            frameCols: 100,
        );
        $this->assertStringContainsString('space/⏎/↑/w flap', $roomy->view());
        $this->assertStringNotContainsString('space flap · q quit', $roomy->view());
    }

    public function testRestartCarriesViewportAndProfile(): void
    {
        $g = $this->sized(100, 40);
        [$g,] = $g->update(new ColorProfileMsg(ColorProfile::Ascii));
        $g = $g->tickN(80);
        $this->assertTrue($g->crashed);
        [$re, $cmd] = $g->update(new KeyMsg(KeyType::Char, 'r'));
        $this->assertNull($cmd);
        $this->assertSame(100, $re->frameCols);
        $this->assertSame(40, $re->frameRows);
        $this->assertSame(ColorProfile::Ascii, $re->colorProfile);
        // m30: it does NOT snap back to the 62x21 default.
        $this->assertFalse($re->tooSmall());
    }

    public function testRestartFromTooSmallStillShowsTheNotice(): void
    {
        $g = $this->sized(40, 10)->tickN(80);
        $this->assertTrue($g->crashed);
        [$re,] = $g->update(new KeyMsg(KeyType::Char, 'r'));
        $this->assertStringContainsString('needs a 62x21 terminal', $re->view());
    }
}
