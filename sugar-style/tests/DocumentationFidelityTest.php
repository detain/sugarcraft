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

        // Independent of the prose shape: the code really has no with*() builder.
        self::assertSame(0, $withers, 'Style gained with*() methods — the README census paragraph must be re-decided');
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
}
