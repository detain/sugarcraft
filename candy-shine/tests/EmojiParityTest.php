<?php

declare(strict_types=1);

namespace SugarCraft\Shine\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Shine\GithubEmoji;
use SugarCraft\Shine\Renderer;

/**
 * E736 row 7.2 (round-89 lane y6): the shortcode table behind
 * {@see Renderer::withEmoji()} is the full GitHub cheat-sheet corpus
 * glamour's WithEmoji consults (kyokomi/emoji GitHubEmoji), with the
 * curated house layer winning on collisions. These pins hold the
 * parity contract: every code reachable, house bytes stable, and the
 * leading-space dead-key defect (` headphones`) unable to return.
 */
final class EmojiParityTest extends TestCase
{
    /**
     * @return array<string, non-empty-string>
     */
    private static function houseLayer(): array
    {
        $ref = new \ReflectionClass(Renderer::class);

        return $ref->getReflectionConstant('HOUSE_EMOJI')->getValue();
    }

    public function testGithubMapCarriesUpstreamParityVolume(): void
    {
        // The 2026 corpus is 1,837 codes; the floor is deliberately below
        // it so a deliberate future re-transcription can trim a handful
        // of codes without reddening this, while any mass loss cannot.
        self::assertGreaterThanOrEqual(1800, count(GithubEmoji::MAP));
    }

    /**
     * Fail-closed reachability census: a key the expansion regex cannot
     * capture is dead weight — the exact defect class that hid
     * ` headphones` for rounds. Every merged key must round-trip
     * through EMOJI_SHORTCODE_PATTERN unchanged, and every value must
     * be a non-empty glyph.
     */
    public function testEveryMergedKeyIsReachableAndEveryValueIsGlyph(): void
    {
        $ref = new \ReflectionClass(Renderer::class);
        /** @var string $pattern */
        $pattern = $ref->getReflectionConstant('EMOJI_SHORTCODE_PATTERN')->getValue();

        $merged = self::houseLayer() + GithubEmoji::MAP;
        $unreachable = [];
        foreach ($merged as $key => $glyph) {
            // PHP normalises numeric-string array keys to int; the
            // lookup path normalises identically (offset access casts),
            // so compare on the canonicalised string spelling.
            $code = (string) $key;
            $matches = preg_match($pattern, ':' . $code . ':', $m) === 1
                && $m[1] === strtolower($code)
                && $code === strtolower($code);
            if (!$matches || $glyph === '') {
                $unreachable[] = $code;
            }
        }

        self::assertSame([], $unreachable, 'every map key must be capturable by EMOJI_SHORTCODE_PATTERN verbatim');
        self::assertGreaterThanOrEqual(1842, count($merged));
    }

    public function testHouseLayerWinsOnDivergentAndHouseOnlyCodes(): void
    {
        $r = Renderer::plain()->withEmoji(true);

        // GitHub maps :email: to the envelope (✉) and :phone: to the
        // telephone (☎); the house layer shipped receiver glyphs and
        // keeps them — this is the merge-precedence proof.
        self::assertStringContainsString('📧', $r->render(':email: x'));
        self::assertStringNotContainsString('✉', $r->render(':email: x'));
        self::assertStringContainsString('📞', $r->render(':phone: x'));
        self::assertStringNotContainsString('☎', $r->render(':phone: x'));

        // Heart and warning carry the emoji-presentation selector
        // U+FE0F in the house layer; pin the exact two-codepoint bytes.
        $heart = $r->render(':heart: x');
        self::assertStringContainsString("\u{2764}\u{FE0F}", $heart);
        $warning = $r->render(':warning: x');
        self::assertStringContainsString("\u{26A0}\u{FE0F}", $warning);

        // House-only codes (never in the GitHub list) still expand.
        foreach (['candy' => '🍬', 'sugar' => '🍭', 'honey' => '🍯', 'check' => '✅', 'info' => 'ℹ️', 'mail' => '📧'] as $code => $glyph) {
            self::assertStringContainsString($glyph, $r->render(':' . $code . ': x'), $code);
        }
    }

    public function testHeadphonesLeadingSpaceDefectIsFixed(): void
    {
        $house = self::houseLayer();
        self::assertArrayNotHasKey(' headphones', $house, 'a key the regex can never capture is dead weight');
        self::assertArrayHasKey('headphones', $house);

        $r = Renderer::plain()->withEmoji(true);
        self::assertStringContainsString('🎧', $r->render(':headphones: x'));
        // The space-bearing token was never matchable and still passes
        // through — proves the census matches reality, not just the data.
        self::assertStringContainsString(': headphones:', $r->render(': headphones:'));
    }

    /**
     * @return array<array{string, string}>
     */
    public static function githubOnlyCodes(): array
    {
        return [
            ['100', '💯'],
            ['+1', '👍'],
            ['-1', '👎'],
            ['bomb', '💣'],
            ['skull', '💀'],
            ['octopus', '🐙'],
            ['tired_face', '😫'],
            ['astonished', '😲'],
            ['abc', '🔤'],
        ];
    }

    #[DataProvider('githubOnlyCodes')]
    public function testGithubOnlyCodesNowExpand(string $code, string $glyph): void
    {
        self::assertArrayNotHasKey($code, self::houseLayer(), 'fixture must exercise a github-only row');
        $out = Renderer::plain()->withEmoji(true)->render(':' . $code . ': x');
        self::assertStringContainsString($glyph, $out);
    }

    public function testCaseInsensitiveLookupAndPassThroughEdges(): void
    {
        $r = Renderer::plain()->withEmoji(true);
        self::assertStringContainsString('😄', $r->render(':SMILE: x'));
        self::assertStringContainsString('💣', $r->render(':Bomb: x'));
        self::assertStringContainsString(':not_a_real_code:', $r->render(':not_a_real_code: x'));

        // Flag off: even the newly expanded codes stay verbatim.
        $off = Renderer::plain()->render(':bomb: x');
        self::assertStringContainsString(':bomb:', $off);
        self::assertStringNotContainsString('💣', $off);
    }
}
