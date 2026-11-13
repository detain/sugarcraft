<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Files\Support\GlyphSet;

/**
 * CF-35 (closes audit 048): the chrome glyph tier table is a pure
 * function of (lang, charset, TERM) — deterministic units, no real
 * terminal involved. ▸ ✓ ─ ↑ ↓ · … are East Asian Width = Ambiguous;
 * on CJK-locale xterm/vte, the Linux console, and legacy CJK codepages
 * they occupy two cells, so the frame arithmetic must fall back to
 * strictly-narrow glyphs there.
 */
final class GlyphSetTest extends TestCase
{
    /** @var array<string, string|false|null> */
    private array $priorServer = [];

    protected function tearDown(): void
    {
        foreach ($this->priorServer as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }
        $this->priorServer = [];
    }

    private function pin(string $key, ?string $value): void
    {
        // Capture the pristine value once; a second pin in the same test
        // must not mistake the first pin's value for the original.
        if (!array_key_exists($key, $this->priorServer)) {
            $this->priorServer[$key] = $_SERVER[$key] ?? null;
        }

        if ($value === null) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $value;
        }
    }

    public function testCjkLangsTakeTheAsciiTier(): void
    {
        foreach (['ja', 'zh', 'zh-cn', 'zh-tw', 'ko'] as $lang) {
            $this->assertEquals(
                GlyphSet::ascii(),
                GlyphSet::select($lang, 'UTF-8', 'xterm-256color'),
                $lang . ' must not get Ambiguous chrome',
            );
        }
    }

    public function testLegacyCjkCodepagesTakeTheAsciiTierWhateverTheLangSays(): void
    {
        foreach (['CP932', '936', 'GBK', 'EUC-JP', 'EUC-KR', 'SJIS', 'BIG5'] as $charset) {
            $this->assertEquals(GlyphSet::ascii(), GlyphSet::select('en', $charset, 'xterm'), $charset);
        }
    }

    public function testTheLinuxConsoleTakesTheAsciiTier(): void
    {
        // The console font doubles A glyphs even for a Latin locale.
        $this->assertEquals(GlyphSet::ascii(), GlyphSet::select('de', 'UTF-8', 'linux'));
    }

    public function testWesternTerminalsKeepTheUnicodeTier(): void
    {
        foreach ([['en', 'UTF-8', 'xterm-256color'], ['de', 'ISO-8859-1', 'screen'], ['ru', 'UTF-8', null]] as [$l, $c, $t]) {
            $this->assertEquals(GlyphSet::unicode(), GlyphSet::select($l, $c, $t), "$l/$c/$t");
        }
    }

    public function testTheEscapeHatchBeatsTheTable(): void
    {
        $this->pin('CANDY_FILES_GLYPHS', 'ascii');
        $this->assertEquals(GlyphSet::ascii(), GlyphSet::current());

        $this->pin('CANDY_FILES_GLYPHS', 'UNICODE');
        $this->assertEquals(GlyphSet::unicode(), GlyphSet::current());
    }

    public function testCurrentFallsThroughToTheTableWithoutTheOverride(): void
    {
        $this->pin('CANDY_FILES_GLYPHS', null);
        // Japanese LANG (via $_SERVER, which detect() consults first) → ascii.
        $this->pin('LC_ALL', 'ja_JP.UTF-8');
        $this->assertEquals(GlyphSet::ascii(), GlyphSet::current());

        $this->pin('LC_ALL', 'en_US.UTF-8');
        $this->pin('TERM', 'xterm-256color');
        $this->assertEquals(GlyphSet::unicode(), GlyphSet::current());
    }

    public function testSubstituteIsWidthNeutralAndTierCorrect(): void
    {
        $line = 'Tab swap · ↑↓ jk move · Enter open';
        $this->assertSame($line, GlyphSet::unicode()->substitute($line), 'unicode tier is the identity');

        $swapped = GlyphSet::ascii()->substitute($line);
        $this->assertSame('Tab swap | ^v jk move | Enter open', $swapped);
        $this->assertSame(Width::of($line), Width::of($swapped), 'every pair is one cell for one cell');
    }

    public function testTheAsciiTierIsStrictlyNarrow(): void
    {
        $g = GlyphSet::ascii();
        foreach ([$g->cursor, $g->mark, $g->rule, $g->up, $g->down, $g->sep] as $glyph) {
            $this->assertSame(1, Width::of($glyph));
            $this->assertMatchesRegularExpression('/^[\x20-\x7e]$/', $glyph, 'ascii tier must be printable ASCII');
        }
        $this->assertTrue($g->asciiBorders);
        $this->assertFalse(GlyphSet::unicode()->asciiBorders);
    }
}
