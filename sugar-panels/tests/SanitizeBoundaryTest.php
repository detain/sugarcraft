<?php

declare(strict_types=1);

namespace SugarCraft\Panels\Tests;

use SugarCraft\Core\Util\Width;
use SugarCraft\Panels\Node;
use SugarCraft\Panels\Sanitize;
use SugarCraft\Panels\SugarPanels;
use PHPUnit\Framework\TestCase;
use SugarCraft\Panels\Tests\Support\ReadsFrames;

/**
 * The paint boundary (sugar-core's Sanitize cannot be used wholesale here:
 * ESC is this library's feature, and TAB/CR are the bytes its untrusted()
 * variant spares). Every leg of {@see Sanitize} gets a pin, and each pin is
 * written as the renderer sees it — through a Node built by the public API and
 * the bytes a frame actually carries — so a sweep that was added but never
 * wired into the ingest choke cannot pass.
 */
final class SanitizeBoundaryTest extends TestCase
{
    use ReadsFrames;
    private const ZONE_OPEN = "\u{E000}";
    private const ZONE_CLOSE = "\u{E001}";

    private SugarPanels $panels;

    protected function setUp(): void
    {
        $this->panels = SugarPanels::new();
    }

    // -------------------------------------------------------------------------
    // Zone sentinels (forged mouse zones)
    // -------------------------------------------------------------------------

    public function testZoneSentinelsAreStrippedFromContentAtConstruction(): void
    {
        $node = Node::leaf(self::ZONE_OPEN . 'pane:files' . self::ZONE_CLOSE);

        $this->assertSame('pane:files', $node->content, 'a forged zone marker cannot enter the grid');
    }

    public function testZoneSentinelsAreStrippedFromTitlesAtConstruction(): void
    {
        $node = Node::leaf('body')
            ->withBorder(true)
            ->withTitle(self::ZONE_OPEN . 'menu' . self::ZONE_CLOSE);

        $this->assertSame('menu', $node->title);
        $this->assertStringNotContainsString(self::ZONE_OPEN, self::body($this->panels->render($node, 12, 3)));
    }

    public function testAFrameCarriesNoZoneSentinelFromEitherTextChannel(): void
    {
        $layout = Node::horizontal(
            Node::leaf(self::ZONE_OPEN . 'click-me' . self::ZONE_CLOSE)->withBorder(true),
            Node::leaf('x')->withTitle(self::ZONE_OPEN . 'tab' . self::ZONE_CLOSE)->withBorder(true),
        );
        $frame = self::body($this->panels->render($layout, 20, 3));

        $this->assertStringNotContainsString(self::ZONE_OPEN, $frame);
        $this->assertStringNotContainsString(self::ZONE_CLOSE, $frame);
    }

    public function testOtherPrivateUseCodepointsStillPassThrough(): void
    {
        // U+E002 is an image-overlay marker and the rest of the BMP Private Use
        // Area carries Nerd Font glyphs; only the sentinel pair is neutralised.
        $marker = "\u{E002}";
        $icon = "\u{F0132}";

        $this->assertSame($marker . 'disk' . $icon, Sanitize::painted($marker . 'disk' . $icon));
    }

    public function testAnIncompleteSentinelCannotBeCompletedDownstream(): void
    {
        // The raw bytes that would begin a sentinel are ill-formed UTF-8 on their
        // own, so they are repaired here rather than left in place to be
        // completed by whatever the host concatenates later.
        $repaired = Sanitize::painted("\xEE\x80");

        $this->assertSame("\u{FFFD}", $repaired);
        $this->assertStringNotContainsString("\xEE\x80", $repaired);
    }

    // -------------------------------------------------------------------------
    // CR / CRLF smuggling
    // -------------------------------------------------------------------------

    public function testCarriageReturnIsNotPaintable(): void
    {
        $node = Node::leaf("left\rright");

        $this->assertSame("left\nright", $node->content, 'a bare CR becomes the line break the model already understands');
    }

    public function testCarriageReturnLineFeedCollapsesToOneLineBreak(): void
    {
        $this->assertSame("a\nb", Sanitize::painted("a\r\nb"));
    }

    public function testAStyledCarriageReturnCannotEraseTheFrame(): void
    {
        // The exploit shape from the review: a styling byte makes the CR look
        // like part of the span, and the painted row then returns to column 0
        // over the border the renderer already drew.
        $layout = Node::leaf("\x1b[7mleft\rX")->withBorder(true);
        $frame = self::body($this->panels->render($layout, 10, 3));

        $this->assertStringNotContainsString("\r", $frame, 'no control byte may reach the terminal from a frame');
        $this->assertSame(3, \count(\explode("\n", $frame)), 'the frame keeps its row count');
    }

    // -------------------------------------------------------------------------
    // TAB: modelled width vs physical advance
    // -------------------------------------------------------------------------

    public function testTabIsExpandedToTheWidthTheOracleCharges(): void
    {
        $this->assertSame('a' . \str_repeat(' ', Width::TAB_WIDTH) . 'b', Sanitize::painted("a\tb"));
    }

    public function testATabbedRowPaintsWhereTheTabbedModelSaysItPaints(): void
    {
        $tabbed = Node::leaf("a\tb")->withBorder(false);
        $spaced = Node::leaf('a' . \str_repeat(' ', Width::TAB_WIDTH) . 'b')->withBorder(false);

        // Fresh renderers: a repeated size on one instance takes the diff path
        // and legitimately paints nothing.
        $this->assertSame(
            self::body(SugarPanels::new()->render($spaced, 12, 1)),
            self::body(SugarPanels::new()->render($tabbed, 12, 1)),
            'the renderer charges a tab what the terminal advances by, so both shapes agree',
        );
    }

    // -------------------------------------------------------------------------
    // Remaining controls, and what must survive
    // -------------------------------------------------------------------------

    public function testUnpaintableControlsAreRemoved(): void
    {
        $hostile = "\x00\x08\x0b\x0c\x0e\x1a\x1c\x1f\x7fok";

        $this->assertSame('ok', Sanitize::painted($hostile));
    }

    public function testC1ControlsAreRemovedBecauseTheyAreSmuggledEscapes(): void
    {
        // U+0080-U+009F are the 8-bit twins of C0: xterm, VTE and kitty honour
        // U+009B exactly as ESC [ , so a surviving 8-bit CSI is a styling
        // command smuggled past a "control-free" guarantee — and even an inert
        // C1 charges a column it never paints. The core untrusted() helper
        // strips C1, and the README makes this sweep the mandated floor, so it
        // must strip C1 too.
        $this->assertSame('31mred', Sanitize::painted("\u{9B}31mred"));
        $this->assertSame('0;http://eviltail', Sanitize::painted("\u{9D}0;http://evil\u{9C}tail"));
        $this->assertSame(3, Width::string(Sanitize::painted("\u{9B}red")));
    }

    public function testTheC1SweepStopsWhereTheLatinOneSupplementBegins(): void
    {
        // Control semantics, not block snobbery: U+00A0 is ordinary printable
        // text and rides on; U+009F, the last C1 codepoint, does not.
        $this->assertSame("\u{A0}ok", Sanitize::painted("\u{A0}ok"));
        $this->assertSame('ok', Sanitize::painted("\u{9F}ok"));
    }

    public function testABelTerminatedOscSurvivesIngestWhole(): void
    {
        // BEL is spared for a reason: it closes the OSC. Sweeping the beep byte
        // would turn `OSC ... BEL X` into an unterminated OSC, and an
        // unterminated OSC swallows the rest of the row — removing the beep
        // would therefore delete the text that followed it.
        $osc = "\x1b]0;title\x07after";

        $this->assertSame($osc, Sanitize::painted($osc));

        // The frame keeps the payload visible and the sequence zero-width.
        $row = \explode("\n", self::body(SugarPanels::new()->render(Node::leaf($osc)->withBorder(false), 8, 1)))[0];

        $this->assertStringContainsString("\x1b]0;title\x07", $row);
        $this->assertSame('after', \rtrim(\preg_replace('/\x1b\][^\x07]*\x07/', '', $row) ?? '', ' '));
    }

    public function testAStrayBelIsSparedBecauseTheBoundaryCannotTellItFromATerminator(): void
    {
        // Declared limitation: the sweep is byte-wise and has no parser, so a
        // lone BEL (a beep, not a terminator) passes through. Content is
        // TRUSTED text — the README trust boundary is where hostile markup is
        // neutralised; this boundary only guarantees grid-structural safety.
        $this->assertSame("\x07ok", Sanitize::painted("\x07ok"));
    }

    public function testStylingAndLineStructureSurviveTheSweep(): void
    {
        // The complement pin: this is not a blanket scrub. ESC opens the spans
        // the library renders and LF is the content separator — both ride on.
        $styled = "\x1b[31mred\x1b[0m\nsecond\n\x1b[1mbold";

        $this->assertSame($styled, Sanitize::painted($styled));
        $this->assertStringContainsString("\x1b[31mred", self::body($this->panels->render(Node::leaf($styled)->withBorder(false), 12, 3)));
    }

    public function testTitlesFlattenLineBreaksBecauseTheyPaintOnOneRow(): void
    {
        $this->assertSame('two words', Sanitize::paintedTitle("two\nwords"));
        $this->assertSame('two words', Node::leaf('x')->withTitle("two\nwords")->title);
    }

    // -------------------------------------------------------------------------
    // Ill-formed UTF-8
    // -------------------------------------------------------------------------

    public function testTruncatedSequenceIsRepairedToTheReplacementCharacter(): void
    {
        // U+1F5FD (\xF0\x9F\x97\xBD) cut in half: before the repair the renderer
        // charged it a width the terminal cannot see and shifted the row.
        $truncated = "a\xF0\x9F";

        $this->assertSame("a\u{FFFD}", Sanitize::painted($truncated));
        $this->assertSame(2, Width::string(Node::leaf($truncated)->content));
    }

    // -------------------------------------------------------------------------
    // Boundary shape
    // -------------------------------------------------------------------------

    public function testTheSweepIsIdempotent(): void
    {
        $hostile = self::ZONE_OPEN . "a\r\nb\t\x07c\x1b[31mred\n";

        $once = Sanitize::painted($hostile);

        $this->assertSame($once, Sanitize::painted($once), 'a host may pre-sanitise and still round-trip');
    }

    public function testEveryTextChannelOfTheNodeEntersThroughTheSweep(): void
    {
        // Structural pin: the private constructor is the single write site for
        // both painted strings, and each of its assignments goes through the
        // boundary. A new field that skips it, or a factory that stops routing
        // through the constructor, reddens here.
        $source = (string) \file_get_contents(\dirname(__DIR__) . '/src/Node.php');

        // Single '=' only: the boundary is the WRITE site, and a comparison such
        // as `$this->content === ''` is a read that must not be policed here.
        \preg_match_all('/\$this->(content|title)\s*(?<!=)=(?!=)[^\n]*/', $source, $writes);

        $this->assertGreaterThanOrEqual(2, \count($writes[0]), 'the pin must find both text writes it polices');
        foreach ($writes[0] as $write) {
            $this->assertStringContainsString('Sanitize::painted', $write, 'node text enters only through the boundary: ' . $write);
        }
    }

    public function testSanitisingDoesNotChangeAnOrdinaryFrame(): void
    {
        // Negative control: the common path (plain text, box characters, no
        // controls) is byte-for-byte what it was before the boundary existed.
        $layout = Node::vertical(
            Node::leaf('Status: ok')->withBorder(true),
            Node::leaf('second line')->withBorder(true),
        );
        $frame = self::body($this->panels->render($layout, 20, 8));
        $stripped = \preg_replace('/\x1b\[[0-9;]*m/', '', $frame);

        $this->assertStringContainsString('Status: ok', $stripped);
        $this->assertStringContainsString('second line', $stripped);
        $this->assertSame(8, \count(\explode("\n", $frame)));
    }
}
