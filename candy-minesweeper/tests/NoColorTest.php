<?php

declare(strict_types=1);

namespace SugarCraft\Minesweeper\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Minesweeper\Difficulty;
use SugarCraft\Minesweeper\Game;
use SugarCraft\Minesweeper\Renderer;
use SugarCraft\Style\Style;

/**
 * M12 (lib-reviews-1103): the board rendered full 24-bit colour regardless of
 * NO_COLOR / CLICOLOR=0 / TERM=dumb — no-color.org asks for colour OFF, and a
 * dumb terminal cannot carry the escapes at all. Two layers answer that ask:
 * the binary arms sugar-style's process-global default profile at boot (frame,
 * status, toast, footer), and the Renderer gates the CellBuffer board colours
 * on the same authority (CellBuffer knows nothing about capability — it just
 * writes the SGR bytes it is handed). The board glyphs are plain Unicode and
 * survive either way; bold/reverse decorations stay, because they are not
 * colour.
 */
final class NoColorTest extends TestCase
{
    public function testTheBinaryWiresTheFleetColourAuthority(): void
    {
        $bin = realpath(__DIR__ . '/../bin/candy-minesweeper');
        self::assertIsString($bin, 'sanity: binary exists');
        $source = (string) file_get_contents($bin);

        // The wiring is two statements: probe the authority, then apply ONLY
        // the downgrades — an upgrade would force a depth detection merely
        // tolerates. STDOUT is passed so detect() can see a pipe/redirect
        // (candy-minesweeper-002): without the handle its isatty rung never
        // runs and a redirected launch was classified by TERM alone.
        $this->assertStringContainsString('ColorProfile::detect(null, STDOUT)', $source,
            'the boot path must consult sugar-core ColorProfile with the stdout handle');
        $this->assertStringContainsString('Style::setDefaultProfile($profile)', $source,
            'a downgrade answer must arm the process-global style profile');
        $this->assertStringContainsString('$profile === ColorProfile::Ascii || $profile === ColorProfile::NoTty', $source,
            'only Ascii/NoTty may be applied at boot');
    }

    public function testDetectAnswersTheEnvArrayFormDeterministically(): void
    {
        // detect() probes stream_isatty ONLY when a stdout resource is passed;
        // with an explicit env array the answer comes from the variables alone,
        // which keeps these pins hermetic.
        $this->assertSame(ColorProfile::Ascii, ColorProfile::detect(['NO_COLOR' => '1']),
            'NO_COLOR is the no-color.org ask: colour off, escapes kept');
        $this->assertSame(ColorProfile::Ascii, ColorProfile::detect(['CLICOLOR' => '0']),
            'the BSD-family opt-out means the same');
        $this->assertSame(ColorProfile::NoTty, ColorProfile::detect(['TERM' => 'dumb']),
            'a dumb terminal gets no escapes at all');
    }

    public function testColoursAreStrippedWhenTheAuthoritySaysNoColour(): void
    {
        // Arm BOTH production layers exactly as the boot would: the board
        // knob (Renderer gate) and the process-global sugar-style default the
        // binary arms for frame/status/toast/footer.
        Renderer::setBoardColourOverride(false);
        Style::setDefaultProfile(ColorProfile::Ascii);
        try {
            $frame = Renderer::render(Game::withDifficulty(Difficulty::EASY, static fn(int $max): int => 0));

            $this->assertStringNotContainsString('38;2;', $frame,
                'no TrueColor sequences may survive a colour-refused boot');
            $this->assertStringContainsString('·', $frame,
                'board glyphs are plain Unicode and must render anyway');
            $this->assertStringContainsString('mines: 10', $frame,
                'the status line keeps its text under the downgrade');
            $this->assertStringContainsString("\x1b[0;7m", $frame,
                'decorations are not colour: the reverse cursor stays');
        } finally {
            Style::setDefaultProfile(null);
            Renderer::setBoardColourOverride(true); // suite default (tests/bootstrap.php)
        }
    }

    public function testBoardRendersTheFullChainByDefault(): void
    {
        $frame = Renderer::render(Game::withDifficulty(Difficulty::EASY, static fn(int $max): int => 0));

        $this->assertStringContainsString('38;2;', $frame,
            'sanity for the pin above: a capable terminal keeps the colours');
        $this->assertStringContainsString('38;2;90;74;120m', $frame,
            'hidden cells keep the documented dim violet');
    }
}
