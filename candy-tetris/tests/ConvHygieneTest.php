<?php

/**
 * candy-tetris/tests/ConvHygieneTest.php
 */

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Convention hygiene, pinned (candy-tetris-076/-078/-080): the shapes a
 * reviewer should never have to re-find. Files here fail loudly at the
 * convention, not three reads later.
 *
 *  - declare-first: `declare(strict_types=1);` is the first statement of
 *    every src file — the leading docblock rides AFTER it (the P2-era
 *    KeyMap shape was the verified pattern; five Support files predated
 *    it and are now reflowed).
 *  - every private function carries its own header: no orphaned
 *    docblocks adrift above the wrong body, no headerless methods (the
 *    pre-MA-23 block sat above handleIntro while stepGame ran bare).
 *  - Game::mutate is public BY CONTRACT, not by accident: the docblock
 *    carries @internal, names the one friend that blocks protected
 *    (VsGame's seat bookkeeping), and this census proves the friend
 *    sites are still there — delete them, and the honest next step is
 *    visibility, which this test will then demand a rewrite of, not a
 *    silent drift.
 */
final class ConvHygieneTest extends TestCase
{
    /** @return list<string> */
    private static function srcFiles(): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../src'));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        self::assertNotEmpty($files, 'the src census found no files at all');

        return $files;
    }

    public function testEverySrcFileDeclaresStrictTypesAsItsFirstStatement(): void
    {
        foreach (self::srcFiles() as $file) {
            $lines = explode("\n", (string) file_get_contents($file));
            $name = basename($file);
            self::assertSame('<?php', $lines[0], "$name must open with the PHP tag");
            self::assertSame('', $lines[1], "$name keeps one blank line after the tag");
            self::assertSame(
                'declare(strict_types=1);',
                $lines[2],
                "$name must declare strict types before any docblock or namespace (candy-tetris-080)",
            );
        }
    }

    public function testTheOrphanedDispatchHeaderCameHomeAndMutateCarriesItsContract(): void
    {
        $game = (string) file_get_contents(__DIR__ . '/../src/Game.php');

        // -076: the pre-MA-23 block moved to the body it describes.
        self::assertStringNotContainsString(
            'pre-MA-23 dispatch body, unchanged',
            $game,
            'the orphaned header must not float above handleIntro again (candy-tetris-076)',
        );
        $home = strpos($game, 'The live dispatch body');
        $step = strpos($game, 'private function stepGame');
        self::assertNotFalse($home, 'stepGame must carry the dispatch header it was orphaned from');
        self::assertNotFalse($step);
        self::assertLessThan($step, $home, 'the header sits above its own function');
        self::assertLessThan(400, $step - $home, 'the header belongs to stepGame, not to some body between');

        // -078: the contract is written where the visibility cannot go.
        $internal = strpos($game, '@internal Model plumbing only');
        $mutate = strpos($game, 'public function mutate');
        self::assertNotFalse($internal, 'mutate must state its @internal contract');
        self::assertNotFalse($mutate);
        self::assertLessThan($mutate, $internal, 'the contract rides mutate\'s own docblock');
        $contract = substr($game, $internal, $mutate - $internal);
        self::assertStringNotContainsString('function ', $contract, 'nothing but the docblock separates them');
        self::assertStringContainsString('VsGame', $contract, 'the friend that blocks protected is named');
        self::assertStringContainsString('garbageStream', $contract, 'why the canonical trait cannot host Game is stated');
    }

    public function testTheFriendAccessTheContractNamesStillExists(): void
    {
        $vs = (string) file_get_contents(__DIR__ . '/../src/VsGame.php');

        // VsGame::mutate is private; every public-game mutate call in this
        // file is the seat bookkeeping the @internal contract exempts.
        // If this census ever answers zero, tighten Game::mutate to
        // protected and update the contract — do not let it go stale.
        $friend = preg_match_all(
            '/\$(?:freshPair|pausedPlayer|finalPlayer|overPlayer|overComputer|stepped|grown|computer|player)\b[^=\n]*->mutate\(/',
            $vs,
        );
        self::assertGreaterThanOrEqual(5, $friend, 'the documented friend sites vanished — revisit visibility');
    }
}
