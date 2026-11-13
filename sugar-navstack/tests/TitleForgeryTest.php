<?php

declare(strict_types=1);

namespace SugarCraft\NavStack\Tests;

use SugarCraft\Mouse\Scanner;
use SugarCraft\NavStack\{Breadcrumb, NavStack, Shell};
use PHPUnit\Framework\TestCase;

/**
 * C1 (fleet-strongest finding, 7-lane convergence): crumb titles reach
 * sugar-mouse Mark::zone through the old Sanitize::untrusted() sweep,
 * which PASSES U+E000/U+E001 by design (they are image/zone marker bytes,
 * not controls). A forged title of "\u{E000}crumb-0\u{E001}" therefore
 * opened a fake zone in the rendered row: the scanner registers
 * earliest-first, so the forgery hijacked the legitimate crumb-0's click
 * cells — and two zones with the same id crashed the render loop
 * permanently for the whole frame.
 *
 * The fix: every title sink runs Sanitize::untrustedForMarkedFrames()
 * (sweep + sentinel removal) + stripInvisibleFormatting() through the
 * shared TitleSanitizer, before width math and zone wrapping.
 */
final class TitleForgeryTest extends TestCase
{
    private const ZONE_OPEN  = "\u{E000}";
    private const ZONE_CLOSE = "\u{E001}";

    public function testForgedSentinelTitleCannotOpenZones(): void
    {
        $scanner = Scanner::new();
        $bc = (new Breadcrumb())->withScanner($scanner);

        $s = new NavStack();
        $s->push('Home');
        // Attack: forge a second "crumb-0" zone that would register over
        // (or crash against) the legitimate one.
        $s->push(self::ZONE_OPEN . 'crumb-0' . self::ZONE_CLOSE . 'Evil');

        // Pre-fix this render threw (duplicate zone id) and the row was
        // dead for every later frame.
        $rendered = $bc->render($s);

        // Exactly the two legitimate zones — no forgery, no duplicate.
        $bc->scan($rendered, 10_000);
        $zones = $bc->scanner()?->prefixed('crumb-') ?? [];
        $ids = \array_keys($zones);
        sort($ids);
        $this->assertSame(['crumb-0', 'crumb-1'], $ids);

        // The hostile title survives as plain text (only markers stripped).
        $this->assertStringContainsString('Evil', $rendered);

        // Click on the first crumb routes to the LEGITIMATE item.
        $item = $bc->hitItem($s, 1, 1);
        $this->assertNotNull($item);
        $this->assertSame('Home', $item->title);
    }

    public function testNoForeignZonesAreRegisteredFromUntrustedTitles(): void
    {
        $scanner = Scanner::new();
        $bc = (new Breadcrumb())->withScanner($scanner);

        $s = new NavStack();
        $s->push('Home');
        $s->push('Panel');
        $rendered = $bc->render($s);

        // Titles carrying entirely foreign zone ids must mint no zones.
        $hostileScanner = Scanner::new();
        $hostile = (new Breadcrumb())->withScanner($hostileScanner);
        $s2 = new NavStack();
        $s2->push(self::ZONE_OPEN . 'overlay-9' . self::ZONE_CLOSE . 'hi');
        $hostileRender = $hostile->render($s2);

        $hostileZones = $hostileScanner->scan($hostileRender)->prefixed('');
        // The forged id text survives ONLY as inert display characters —
        // the zone table knows a single legitimate crumb zone.
        $this->assertSame(['crumb-0'], \array_keys($hostileZones));

        $legit = $scanner->scan($rendered)->prefixed('');
        $this->assertCount(2, $legit); // only our two crumb zones exist
    }

    public function testSentinelTitleNeverLeaksIntoViewOrHtml(): void
    {
        $s = new NavStack();
        $s->push('a' . self::ZONE_OPEN . 'x' . self::ZONE_CLOSE . 'b');

        $this->assertStringNotContainsString(self::ZONE_OPEN, $s->view());
        $this->assertStringNotContainsString(self::ZONE_CLOSE, $s->view());
        $this->assertStringNotContainsString(self::ZONE_OPEN, $s->viewHtml());
        $this->assertStringNotContainsString(self::ZONE_CLOSE, $s->viewHtml());
    }

    public function testDirectoryPathSentinelBytesAreSwept(): void
    {
        // Shell::pushDirectory is the real-world intake: paths are user/
        // filesystem controlled.
        $shell = Shell::new();
        $shell = $shell->pushDirectory('/home/' . self::ZONE_OPEN . 'crumb-0' . self::ZONE_CLOSE . 'evil');

        $rendered = $shell->breadcrumb->render($shell->stack);
        $this->assertStringNotContainsString(self::ZONE_OPEN, $rendered);

        // And the zone set is exactly the crumbs, no hijack:
        $bc = (new Breadcrumb())->withScanner(Scanner::new());
        $out = $bc->render($shell->stack);
        $scanner = $bc->scanner();
        $this->assertNotNull($scanner);
        $zones = $scanner->scan($out)->prefixed('crumb-');
        $this->assertCount($shell->stack->depth(), $zones);
    }

    public function testBidiSpoofingCharactersStrippedFromTitles(): void
    {
        // U+202E RIGHT-TO-LEFT OVERRIDE can make a crumb visually cover
        // cells the width math did not account for.
        $s = new NavStack();
        $s->push("a\u{202E}b");
        $view = $s->view();
        $this->assertStringNotContainsString("\u{202E}", $view);

        $bc = (new Breadcrumb())->withScanner(Scanner::new());
        $rendered = $bc->render($s);
        $this->assertStringNotContainsString("\u{202E}", $rendered);
    }

    public function testZeroWidthJoinerFamilyInLegitimateContextsSurvives(): void
    {
        // stripInvisibleFormatting only removes always-tier bytes and
        // joiners in spoofing positions; a family emoji must not disintegrate.
        $family = "Family \u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";
        $s = new NavStack();
        $s->push($family);
        $this->assertStringContainsString("\u{200D}", $s->view());
    }

    public function testSeparatorAndTruncatorSentinelForgeryRefused(): void
    {
        // A pure-sentinel separator strips to nothing — refused loudly.
        $bc = new Breadcrumb();
        try {
            $bc->setSeparator(self::ZONE_OPEN . self::ZONE_CLOSE);
            $this->fail('expected InvalidArgumentException for fully-stripped separator');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('non-empty', $e->getMessage());
        }

        // A separator mixing sentinels with text keeps only the text…
        $bc->setSeparator(self::ZONE_OPEN . 'crumb-7' . self::ZONE_CLOSE);
        $s = new NavStack();
        $s->push('A')->push('B');
        $scan = Scanner::new();
        $bc2 = (new Breadcrumb())->setSeparator(self::ZONE_OPEN . 'crumb-7' . self::ZONE_CLOSE)
            ->withScanner($scan);
        $out = $bc2->render($s);
        // …and that text mints NO zone: only the two legit crumbs exist.
        $this->assertSame(['crumb-0', 'crumb-1'], \array_keys($scan->scan($out)->prefixed('')));

        // A truncator loses ONLY its sentinels (rest kept).
        $bc->setTruncator('…' . self::ZONE_OPEN . 'x' . self::ZONE_CLOSE);
        $s2 = new NavStack();
        $s2->push('old')->push(str_repeat('w2 ', 20));
        $out2 = $bc->setMaxWidth(8)->render($s2);
        $this->assertStringNotContainsString(self::ZONE_OPEN, $out2);
        $this->assertStringStartsWith('…x', $out2);
    }

    public function testHostAnsiStylingInSeparatorSurvives(): void
    {
        // The sweep is for forged zone bytes, not for the host's palette:
        // a styled separator is legitimate chrome and must pass through.
        $bc = (new Breadcrumb())->setSeparator("\x1b[90m|\x1b[0m");
        $s = new NavStack();
        $s->push('A')->push('B');
        $this->assertStringContainsString("\x1b[90m|\x1b[0m", $bc->render($s));
    }
}
