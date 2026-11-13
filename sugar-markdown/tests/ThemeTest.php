<?php

declare(strict_types=1);

namespace SugarCraft\Markdown\Tests;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Palettes;
use SugarCraft\Markdown\Renderer;
use SugarCraft\Markdown\Theme;
use SugarCraft\Style\Style;
use PHPUnit\Framework\TestCase;

final class ThemeTest extends TestCase
{
    public function testAnsiThemeAppliesColourToHeadings(): void
    {
        $rendered = Theme::ansi()->heading1->render('Hello');
        $this->assertStringContainsString("\x1b[", $rendered);
        $this->assertStringContainsString('Hello', $rendered);
    }

    public function testPlainThemeAppliesNoStyling(): void
    {
        foreach (['heading1','heading2','bold','italic','code','codeBlock','link','blockquote','listMarker','rule'] as $field) {
            $this->assertSame('text', Theme::plain()->{$field}->render('text'));
        }
    }

    public function testFromJsonStringParsesHexAndFlags(): void
    {
        $json = json_encode([
            'heading1'  => ['bold' => true, 'foreground' => '#ff5f87'],
            'paragraph' => ['italic' => true],
            'code'      => ['foreground' => 'ansi256:202'],
            'codeBlock' => ['background' => 'ansi:8', 'faint' => true],
        ]);
        $t = Theme::fromJsonString($json);
        $rendered = $t->heading1->render('h');
        $this->assertStringContainsString("\x1b[1m",            $rendered); // bold
        $this->assertStringContainsString('38;2;255;95;135',    $rendered); // hex truecolor

        $italic = $t->paragraph->render('p');
        $this->assertStringContainsString("\x1b[3m", $italic);

        // ansi256:202 → Color::ansi256(202), which is a palette SLOT and stays
        // one: it renders as 38;5;202 at any profile that can address a
        // 256-colour index, rather than as the slot's nominal RGB(255,95,0).
        // Naming an index is a request for the terminal's value for that index.
        $code = $t->code->render('c');
        $this->assertStringContainsString('38;5;202', $code);

        $cb = $t->codeBlock->render('cb');
        $this->assertStringContainsString("\x1b[2m",  $cb); // faint
        // `ansi:8` is a palette slot, so the background is the 4-bit bright-black
        // bg code (SGR 100) rather than the extended `48;…` introducer.
        $this->assertStringContainsString("\x1b[100m", $cb);
    }

    public function testFromJsonStringMissingElementsDefaultToPlain(): void
    {
        $json = json_encode(['heading1' => ['bold' => true]]);
        $t = Theme::fromJsonString($json);
        // h2 not in JSON → plain.
        $this->assertSame('p', $t->heading2->render('p'));
        // h1 styled.
        $this->assertStringContainsString("\x1b[1m", $t->heading1->render('h'));
    }

    public function testFromJsonStringRejectsNonObject(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Theme::fromJsonString('"just a string"');
    }

    public function testFromJsonReadsFile(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'markdown');
        file_put_contents($tmp, json_encode(['bold' => ['bold' => true]]));
        try {
            $t = Theme::fromJson($tmp);
            $this->assertStringContainsString("\x1b[1m", $t->bold->render('b'));
        } finally {
            unlink($tmp);
        }
    }

    public function testFromJsonRaisesOnMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        Theme::fromJson('/nonexistent/path/' . uniqid());
    }

    public function testFromJsonRejectsDirectory(): void
    {
        $this->expectException(\RuntimeException::class);
        Theme::fromJson(__DIR__);
    }

    public function testAnsiThemeHasSyntaxTokenStyles(): void
    {
        $t = Theme::ansi();
        $this->assertStringContainsString("\x1b[", $t->keyword?->render('if')   ?? '');
        $this->assertStringContainsString("\x1b[", $t->string?->render('"abc"') ?? '');
        $this->assertStringContainsString("\x1b[", $t->number?->render('42')    ?? '');
        $this->assertStringContainsString("\x1b[", $t->comment?->render('// x') ?? '');
    }

    public function testFromJsonStringParsesTokenStyles(): void
    {
        $json = json_encode([
            'keyword' => ['bold' => true],
            'string'  => ['foreground' => '#00ff00'],
        ]);
        $t = Theme::fromJsonString($json);
        $this->assertStringContainsString("\x1b[1m",            $t->keyword?->render('if') ?? '');
        $this->assertStringContainsString('38;2;0;255;0',       $t->string?->render('"x"') ?? '');
        // Unspecified token styles parse to plain Style::new().
        $this->assertSame('42', $t->number?->render('42') ?? '');
    }

    public function testAsciiThemeIsMonochromeButPreservesEmphasis(): void
    {
        $t = Theme::ascii();
        // Bold / italic / underline still emit, but no SGR colours.
        $this->assertStringContainsString("\x1b[1m",  $t->bold->render('hi'));
        $this->assertStringContainsString("\x1b[3m",  $t->italic->render('hi'));
        $this->assertStringContainsString("\x1b[4m",  $t->link->render('hi'));
        // No 38;2; truecolor or 38;5; 256-colour.
        $rendered = $t->heading1->render('Hello');
        $this->assertStringNotContainsString('38;2;', $rendered);
        $this->assertStringNotContainsString('38;5;', $rendered);
        // Code blocks don't add colour — just pass-through.
        $this->assertSame('return 42;', $t->codeBlock->render('return 42;'));
    }

    public function testByNameDispatchesAllPresets(): void
    {
        $expected = ['ansi', 'plain', 'notty', 'ascii', 'dark', 'light', 'dracula', 'tokyo-night', 'pink'];
        foreach ($expected as $name) {
            $this->assertInstanceOf(Theme::class, Theme::byName($name), $name);
        }
        // Hyphen / underscore / case insensitivity.
        $this->assertInstanceOf(Theme::class, Theme::byName('TOKYO_NIGHT'));
        $this->assertInstanceOf(Theme::class, Theme::byName('TokyoNight'));
        $this->assertNull(Theme::byName('does-not-exist'));
    }

    public function testFromEnvironmentReadsGlamourStyleEnv(): void
    {
        putenv('GLAMOUR_STYLE=dracula');
        try {
            $t = Theme::fromEnvironment();
            $this->assertEquals(Theme::dracula(), $t);
        } finally {
            putenv('GLAMOUR_STYLE');
        }
    }

    public function testFromEnvironmentFallsBackOnUnknown(): void
    {
        putenv('GLAMOUR_STYLE=not-a-theme');
        try {
            $this->assertEquals(Theme::ansi(), Theme::fromEnvironment());
        } finally {
            putenv('GLAMOUR_STYLE');
        }
    }

    public function testFromEnvironmentHonoursDefault(): void
    {
        putenv('GLAMOUR_STYLE');
        $this->assertEquals(Theme::plain(), Theme::fromEnvironment(Theme::plain()));
    }

    public function testParseColorRejectsMalformedAnsiSpec(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Theme::fromJsonString(json_encode(['code' => ['foreground' => 'ansi:300']]));
    }

    public function testParseColorRejectsMalformedAnsi256Spec(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Theme::fromJsonString(json_encode(['code' => ['foreground' => 'ansi256:abc']]));
    }

    public function testParseColorRejectsMalformedAnsiSpecLetters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Theme::fromJsonString(json_encode(['code' => ['foreground' => 'ansi:xyz']]));
    }

    /**
     * Strikethrough with a null strike slot should fall back to
     * Style::new()->strikethrough() (the default from Renderer::renderStrike).
     * Theme::ansi() has null strike by not specifying it.
     */
    public function testStrikethroughNullFallbackRenders(): void
    {
        $r = new Renderer(Theme::ansi());
        $out = $r->render('~~strike~~');
        // Fallback is Style::new()->strikethrough() which emits the strikethrough SGR code.
        $this->assertStringContainsString("\x1b[9m", $out);
        $this->assertStringContainsString('strike', $out);
    }

    /**
     * Drift guard: the 9 named colours the dracula() factory renders with
     * must stay byte-identical to the sugar-core Palettes SSOT they are
     * now sourced from. Style hides its `$fg`/`$bg` behind private
     * readonly props, so we compare the emitted truecolor SGR sequence
     * (`38;2;r;g;b` for fg, `48;2;r;g;b` for bg) of each dracula slot
     * against the same sequence produced independently from
     * Palettes::DRACULA. If someone re-hardcodes a slot to a literal that
     * no longer matches the SSOT, the sequences diverge and this fails.
     */
    public function testDraculaColoursMatchCorePalettesSsot(): void
    {
        $t = Theme::dracula();

        // Foreground SGR sequence a palette colour renders to.
        $fgSeq = static function (string $name): string {
            $out = Style::new()->foreground(Color::hex(Palettes::DRACULA[$name]))->render('x');
            self::assertSame(1, preg_match('/38;2;\d+;\d+;\d+/', $out, $m), "no fg SGR for {$name}");
            return $m[0];
        };
        // Background SGR sequence a palette colour renders to.
        $bgSeq = static function (string $name): string {
            $out = Style::new()->background(Color::hex(Palettes::DRACULA[$name]))->render('x');
            self::assertSame(1, preg_match('/48;2;\d+;\d+;\d+/', $out, $m), "no bg SGR for {$name}");
            return $m[0];
        };

        // fg-only slots (bold flag doesn't affect the colour sequence).
        $fgSlots = [
            'pink'       => [$t->heading1, $t->listMarker, $t->keyword],
            'purple'     => [$t->heading2, $t->number],
            'cyan'       => [$t->heading3, $t->link, $t->linkText, $t->image, $t->autolink],
            'green'      => [$t->heading4],
            'orange'     => [$t->heading5],
            'yellow'     => [$t->heading6, $t->string],
            'foreground' => [$t->paragraph],
            'comment'    => [$t->blockquote, $t->rule, $t->comment, $t->strike, $t->htmlBlock, $t->htmlSpan],
        ];
        foreach ($fgSlots as $name => $styles) {
            $seq = $fgSeq($name);
            foreach ($styles as $i => $style) {
                $this->assertStringContainsString($seq, $style->render('x'), "{$name} slot #{$i}");
            }
        }

        // code fg=pink over bg=background; codeBlock fg=foreground over bg=background.
        $this->assertStringContainsString($fgSeq('pink'),        $t->code->render('x'));
        $this->assertStringContainsString($bgSeq('background'),  $t->code->render('x'));
        $this->assertStringContainsString($fgSeq('foreground'),  $t->codeBlock->render('x'));
        $this->assertStringContainsString($bgSeq('background'),  $t->codeBlock->render('x'));
    }

    public function testNoColorEnvironmentWinsOverGlamourStyle(): void
    {
        putenv('NO_COLOR=1');
        putenv('GLAMOUR_STYLE=dracula');
        try {
            $this->assertEquals(Theme::plain(), Theme::fromEnvironment());
            $this->assertEquals(Theme::plain(), Theme::fromEnvironment(Theme::dark()));
        } finally {
            putenv('NO_COLOR');
            putenv('GLAMOUR_STYLE');
        }
    }

    public function testAnEmptyNoColorValueLeavesTheChannelAlone(): void
    {
        putenv('NO_COLOR=');
        putenv('GLAMOUR_STYLE=dracula');
        try {
            $this->assertEquals(Theme::dracula(), Theme::fromEnvironment());
        } finally {
            putenv('NO_COLOR');
            putenv('GLAMOUR_STYLE');
        }
    }

    public function testAGlamourDocumentPayloadRoutesThroughStyleGuide(): void
    {
        $json = json_encode([
            'document' => ['margin' => 2],
            'h1'       => ['color' => '#ff0000', 'bold' => true],
        ]);
        $rendered = (new Renderer(Theme::fromJsonString($json)))->render("# Hi\n");
        $this->assertStringContainsString("\x1b[1m\x1b[38;2;255;0;0m# Hi\x1b[0m", $rendered, 'the routed theme must style the heading, not swallow the schema (M6)');
        $this->assertStringStartsWith("\n\n", $rendered, 'the document margin pads the run');
    }

    public function testAFlatPayloadStillDecodesFlat(): void
    {
        $theme = Theme::fromJsonString(json_encode(['heading1' => ['foreground' => '#00ff00']]));
        $this->assertStringContainsString("\x1b[38;2;0;255;0m", $theme->heading1->render('x'));
    }

    public function testAFlatFlagSpelledFalseStaysOff(): void
    {
        $theme = Theme::fromJsonString(json_encode(['bold' => ['bold' => 'false']]));
        $this->assertSame('x', $theme->bold->render('x'), 'm12: the string "false" must not switch a flag on');
    }

    public function testAGlamourFlagSpelledFalseStaysOff(): void
    {
        $theme = Theme::fromJsonString(json_encode([
            'document' => [],
            'strong'   => ['bold' => 'false'],
        ]));
        $this->assertSame('x', $theme->bold->render('x'));
    }

    public function testAWarningingReadIsRefusedRatherThanDecoded(): void
    {
        if (!in_array('mdwarning', stream_get_wrappers(), true)) {
            stream_wrapper_register('mdwarning', WarningReadingStreamTest::class);
        }
        try {
            Theme::fromJson('mdwarning://style.json');
            $this->fail('a file_get_contents() warning must not be swallowed into a decoded theme');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('simulated degraded read', $e->getMessage());
        }
    }

    public function testConstructorElementSlotsDefaultToNoOpStyle(): void
    {
        $theme = new Theme();
        $fields = ['heading1', 'heading2', 'heading3', 'heading4', 'heading5', 'heading6',
            'paragraph', 'bold', 'italic', 'code', 'codeBlock', 'link', 'blockquote', 'listMarker', 'rule'];
        foreach ($fields as $field) {
            $this->assertSame('text', $theme->{$field}->render('text'), "{$field} must default to a no-op Style");
        }
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function stockThemePins(): array
    {
        return [
            'ansi'        => ['ansi', '0f419b27be52b124a81f28c56b29cd6923736d1f'],
            'plain'       => ['plain', 'feeaa7d00c7fba48019928af25608b83fab7e553'],
            'notty'       => ['notty', 'feeaa7d00c7fba48019928af25608b83fab7e553'],
            'ascii'       => ['ascii', 'bf3c99f884901b2e867fada2725f2224cb529304'],
            'dark'        => ['dark', 'ef74587f7471249edcc1d0745448e5e33ea6e1e8'],
            'light'       => ['light', '0dd8ebb37b051aae40125ca0cad9fa51a20dd3fd'],
            'dracula'     => ['dracula', '8b8acdc32532b10d99934826d2c0092ed414ec83'],
            'tokyoNight'  => ['tokyoNight', 'd5ba6891a3f0efeddfa2119cbc793737da199f9f'],
            'pink'        => ['pink', '54f4be28cd8b6b3d6c6d954b06ad8a95240fcd6b'],
        ];
    }

    /**
     * Adding constructor defaults must not move a single byte of any
     * stock theme's output — the pins below were computed on the
     * pre-change revision and re-asserted after it.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('stockThemePins')]
    public function testStockThemeOutputIsPinStable(string $factory, string $sha1): void
    {
        $doc = "# H1\n\n## H2\n\n### H3\n\nPara **bold** *it* `code` [link](http://x.y) ~~strike~~ ![img](i.png).\n\n> quote\n\n- a\n- [x] b\n\n1. one\n2. two\n\n```php\necho 1;\n```\n\n| a | b |\n|---|---|\n| 1 | 2 |\n\n---\n\nTerm\n: def\n";
        $theme = Theme::{$factory}();
        $out = (new Renderer($theme, wrapWidth: 60))->render($doc);
        $this->assertSame($sha1, sha1($out), "stock theme {$factory}() output drifted");
    }

    public function testWithSwapsExactlyOneSlotAndCopiesEverythingElse(): void
    {
        $base = Theme::dark();
        $gold = Style::new()->foreground(Color::hex('#ffd700'));
        $custom = $base->with('code', $gold);

        $this->assertNotSame($base, $custom);
        $this->assertStringContainsString("\x1b[38;2;255;215;0m", $custom->code->render('x')); // swapped
        $this->assertSame($base->heading1, $custom->heading1);                                  // copied
        $this->assertSame($base->documentIndent, $custom->documentIndent);                     // scalar kept
        $this->assertSame($base->headingCase, $custom->headingCase);                           // scalar kept
    }

    public function testWithKeepsScalarFieldsAcrossTheSwap(): void
    {
        $theme = (new Theme(documentIndent: 2, listLevelIndent: 4, headingCase: 'upper'))
            ->with('code', Style::new()->bold());
        $this->assertSame(2, $theme->documentIndent);
        $this->assertSame(4, $theme->listLevelIndent);
        $this->assertSame('upper', $theme->headingCase);
        $this->assertSame('text', $theme->heading1->render('text')); // still no-op default
    }

    public function testWithRejectsUnknownSlotWithTheValidRoster(): void
    {
        try {
            Theme::dark()->with('nope', Style::new());
            $this->fail('an unknown slot must throw');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('is not a Style slot', $e->getMessage());
            $this->assertStringContainsString('heading1', $e->getMessage());
            $this->assertStringContainsString('autolink', $e->getMessage());
        }
    }

    public function testWithRejectsScalarSlots(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Theme::dark()->with('documentIndent', Style::new());
    }

    /**
     * SM-S11: names() is the single roster; every listed name must
     * resolve through byName(), and the roster must carry no duplicates.
     */
    public function testNamesRosterRoundTripsThroughByName(): void
    {
        $names = Theme::names();
        $this->assertSame(array_values($names), array_unique($names), 'roster must be a set');
        foreach ($names as $name) {
            $this->assertNotNull(Theme::byName($name), $name . ' must resolve through byName()');
        }
        $this->assertSame(
            ['ansi', 'plain', 'notty', 'ascii', 'dark', 'light', 'dracula', 'tokyo-night', 'pink'],
            $names
        );
    }

    public function testRequireNameResolvesNormalisedAliases(): void
    {
        $this->assertInstanceOf(Theme::class, Theme::requireName('Tokyo-Night'));
        $this->assertInstanceOf(Theme::class, Theme::requireName('tokyonight'));
        $this->assertInstanceOf(Theme::class, Theme::requireName('no_tty'));
    }

    public function testRequireNameThrowsWithTheFullRoster(): void
    {
        try {
            Theme::requireName('nope');
            $this->fail('requireName() must refuse an unknown name');
        } catch (\InvalidArgumentException $e) {
            foreach (Theme::names() as $name) {
                $this->assertStringContainsString($name, $e->getMessage());
            }
        }
    }
}

/**
 * Fault injection for the warning-read refusal: presents as a regular
 * file of fixed bytes, but warns on its first read — the asymmetry
 * StyleGuide::fromFile already refuses and Theme::fromJson now mirrors.
 */
final class WarningReadingStreamTest
{
    /** @var resource|null */
    public $context;

    private const BODY = '{"bold": {"bold": true}}';

    private int $position = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->position = 0;

        return true;
    }

    public function stream_read(int $count): string
    {
        if ($this->position === 0) {
            trigger_error('simulated degraded read', E_USER_WARNING);
        }
        $chunk = substr(self::BODY, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::BODY);
    }

    /** @return array<int,int> */
    public function url_stat(string $path, int $flags): array
    {
        $now = time();

        return [
            'dev'   => 0,
            'ino'   => 0,
            'mode'  => 0100644,
            'nlink' => 1,
            'uid'   => 0,
            'gid'   => 0,
            'rdev'  => 0,
            'size'  => strlen(self::BODY),
            'mtime' => $now,
            'atime' => $now,
            'ctime' => $now,
        ];
    }
}
