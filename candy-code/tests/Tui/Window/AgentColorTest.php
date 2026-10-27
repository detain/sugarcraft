<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui\Window;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Theme;
use SugarCraft\Code\Tui\Window\AgentColor;
use SugarCraft\Core\Util\Color;

/**
 * Agent colour resolution of the window kit (P-TABS.a): the eight word
 * names ride theme tokens, a literal hex must clear the theme's contrast
 * floor, and everything else answers null — a half-matched colour is
 * dropped, never guessed at.
 */
final class AgentColorTest extends TestCase
{
    public function testWordNamesResolveToThemeTokens(): void
    {
        $theme = Theme::default();
        $expected = [
            'red' => $theme->shellError,
            'blue' => $theme->shellInfo,
            'green' => $theme->shellSuccess,
            'yellow' => $theme->shellWarning,
            'purple' => $theme->shellPrimary,
            'orange' => $theme->userLabel,
            'pink' => $theme->assistantLabel,
            'cyan' => $theme->systemLabel,
        ];
        foreach ($expected as $word => $token) {
            self::assertSame($token->toHex(), AgentColor::resolve($word, $theme)?->toHex(), "the word {$word} must ride its token");
        }
    }

    public function testResolutionIsCaseInsensitiveAndVocabularyIsWhatItClaims(): void
    {
        $theme = Theme::default();
        self::assertSame(AgentColor::resolve('red', $theme)?->toHex(), AgentColor::resolve('RED', $theme)?->toHex());
        self::assertSame(AgentColor::resolve('red', $theme)?->toHex(), AgentColor::resolve(' Red ', $theme)?->toHex());

        self::assertSame(
            ['blue', 'cyan', 'green', 'orange', 'pink', 'purple', 'red', 'yellow'],
            AgentColor::VOCABULARY,
            'the vocabulary is a documented surface; a silent addition or removal must redden here'
        );
    }

    public function testGarbageAnswersNull(): void
    {
        $theme = Theme::default();
        foreach ([null, '', '   ', 'mauve', 'red;', '#ff00f', '#gg0000', '#ff00', 'rgb(1,2,3)', 'red red'] as $hostile) {
            self::assertNull(AgentColor::resolve($hostile, $theme), 'untrusted colour text must never paint: ' . var_export($hostile, true));
        }
    }

    public function testLegibleHexPassesAndLowContrastHexIsRefused(): void
    {
        $theme = Theme::default();

        // Deterministic from the shipped theme: near-black clears the floor
        // against the foreground the chip name competes with...
        self::assertSame('#000000', AgentColor::resolve('#000000', $theme)?->toHex());
        // ...while the foreground's own hex is contrast 1.0 — invisible text
        // — and any grey that washes out against it is refused the same way.
        self::assertNull(AgentColor::resolve($theme->shellForeground->toHex(), $theme));
        self::assertNull(AgentColor::resolve('#808080', $theme));

        // The refusal follows the theme, not a hard-coded list: re-measure
        // the accepted colour against the same floor the lib uses.
        $accepted = AgentColor::resolve('#000000', $theme);
        self::assertInstanceOf(Color::class, $accepted);
        self::assertGreaterThanOrEqual(Theme::CONTRAST_MIN, Theme::contrast($accepted, $theme->shellForeground));
    }

    public function testAcceptedHexIsNormalizedToLowercase(): void
    {
        $theme = Theme::default();
        self::assertSame('#000000', AgentColor::resolve('#000000', $theme)?->toHex());

        // 3-digit shorthand is NOT accepted: the kit's contract is the full
        // form, and #0f0-style guesses would expand silently.
        self::assertNull(AgentColor::resolve('#0f0', $theme));
    }
}
