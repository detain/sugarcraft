<?php

declare(strict_types=1);

namespace SugarCraft\Style\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Style\Layout\Constraint;
use SugarCraft\Style\Layout\Layout as ConstraintLayout;
use SugarCraft\Style\Layout\Rect;
use SugarCraft\Style\Style;
use SugarCraft\Style\UnderlineStyle;

/**
 * Documentation-fidelity pins (docs-fix-1023 style lane).
 *
 * These are the lexical + behavioural anchors for the claims that the
 * review found inverted, phantom, or stale in sugar-style's docs:
 *
 *  - the ONE canonical sentence about SGR `4:N` degradation must appear
 *    verbatim (whitespace-flattened) in README.md, UnderlineStyle.php and
 *    Style.php — a future behaviour flip must re-decide all three texts;
 *  - the wire bytes it describes (4:N emitted, no bare SGR 4 companion,
 *    pass-through under the Ansi colour tier);
 *  - the README API census numerals for `Style` must match reflection
 *    (the doc once claimed "~40 with*() methods"; there are zero);
 *  - `Style::setDefaultProfile()` is documented as process-global next to
 *    its PROCESS-AMBIENT WARNING docblock anchor;
 *  - `Constraint::percentage()` is of the TOTAL area (README once said
 *    "remaining"; measured 60% of an 80-wide row is 48, not 36);
 *  - RegionBridge + the SUGARCRAFT_LAYOUT_SOLVER gate have a README home;
 *  - the private with() choke's arity quoted in the README matches reflection;
 *  - the Ansi-tier underlineColor() glyph-recolour fallback is byte-pinned
 *    AND named on both documented surfaces;
 *  - Style's NAMING EXCEPTION docblock paragraph (the bare-setter law
 *    exemption) is present where newcomers read it.
 */
final class DocumentationFidelityTest extends TestCase
{
    /**
     * The canonical underline-degradation sentence, as authored once and
     * copied verbatim into all three surfaces.
     */
    private const CANONICAL_UNDERLINE_SENTENCE = 'Terminals that understand SGR `4:N` render the styled underline; terminals that don\'t typically ignore the extended form and render no underline at all, since no plain SGR 4 companion is emitted.';

    /** Flatten docblock/line-comment leaders and whitespace for needle scans. */
    private static function flattened(string $file): string
    {
        $text    = (string) file_get_contents($file);
        $text    = preg_replace('/^\s*\*+\s*/m', ' ', $text) ?? $text;
        $text    = preg_replace('/^\s*\/\/+\s*/m', ' ', $text) ?? $text;

        return preg_replace('/\s+/u', ' ', $text) ?? $text;
    }

    public function testTheCanonicalUnderlineSentenceLivesOnAllThreeSurfaces(): void
    {
        foreach (
            [
                dirname(__DIR__) . '/README.md',
                dirname(__DIR__) . '/src/UnderlineStyle.php',
                dirname(__DIR__) . '/src/Style.php',
            ] as $surface
        ) {
            self::assertStringContainsString(
                self::CANONICAL_UNDERLINE_SENTENCE,
                self::flattened($surface),
                basename($surface) . ' must carry the one true 4:N sentence verbatim (flattened)',
            );
        }
    }

    public function testTheStyledUnderlineEmitsOnlyTheExtendedFormOnTheWire(): void
    {
        $rendered = Style::new()->underline()->underlineStyle(UnderlineStyle::Curly)
            ->of('wave')->render();

        self::assertStringContainsString("\x1b[4:3m", $rendered, 'curly sub-style rides SGR 4:3');
        self::assertStringNotContainsString("\x1b[4m", $rendered, 'no plain SGR 4 companion is emitted');
    }

    public function testSingleUnderlineStyleStillEmitsTheExtendedForm(): void
    {
        $rendered = Style::new()->underline()->underlineStyle(UnderlineStyle::Single)
            ->of('one')->render();

        self::assertStringContainsString("\x1b[4:1m", $rendered);
        self::assertStringNotContainsString("\x1b[4m", $rendered);
    }

    public function testTheAttributePassesThroughTheAnsiColourTier(): void
    {
        // Colour degrades per profile; the 4:N attribute itself never does —
        // exactly what the canonical sentence promises.
        $rendered = Style::new()->colorProfile(ColorProfile::Ansi)
            ->underline()->underlineStyle(UnderlineStyle::Curly)
            ->fg(Color::hex('#ff0000'))
            ->of('a')->render();

        self::assertStringContainsString("\x1b[4:3m", $rendered, '4:N survives the Ansi tier');
    }

    public function testReadmeStyleCensusMatchesReflection(): void
    {
        $setters = 0;
        $getters = 0;
        $unsetters = 0;
        $withers = 0;
        foreach ((new \ReflectionClass(Style::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->isConstructor() || str_starts_with($method->getName(), '__')) {
                continue;
            }
            $name = $method->getName();
            if (str_starts_with($name, 'with')) {
                $withers++;
            }
            if (str_starts_with($name, 'unset')) {
                $unsetters++;
                continue;
            }
            if (str_starts_with($name, 'get') || str_starts_with($name, 'is')) {
                $getters++;
                continue;
            }
            if ((string) $method->getReturnType() === 'self') {
                $setters++;
            }
        }

        $readme = (string) file_get_contents(dirname(__DIR__) . '/README.md');
        self::assertStringContainsString($setters . ' fluent setters', $readme, 'README setter census drifted');
        self::assertStringContainsString($withers . ' `with*()` methods', $readme, 'README with*() census drifted');
        self::assertStringContainsString($getters . ' getters', $readme, 'README getter census drifted');
        self::assertStringContainsString($unsetters . ' `unset*()`', $readme, 'README unsetter census drifted');

        // The private with() choke's arity is quoted in the README prose.
        $params = (new \ReflectionMethod(Style::class, 'with'))->getNumberOfParameters();
        self::assertStringContainsString(
            $params . '-positional-parameter `with()` choke',
            $readme,
            'README with() parameter count drifted from reflection (' . $params . ')',
        );

        // Independent of the prose shape: the only with*() builders are the
        // four colour-slot value-object setters (2026-11 re-decision of the
        // original zero-wither census — finding 019). A fifth wither without
        // a README re-decision goes red here.
        self::assertSame(4, $withers, 'Style gained with*() methods — the README census paragraph must be re-decided');
    }

    /**
     * The Ansi tier has no SGR 58 spelling: underlineColor() falls back to
     * the foreground slot and recolours the glyphs (measured ESC[4m
     * ESC[91m, sugar-core Color::toUnderline()). The README degradation
     * section and the Style::underlineColor() docblock must both keep
     * saying so — a silent "fixed" behaviour flip has to re-decide both.
     */
    public function testAnsiTierUnderlineColorRecoloursGlyphsAndBothDocsSaySo(): void
    {
        $rendered = Style::new()->colorProfile(ColorProfile::Ansi)
            ->underline()->underlineColor(Color::hex('#ff0000'))
            ->of('x')->render();

        self::assertStringContainsString("\x1b[4m", $rendered, 'plain SGR 4 underline rides along');
        self::assertStringContainsString("\x1b[91m", $rendered, 'Ansi-tier underline colour lands on the text');
        self::assertStringNotContainsString('58;', $rendered, 'no SGR 58 exists at the 16-colour tier');

        $readme = self::flattened(dirname(__DIR__) . '/README.md');
        self::assertStringContainsString('recolours the glyphs', $readme);

        $styleSource = self::flattened(dirname(__DIR__) . '/src/Style.php');
        self::assertStringContainsString('recolours the glyphs', $styleSource);
    }

    public function testDefaultProfileIsDocumentedAsProcessGlobalWhereItIsDeclared(): void
    {
        $readme = self::flattened(dirname(__DIR__) . '/README.md');
        self::assertStringContainsString('process-global mutable static', $readme);
        self::assertStringContainsString('Style::setDefaultProfile(', $readme);

        $style = self::flattened(dirname(__DIR__) . '/src/Style.php');
        self::assertStringContainsString('PROCESS-AMBIENT WARNING', (string) file_get_contents(dirname(__DIR__) . '/src/Style.php'));
        self::assertStringContainsString('process-global', $style);
    }

    public function testPercentageIsOfTheTotalAreaAndTheReadmeSaysSo(): void
    {
        $cols = ConstraintLayout::horizontal([
            Constraint::length(20),
            Constraint::percentage(60),
            Constraint::fill(1),
        ])->split(Rect::fromSize(80, 10));

        self::assertSame(48, $cols[1]->width, '60% of the 80-wide TOTAL, not of the remaining 60');

        $readme = self::flattened(dirname(__DIR__) . '/README.md');
        self::assertStringContainsString('% of the total area', $readme);
        self::assertStringContainsString('60% of the total width', $readme);
        self::assertStringNotContainsString('60% of remaining', $readme);
    }

    public function testTheCrossPkgBridgeAndSolverGateHaveAReadmeHome(): void
    {
        $readme = self::flattened(dirname(__DIR__) . '/README.md');
        self::assertStringContainsString('RegionBridge', $readme);
        self::assertStringContainsString('toConstraints', $readme);
        self::assertStringContainsString('SUGARCRAFT_LAYOUT_SOLVER', $readme);
        self::assertStringContainsString('resetEnvironmentResolutionForTesting', $readme);
    }

    public function testAccessibilityGuidanceHasAReadmeHome(): void
    {
        $readme = self::flattened(dirname(__DIR__) . '/README.md');
        self::assertStringContainsString('## Accessibility ', $readme);
        self::assertStringContainsString('WCAG 1.4.1', $readme);
    }

    public function testStyleCarriesTheNamingExceptionDocblockParagraph(): void
    {
        $style = self::flattened(dirname(__DIR__) . '/src/Style.php');
        self::assertStringContainsString('NAMING EXCEPTION', $style);
        self::assertStringContainsString('PHP cannot overload by arity', $style);
        self::assertStringContainsString('only the spelling deviates', $style);
    }

    // ─── docs-review-1023 / lib-reviews-1102 sugar-style lane ───────────────

    public function testStyleParserTakesTwoRequiredParamsAndTheReadmeSaysSo(): void
    {
        $parse = new \ReflectionMethod(\SugarCraft\Style\StyleParser::class, 'parse');
        self::assertSame(2, $parse->getNumberOfRequiredParameters(), 'README claims no one-argument form');

        $readme = self::flattened(dirname(__DIR__) . '/README.md');
        self::assertStringContainsString('StyleParser::parse($input, $defaultStyle)', $readme);
        self::assertStringContainsString('no one-argument form', $readme);
        self::assertStringNotContainsString('StyleParser::parse(string)', $readme, 'the old one-param cite is back — it fatals on paste');
        self::assertStringContainsString('unknown bare word is dropped and ignored', $readme, 'the throw-vs-ignore polarity must be documented');
    }

    public function testThemeAccentMutedFallbackOnlyHoldsInDarkAndTheReadmeMatches(): void
    {
        $hex = static fn (\SugarCraft\Core\Util\Color $c): string => strtolower($c->toHex());

        $dark = \SugarCraft\Style\Theme::dark();
        self::assertSame($hex($dark->accent), $hex($dark->primary));
        self::assertSame($hex($dark->muted), $hex($dark->secondary));

        $light = \SugarCraft\Style\Theme::light();
        self::assertSame($hex($light->accent), $hex($light->primary));
        self::assertSame('#6b7280', $hex($light->muted), 'light muted is its own grey');
        self::assertSame('#10b981', $hex($light->secondary));

        $ansi = \SugarCraft\Style\Theme::ansi();
        self::assertSame('#00cdcd', $hex($ansi->primary));
        self::assertSame('#cd00cd', $hex($ansi->accent));
        self::assertSame('#00cd00', $hex($ansi->secondary));
        self::assertSame('#7f7f7f', $hex($ansi->muted));

        $readme = self::flattened(dirname(__DIR__) . '/README.md');
        self::assertStringContainsString('fall back to `primary`/`secondary` only in `dark()`', $readme);
    }

    public function testBareTableCarriesNoBorderAndTheReadmeSaysSo(): void
    {
        $bare = \SugarCraft\Style\Table\Table::new()->headers('a')->row('b')->render();
        self::assertStringNotContainsString("\u{256D}", $bare);
        self::assertStringNotContainsString("\u{2502}", $bare, 'a bare table draws no verticals either');

        $bordered = \SugarCraft\Style\Table\Table::new()->headers('a')->row('b')
            ->border(\SugarCraft\Style\Border::rounded())->render();
        self::assertStringContainsString("\u{256D}", $bordered);

        $readme = self::flattened(dirname(__DIR__) . '/README.md');
        self::assertStringContainsString('a bare `Table::new()` carries **no** border', $readme);
    }

    public function testSevenBorderFlagsExistAndTheReadmeCountsThemCorrectly(): void
    {
        $flags = [];
        foreach ((new \ReflectionClass(\SugarCraft\Style\Table\Table::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();
            if (preg_match('/^border[A-Z]/', $name) === 1) {
                $flags[] = $name;
            }
        }
        sort($flags);
        self::assertSame([
            'borderBottom', 'borderColumn', 'borderHeader', 'borderLeft',
            'borderRight', 'borderRow', 'borderTop',
        ], $flags);

        $readme = self::flattened(dirname(__DIR__) . '/README.md');
        self::assertStringContainsString('Seven border flags', $readme);
        self::assertStringNotContainsString('four border-section flags', $readme);
    }

    public function testEnumeratorStyleSetMatchesTheReadmeBullet(): void
    {
        $styles = ['dash', 'bullet', 'asterisk', 'arabic', 'alphabet', 'roman', 'romanUpper', 'decimal', 'tree', 'none'];
        foreach ($styles as $style) {
            self::assertTrue(
                method_exists(\SugarCraft\Style\Listing\Enumerator::class, $style),
                "Listing\\Enumerator::{$style}() named in the README does not exist",
            );
        }

        $readme = self::flattened(dirname(__DIR__) . '/README.md');
        self::assertStringContainsString('arabic, alphabet, roman, romanUpper, decimal, tree, none', $readme);
    }

    public function testUnderlineStyleIsInertWithoutUnderlineAndTheReadmeSaysSo(): void
    {
        $alone = Style::new()->underlineStyle(UnderlineStyle::Curly)->of('x')->render();
        self::assertStringNotContainsString("\x1b[4:", $alone, 'the sub-style alone emits nothing');

        $together = Style::new()->underline()->underlineStyle(UnderlineStyle::Curly)->of('x')->render();
        self::assertStringContainsString("\x1b[4:3m", $together);

        $readme = self::flattened(dirname(__DIR__) . '/README.md');
        self::assertStringContainsString('Inert on its own', $readme);

        $style = self::flattened(dirname(__DIR__) . '/src/Style.php');
        self::assertStringContainsString('in combination with `underline()`', $style, 'the setter docblock must state the gate');
    }

    public function testLightDarkIsAClosureAndNoDocSellsAPickMethod(): void
    {
        $renderer = \SugarCraft\Style\Renderer::new()->withHasDarkBackground(true);
        $picker   = $renderer->lightDark();
        self::assertInstanceOf(\Closure::class, $picker);

        $picked = $picker(Color::hex('#ffffff'), Color::hex('#000000'));
        self::assertSame('#000000', strtolower($picked->toHex()), 'dark background picks the dark colour');

        $src = (string) file_get_contents(dirname(__DIR__) . '/src/Renderer.php');
        self::assertStringNotContainsString('lightDark()->pick', $src, 'the docblock example must not resurrect the phantom method');
    }

    public function testPreStyledContentIsNotRestyledAfterTheInnerReset(): void
    {
        $inner = Style::new()->fg(Color::hex('#ff0000'))->of('a')->render();
        $outer = Style::new()->bold()->of($inner . ' b')->render();

        self::assertStringContainsString("\x1b[0m b", $outer, 'the outer style stops at the inner reset (documented bleed)');

        $readme = self::flattened(dirname(__DIR__) . '/README.md');
        self::assertStringContainsString('is **not** re-applied to the remainder', $readme);
    }

    public function testFgOnAliasesAreHexOnlyAndTheReadmeSaysSo(): void
    {
        foreach (['fg' => 'white', 'on' => 'red'] as $setter => $name) {
            try {
                Style::new()->{$setter}($name);
                self::fail("Style::{$setter}('{$name}') must throw — the alias routes through Color::hex");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('invalid hex color', $e->getMessage());
            }
        }

        self::assertInstanceOf(Color::class, Color::parse('white'), 'names DO parse via Color::parse / markup');

        $readme = self::flattened(dirname(__DIR__) . '/README.md');
        self::assertStringContainsString('**hex-only**', $readme);
    }

    public function testCassowaryNoticesFireOncePerProcessAndOncePerSolve(): void
    {
        \SugarCraft\Style\Layout\SolverFactory::resetEnvironmentResolutionForTesting();
        putenv('SUGARCRAFT_LAYOUT_SOLVER=cassowary');
        \SugarCraft\Style\Layout\SolverFactory::resetEnvironmentResolutionForTesting();

        $notices = [];
        try {
            set_error_handler(static function (int $number, string $message) use (&$notices): bool {
                $notices[] = $message;

                return true;
            });

            \SugarCraft\Style\Layout\SolverFactory::fromEnvironment();
            \SugarCraft\Style\Layout\SolverFactory::fromEnvironment();

            $area      = \SugarCraft\Style\Layout\Rect::fromSize(80, 10);
            $structure = [Constraint::length(20), Constraint::fill(1)];
            ConstraintLayout::horizontal($structure)->split($area);
            ConstraintLayout::horizontal($structure)->split($area);
        } finally {
            restore_error_handler();
            putenv('SUGARCRAFT_LAYOUT_SOLVER');
            \SugarCraft\Style\Layout\SolverFactory::resetEnvironmentResolutionForTesting();
        }

        self::assertSame(
            1,
            substr_count(implode("\n", $notices), 'This notice fires once per process.'),
            'the factory announcement must be once-per-process',
        );
        self::assertSame(
            2,
            substr_count(implode("\n", $notices), 'CassowarySolver never converges'),
            'the shim announces on EVERY solve — README trust section says so',
        );

        $readme = self::flattened(dirname(__DIR__) . '/README.md');
        self::assertStringContainsString('on **every** `solve()` it takes', $readme);
    }

    public function testClicolorIsConsultedAndTheRosterSaysHowItRanks(): void
    {
        self::assertSame(ColorProfile::Ascii, ColorProfile::detect(['TERM' => 'xterm-256color', 'CLICOLOR' => '0']));
        self::assertSame(ColorProfile::Ansi256, ColorProfile::detect(['TERM' => 'xterm-256color', 'CLICOLOR' => '1']));
        self::assertSame(ColorProfile::TrueColor, ColorProfile::detect(['CLICOLOR' => '0', 'CLICOLOR_FORCE' => '1']), 'force outranks CLICOLOR=0');
        self::assertSame(ColorProfile::Ascii, ColorProfile::detect(['NO_COLOR' => '1', 'CLICOLOR_FORCE' => '1']), 'NO_COLOR precedes even force');

        $readme = self::flattened(dirname(__DIR__) . '/README.md');
        self::assertStringContainsString('is honoured', $readme, 'the env roster must state CLICOLOR is consulted');
        self::assertStringNotContainsString('deliberately **not** consulted', $readme);
    }

    public function testSiteBodyCarriesTheTruthPass(): void
    {
        $body = dirname(__DIR__, 2) . '/docs/_data/sugar-style.body.html';
        if (!is_file($body)) {
            $this->markTestSkipped('site data is monorepo-only (split-repo checkout has no docs/)');
        }

        $text = (string) file_get_contents($body);
        self::assertStringNotContainsString('alpha-aware merge', $text, 'Canvas blends no alpha — README says cell-aware splice');
        self::assertStringContainsString('Underline truth', $text, 'the 4:N gate must exist on the generated surface too');
        self::assertStringContainsString('>Bar<', $text, 'the API table must list the Bar widgets');
        self::assertStringNotContainsString('tree/main/examples', $text, 'examples links ride the master branch');
    }

    public function testTrustAndSanitisationSectionHasAReadmeHome(): void
    {
        $readme = self::flattened(dirname(__DIR__) . '/README.md');
        self::assertStringContainsString('## Trust and sanitisation', $readme);
        self::assertStringContainsString('Sanitize::untrustedForMarkedFrames', $readme);
        self::assertStringContainsString('never collapsed', $readme, 'the \\r handling contract is stated');
    }
}
