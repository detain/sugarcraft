<?php

declare(strict_types=1);

namespace SugarCraft\Glow\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Glow\GlamourTheme;
use SugarCraft\Shine\Theme;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * E735 step 10.25 — glamour STYLE JSON parsing + the candy-shine adapter,
 * including the live `--theme-config` route that keeps the adapter from
 * rotting into dead code again (audit W11 deleted the first, unwired
 * incarnation for exactly that reason).
 *
 * @covers \SugarCraft\Glow\GlamourTheme
 */
final class GlamourThemeTest extends TestCase
{
    /**
     * Condensed from charmbracelet/glamour styles/dark.json — the real
     * nested shape: element blocks carrying StylePrimitive fields plus a
     * sibling chroma map of token-name primitives.
     */
    private function stockGlamourJson(): string
    {
        return json_encode([
            'document'    => ['block_prefix' => "\n", 'block_suffix' => "\n", 'color' => '252', 'margin' => 2, 'indent' => 1],
            'paragraph'   => ['margin' => 1, 'color' => '252'],
            'heading'     => ['block_prefix' => "\n", 'color' => '229', 'bold' => true],
            'h1'          => ['block_prefix' => "\n", 'block_suffix' => "\n", 'color' => '228', 'bold' => true],
            'block_quote' => ['block_prefix' => "\n", 'indent' => 1, 'indent_token' => '│ ', 'color' => '252'],
            'code_block'  => ['color' => '246', 'indent_token' => '    '],
            'codespan'    => ['color' => '228'],
            'strong'      => ['bold' => true],
            'emph'        => ['italic' => true],
            'link'        => ['color' => '35', 'underline' => true],
            'hr'          => ['color' => '238'],
            'strikethrough' => ['strikethrough' => true],
            'list'        => ['color' => '252'],
            'chroma'      => [
                'Keyword'       => ['color' => '205', 'bold' => true],
                'KeywordType'   => ['color' => '141'],
                'Comment'       => ['color' => '243', 'italic' => true],
                'LiteralString' => ['color' => '179'],
                'LiteralNumber' => ['color' => '247'],
                'NameFunction'  => ['color' => '148'],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    public function testSchemaSignatureBothPolarities(): void
    {
        $glamour = json_decode($this->stockGlamourJson(), associative: true);
        self::assertTrue(GlamourTheme::isGlamourSchema($glamour));

        // The flat per-element colour map (candy-shine's own schema) has no
        // document OBJECT — a same-named scalar must not false-positive.
        self::assertFalse(GlamourTheme::isGlamourSchema(['paragraph' => ['foreground' => '#fff']]));
        self::assertFalse(GlamourTheme::isGlamourSchema(['document' => 'not-a-block']));
    }

    public function testDocumentBlockFieldsParse(): void
    {
        $theme = GlamourTheme::fromJsonString($this->stockGlamourJson());

        self::assertSame("\n", $theme->blockPrefix);
        self::assertSame("\n", $theme->blockSuffix);
        self::assertSame(1, $theme->documentIndent);
        self::assertSame(2, $theme->documentMargin);
    }

    public function testElementAndChromaBlocksStayAddressable(): void
    {
        $theme = GlamourTheme::fromJsonString($this->stockGlamourJson());

        self::assertSame('228', $theme->element('h1')['color']);
        self::assertTrue($theme->chroma('Keyword')['bold']);
        self::assertNull($theme->element('no_such_block'));
        self::assertNull($theme->chroma('NameBuiltin'));
        // The document's own primitive rides the elements map too.
        self::assertSame(2, $theme->element('document')['margin']);
    }

    public function testIndentTokenPerElementWithDocumentFallback(): void
    {
        $theme = GlamourTheme::fromJsonString($this->stockGlamourJson());

        self::assertSame('│ ', $theme->indentToken('block_quote'));
        self::assertSame('    ', $theme->indentToken('code_block'));
        // document has indent=1 and no indent_token → space fallback.
        self::assertSame(' ', $theme->indentToken());
        // Elements without either answer ''.
        self::assertSame('', $theme->indentToken('hr'));
    }

    public function testShineThemeCarriesDocumentAffixesIndentAndMargin(): void
    {
        $shine = GlamourTheme::fromJsonString($this->stockGlamourJson())->toShineTheme();

        self::assertSame("\n", $shine->documentBlockPrefix);
        self::assertSame("\n", $shine->documentBlockSuffix);
        self::assertSame(1, $shine->documentIndent);
        self::assertSame(2, $shine->documentMargin);
    }

    public function testShineThemeHeadingsAndParagraphStyles(): void
    {
        $shine = GlamourTheme::fromJsonString($this->stockGlamourJson())->toShineTheme();

        // h1 defines its own colour 228…
        self::assertStringContainsString('38;5;228', $shine->heading1->render('x'));
        // …h2-h6 fall through to the generic `heading` block (229)…
        self::assertStringContainsString('38;5;229', $shine->heading4->render('x'));
        // …paragraph, codespan and link land on their parity slots.
        self::assertStringContainsString('38;5;252', $shine->paragraph->render('x'));
        self::assertStringContainsString('38;5;228', $shine->code->render('x'));
        $link = $shine->link->render('x');
        self::assertStringContainsString('38;5;35', $link);
        self::assertStringContainsString("\x1b[4m", $link); // underline
    }

    public function testShineThemeChromaFamilies(): void
    {
        $shine = GlamourTheme::fromJsonString($this->stockGlamourJson())->toShineTheme();

        // EXACT base token wins over the first subtype: Keyword (205) is
        // picked although KeywordType (141) also prefix-matches.
        $keyword = $shine->keyword?->render('x') ?? '';
        self::assertStringContainsString('38;5;205', $keyword);
        self::assertStringNotContainsString('38;5;141', $keyword);

        self::assertStringContainsString('38;5;179', $shine->string?->render('x') ?? '');
        self::assertStringContainsString('38;5;247', $shine->number?->render('x') ?? '');
        $comment = $shine->comment?->render('x') ?? '';
        self::assertStringContainsString('38;5;243', $comment);
        self::assertStringContainsString("\x1b[3m", $comment); // italic
        // Families without a Theme slot (NameFunction etc.) are dropped:
        // its colour must not leak into any of the four chroma families.
        foreach ([$shine->keyword, $shine->string, $shine->number, $shine->comment] as $family) {
            self::assertStringNotContainsString('38;5;148', $family?->render('x') ?? '');
        }

        // The win must come from EXACT matching, not from doc order: list
        // the subtype FIRST and the exact base token still takes it.
        $flipped = GlamourTheme::fromJsonString((string) json_encode([
            'document' => [],
            'chroma'   => ['KeywordType' => ['color' => '141'], 'Keyword' => ['color' => '205']],
        ], JSON_THROW_ON_ERROR))->toShineTheme();
        $flippedKeyword = $flipped->keyword?->render('x') ?? '';
        self::assertStringContainsString('38;5;205', $flippedKeyword);
        self::assertStringNotContainsString('38;5;141', $flippedKeyword);
    }

    public function testShineThemeChromaFamilyPrefixOnlyStillResolves(): void
    {
        $json = json_encode([
            'document' => ['margin' => 0],
            'chroma'   => ['KeywordReserved' => ['color' => '197'], 'CommentMultiline' => ['color' => '240']],
        ], JSON_THROW_ON_ERROR);

        $shine = GlamourTheme::fromJsonString($json)->toShineTheme();

        self::assertStringContainsString('38;5;197', $shine->keyword?->render('x') ?? '');
        self::assertStringContainsString('38;5;240', $shine->comment?->render('x') ?? '');
        self::assertNull($shine->string);
    }

    public function testParagraphBlockAffixesRouteToShinePrefixSuffix(): void
    {
        $json = json_encode([
            'document'  => [],
            'paragraph' => ['block_prefix' => '> ', 'block_suffix' => ' <'],
        ], JSON_THROW_ON_ERROR);

        $shine = GlamourTheme::fromJsonString($json)->toShineTheme();

        self::assertSame('> ', $shine->paragraphPrefix);
        self::assertSame(' <', $shine->paragraphSuffix);
    }

    public function testColourVocabularyHexAutoAndRanges(): void
    {
        $json = json_encode([
            'document'  => [],
            'h1'        => ['color' => '#ff0000'],
            'h2'        => ['color' => 'auto'],
            'h3'        => ['color' => 300],     // int beyond the palette → ignored
            'h4'        => ['color' => '999'],   // 3-digit out-of-range index → ignored
            'paragraph' => ['color' => '7'],
        ], JSON_THROW_ON_ERROR);
        $shine = GlamourTheme::fromJsonString($json)->toShineTheme();

        self::assertStringContainsString('38;2;255;0;0', $shine->heading1->render('x'));
        // No foreground on `auto` / out-of-range colours: no 38;… SGR at all.
        self::assertStringNotContainsString('38;', $shine->heading2->render('x'));
        self::assertStringNotContainsString('38;', $shine->heading3->render('x'));
        self::assertStringNotContainsString('38;', $shine->heading4->render('x'));
        // Index 7 sits in the basic 0-15 band: Color::ansi256 folds it to
        // the plain SGR (37), it is NOT emitted as an extended-colour pair.
        self::assertStringContainsString("\x1b[37m", $shine->paragraph->render('x'));
        self::assertStringNotContainsString('38;', $shine->paragraph->render('x'));
    }

    public function testMalformedHexFailsFast(): void
    {
        $json = json_encode(['document' => [], 'h1' => ['color' => '#gg']], JSON_THROW_ON_ERROR);

        $this->expectException(\InvalidArgumentException::class);
        GlamourTheme::fromJsonString($json)->toShineTheme();
    }

    public function testBrokenJsonThrowsWithGlamourWording(): void
    {
        try {
            GlamourTheme::fromJsonString('{not json');
            self::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('not valid JSON', $e->getMessage());
        }

        try {
            GlamourTheme::fromJsonString('"a bare string is valid JSON but not a document"');
            self::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('must be a JSON object', $e->getMessage());
        }
    }

    public function testFromFileRoundTripAndUnreadable(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'glow-t4-theme-');
        self::assertNotFalse($path);
        file_put_contents($path, $this->stockGlamourJson());
        try {
            $fromFile = GlamourTheme::fromFile($path);
            self::assertSame(2, $fromFile->documentMargin);
            self::assertSame($fromFile->toShineTheme()->heading1->render('x'), GlamourTheme::fromJsonString($this->stockGlamourJson())->toShineTheme()->heading1->render('x'));
        } finally {
            unlink($path);
        }

        $this->expectException(\InvalidArgumentException::class);
        GlamourTheme::fromFile('/no/such/glow-t4-theme.json');
    }

    public function testFromDecodedMatchesFromJsonString(): void
    {
        $decoded = json_decode($this->stockGlamourJson(), associative: true);
        self::assertEquals(GlamourTheme::fromJsonString($this->stockGlamourJson()), GlamourTheme::fromDecoded($decoded));
    }

    public function testThemeConfigFileWithGlamourSchemaReachesLivePath(): void
    {
        // The W11 anti-dead-code pin: a glamour-shaped --theme-config is
        // consumed by RenderCommand, not just parseable in isolation. The
        // document block affixes must show up in the rendered output.
        $md = tempnam(sys_get_temp_dir(), 'glow-t4-md-');
        $config = tempnam(sys_get_temp_dir(), 'glow-t4-cfg-');
        self::assertNotFalse($md);
        self::assertNotFalse($config);
        file_put_contents($md, "body text\n");
        file_put_contents($config, json_encode([
            'document' => ['block_prefix' => '[DOC_OPEN]', 'block_suffix' => '[DOC_CLOSE]', 'margin' => 0],
        ], JSON_THROW_ON_ERROR));

        try {
            $input = $this->createMock(InputInterface::class);
            $input->method('getArgument')->with('file')->willReturn($md);
            $input->method('getOption')->willReturnMap([
                ['theme-config', $config],
                ['style', null],
                ['theme', 'ansi'],
                ['width', 0],
                ['pager', false],
                ['no-hyperlinks', false],
            ]);

            $captured = null;
            $output = $this->createMock(OutputInterface::class);
            $output->method('writeln')->willReturnCallback(
                static function (string $text) use (&$captured): void {
                    $captured = $text;
                }
            );

            $command = new \SugarCraft\Glow\RenderCommand();
            $invoke = new \ReflectionMethod($command, 'execute');
            self::assertSame(0, $invoke->invoke($command, $input, $output));

            self::assertIsString($captured);
            self::assertStringStartsWith('[DOC_OPEN]', $captured);
            self::assertStringEndsWith('[DOC_CLOSE]', $captured);
        } finally {
            unlink($md);
            unlink($config);
        }
    }
}
