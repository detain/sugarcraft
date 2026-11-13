<?php

declare(strict_types=1);

namespace SugarCraft\MdView\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\MdView\GlamourTheme;
use SugarCraft\Markdown\Theme;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Glamour STYLE JSON parsing + the sugar-markdown adapter,
 * including the live `--theme-config` route that keeps the adapter from
 * rotting into dead code again (an earlier audit deleted the first, unwired
 * incarnation for exactly that reason).
 *
 * @covers \SugarCraft\MdView\GlamourTheme
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
            // 085: real glamour dark.json ships these too; the adapter
            // drops them by decision, and
            // testDroppedTableAndImageStylesStayAddressable pins that.
            'image'         => ['color' => '252', 'block_prefix' => "\n"],
            'table_header'  => ['color' => '228', 'bold' => true],
            'table_row'     => ['color' => '252'],
            'table_cell'    => ['color' => '252'],
            'table_separator' => ['color' => '238', 'bold' => true],
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

        // The flat per-element colour map (sugar-markdown's own schema) has no
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

    public function testMarkdownThemeCarriesDocumentAffixesIndentAndMargin(): void
    {
        $shine = GlamourTheme::fromJsonString($this->stockGlamourJson())->toMarkdownTheme();

        self::assertSame("\n", $shine->documentBlockPrefix);
        self::assertSame("\n", $shine->documentBlockSuffix);
        self::assertSame(1, $shine->documentIndent);
        self::assertSame(2, $shine->documentMargin);
    }

    public function testMarkdownThemeHeadingsAndParagraphStyles(): void
    {
        $shine = GlamourTheme::fromJsonString($this->stockGlamourJson())->toMarkdownTheme();

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

    public function testMarkdownThemeChromaFamilies(): void
    {
        $shine = GlamourTheme::fromJsonString($this->stockGlamourJson())->toMarkdownTheme();

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
        ], JSON_THROW_ON_ERROR))->toMarkdownTheme();
        $flippedKeyword = $flipped->keyword?->render('x') ?? '';
        self::assertStringContainsString('38;5;205', $flippedKeyword);
        self::assertStringNotContainsString('38;5;141', $flippedKeyword);
    }

    public function testMarkdownThemeChromaFamilyPrefixOnlyStillResolves(): void
    {
        $json = json_encode([
            'document' => ['margin' => 0],
            'chroma'   => ['KeywordReserved' => ['color' => '197'], 'CommentMultiline' => ['color' => '240']],
        ], JSON_THROW_ON_ERROR);

        $shine = GlamourTheme::fromJsonString($json)->toMarkdownTheme();

        self::assertStringContainsString('38;5;197', $shine->keyword?->render('x') ?? '');
        self::assertStringContainsString('38;5;240', $shine->comment?->render('x') ?? '');
        self::assertNull($shine->string);
    }

    public function testParagraphBlockAffixesRouteToMarkdownPrefixSuffix(): void
    {
        // S08 tightened these slots to the whitespace class (glamour's own
        // usage is vertical spacing only), so the routing proof now rides
        // distinct whitespace bytes — an arbitrary-glyph affix is the
        // refusal test's subject, not this one's.
        $json = json_encode([
            'document'  => [],
            'paragraph' => ['block_prefix' => "\n", 'block_suffix' => "\t "],
        ], JSON_THROW_ON_ERROR);

        $shine = GlamourTheme::fromJsonString($json)->toMarkdownTheme();

        self::assertSame("\n", $shine->paragraphPrefix);
        self::assertSame("\t ", $shine->paragraphSuffix);
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
        $shine = GlamourTheme::fromJsonString($json)->toMarkdownTheme();

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
        GlamourTheme::fromJsonString($json)->toMarkdownTheme();
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
        $path = tempnam(sys_get_temp_dir(), 'mdview-t4-theme-');
        self::assertNotFalse($path);
        file_put_contents($path, $this->stockGlamourJson());
        try {
            $fromFile = GlamourTheme::fromFile($path);
            self::assertSame(2, $fromFile->documentMargin);
            self::assertSame($fromFile->toMarkdownTheme()->heading1->render('x'), GlamourTheme::fromJsonString($this->stockGlamourJson())->toMarkdownTheme()->heading1->render('x'));
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
        // The anti-dead-code pin: a glamour-shaped --theme-config is
        // consumed by RenderCommand, not just parseable in isolation. The
        // document block affixes must show up in the rendered output.
        $md = tempnam(sys_get_temp_dir(), 'mdview-t4-md-');
        $config = tempnam(sys_get_temp_dir(), 'mdview-t4-cfg-');
        self::assertNotFalse($md);
        self::assertNotFalse($config);
        file_put_contents($md, "body text\n");
        file_put_contents($config, json_encode([
            'document' => ['block_prefix' => "\n", 'block_suffix' => "\t", 'margin' => 0],
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
            $output->method('write')->willReturnCallback(
                function ($messages, bool $newline = false, int $type = 0) use (&$captured): void {
                    $captured = (string) $messages;
                }
            );

            $command = new \SugarCraft\MdView\RenderCommand();
            $invoke = new \ReflectionMethod($command, 'execute');
            self::assertSame(0, $invoke->invoke($command, $input, $output));

            self::assertIsString($captured);
            // S08's whitespace-only decoration law: the routing proof is now
            // the affix bytes themselves (the old bracket glyphs refuse at
            // the parse door — see testUnsafeDecorationSlotsRefuseByName).
            self::assertStringStartsWith("\n", $captured);
            self::assertStringEndsWith("\t", $captured);
        } finally {
            unlink($md);
            unlink($config);
        }
    }


    /**
     * The size ceiling must guard EVERY parse door, not just the CLI
     * stream read: fromFile() slurping an unbounded path was the bypass.
     */
    public function testFromFileRefusesConfigsOverTheByteCap(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mdview-cap-');
        $this->assertIsString($path);
        try {
            $handle = fopen($path, 'wb');
            $this->assertIsResource($handle);
            fwrite($handle, '{"document":{');
            $chunk = str_repeat('x', 4096);
            $written = 14;
            while ($written < GlamourTheme::MAX_CONFIG_BYTES + 1) {
                fwrite($handle, $chunk);
                $written += 4096;
            }
            fclose($handle);

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('exceeds 1000000 bytes');
            GlamourTheme::fromFile($path);
        } finally {
            unlink($path);
        }
    }

    /**
     * A config carrying nested values where decorator strings belong is
     * malformed; it must coerce to empty strings, never emit PHP's
     * "Array to string conversion" warning at the terminal.
     */
    public function testNonStringDocumentDecoratorsCoerceToEmptyStrings(): void
    {
        $theme = GlamourTheme::fromDecoded([
            'document' => [
                'block_prefix' => ['nested'],
                'block_suffix' => ['tail' => 'x'],
                'indent'       => 0,
                'margin'       => 0,
            ],
        ]);
        $this->assertSame('', $theme->blockPrefix);
        $this->assertSame('', $theme->blockSuffix);

        $markdownTheme = $theme->toMarkdownTheme();
        $this->assertInstanceOf(Theme::class, $markdownTheme);
    }

    /** @return iterable<string, array{string,string}> slot-key => [json, expected message] */
    public static function unsafeDecorationProvider(): iterable
    {
        // The P2 evidence class (evil-theme.json): every payload a style
        // file could use to speak to the terminal through a decoration
        // slot — title-set OSC, raw SGR, a forged OSC 8 envelope that
        // would bypass the renderer's refused-scheme gate, and a CSI
        // cursor-move prefix.
        yield 'document OSC 0 title' => ['document.block_prefix', "\x1b]0;pwned\x07"];
        yield 'document SGR'         => ['document.block_suffix', "\x1b[31m"];
        yield 'paragraph OSC 8'      => ['paragraph.block_prefix', "\x1b]8;;javascript:alert(1)\x1b\\CLICK"];
        yield 'paragraph DCS'         => ['paragraph.block_suffix', "\x1bP1;2\$pwned\x1b\\"];
    }

    public function testUnsafeDecorationSlotsRefuseByName(): void
    {
        // Finding 005: decoration slots are glamour's vertical-spacing
        // channel; accepting arbitrary strings let a style file inject live
        // terminal control sequences into EVERY rendered frame at rc 0.
        foreach (self::unsafeDecorationProvider() as [$slot, $payload]) {
            [$element, $field] = explode('.', $slot);
            $decoded = json_decode($this->stockGlamourJson(), true);
            $decoded[$element][$field] = $payload;

            try {
                GlamourTheme::fromDecoded($decoded);
                self::fail("slot $slot accepted an escape payload");
            } catch (\InvalidArgumentException $refusal) {
                self::assertStringContainsString($slot, $refusal->getMessage(), 'the refusal names the slot');
                self::assertStringContainsString('newlines, tabs and spaces', $refusal->getMessage());
            }
        }
    }

    public function testWhitespaceDecorationsStillPass(): void
    {
        // Stock glamour ships "\n" affixes — the whitespace class must stay
        // accepted untouched or every real style file breaks.
        $decoded = json_decode($this->stockGlamourJson(), true);
        $decoded['paragraph']['block_prefix'] = "\n";
        $decoded['paragraph']['block_suffix'] = " \t\n";
        $theme = GlamourTheme::fromDecoded($decoded);

        self::assertSame("\n", $theme->toMarkdownTheme()->paragraphPrefix);
        self::assertSame(" \t\n", $theme->toMarkdownTheme()->paragraphSuffix);
    }

    public function testDecorationIntsOverCapRefuseWithoutSlurping(): void
    {
        // Finding 018: document.indent/margin crossed straight into
        // str_repeat — a 60-byte style file produced a 1.5 GB allocation
        // and rc 255 below any consumer's own guards.
        $cap = Theme::MAX_DOCUMENT_DECORATION;

        $huge = json_decode($this->stockGlamourJson(), true);
        $huge['document']['indent'] = 1_500_000_000;
        try {
            GlamourTheme::fromDecoded($huge);
            self::fail('a 1.5e9-cell indent must refuse');
        } catch (\InvalidArgumentException $refusal) {
            self::assertStringContainsString('document.indent', $refusal->getMessage());
            self::assertStringContainsString((string) $cap, $refusal->getMessage());
        }

        // Numeric strings carry the same payload through the coercion path.
        $stringy = json_decode($this->stockGlamourJson(), true);
        $stringy['document']['margin'] = '999999999';
        try {
            GlamourTheme::fromDecoded($stringy);
            self::fail('a 999999999-line margin must refuse');
        } catch (\InvalidArgumentException $refusal) {
            self::assertStringContainsString('document.margin', $refusal->getMessage());
        }

        // Cap-edge honesty: exactly the cap is stock-plus-generous, accepted.
        $edge = json_decode($this->stockGlamourJson(), true);
        $edge['document']['margin'] = $cap;
        self::assertSame($cap, GlamourTheme::fromDecoded($edge)->documentMargin);
    }

    public function testFromFileRefusesNonRegularFilesByNamedDoor(): void
    {
        // Finding 015 at the library door too: is_readable() passes for
        // directories and the filesize() cap skips when size is unknowable
        // — the guard refuses before either hole can open.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('theme config not readable: /tmp');
        GlamourTheme::fromFile('/tmp');
    }

    public function testDroppedTableAndImageStylesStayAddressable(): void
    {
        // Finding 085's default arm: the drop-list is DISCLOSED, not
        // silently mapped. The fixture now carries the five glamour table/
        // image blocks stock dark.json ships; the projection deliberately
        // leaves the matching SugarMarkdown slots untouched (mapping them
        // would change what existing style files render — owner-gated),
        // while element() keeps every dropped primitive readable.
        $theme = GlamourTheme::fromJsonString($this->stockGlamourJson());

        self::assertNotNull($theme->element('image'), 'dropped keys stay readable — no silent loss');
        self::assertNull($theme->toMarkdownTheme()->image, 'image is NOT mapped (disclosed drop)');
        self::assertNull($theme->toMarkdownTheme()->tableHeader, 'table_header is NOT mapped (disclosed drop)');
        self::assertNull($theme->toMarkdownTheme()->tableCell, 'table_cell is NOT mapped (disclosed drop)');
    }
}
