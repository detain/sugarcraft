<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\View;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Flip\AnsiArt;
use SugarCraft\Flip\ArtLibrary;
use SugarCraft\Top\Theme\ThemeConfig;
use SugarCraft\Top\View\Ink;
use SugarCraft\Top\View\Logo;
use SugarCraft\Top\View\Surface;

/**
 * The flip-art façade: env-driven selection (off / slug / seeded random),
 * the process-wide memo, and the Banner-shaped blit (centred with the
 * house -1 offset, unstyled spaces left to the surface base, right-edge
 * cells dropped instead of wrapped).
 */
final class LogoTest extends TestCase
{
    private string $tz;

    protected function setUp(): void
    {
        $this->tz = date_default_timezone_get();
        date_default_timezone_set('UTC');
        Logo::resetForTesting();
    }

    protected function tearDown(): void
    {
        putenv(Logo::CHOICE_ENV . '=off');
        putenv(Logo::SEED_ENV);
        Logo::resetForTesting();
        date_default_timezone_set($this->tz);
    }

    public function testOffEnvResolvesToNullForEveryProfile(): void
    {
        putenv(Logo::CHOICE_ENV . '=off');
        foreach ([ColorProfile::TrueColor, ColorProfile::Ansi256, ColorProfile::Ansi] as $profile) {
            $this->assertNull(Logo::pick($profile), 'off must fall through to the legacy Banner');
        }
    }

    public function testUnknownSlugResolvesToNull(): void
    {
        putenv(Logo::CHOICE_ENV . '=no-such-slug-anywhere');
        $this->assertNull(Logo::pick(ColorProfile::TrueColor));
    }

    public function testUnresolvableSlugFallsThroughToNullNotViaTheOffPath(): void
    {
        // The corpus IS present, so the only way this pick resolves to null
        // is a genuine miss inside the library — not the env-off shortcut and
        // not a missing-directory collapse. The miss memoises under its own
        // choice key, so switching to a real slug right after must still
        // resolve (a polluted memo would strand every later pick on null).
        $this->skipWithoutCorpus();
        putenv(Logo::CHOICE_ENV . '=no-such-slug-anywhere');
        $this->assertNull(Logo::pick(ColorProfile::TrueColor), 'first miss');
        $this->assertNull(Logo::pick(ColorProfile::TrueColor), 'memoised miss still null');
        putenv(Logo::CHOICE_ENV . '=rock-candy-prism');
        $this->assertNotNull(Logo::pick(ColorProfile::TrueColor), 'miss memo must not bleed across choices');
    }

    public function testNamedSlugPicksDeterministically(): void
    {
        $this->skipWithoutCorpus();
        putenv(Logo::CHOICE_ENV . '=rock-candy-prism');
        $art = Logo::pick(ColorProfile::TrueColor);
        $this->assertNotNull($art);
        $this->assertSame(76, $art->width());
        $this->assertSame(8, $art->height());
        $this->assertTrue($art->isAnimated());
    }

    public function testDepthTagRoutesFromProfile(): void
    {
        $this->skipWithoutCorpus();
        putenv(Logo::CHOICE_ENV . '=rock-candy-prism');
        // Ansi (tty_mode) must land on the 16-colour render of the same slug.
        $ansi = Logo::pick(ColorProfile::Ansi);
        $this->assertNotNull($ansi);
        $expected = ArtLibrary::fromDir(Logo::DIR)->pick('rock-candy-prism', '16');
        $this->assertNotNull($expected);
        $this->assertSame($expected->sgrText(0), $ansi->sgrText(0));
        $this->assertNotSame($expected->sgrText(0), Logo::pick(ColorProfile::TrueColor)?->sgrText(0));
    }

    public function testSeededRandomIsReproducibleAcrossProcessesOfPicks(): void
    {
        $this->skipWithoutCorpus();
        putenv(Logo::CHOICE_ENV . '=random');
        putenv(Logo::SEED_ENV . '=42');
        $first = Logo::pick(ColorProfile::TrueColor);
        $this->assertNotNull($first);
        Logo::resetForTesting();
        $second = Logo::pick(ColorProfile::TrueColor);
        $this->assertNotNull($second);
        $this->assertSame($first->sgrText(0), $second->sgrText(0), 'seed 42 owes the same art every time');
    }

    public function testPicksAreMemoisedPerProcess(): void
    {
        $this->skipWithoutCorpus();
        putenv(Logo::CHOICE_ENV . '=rock-candy-prism');
        // The memo lives behind the env read: the second call must not re-parse.
        $this->assertSame(Logo::pick(ColorProfile::TrueColor), Logo::pick(ColorProfile::TrueColor));
    }

    public function testPaintCentresWithHouseOffsetAndKeepsUnstyledSpaces(): void
    {
        $art = $this->synthetic();
        $surface = Surface::new(12, 6);
        Logo::paint($surface, 1, $art, 0, Ink::new(ThemeConfig::new()));

        $this->assertSame('    AB      ', $surface->plainLines()[1]);
        $this->assertSame('     CD     ', $surface->plainLines()[2]);
        $this->assertSame("\x1b[31m", $surface->style(4, 1));
        $this->assertSame('', $surface->style(5, 2), 'unstyled space inherits the surface base');
        $this->assertSame("\x1b[32m", $surface->style(6, 2));
    }

    public function testPaintClampsCentreOffsetAndDropsOverflowColumns(): void
    {
        $art = $this->synthetic();
        $narrow = Surface::new(2, 3);
        Logo::paint($narrow, 1, $art, 0, Ink::new(ThemeConfig::new()));
        // x = max(0, intdiv(2,2) - intdiv(3,2) - 1) = 0; the third column is dropped.
        $this->assertSame('AB', $narrow->plainLines()[1]);
        $this->assertSame(2, Surface::new(2, 1)->bounds()->width);
    }

    public function testPaintClampsFrameIndexIntoRange(): void
    {
        $this->skipWithoutCorpus();
        putenv(Logo::CHOICE_ENV . '=rock-candy-prism');
        $art = Logo::pick(ColorProfile::TrueColor);
        $this->assertNotNull($art);
        $surface = Surface::new(90, 12);
        Logo::paint($surface, 1, $art, -5, Ink::new(ThemeConfig::new()));
        $first = $surface->plainLines();
        $surface2 = Surface::new(90, 12);
        Logo::paint($surface2, 1, $art, $art->frameCount() + 99, Ink::new(ThemeConfig::new()));
        $last = $surface2->plainLines();
        $this->assertNotSame($first, $last, 'a 14-frame animation must not collapse under clamping');
    }

    private function synthetic(): AnsiArt
    {
        // Red AB over an unstyled space, then CD where C inherits the base
        // and D goes green — one of each paint-relevant cell kind.
        return AnsiArt::fromString("\x1b[31mAB\n C\x1b[32mD");
    }

    private function skipWithoutCorpus(): void
    {
        if (!is_dir(Logo::DIR)) {
            $this->markTestSkipped('logo corpus not present in this checkout');
        }
    }
}
