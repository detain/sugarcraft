<?php

declare(strict_types=1);

namespace SugarCraft\Vterm\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Testing\Snapshot\Assertions;
use SugarCraft\Vterm\Terminal;
use SugarCraft\Vterm\Tests\Support\RenditionOracle;

/**
 * Byte-fixture replay corpus (vterm-33, finding sugar-vterm-015).
 *
 * CALIBER_LEARNINGS long promised `tests/fixtures/*.ansi` raw byte
 * captures replayed against cell/mode expectations; the corpus never
 * existed. It lands here: five small hostile-but-realistic ANSI streams
 * (scroll+wrap, OSC-heavy, combining+wide, DECSTBM+DECSCUSR — whose
 * 1;12 region clips against the replay geometry's bottom row on
 * purpose — and OSC 52), each replayed through the renderer and baselined
 * as a full rendition dump through the SAME oracle vterm-31's goldens
 * use. This is the landing pad the docs promised for "add hostile bytes
 * as a regression": new attack-shaped streams become a byte file plus a
 * one-way golden re-pin, never a bespoke assertion.
 *
 * Regenerating a fixture: write the bytes with `printf`/php, not an
 * editor (BOM/newline normalisers corrupt ANSI captures), then delete
 * corpus-replay.golden and run
 *   SUGAR_TESTING_UPDATE_GOLDENS=1 vendor/bin/phpunit --filter FuzzCorpusTest
 * The root `.gitattributes` `*.ansi -text` rule keeps the bytes verbatim
 * through checkout — it earns its place here.
 */
final class FuzzCorpusTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/fixtures';

    public function testReplayCorpusMatchesTheRenditionOracleBaseline(): void
    {
        $paths = glob(self::FIXTURES . '/*.ansi');
        self::assertIsArray($paths);
        sort($paths);
        self::assertGreaterThanOrEqual(5, count($paths), 'the promised byte-fixture corpus exists');

        $golden = '';
        $titles = [];
        foreach ($paths as $path) {
            $name = basename($path, '.ansi');
            $bytes = (string) file_get_contents($path);
            $terminal = Terminal::new(20, 6);
            $terminal->feed($bytes);
            $title = $terminal->windowTitle();
            $titles[$name] = $title;
            $golden .= "## $name (" . strlen($bytes) . " bytes)\n"
                . RenditionOracle::dumpGrid($terminal->grid())
                . sprintf("#cursor %d,%d\n", $terminal->cursor()->row, $terminal->cursor()->col)
                . '#title ' . strlen($title) . ' ' . sha1($title) . "\n";
        }

        Assertions::assertGoldenAnsi(self::FIXTURES . '/corpus-replay.golden', $golden);

        // Goalpost-proof truths independent of the file, so a blind
        // update-and-commit re-baseline cannot quietly move them: the OSC 0
        // title landed, and the combining stream kept e+U+0301 as one
        // grapheme cluster (the parser segments clusters; a renderer that
        // took only the base would dump a bare 'e' and lose the accent).
        self::assertSame('Drain Lane Title', $titles['osc-heavy'] ?? null);
        $combining = Terminal::new(20, 6);
        $combining->feed((string) file_get_contents(self::FIXTURES . '/combining-wide.ansi'));
        $dump = RenditionOracle::dumpGrid($combining->grid());
        self::assertStringContainsString("e\xCC\x81|7|0", $dump, 'the accented cluster survives segmentation');
        self::assertGreaterThanOrEqual(2, substr_count($dump, '·CONT'), 'both wide glyphs keep their continuation half');
    }
}
