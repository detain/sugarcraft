<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tui\TerminalBackground;
use SugarCraft\Core\Msg\BackgroundColorMsg;
use SugarCraft\Core\Util\Color;
use SugarCraft\Code\Theme;
use SugarCraft\Markdown\Theme as MarkdownTheme;
use SugarCraft\Style\Style;

/**
 * The measured contrast contract for the transcript's ASSISTANT body text —
 * the sugar-markdown palette as {@see Theme::pair()} projects it.
 *
 * A11Y-1 (cc-a11y review): `Theme::pair()` used to project every chrome token
 * and store the markdown half VERBATIM, so the highest-volume surface in the
 * product — assistant answers, rendered through `Theme::$markdown` at
 * Renderer.php — bypassed the repo's own CONTRAST_MIN law. Measured at the
 * time: on a white terminal dracula's paragraph painted #f8f8f2 (1.07:1) and
 * tokyoNight's #a9b1d6 (2.11:1). OD-18 ruled the remedy PROJECTION (the same
 * resolver, not a theme swap), and this file is the pin that keeps it: every
 * markdown Style that paints an explicit foreground on the terminal's surface
 * must clear {@see Theme::CONTRAST_MIN} against BOTH the background and the
 * row-highlight fill, for every shipped theme on every probed terminal.
 *
 * Two categories of slot are deliberately not measured, mirroring the
 * projection's own guards:
 *   - a Style with NO explicit foreground paints the terminal's default text
 *     colour, the user's own pairing (this is what plain terminals and the
 *     `ansi` body text do);
 *   - a Style carrying its OWN background (`code`/`codeBlock` blocks paint a
 *     slab) is seen on its own surface, never the terminal's.
 *
 * The "does not wash out dark terminals" half of the ruling is pinned as byte
 * identity: a token that already clears the threshold on its theme's native
 * background must come out of the projection UNMOVED — projection is a floor,
 * not a rebrand.
 */
final class AssistantBodyContrastTest extends TestCase
{
    /**
     * Light terminals, dark terminals (including the palette-native dracula
     * and tokyoNight grounds), and the two greys the shell suite probes.
     *
     * @var list<string>
     */
    private const BACKGROUNDS = ['#ffffff', '#fafafa', '#000000', '#282a36', '#1a1b26', '#808080', '#666666'];

    protected function setUp(): void
    {
        // The resolver reads the REAL terminal background; a stray probe
        // environment would answer the query instead of the observed message.
        $this->assertFalse(
            getenv(TerminalBackground::ENV_OVERRIDE),
            TerminalBackground::ENV_OVERRIDE . ' must be unset for this suite to measure what it claims',
        );
        $this->assertFalse(getenv('COLORFGBG'), 'COLORFGBG must be unset for this suite');
    }

    protected function tearDown(): void
    {
        TerminalBackground::forget();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function themeProvider(): array
    {
        $cases = [];
        foreach (Theme::names() as $name) {
            $cases[$name] = [$name];
        }

        return $cases;
    }

    /**
     * Every projected markdown foreground clears the floor on both surfaces,
     * per theme per background. Collected violations, one assertion per
     * surface, so a failure names every offending byte at once.
     *
     * @dataProvider themeProvider
     */
    public function testEveryAssistantBodyForegroundClearsTheFloorOnBothSurfaces(string $themeName): void
    {
        foreach (self::BACKGROUNDS as $hex) {
            $background = Color::hex($hex);
            TerminalBackground::forget();
            TerminalBackground::observe(new BackgroundColorMsg($background->r, $background->g, $background->b));

            $theme = Theme::byName($themeName);
            $fill = $theme->shellSeparator;

            $bgViolations = [];
            $fillViolations = [];
            foreach (self::styleSlots($theme->markdown) as $label => $style) {
                $foreground = $style->getForeground();
                if ($foreground === null || $style->getBackground() !== null) {
                    continue; // the two deliberate categories, see class docblock
                }

                $onBackground = Theme::contrast($foreground, $background);
                if ($onBackground < Theme::CONTRAST_MIN) {
                    $bgViolations[] = sprintf('%s %s on %s = %.2f:1', $label, $foreground->toHex(), $hex, $onBackground);
                }

                $onFill = Theme::contrast($foreground, $fill);
                if ($onFill < Theme::CONTRAST_MIN) {
                    $fillViolations[] = sprintf('%s %s on %s = %.2f:1', $label, $foreground->toHex(), $fill->toHex(), $onFill);
                }
            }

            $this->assertSame([], $bgViolations, "{$themeName} on {$hex}: assistant foregrounds below the floor");
            $this->assertSame([], $fillViolations, "{$themeName} on {$hex}: assistant foregrounds below the floor on the row highlight (fill {$fill->toHex()})");
        }
    }

    /**
     * The two named numbers from A11Y-1, asserted as BEFORE/AFTER facts of the
     * projection: on light terminals the raw palette bytes must NOT survive —
     * and what replaces them must clear the floor.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: float}>
     */
    public static function washedOutBodyProvider(): array
    {
        // name, raw paragraph hex, light terminal, raw ratio the bug shipped
        // (both ratios MEASURED here: the A11Y-1 range is 1.02-1.07 for
        // dracula #ffffff/#fafafa and 2.02-2.11 for tokyoNight #ffffff/#fafafa)
        return [
            'dracula' => ['dracula', '#f8f8f2', '#ffffff', 1.07],
            'tokyoNight' => ['tokyoNight', '#a9b1d6', '#ffffff', 2.11],
        ];
    }

    /**
     * @dataProvider washedOutBodyProvider
     */
    public function testTheReportedLightTerminalWashoutIsGone(string $themeName, string $rawHex, string $terminalHex, float $rawRatio): void
    {
        $terminal = Color::hex($terminalHex);
        TerminalBackground::observe(new BackgroundColorMsg($terminal->r, $terminal->g, $terminal->b));

        // Sanity on the quoted number itself: the RAW factory byte really does
        // ship at the measured washout ratio, so the fix has a fact to move.
        $raw = MarkdownTheme::byName($themeName);
        $this->assertNotNull($raw, "{$themeName} must stay a sugar-markdown factory theme");
        $rawForeground = $raw->paragraph->getForeground();
        $this->assertNotNull($rawForeground, "{$themeName}'s paragraph must keep painting an explicit colour");
        $this->assertSame($rawHex, $rawForeground->toHex());
        $this->assertEqualsWithDelta($rawRatio, Theme::contrast($rawForeground, $terminal), 0.05);

        $theme = Theme::byName($themeName);
        $projected = $theme->markdown->paragraph->getForeground();
        $this->assertNotNull($projected);
        $this->assertNotSame($rawHex, $projected->toHex(), "{$themeName}'s paragraph must not stay {$rawHex} on {$terminalHex}");
        $this->assertGreaterThanOrEqual(Theme::CONTRAST_MIN, Theme::contrast($projected, $terminal));
        $this->assertGreaterThanOrEqual(Theme::CONTRAST_MIN, Theme::contrast($projected, $theme->shellSeparator));
    }

    /**
     * The other half of OD-18: no wash-out on dark terminals. A token already
     * legible on its theme's native ground must pass through UNMOVED —
     * byte-identical, same object even.
     */
    public function testNativeDarkTerminalsKeepTheirPaletteBytesUnmoved(): void
    {
        $native = [
            ['dracula', '#282a36'],
            ['tokyoNight', '#1a1b26'],
        ];
        // `dark` is deliberately not listed: its paragraph paints NO explicit
        // colour (terminal default), so there is no byte for the projection
        // to move or keep.

        foreach ($native as [$themeName, $hex]) {
            $background = Color::hex($hex);
            TerminalBackground::forget();
            TerminalBackground::observe(new BackgroundColorMsg($background->r, $background->g, $background->b));

            $raw = MarkdownTheme::byName($themeName);
            $this->assertNotNull($raw);
            $projected = Theme::byName($themeName)->markdown->paragraph->getForeground();

            $rawForeground = $raw->paragraph->getForeground();
            $this->assertNotNull($rawForeground);
            $this->assertNotNull($projected);
            $this->assertSame(
                $rawForeground->toHex(),
                $projected->toHex(),
                "{$themeName} body on its native {$hex} must not be touched",
            );
        }
    }

    /**
     * Distinct source tokens stay distinct after the projection — the
     * `$claimed` law one layer up, applied to the markdown half: dracula's
     * paragraph (a near-white) and its comment (a slate) are different bytes
     * at source and must not collide into one nudged result on a white
     * terminal, where both need moving.
     */
    public function testDistinctMarkdownTokensStayDistinctAfterProjection(): void
    {
        TerminalBackground::observe(new BackgroundColorMsg(255, 255, 255));

        $markdown = Theme::byName('dracula')->markdown;
        $paragraph = $markdown->paragraph->getForeground();
        $comment = $markdown->comment->getForeground();
        $this->assertNotNull($paragraph);
        $this->assertNotNull($comment);
        $this->assertNotSame($paragraph->toHex(), $comment->toHex());
    }

    /**
     * Every Style the markdown palette actually holds, keyed by slot name,
     * enumerated off the live instance — the same reflection walk sugar-markdown's
     * own {@see MarkdownTheme::mapStyles()} uses to rebuild, so the measured
     * set cannot drift from the projected set.
     *
     * @return array<string, Style>
     */
    private function styleSlots(MarkdownTheme $markdown): array
    {
        $slots = [];
        foreach ((new \ReflectionClass(MarkdownTheme::class))->getProperties() as $property) {
            $value = $property->getValue($markdown);
            if ($value instanceof Style) {
                $slots[$property->getName()] = $value;
            }
        }

        $this->assertNotEmpty($slots, 'the markdown palette must carry Style slots to measure');

        return $slots;
    }
}
