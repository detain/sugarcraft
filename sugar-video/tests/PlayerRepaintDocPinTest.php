<?php

declare(strict_types=1);

namespace SugarCraft\Video\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Renderer;
use SugarCraft\Video\Player;
use SugarCraft\Video\Reel;

/**
 * E-08: the Player docblocks must tell the verified truth about the
 * framework diff, and the truth must be re-derived from the sugar-core
 * Renderer source — never pinned as prose alone (plan_chafa §4.10 doc-pin
 * law).
 *
 * The survey (chafa_improvemnts.md E-08, correction PC-3) claimed sugar-core
 * performs NO frame differencing and that the Player docblock asserting the
 * framework "already diffs" was a verbatim lie. Disk refutes that: sugar-core
 * src/Renderer.php is titled "Frame-diff renderer", has compared consecutive
 * frames via $lastLines since candy-core 7203924a4 (2026-05-02), and Reel
 * runs the Player through Program, which constructs that Renderer. The
 * survey's grep missed the phrase only because the class docblock wraps
 * "previously rendered" across a newline. The docblock's real defect is the
 * GRANULARITY overclaim ("minimal terminal update") — the default path
 * repaints whole changed ROWS; finer cell-diff is opt-in. These pins keep
 * that precision from regrowing in either direction: neither the old
 * "minimal" overclaim, nor the PC-3 "full repaint / no diff engine" wording,
 * can re-enter the docblocks.
 */
final class PlayerRepaintDocPinTest extends TestCase
{
    /**
     * Phrases the Player docblocks may NEVER contain: the old granularity
     * overclaim (both spellings) and the refuted-PC-3 flip wording.
     *
     * @return array<string, array{0: string, 1: string}> label => [needle, file-role]
     */
    private const FORBIDDEN_DOCCLAIMS = [
        'old minimal-update overclaim' => 'emits only the minimal terminal update',
        'old minimal-write overclaim' => 'writes only the minimal terminal update',
        'refuted no-diff-engine flip' => 'does not diff',
        'refuted full-repaint flip' => 'is repainted in full',
    ];

    private function playerSource(): string
    {
        $file = (new \ReflectionClass(Player::class))->getFileName();
        $this->assertIsString($file, 'Player must be autoloadable from its own source file');
        $src = file_get_contents($file);
        $this->assertNotFalse($src, 'Player source must be readable');
        return $src;
    }

    /**
     * Docblock-aware flatten: collapse whitespace runs, then dissolve the
     * " * " line prefixes so needles wrapped across docblock lines match as
     * single phrases (the same wrap-artifact that hid the sugar-core
     * Renderer's "previously rendered" phrase from the survey's grep).
     */
    private function flattenedPlayerSource(): string
    {
        $flat = (string) preg_replace('/\s+/', ' ', $this->playerSource());
        return (string) preg_replace('/ \* /', ' ', $flat);
    }

    public function testThePlayerDocblocksCarryNoForbiddenDiffClaims(): void
    {
        $flat = $this->flattenedPlayerSource();

        foreach (self::FORBIDDEN_DOCCLAIMS as $label => $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $flat,
                "the Player docblock must not {$label}: '$needle' is a claim this pin keeps out"
            );
        }
    }

    public function testThePlayerDocblocksStateTheRowGranularityTruth(): void
    {
        $flat = $this->flattenedPlayerSource();

        // The replacement wording must exist in BOTH sites (class docblock and
        // view()), so count >= 2 for each anchor.
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($flat, 're-sends only the rows that changed'),
            'both docblocks must state the row-granularity diff truth'
        );
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($flat, 'opt-in'),
            'both docblocks must disclose that cell-granularity diffing is opt-in'
        );
    }

    /**
     * The claims above must be TRUE of the framework: derive them from the
     * autoloaded sugar-core Renderer source and the wiring, not from prose.
     */
    public function testTheFrameworkDiffClaimIsDerivedFromTheRendererSource(): void
    {
        $file = (new \ReflectionClass(Renderer::class))->getFileName();
        $this->assertIsString($file, 'sugar-core Renderer must resolve to a source file');
        $src = (string) file_get_contents($file);

        // Mechanism exists: identical frames are skipped, changed output is
        // emitted per row.
        $this->assertStringContainsString('$this->lastLines === $lines', $src);
        $this->assertStringContainsString('function diffLines', $src);
        $this->assertStringContainsString('function diffCells', $src);

        // The docblock's "opt-in" qualifier is derived, not decorative: the
        // constructor default must still be the row-diff path.
        $this->assertMatchesRegularExpression('/bool \$cellDiff = false/', $src);

        // And the Player's output really flows through this Renderer:
        // Program constructs it, and Reel runs the Player through Program.
        $programSrc = (string) file_get_contents(dirname($file) . '/Program.php');
        $this->assertStringContainsString('new Renderer(', $programSrc);
        $reelSrc = (new \ReflectionClass(Reel::class))->getFileName();
        $this->assertStringContainsString(
            'new Program(',
            (string) file_get_contents((string) $reelSrc),
            'Reel must keep driving the Player through the TEA Program (the wiring this doc-pin relies on)'
        );
    }
}
