<?php

declare(strict_types=1);

namespace SugarCraft\MdView\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\MdView\GlamourDecorationGate;
use SugarCraft\MdView\RenderCommand;
use SugarCraft\Markdown\StyleGuide;
use SugarCraft\Markdown\Theme;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * candy-mdview's door onto glamour style JSON (P1-12c-mdview, owner option b).
 *
 * The loader is sugar-markdown's {@see StyleGuide}; this door keeps one
 * refusal in front of it, the whitespace-only affix law
 * ({@see GlamourDecorationGate}), and routes every `--theme-config` glamour
 * file through both. The rendered output follows StyleGuide, except for the
 * edge files pinned at the bottom of this class (headingCase, imageText, and
 * the string `"false"` flag), which are accepted changes.
 *
 * @covers \SugarCraft\MdView\GlamourDecorationGate
 * @covers \SugarCraft\MdView\RenderCommand
 */
final class GlamourDecorationGateTest extends TestCase
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
            // Real glamour dark.json ships these too; the door does not map
            // them, and testUnmappedTableAndImageStylesStayUnmapped pins that.
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

    /** The door's full path: decode, gate, then the sugar-markdown loader. */
    private function themeFrom(string $json): Theme
    {
        $decoded = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return StyleGuide::fromDecoded(GlamourDecorationGate::admit($decoded))->theme();
    }

    public function testSchemaSignatureBothPolarities(): void
    {
        $glamour = json_decode($this->stockGlamourJson(), associative: true);
        self::assertTrue(StyleGuide::isGlamourSchema($glamour));

        // The flat per-element colour map (sugar-markdown's own schema) has no
        // document OBJECT — a same-named scalar must not false-positive.
        self::assertFalse(StyleGuide::isGlamourSchema(['paragraph' => ['foreground' => '#fff']]));
        self::assertFalse(StyleGuide::isGlamourSchema(['document' => 'not-a-block']));
    }

    public function testMarkdownThemeCarriesDocumentAffixesIndentAndMargin(): void
    {
        $shine = $this->themeFrom($this->stockGlamourJson());

        self::assertSame("\n", $shine->documentBlockPrefix);
        self::assertSame("\n", $shine->documentBlockSuffix);
        self::assertSame(1, $shine->documentIndent);
        self::assertSame(2, $shine->documentMargin);
    }

    public function testMarkdownThemeHeadingsAndParagraphStyles(): void
    {
        $shine = $this->themeFrom($this->stockGlamourJson());

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
        $shine = $this->themeFrom($this->stockGlamourJson());

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
        $flipped = $this->themeFrom((string) json_encode([
            'document' => [],
            'chroma'   => ['KeywordType' => ['color' => '141'], 'Keyword' => ['color' => '205']],
        ], JSON_THROW_ON_ERROR));
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

        $shine = $this->themeFrom($json);

        self::assertStringContainsString('38;5;197', $shine->keyword?->render('x') ?? '');
        self::assertStringContainsString('38;5;240', $shine->comment?->render('x') ?? '');
        self::assertNull($shine->string);
    }

    public function testParagraphBlockAffixesRouteToMarkdownPrefixSuffix(): void
    {
        // The slots are whitespace-only (glamour's own usage is vertical
        // spacing), so the routing proof rides distinct whitespace bytes.
        $json = json_encode([
            'document'  => [],
            'paragraph' => ['block_prefix' => "\n", 'block_suffix' => "\t "],
        ], JSON_THROW_ON_ERROR);

        $shine = $this->themeFrom($json);

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
        $shine = $this->themeFrom($json);

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
        $this->themeFrom($json);
    }

    public function testAdmitReturnsAcceptedDocumentUnchanged(): void
    {
        $decoded = json_decode($this->stockGlamourJson(), associative: true);

        self::assertSame($decoded, GlamourDecorationGate::admit($decoded));
    }

    /**
     * The door coerces a non-string affix to '' (the contract since before the
     * loader move). StyleGuide's own cast would print "Array to string
     * conversion" and render a number as a glyph, so the gate must catch both.
     */
    public function testNonStringDocumentDecoratorsCoerceToEmptyStrings(): void
    {
        $decoded = [
            'document' => [
                'block_prefix' => ['nested'],
                'block_suffix' => ['tail' => 'x'],
                'indent'       => 0,
                'margin'       => 0,
            ],
            'paragraph' => ['block_prefix' => 5, 'block_suffix' => null],
        ];

        $admitted = GlamourDecorationGate::admit($decoded);
        self::assertSame('', $admitted['document']['block_prefix']);
        self::assertSame('', $admitted['document']['block_suffix']);
        self::assertSame('', $admitted['paragraph']['block_prefix']);
        self::assertSame('', $admitted['paragraph']['block_suffix']);

        $markdownTheme = StyleGuide::fromDecoded($admitted)->theme();
        self::assertInstanceOf(Theme::class, $markdownTheme);
        self::assertSame('', $markdownTheme->documentBlockPrefix);
        self::assertSame('', $markdownTheme->documentBlockSuffix);
        self::assertSame('', $markdownTheme->paragraphPrefix);
    }

    /** @return iterable<string, array{mixed}> */
    public static function nonStringAffixProvider(): iterable
    {
        yield 'int' => [5];
        yield 'float' => [1.5];
        yield 'bool true' => [true];
        yield 'bool false' => [false];
        yield 'null' => [null];
        yield 'list holding an OSC title-set' => [["\x1b]0;title\x07"]];
        yield 'map holding raw SGR' => [['payload' => "\x1b[31m"]];
    }

    /**
     * Coercion is the whole rule for a non-string affix: it is never a refusal
     * and never names a slot, even when the nested payload would be refused as
     * a string. The refusal law (`glamour.decoration_unsafe`) applies only to
     * string affixes.
     */
    #[DataProvider('nonStringAffixProvider')]
    public function testNonStringAffixCoercesWithoutRefusal(mixed $value): void
    {
        $decoded = [
            'document'  => ['block_prefix' => $value, 'block_suffix' => $value],
            'paragraph' => ['block_prefix' => $value, 'block_suffix' => $value],
        ];

        $admitted = GlamourDecorationGate::admit($decoded);

        self::assertSame('', $admitted['document']['block_prefix']);
        self::assertSame('', $admitted['document']['block_suffix']);
        self::assertSame('', $admitted['paragraph']['block_prefix']);
        self::assertSame('', $admitted['paragraph']['block_suffix']);
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
                GlamourDecorationGate::admit($decoded);
                self::fail("slot $slot accepted an escape payload");
            } catch (\InvalidArgumentException $refusal) {
                self::assertStringContainsString($slot, $refusal->getMessage(), 'the refusal names the slot');
                self::assertStringContainsString('newlines, tabs and spaces', $refusal->getMessage());
            }
        }
    }

    public function testUnsafeDecorationRefusesBeforeTheLoaderRuns(): void
    {
        // The gate is in front of StyleGuide: a payload that the loader would
        // otherwise sanitize (rather than refuse) must still be refused here.
        $decoded = json_decode($this->stockGlamourJson(), true);
        $decoded['document']['block_prefix'] = "\x1b]0;pwned\x07";

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('document.block_prefix');
        StyleGuide::fromDecoded(GlamourDecorationGate::admit($decoded));
    }

    public function testWhitespaceDecorationsStillPass(): void
    {
        // Stock glamour ships "\n" affixes — the whitespace class must stay
        // accepted untouched or every real style file breaks.
        $decoded = json_decode($this->stockGlamourJson(), true);
        $decoded['paragraph']['block_prefix'] = "\n";
        $decoded['paragraph']['block_suffix'] = " \t\n";
        $theme = StyleGuide::fromDecoded(GlamourDecorationGate::admit($decoded))->theme();

        self::assertSame("\n", $theme->paragraphPrefix);
        self::assertSame(" \t\n", $theme->paragraphSuffix);
    }

    public function testDecorationIntsOverCapRefuseWithoutSlurping(): void
    {
        // Finding 018: document.indent/margin crossed straight into
        // str_repeat — a 60-byte style file produced a 1.5 GB allocation
        // and rc 255 below any consumer's own guards. The loader refuses
        // over-cap values by name; the gate passes them through untouched.
        $cap = Theme::MAX_DOCUMENT_DECORATION;

        $huge = json_decode($this->stockGlamourJson(), true);
        $huge['document']['indent'] = 1_500_000_000;
        try {
            StyleGuide::fromDecoded(GlamourDecorationGate::admit($huge));
            self::fail('a 1.5e9-cell indent must refuse');
        } catch (\InvalidArgumentException $refusal) {
            self::assertStringContainsString('document.indent', $refusal->getMessage());
            self::assertStringContainsString((string) $cap, $refusal->getMessage());
        }

        // Numeric strings carry the same payload through the coercion path.
        $stringy = json_decode($this->stockGlamourJson(), true);
        $stringy['document']['margin'] = '999999999';
        try {
            StyleGuide::fromDecoded(GlamourDecorationGate::admit($stringy));
            self::fail('a 999999999-line margin must refuse');
        } catch (\InvalidArgumentException $refusal) {
            self::assertStringContainsString('document.margin', $refusal->getMessage());
        }

        // Cap-edge honesty: exactly the cap is stock-plus-generous, accepted.
        $edge = json_decode($this->stockGlamourJson(), true);
        $edge['document']['margin'] = $cap;
        self::assertSame($cap, StyleGuide::fromDecoded(GlamourDecorationGate::admit($edge))->documentMargin);
    }

    public function testUnmappedTableAndImageStylesStayUnmapped(): void
    {
        // The table and plain-image blocks have no Theme slot in this door.
        // Mapping them would change what existing style files render, so the
        // slots stay null rather than being repurposed.
        $theme = $this->themeFrom($this->stockGlamourJson());

        self::assertNull($theme->image, 'image is NOT mapped');
        self::assertNull($theme->tableHeader, 'table_header is NOT mapped');
        self::assertNull($theme->tableCell, 'table_cell is NOT mapped');
    }

    /**
     * Accepted edge change (P1-12c-mdview): StyleGuide reads `uppercase` /
     * `lowercase` on the heading blocks into headingCase. The former door
     * ignored them.
     */
    public function testHeadingCaseFlagFollowsStyleGuide(): void
    {
        $theme = $this->themeFrom((string) json_encode([
            'document' => [],
            'h1'       => ['uppercase' => true],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('upper', $theme->headingCase);
    }

    /**
     * Accepted edge change (P1-12c-mdview): StyleGuide reads `inline_image` /
     * `block_image` into imageText. The former door ignored them.
     */
    public function testInlineImageStyleFollowsStyleGuide(): void
    {
        $theme = $this->themeFrom((string) json_encode([
            'document'     => [],
            'inline_image' => ['color' => '208'],
        ], JSON_THROW_ON_ERROR));

        self::assertNotNull($theme->imageText);
        self::assertStringContainsString('38;5;208', $theme->imageText->render('x'));
    }

    /**
     * Accepted edge change (P1-12c-mdview): StyleGuide reads the string
     * `"false"` as off. The former door read any non-empty string as on.
     */
    public function testStringFalseFlagIsOffUnderStyleGuide(): void
    {
        $theme = $this->themeFrom((string) json_encode([
            'document' => [],
            'strong'   => ['bold' => 'false'],
        ], JSON_THROW_ON_ERROR));

        self::assertStringNotContainsString("\x1b[1m", $theme->bold->render('x'));
    }

    /**
     * Every bold value the README's "Accepted edge changes" names as off, plus
     * the values that keep their old reading. The off rows are the edge
     * changes; the on rows guard against a flagOn that turns everything off.
     *
     * @return iterable<string, array{mixed, bool}> value => [raw JSON value, bold on?]
     */
    public static function boldFlagValues(): iterable
    {
        yield '"false"'             => ['false', false];
        yield '"no"'                => ['no', false];
        yield '"off"'               => ['off', false];
        yield '"OFF" in capitals'   => ['OFF', false];
        yield '"No" mixed case'     => ['No', false];
        yield 'non-zero float 1.0'  => [1.0, false];
        yield 'non-empty array'     => [['on'], false];
        yield 'boolean false'       => [false, false];
        yield 'integer 0'           => [0, false];
        yield '"0"'                 => ['0', false];
        yield 'empty string'        => ['', false];
        yield 'boolean true'        => [true, true];
        yield 'integer 1'           => [1, true];
        yield '"yes"'               => ['yes', true];
        yield '"on"'                => ['on', true];
    }

    #[DataProvider('boldFlagValues')]
    public function testBoldFlagEdgeValuesFollowStyleGuide(mixed $value, bool $expectedOn): void
    {
        $theme = $this->themeFrom((string) json_encode([
            'document' => [],
            'strong'   => ['bold' => $value],
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));

        $rendered = $theme->bold->render('x');
        if ($expectedOn) {
            self::assertStringContainsString("\x1b[1m", $rendered);
        } else {
            self::assertStringNotContainsString("\x1b[1m", $rendered);
        }
    }

    /**
     * The heading case flags share the reading: `"no"` and a non-empty array
     * are off, so no uppercase is applied.
     *
     * @return iterable<string, array{mixed, string}> value => expected headingCase
     */
    public static function headingCaseFlagValues(): iterable
    {
        yield 'boolean true'       => [true, 'upper'];
        yield '"yes"'              => ['yes', 'upper'];
        yield '"no"'               => ['no', 'none'];
        yield '"OFF" in capitals'  => ['OFF', 'none'];
        yield 'non-zero float 1.0' => [1.0, 'none'];
        yield 'non-empty array'    => [['on'], 'none'];
    }

    #[DataProvider('headingCaseFlagValues')]
    public function testHeadingUppercaseEdgeValuesFollowStyleGuide(mixed $value, string $expectedCase): void
    {
        $theme = $this->themeFrom((string) json_encode([
            'document' => [],
            'h1'       => ['uppercase' => $value],
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));

        self::assertSame($expectedCase, $theme->headingCase);
    }

    public function testThemeConfigFileWithGlamourSchemaReachesLivePath(): void
    {
        // The anti-dead-code pin: a glamour-shaped --theme-config is
        // consumed by RenderCommand, not just parseable in isolation. The
        // document block affixes must show up in the rendered output.
        $config = tempnam(sys_get_temp_dir(), 'mdview-gate-cfg-');
        self::assertNotFalse($config);
        file_put_contents($config, json_encode([
            'document' => ['block_prefix' => "\n", 'block_suffix' => "\t", 'margin' => 0],
        ], JSON_THROW_ON_ERROR));

        try {
            [$code, $captured] = $this->renderThroughThemeConfig($config);

            self::assertSame(0, $code);
            // The routing proof is the affix bytes themselves: the
            // whitespace law lets them through, and the bracket glyphs
            // would refuse at the gate (testUnsafeDecorationSlotsRefuseByName).
            self::assertStringStartsWith("\n", $captured);
            self::assertStringEndsWith("\t", $captured);
        } finally {
            unlink($config);
        }
    }

    public function testThemeConfigGlamourAffixRefusalReachesTheCli(): void
    {
        // The gate's refusal is the one the CLI reports, not a loader error.
        $config = tempnam(sys_get_temp_dir(), 'mdview-gate-bad-');
        self::assertNotFalse($config);
        file_put_contents($config, json_encode([
            'document' => ['block_prefix' => "\x1b[31m"],
        ], JSON_THROW_ON_ERROR));

        try {
            [$code, $captured] = $this->renderThroughThemeConfig($config);

            self::assertSame(2, $code);
            self::assertStringContainsString('document.block_prefix', $captured);
            self::assertStringContainsString('newlines, tabs and spaces', $captured);
        } finally {
            unlink($config);
        }
    }

    /**
     * The size ceiling must guard the CLI's --theme-config read: a file one
     * byte over the cap refuses by name before any decode.
     */
    public function testThemeConfigOverTheByteCapRefusesByName(): void
    {
        $config = tempnam(sys_get_temp_dir(), 'mdview-gate-cap-');
        self::assertNotFalse($config);
        file_put_contents($config, str_repeat('x', StyleGuide::MAX_CONFIG_BYTES + 1));

        try {
            [$code, $captured] = $this->renderThroughThemeConfig($config);

            self::assertSame(2, $code);
            self::assertStringContainsString('exceeds 1000000 bytes', $captured);
        } finally {
            unlink($config);
        }
    }

    public function testThemeConfigNonRegularFileRefusesByNamedDoor(): void
    {
        // Finding 015: is_readable() passes for directories, and the cap
        // check skips when the size is unknowable. The door refuses first.
        $dir = sys_get_temp_dir();

        [$code, $captured] = $this->renderThroughThemeConfig($dir);

        self::assertSame(2, $code);
        self::assertStringContainsString('theme config not readable: ' . $dir, $captured);
    }

    /**
     * Run one `--theme-config` render. Usage refusals leave through the
     * console's error line with Command::INVALID, so the output is captured
     * from both write() and writeln(), and the exit code comes back with it.
     *
     * @return array{int, string}
     */
    private function renderThroughThemeConfig(string $configPath): array
    {
        $md = tempnam(sys_get_temp_dir(), 'mdview-gate-md-');
        self::assertNotFalse($md);
        file_put_contents($md, "body text\n");

        try {
            $input = $this->createMock(InputInterface::class);
            $input->method('getArgument')->with('file')->willReturn($md);
            $input->method('getOption')->willReturnMap([
                ['theme-config', $configPath],
                ['style', null],
                ['theme', 'ansi'],
                ['width', 0],
                ['pager', false],
                ['no-hyperlinks', false],
            ]);

            $captured = '';
            $capture = function ($messages) use (&$captured): void {
                $captured .= is_string($messages) ? $messages : implode('', (array) $messages);
            };
            $output = $this->createMock(OutputInterface::class);
            $output->method('write')->willReturnCallback($capture);
            $output->method('writeln')->willReturnCallback($capture);

            $command = new RenderCommand();
            $invoke = new \ReflectionMethod($command, 'execute');
            $code = $invoke->invoke($command, $input, $output);

            return [(int) $code, $captured];
        } finally {
            unlink($md);
        }
    }
}
