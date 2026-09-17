<?php

declare(strict_types=1);

namespace SugarCraft\Shine\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Shine\StyleGuide;
use SugarCraft\Sprinkles\Style;

/**
 * Pins for {@see StyleGuide} — the E736 7.1 glamour STYLE JSON decoder.
 *
 * The ordering law (exact chroma token before any prefix subtype, whatever
 * the document order) and the fail-fast colour legs are mutation targets:
 * each has a dedicated arm so re-spelling them cannot survive silently.
 */
final class StyleGuideTest extends TestCase
{
    /** Minimal style doc: document affixes + elements + chroma. */
    private const FULL_DOC = <<<'JSON'
    {
      "document": {
        "block_prefix": "«",
        "block_suffix": "»",
        "indent": 2,
        "margin": "1",
        "color": "#aabbcc"
      },
      "h1": { "color": "#ff0000", "bold": true },
      "paragraph": { "block_prefix": "P> ", "block_suffix": " <P" },
      "strong": { "color": "5" },
      "block_quote": { "italic": true },
      "chroma": {
        "KeywordReserved": { "color": "#101010" },
        "Keyword": { "color": "#202020", "bold": true },
        "LiteralNumber": { "color": 3 },
        "Comment": { "color": "auto", "faint": true }
      }
    }
    JSON;

    public function testIsGlamourSchemaSignatures(): void
    {
        $this->assertTrue(StyleGuide::isGlamourSchema(['document' => ['color' => '#fff']]));
        $this->assertFalse(StyleGuide::isGlamourSchema([]));
        $this->assertFalse(StyleGuide::isGlamourSchema(['document' => 'scalar']));
        $this->assertFalse(StyleGuide::isGlamourSchema(['paragraph' => ['bold' => true]]));
    }

    public function testDecodedDocumentCarriesAffixesAndCoercions(): void
    {
        $guide = StyleGuide::fromJsonString(self::FULL_DOC);

        $this->assertSame('«', $guide->blockPrefix);
        $this->assertSame('»', $guide->blockSuffix);
        $this->assertSame(2, $guide->documentIndent);
        $this->assertSame(1, $guide->documentMargin, 'digit-string margin coerces to int');
    }

    public function testElementAndChromaBlocksStayAddressable(): void
    {
        $guide = StyleGuide::fromJsonString(self::FULL_DOC);

        $this->assertSame(['color' => '#ff0000', 'bold' => true], $guide->element('h1'));
        $this->assertNull($guide->element('missing'));
        // chroma is partitioned OUT of the element map…
        $this->assertNull($guide->element('chroma'));
        $this->assertNotNull($guide->chroma('Keyword'));
        $this->assertNull($guide->chroma('Missing'));
    }

    public function testScalarAndNonStringKeysAreSkipped(): void
    {
        $guide = StyleGuide::fromJsonString('{"document": {"indent": 1}, "note": "scalar", "h1": {"bold": true}, "chroma": {"Comment": "scalar"}}');

        $this->assertSame(['document', 'h1'], array_keys($guide->elements));
        $this->assertSame([], $guide->chroma);
    }

    public function testChromaExactTokenBeatsPrefixRegardlessOfOrder(): void
    {
        // KeywordReserved is listed FIRST: the exact Keyword row must still
        // win. Reversing the ordering law (prefix loop before exact lookup)
        // flips this pin red.
        $guide = StyleGuide::fromJsonString(self::FULL_DOC);
        $theme = $guide->theme();

        $this->assertNotNull($theme->keyword);
        $this->assertSame(
            Style::new()->bold()->foreground(Color::hex('#202020'))->render('k'),
            $theme->keyword->render('k'),
        );
    }

    public function testChromaSubtypeFallsBackToFirstPrefixInDocumentOrder(): void
    {
        $json = '{"document":{},"chroma":{'
            . '"LiteralStringSingle":{"color":"#111111"},'
            . '"LiteralStringDouble":{"color":"#222222"}}}';
        $theme = StyleGuide::fromJsonString($json)->theme();

        $this->assertNotNull($theme->string);
        $this->assertSame(
            Style::new()->foreground(Color::hex('#111111'))->render('s'),
            $theme->string->render('s'),
            'no exact LiteralString row: first document-order subtype wins',
        );
    }

    public function testColorVocabulary(): void
    {
        $json = '{"document":{},"chroma":{'
            . '"Keyword":{"color":"#ff0000"},'
            . '"LiteralNumber":{"color":7},'
            . '"Comment":{"color":"9"}}}';
        $theme = StyleGuide::fromJsonString($json)->theme();

        $this->assertSame(Style::new()->foreground(Color::hex('#ff0000'))->render('k'), $theme->keyword?->render('k'));
        $this->assertSame(Style::new()->foreground(Color::ansi256(7))->render('n'), $theme->number?->render('n'));
        $this->assertSame(Style::new()->foreground(Color::ansi256(9))->render('c'), $theme->comment?->render('c'));
    }

    public function testAutoAndNamedAndOutOfRangeResolveToNoColour(): void
    {
        $json = '{"document":{},"chroma":{'
            . '"Keyword":{"color":"auto","bold":true},'
            . '"LiteralNumber":{"color":"999"},'
            . '"Comment":{"color":"chocolate","italic":true}}}';
        $theme = StyleGuide::fromJsonString($json)->theme();

        $this->assertSame(Style::new()->bold()->render('k'), $theme->keyword?->render('k'), '"auto" carries the flag but no colour');
        $this->assertSame(Style::new()->render('n'), $theme->number?->render('n'), 'out-of-range index is ignored, not guessed');
        $this->assertSame(Style::new()->italic()->render('c'), $theme->comment?->render('c'), 'unknown colour name resolves to none');
    }

    public function testMalformedHexFailsFast(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StyleGuide::fromJsonString('{"document":{},"chroma":{"Keyword":{"color":"#zzz"}}}')->theme();
    }

    public function testIndentMarginCoercionEdges(): void
    {
        $guide = StyleGuide::fromJsonString('{"document":{"indent":-4,"margin":"x"}}');

        $this->assertSame(0, $guide->documentIndent, 'negative indent clamps to 0');
        $this->assertSame(0, $guide->documentMargin, 'non-numeric margin coerces to 0');

        $floaty = StyleGuide::fromJsonString('{"document":{"indent":4.5}}');
        $this->assertSame(0, $floaty->documentIndent, 'non-int types coerce to 0 rather than being guessed');
    }

    public function testIndentTokenResolution(): void
    {
        $guide = StyleGuide::fromJsonString('{"document":{"indent":3},"block_quote":{"indent_token":"│ "}}');

        $this->assertSame('│ ', $guide->indentToken('block_quote'));
        $this->assertSame('   ', $guide->indentToken(), 'document without a token falls back to indent cells');
        $this->assertSame('', $guide->indentToken('h1'), 'non-document elements answer "" when unset');
    }

    public function testInvalidJsonThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StyleGuide::fromJsonString('{not json');
    }

    public function testTopLevelMustBeObject(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StyleGuide::fromJsonString('"just a string"');
    }

    public function testThemeLandsNativeSlots(): void
    {
        $theme = StyleGuide::fromJsonString(self::FULL_DOC)->theme();

        $this->assertSame('«', $theme->documentBlockPrefix);
        $this->assertSame('»', $theme->documentBlockSuffix);
        $this->assertSame(2, $theme->documentIndent);
        $this->assertSame(1, $theme->documentMargin);
        $this->assertSame('P> ', $theme->paragraphPrefix);
        $this->assertSame(' <P', $theme->paragraphSuffix);
        $this->assertSame(
            Style::new()->foreground(Color::hex('#ff0000'))->bold()->render('h'),
            $theme->heading1->render('h'),
        );
        $this->assertSame(
            Style::new()->italic()->render('q'),
            $theme->blockquote->render('q'),
        );
        $this->assertSame(
            Style::new()->foreground(Color::ansi256(5))->render('b'),
            $theme->bold->render('b'),
        );
    }

    public function testHeadingAndQuotemarkSpellingsAndAttributeDefaults(): void
    {
        // h2 absent → generic "heading" block; blockquote spelling accepted;
        // no "strong" → bold carries the real SGR attribute, not a no-op.
        $theme = StyleGuide::fromJsonString(
            '{"document":{},"heading":{"color":"#00ff00"},"blockquote":{"underline":true}}'
        )->theme();

        $this->assertSame(Style::new()->foreground(Color::hex('#00ff00'))->render('t'), $theme->heading2->render('t'));
        $this->assertSame(Style::new()->underline()->render('q'), $theme->blockquote->render('q'));
        $this->assertSame(Style::new()->bold()->render('x'), $theme->bold->render('x'));
        $this->assertSame(Style::new()->italic()->render('x'), $theme->italic->render('x'));
    }

    public function testFromFileReadsJsonFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'y4guide');
        $this->assertIsString($path);
        try {
            file_put_contents($path, self::FULL_DOC);
            $guide = StyleGuide::fromFile($path);

            $this->assertSame('«', $guide->blockPrefix);
            $this->assertSame(['color' => '#ff0000', 'bold' => true], $guide->element('h1'));
        } finally {
            @unlink($path);
        }
    }

    public function testFromFileDoorThrowsOnMissingPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StyleGuide::fromFile('/definitely/not/here/y4-style.json');
    }

    public function testFromFileDoorThrowsWhenReadFailsAfterProbePasses(): void
    {
        // A directory passes is_readable() but never file_get_contents():
        // exercises the @ + === false race net independently of the probe,
        // on every platform and every uid.
        $this->expectException(\InvalidArgumentException::class);
        StyleGuide::fromFile(sys_get_temp_dir());
    }
}
