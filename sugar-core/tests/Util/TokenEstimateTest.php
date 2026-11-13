<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\TokenEstimate;

/** Ported from candy-code's TokenProxyScriptWeightTest (direct ofText pins). */
final class TokenEstimateTest extends TestCase
{
    public function testAsciiIsCeilBytesOverFour(): void
    {
        self::assertSame(2, TokenEstimate::ofText('hello'));
        self::assertSame(0, TokenEstimate::ofText(''));
    }

    public function testLatinPunctuationIsNotReweighted(): void
    {
        foreach (['café naïve façade', 'it’s — “quoted” … done', "cafe\u{0301} re\u{0301}sume\u{0301}", '┌──┐│  │└──┘'] as $t) {
            self::assertSame((int) ceil(mb_strlen($t) / 4), TokenEstimate::ofText($t));
        }
    }

    public function testEmojiAreTwoTokensEach(): void
    {
        self::assertSame(2_000, TokenEstimate::ofText(str_repeat('😀', 1_000)));
    }

    public function testBmpPictographsCountOneTokenEach(): void
    {
        self::assertSame(4, TokenEstimate::ofText("✓ ⚠\u{FE0F}"));
    }

    public function testOtherScriptsWeighHalfAToken(): void
    {
        self::assertSame(100, TokenEstimate::ofText(str_repeat('ж', 200)));
    }

    public function testMixedTextSumsAndConcatenationAdds(): void
    {
        self::assertSame(6, TokenEstimate::ofText('Hello 世界 😀'));
        $en = str_repeat('word ', 400);
        $zh = str_repeat('中文', 200);
        self::assertSame(TokenEstimate::ofText($en) + TokenEstimate::ofText($zh), TokenEstimate::ofText($en . $zh));
    }

    public function testInvalidUtf8FallsBackToBytesOverThree(): void
    {
        $t = "\xFF\xFE" . str_repeat('a', 100);
        self::assertSame((int) ceil(strlen($t) / 3), TokenEstimate::ofText($t));
    }

    public function testToolSchemaPricesTheJsonEnvelope(): void
    {
        $json = json_encode(['name' => 'Read', 'description' => 'd', 'parameters' => ['type' => 'object']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        self::assertSame(TokenEstimate::ofText($json), TokenEstimate::ofToolSchema('Read', 'd', ['type' => 'object']));
    }

    public function testToolSchemasSumsDuckTypedTools(): void
    {
        $tool = new class {
            public function name(): string { return 'Read'; }
            public function description(): string { return 'd'; }
            public function inputSchema(): array { return ['type' => 'object']; }
        };
        self::assertSame(2 * TokenEstimate::ofToolSchema('Read', 'd', ['type' => 'object']), TokenEstimate::ofToolSchemas([$tool, $tool]));
        self::assertSame(0, TokenEstimate::ofToolSchemas([]));
    }
}
