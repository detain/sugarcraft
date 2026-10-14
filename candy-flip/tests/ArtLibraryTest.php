<?php

declare(strict_types=1);

namespace SugarCraft\Flip\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Flip\AnsiArt;
use SugarCraft\Flip\ArtLibrary;

final class ArtLibraryTest extends TestCase
{
    private const STATIC_ART = "\x1b[31mAB\x1b[0m\n";

    private const ANIM_ART = "\x1b[?25l\x1b[32mA\x1b[0m\n\x1b[1A\r\x1b[33mB\x1b[0m\n\x1b[?25h";

    /** Distinct glyph ('D') so cascade leg picks are observable, same 2x1 shape. */
    private const DELTA_256_ART = "\x1b[35mD2\x1b[0m\n";

    private string $dir;

    /** @var list<string> extra temp dirs created per-test, removed in tearDown */
    private array $extraDirs = [];

    private function makeDir(array $matrix): string
    {
        $dir = sys_get_temp_dir() . '/artlib-' . uniqid('t', true);
        mkdir($dir, 0o700);
        foreach ($matrix as $name => $bytes) {
            file_put_contents($dir . '/' . $name, $bytes);
        }
        $this->extraDirs[] = $dir;

        return $dir;
    }

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/artlib-' . uniqid('t', true);
        mkdir($this->dir, 0o700);
        $matrix = [
            'logo-alpha-tc-2x1.ansi' => self::STATIC_ART,
            'logo-alpha-anim-tc-2x1.ansi' => self::ANIM_ART,
            'logo-alpha-16-2x1.ansi' => self::STATIC_ART,
            'logo-beta-256-2x1.ansi' => self::STATIC_ART,
            'logo-gamma-anim-tc-2x1.ansi' => self::ANIM_ART,
            'logo-delta-16-2x1.ansi' => self::STATIC_ART,
            'logo-delta-256-2x1.ansi' => self::DELTA_256_ART,
            'logo-epsilon-anim-256-2x1.ansi' => self::ANIM_ART,
            'logo-epsilon-16-2x1.ansi' => self::STATIC_ART,
            'logo-dwarf-skinny-tc-2x1.ansi' => self::STATIC_ART,
            // unparsable names must be skipped silently:
            'notalogo-anim-tc-2x2.ansi' => self::STATIC_ART,
            'logo-baddepth-xterm-2x1.ansi' => self::STATIC_ART,
            'logo-nodims-tc.ansi' => self::STATIC_ART,
            'notes.txt' => 'ignore me',
        ];
        foreach ($matrix as $name => $bytes) {
            file_put_contents($this->dir . '/' . $name, $bytes);
        }
    }

    protected function tearDown(): void
    {
        foreach ([$this->dir, ...$this->extraDirs] as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }

    public function testFromDirIndexesMatchingNamesAndSkipsJunk(): void
    {
        $library = ArtLibrary::fromDir($this->dir);
        $entries = $library->entries();

        self::assertCount(9, $entries, 'alpha x3, beta, gamma, delta x2, epsilon x2; skinny excluded, junk skipped');
        foreach ($entries as $entry) {
            self::assertFileExists($entry['path']);
            self::assertContains($entry['depth'], ['16', '256', 'tc']);
            self::assertSame(2, $entry['width']);
            self::assertSame(1, $entry['height']);
        }
    }

    public function testSkinnyExcludedByDefaultIncludedOnRequest(): void
    {
        self::assertNotContains('dwarf', ArtLibrary::fromDir($this->dir)->slugs());
        self::assertContains('dwarf', ArtLibrary::fromDir($this->dir, true)->slugs());
    }

    public function testSlugsAreSortedUniqueWithoutVariantSuffixes(): void
    {
        self::assertSame(
            ['alpha', 'beta', 'delta', 'epsilon', 'gamma'],
            ArtLibrary::fromDir($this->dir)->slugs(),
            'anim/static variants group under the bare design slug',
        );
    }

    public function testSkinnyAndAnimSuffixNormalisationKeepsDimensions(): void
    {
        $library = ArtLibrary::fromDir($this->dir, true);
        $byPath = [];
        foreach ($library->entries() as $entry) {
            $byPath[basename($entry['path'])] = $entry;
        }
        self::assertSame('dwarf', $byPath['logo-dwarf-skinny-tc-2x1.ansi']['slug']);
        self::assertTrue($byPath['logo-dwarf-skinny-tc-2x1.ansi']['skinny'], 'skinny axis carried on the entry');
        self::assertFalse($byPath['logo-gamma-anim-tc-2x1.ansi']['skinny']);
        self::assertFalse($byPath['logo-dwarf-skinny-tc-2x1.ansi']['animated']);
        self::assertTrue($byPath['logo-gamma-anim-tc-2x1.ansi']['animated']);
    }

    public function testFromDirMissingDirectoryThrows(): void
    {
        $this->expectException(RuntimeException::class);
        ArtLibrary::fromDir($this->dir . '-nope');
    }

    public function testCascadePrefersAnimationAtExactDepth(): void
    {
        $library = ArtLibrary::fromDir($this->dir);
        $art = $library->pick('alpha', 'tc');
        self::assertInstanceOf(AnsiArt::class, $art);
        self::assertTrue($art->isAnimated(), 'anim@tc beats static@tc and every other depth');
    }

    public function testCascadeStaticFirstWhenAnimationNotPreferred(): void
    {
        $art = ArtLibrary::fromDir($this->dir)->pick('alpha', 'tc', false);
        self::assertNotNull($art);
        self::assertFalse($art->isAnimated());
    }

    public function testCascadeFallsToNearestOtherDepthAnimationBeforeOtherKind(): void
    {
        $library = ArtLibrary::fromDir($this->dir);

        // epsilon has only anim@256 and static@16: at tc the ANIM cascade leg
        // must win over the STATIC leg regardless of depth adjacency.
        $preferAnim = $library->pick('epsilon', 'tc', true);
        self::assertNotNull($preferAnim);
        self::assertTrue($preferAnim->isAnimated(), 'anim@256 chosen over static@16');

        $preferStatic = $library->pick('epsilon', 'tc', false);
        self::assertNotNull($preferStatic);
        self::assertFalse($preferStatic->isAnimated(), 'static@16 chosen when animation de-prioritised');
    }

    public function testCascadeDepthOnlySlugAndDepthOrder(): void
    {
        $library = ArtLibrary::fromDir($this->dir);

        // gamma is anim-only at tc; asking for 16 must still resolve it.
        $art = $library->pick('gamma', '16');
        self::assertNotNull($art, 'anim-only slug resolves at every depth via cascade');
        self::assertTrue($art->isAnimated());

        // beta is static-only at 256.
        self::assertNotNull($library->pick('beta', '16'));

        // delta is static at 256 AND 16 (genuinely multi-candidate): requesting
        // tc must walk the fallback adjacent-first and land on 256, never jump
        // two rungs to 16. Reversing the tc cascade leg to [16, 256] reddens.
        $delta = $library->pick('delta', 'tc');
        self::assertNotNull($delta);
        self::assertFalse($delta->isAnimated());
        self::assertSame('D', $delta->frameCells(0)[0][0]->glyph, 'adjacent 256 rung wins over distant 16');
    }

    public function testDepthCascadeIsAdjacentFirstFromEveryRung(): void
    {
        // theta: static at tc ('T' glyph) and 16 ('O' glyph), none at 256.
        // iota: static at tc ('I') and 256 ('N'), none at 16.
        // mu: static at 256 ('R') and 16 ('P'), none at tc.
        $dir = $this->makeDir([
            'logo-theta-tc-2x1.ansi' => "\x1b[31mTT\x1b[0m\n",
            'logo-theta-16-2x1.ansi' => "\x1b[32mOO\x1b[0m\n",
            'logo-iota-tc-2x1.ansi' => "\x1b[33mII\x1b[0m\n",
            'logo-iota-256-2x1.ansi' => "\x1b[34mNN\x1b[0m\n",
            'logo-mu-256-2x1.ansi' => "\x1b[36mRR\x1b[0m\n",
            'logo-mu-16-2x1.ansi' => "\x1b[90mPP\x1b[0m\n",
        ]);
        $library = ArtLibrary::fromDir($dir);

        // From 256 the richer neighbour comes first: theta -> tc, not 16.
        self::assertSame('T', self::glyph($library->pick('theta', '256')), '256 cascade prefers tc over 16');
        // From 16 the ADJACENT rung comes first: iota -> 256, never jump to tc
        // (this is the leg the old fixed tc,256,16 order got wrong for 16).
        self::assertSame('N', self::glyph($library->pick('iota', '16')), '16 cascade prefers 256 over tc');
        // From tc adjacent-first: mu -> 256, never jump two rungs to 16.
        self::assertSame('R', self::glyph($library->pick('mu', 'tc')), 'tc cascade prefers 256 over 16');
    }

    public function testSkinnyAndWideVariantsNeverCollide(): void
    {
        $dir = $this->makeDir([
            'logo-zeta-tc-2x1.ansi' => "\x1b[31mWW\x1b[0m\n",            // wide static@tc
            'logo-zeta-skinny-tc-2x1.ansi' => "\x1b[32mSS\x1b[0m\n",     // skinny static@tc — same slot!
            'logo-eta-skinny-16-2x1.ansi' => "\x1b[33mEE\x1b[0m\n",      // skinny-only slug
            'logo-kappa-16-2x1.ansi' => "\x1b[34mKK\x1b[0m\n",           // wide@16 only...
            'logo-kappa-skinny-tc-2x1.ansi' => "\x1b[35mJJ\x1b[0m\n",    // ...plus skinny@tc exact
        ]);

        // Without skinny: the wide entry stands alone, nothing overwritten.
        $wide = ArtLibrary::fromDir($dir);
        self::assertCount(2, $wide->entries());
        self::assertSame('W', self::glyph($wide->pick('zeta', 'tc')));
        self::assertNull($wide->pick('eta', '16'), 'skinny-only slug is invisible when excluded');

        // With skinny: both forms coexist; pick prefers the NON-skinny axis and
        // the depth cascade never crosses into skinny art (kappa@tc resolves to
        // the wide 16 render, not the exact-depth skinny 'J').
        $both = ArtLibrary::fromDir($dir, true);
        self::assertCount(5, $both->entries());
        self::assertSame('W', self::glyph($both->pick('zeta', 'tc')), 'wide wins over skinny in the same slot');
        self::assertSame('E', self::glyph($both->pick('eta', '16')), 'skinny-only slug still resolves');
        self::assertSame('K', self::glyph($both->pick('kappa', 'tc')), 'wide axis is exhausted before skinny');
    }

    private static function glyph(?AnsiArt $art): string
    {
        self::assertNotNull($art, 'cascade must resolve the fixture slug');

        return $art->frameCells(0)[0][0]->glyph;
    }

    public function testPickUnknownSlugReturnsNull(): void
    {
        self::assertNull(ArtLibrary::fromDir($this->dir)->pick('ghost', 'tc'));
    }

    public function testPickUnknownDepthTagThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ArtLibrary::fromDir($this->dir)->pick('alpha', 'xterm');
    }

    public function testParsedArtIsMemoisedPerPath(): void
    {
        $library = ArtLibrary::fromDir($this->dir);
        self::assertSame($library->pick('delta', '16'), $library->pick('delta', '16'), 'process-lifetime parse cache');
    }

    public function testProfileTagMapping(): void
    {
        self::assertSame('tc', ArtLibrary::profileTag(ColorProfile::TrueColor));
        self::assertSame('256', ArtLibrary::profileTag(ColorProfile::Ansi256));
        self::assertSame('16', ArtLibrary::profileTag(ColorProfile::Ansi));
        self::assertSame('16', ArtLibrary::profileTag(ColorProfile::Ascii));
        self::assertSame('16', ArtLibrary::profileTag(ColorProfile::NoTty));
    }

    public function testManifestMergeEnrichesByKeyedByFilename(): void
    {
        $manifest = $this->dir . '/logos.jsonl';
        file_put_contents($manifest, implode("\n", [
            // the manifest slug quirk: animated rows carry '-anim' in slug — must be ignored
            json_encode(['filename' => 'logo-alpha-anim-tc-2x1.ansi', 'slug' => 'alpha-anim', 'description' => 'Anim alpha row', 'tags' => ['anim', 'tc']]),
            'this line is not json',
            json_encode(['description' => 'no filename key']),
            json_encode(['filename' => 'logo-dwarf-skinny-tc-2x1.ansi', 'slug' => 'dwarf', 'description' => 'excluded file', 'tags' => []]),
            json_encode(['filename' => 'logo-alpha-tc-2x1.ansi', 'slug' => 'alpha', 'description' => 'Static row', 'tags' => 'not-a-list']),
        ]));

        $library = ArtLibrary::fromDir($this->dir)->mergeManifest($manifest);

        self::assertSame('Anim alpha row', $library->description('alpha'), 'first matching row wins per slug');
        self::assertSame(['anim', 'tc'], $library->tags('alpha'));
        self::assertSame('', $library->description('dwarf'), 'excluded skinny file is not enriched');
        self::assertSame([], $library->tags('beta'), 'slug with no manifest row stays empty');
    }

    public function testManifestMergeToleratesMissingFile(): void
    {
        $library = ArtLibrary::fromDir($this->dir)->mergeManifest($this->dir . '/absent.jsonl');
        self::assertSame('', $library->description('alpha'));
        self::assertSame([], $library->tags('alpha'));
    }

    public function testRandomSlugIsUniformOverCascadeResolvableSlugsViaInjectedRng(): void
    {
        $library = ArtLibrary::fromDir($this->dir);
        $seen = [];
        $library->randomSlug('tc', true, function (int $n) use (&$seen): int {
            $seen[] = $n;
            return 2;
        });
        self::assertSame([5], $seen, 'candidates are the five resolvable slugs');
        self::assertSame('alpha', $library->randomSlug('tc', true, static fn (int $n): int => 0));
        self::assertSame('delta', $library->randomSlug('tc', true, static fn (int $n): int => 2));
        self::assertSame('gamma', $library->randomSlug('tc', true, static fn (int $n): int => $n - 1));
    }

    public function testRandomSlugRejectsOutOfRangeRngResult(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ArtLibrary::fromDir($this->dir)->randomSlug('tc', true, static fn (int $n): int => $n);
    }

    public function testRandomSlugUnknownDepthTagThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ArtLibrary::fromDir($this->dir)->randomSlug('rainbow');
    }

    public function testPickRandomReturnsArtForChosenSlug(): void
    {
        $art = ArtLibrary::fromDir($this->dir)->pickRandom('tc', true, static fn (int $n): int => 4);
        self::assertInstanceOf(AnsiArt::class, $art);
        self::assertTrue($art->isAnimated(), 'index 4 of the sorted candidates is gamma, anim-only at tc');
    }

    public function testEmptyLibraryYieldsNothing(): void
    {
        $empty = sys_get_temp_dir() . '/artlib-empty-' . uniqid('t', true);
        mkdir($empty, 0o700);
        try {
            $library = ArtLibrary::fromDir($empty);
            self::assertSame([], $library->slugs());
            self::assertNull($library->pickRandom('tc'));
            self::assertNull($library->randomSlug('tc'));
            self::assertNull($library->pick('anything', '16'));
        } finally {
            @rmdir($empty);
        }
    }

    public function testDefaultRngStaysInCandidateRange(): void
    {
        $library = ArtLibrary::fromDir($this->dir);
        $slugs = $library->slugs();
        for ($i = 0; $i < 30; $i++) {
            self::assertContains($library->randomSlug('tc'), $slugs);
        }
    }

    // ---- real candy-top corpus (integration pins, skipped when absent) ----

    private const CORPUS_DIR = __DIR__ . '/../../candy-top/logo';

    public function testRealCorpusIndexesTwentyWideSlugs(): void
    {
        $library = $this->corpusLibrary();
        self::assertGreaterThanOrEqual(20, count($library->slugs()));
        foreach ($library->entries() as $entry) {
            self::assertStringNotContainsString('-skinny-', basename($entry['path']));
        }
    }

    public function testRealCorpusWideAnimatedTruecolorFilesAllParseAsAnimated(): void
    {
        $library = $this->corpusLibrary();
        $count = 0;
        foreach ($library->entries() as $entry) {
            if (!$entry['animated'] || $entry['depth'] !== 'tc') {
                continue;
            }
            $art = AnsiArt::fromFile($entry['path']);
            self::assertGreaterThanOrEqual(2, $art->frameCount(), $entry['path'] . ' must hold >= 2 frames');
            self::assertTrue($art->isAnimated());
            self::assertSame($entry['height'], $art->height(), 'filename height matches row count');
            self::assertSame($entry['width'], $art->width(), 'filename width matches max cell count');
            $count++;
        }
        self::assertGreaterThanOrEqual(10, $count, 'the corpus ships many anim-tc files');
    }

    public function testRealCorpusCascadeResolvesEverySlugAtEveryDepth(): void
    {
        $library = $this->corpusLibrary();
        foreach ($library->slugs() as $slug) {
            foreach (['tc', '256', '16'] as $depth) {
                self::assertNotNull(
                    $library->pick($slug, $depth),
                    "slug '{$slug}' must resolve at depth '{$depth}' via cascade",
                );
            }
        }
    }

    public function testRealCorpusManifestMergeEnrichesEverySlug(): void
    {
        $library = $this->corpusLibrary()
            ->mergeManifest(self::CORPUS_DIR . '/logos.jsonl');
        foreach ($library->slugs() as $slug) {
            self::assertNotSame('', $library->description($slug), "manifest should describe '{$slug}'");
        }
    }

    public function testRealCorpusDecoGiltSixteenCarriesInheritedBackgroundOnFgOnlyRuns(): void
    {
        // The diagnosed defect shape, pinned on a live file: the header row
        // opens ESC[40;32m and later runs set ONLY the foreground (ESC[91m,
        // ESC[97m) — a terminal keeps painting them on black, so every cell
        // must resolve with bg=40 in its canonical style.
        $art = $this->corpusArt('logo-art-deco-gilt-16-76x9.ansi');
        $row = $art->frameCells(0)[0];

        self::assertSame('╭', $row[0]->glyph);
        self::assertSame("\x1b[32;40m", $row[0]->sgr, 'opening run resolves fg+bg canonically');
        self::assertSame('¹', $row[3]->glyph);
        self::assertSame("\x1b[91;40m", $row[3]->sgr, 'ESC[91m keeps the carried black background');
        self::assertSame('c', $row[4]->glyph);
        self::assertSame("\x1b[97;40m", $row[4]->sgr, 'ESC[97m run: white-on-BLACK, not white-on-default');
        self::assertSame('u', $row[6]->glyph);
        self::assertSame("\x1b[97;40m", $row[6]->sgr);
    }

    public function testRealCorpusDecoGiltAnimTruecolorInheritsBackgroundAcrossFrames(): void
    {
        // anim-tc header: ESC[48;2;0;0;0;38;2;...m opens, every later run is
        // fg-only — the rgb black bg must survive into every following cell.
        $art = $this->corpusArt('logo-art-deco-gilt-anim-tc-76x9.ansi');
        self::assertGreaterThanOrEqual(2, $art->frameCount());
        $row = $art->frameCells(0)[0];

        self::assertSame("\x1b[38;2;85;109;89;48;2;0;0;0m", $row[0]->sgr);
        self::assertSame("\x1b[38;2;238;238;238;48;2;0;0;0m", $row[4]->sgr, 'cpu label: fg swapped, rgb bg inherited');

        $later = $art->frameCells(1)[0];
        self::assertNotSame('', $later[0]->sgr, 'frame 1 inherits the document state, never resets per-frame');
        self::assertStringContainsString('48;2;0;0;0', $later[0]->sgr, 'bg carried across the frame header');
    }

    private function corpusArt(string $file): AnsiArt
    {
        $path = self::CORPUS_DIR . '/' . $file;
        if (!is_file($path)) {
            $this->markTestSkipped('candy-top logo corpus not present beside candy-flip');
        }

        return AnsiArt::fromFile($path);
    }

    private function corpusLibrary(): ArtLibrary
    {
        if (!is_dir(self::CORPUS_DIR)) {
            $this->markTestSkipped('candy-top logo corpus not present beside candy-flip');
        }

        return ArtLibrary::fromDir(self::CORPUS_DIR);
    }
}
