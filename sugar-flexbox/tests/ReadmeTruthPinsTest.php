<?php

declare(strict_types=1);

namespace SugarCraft\Flexbox\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The README and the generated site body are this library's public face;
 * both have drifted from the code before (TableRenderer existed for ages
 * without ever being named, sticky regions were advertised as deferred
 * long after they shipped, the API table taught a private constructor as
 * a public factory). These pins re-derive the claims that matter from the
 * documents themselves so the next drift goes red at the gate, and the
 * probe test proves each anchor is actually load-bearing by deleting it
 * from an in-memory copy.
 */
final class ReadmeTruthPinsTest extends TestCase
{
    /** @var list<string> Phrases the README must carry to be honest. */
    private const README_ANCHORS = [
        'How sizing works',
        'Buffer diffing (TableRenderer)',
        'withStickyHeader',
        'withStickyFooter',
        'withLinkedViewport',
        'halfPageUp',
        'halfPageDown',
        'deprecated alias of',
        '27/27/26',
        'decorative',
        // FBX-46: the control-character sweep spelled exactly, and the
        // tab-only measurement asymmetry. Both backed by the code pins
        // below (testTheSweepMatchesItsDocumentation...).
        'DELETED (not replaced)',
        'over-allocation, never a truncation',
    ];

    /**
     * Per-control-byte truth for "a{ctl}b": the swept output the cell
     * paints and the RAW measuredWidth the FlexBox reserves. Derived
     * from the sanitizer's real switch (Column::sanitize) and the
     * measurement ingress (FlexItem::measuredWidth), not from prose —
     * FBX-46.
     *
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    public static function controlByteTruth(): array
    {
        return [
            'CRLF'      => ["\r\n", 'a b', 1],
            'LF'        => ["\n", 'a b', 1],
            'lone CR'   => ["\r", 'ab', 2],
            'TAB'       => ["\t", 'a b', 6],
            'CSI'       => ["\x1b[31m", 'ab', 2],
            'bare ESC'  => ["\x1b", 'ab', 2],
            'NUL'       => ["\x00", 'ab', 2],
            'BEL'       => ["\x07", 'ab', 2],
            'DEL'       => ["\x7f", 'ab', 2],
        ];
    }

    /** @var list<string> Claims the generated site body must NOT repeat. */
    private const BODY_FICTIONS = [
        'Deferred',
        '(Go)',
        'tree/main',
        'withGap(pixels)',
        'zebra',
    ];

    public function testTheReadmeTeachesTheSizingModelAndEveryComponent(): void
    {
        $missing = self::missingAnchors(self::readme(), self::README_ANCHORS);
        $this->assertSame([], $missing, 'README lost its teaching anchors: ' . implode(', ', $missing));
    }

    public function testTheSiteBodyCarriesNoGhostClaims(): void
    {
        $body = \file_get_contents(\dirname(__DIR__, 2) . '/docs/_data/sugar-flexbox.body.html');
        $this->assertIsString($body, 'the site body feed must exist next to the library');
        foreach (self::BODY_FICTIONS as $ghost) {
            $this->assertStringNotContainsString($ghost, $body, "site body resurrected ghost claim '{$ghost}'");
        }
        $this->assertStringContainsString('TableRenderer', $body, 'the site body must name the delta renderer');
        $this->assertStringContainsString('withStickyHeader()', $body, 'sticky regions are shipped, not deferred');
    }

    public function testTheReadmeLinksTheCellbufferLibraryCorrectly(): void
    {
        // The old URL pointed at a repo that has never existed.
        $this->assertStringContainsString(
            'github.com/detain/sugarcraft/tree/master/sugar-cellbuffer',
            self::readme(),
        );
    }

    public function testThePinsAreLoadBearingUnderPlantedDeletions(): void
    {
        $readme = self::readme();
        foreach (self::README_ANCHORS as $anchor) {
            $doctored = \str_replace($anchor, '', $readme);
            $this->assertSame(
                [$anchor],
                self::missingAnchors($doctored, self::README_ANCHORS),
                "deleting '{$anchor}' must be detected — the pin is vacuous otherwise",
            );
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('controlByteTruth')]
    public function testTheSweepMatchesItsDocumentationForEveryControlByte(string $ctl, string $swept, int $rawCells): void
    {
        $input = 'a' . $ctl . 'b';

        // The render-side sweep, straight from the sanitizer's switch.
        $method = new \ReflectionMethod(\SugarCraft\Flexbox\Table\Column::class, 'sanitize');
        $method->setAccessible(true);
        $column = \SugarCraft\Flexbox\Table\Column::new('t', 4);
        $this->assertSame($swept, $method->invoke($column, $input), "swept output for {$ctl}");

        // The measure-side truth the README contrasts it with: the RAW
        // string, same ingress the layout math uses.
        $this->assertSame($rawCells, \SugarCraft\Flexbox\Flex\FlexItem::new($input)->measuredWidth(), "measured cells for {$ctl}");
    }

    public function testTheReadmeNoLongerClaimsTheFalseSweepAndBlanketSkew(): void
    {
        // Prose regression guard: both sentences were almost-true and
        // both are now documented as exactly-true (finding-059).
        $readme = self::readme();
        $this->assertStringNotContainsString(
            'newlines, tabs and carriage returns are replaced with spaces',
            $readme,
            'the lone CR is DELETED and CRLF collapses to ONE space — the blanket claim is false',
        );
        $this->assertStringNotContainsString(
            'still consume measured cells',
            $readme,
            'only the TAB skews measurement; ESC/CSI/NUL/BEL/DEL measure zero both sides',
        );
    }

    private static function readme(): string
    {
        $readme = \file_get_contents(\dirname(__DIR__) . '/README.md');
        self::assertIsString($readme);
        return $readme;
    }

    /**
     * @param  list<string> $anchors
     * @return list<string> the anchors absent from $text
     */
    private static function missingAnchors(string $text, array $anchors): array
    {
        return \array_values(\array_filter(
            $anchors,
            static fn (string $anchor): bool => !\str_contains($text, $anchor),
        ));
    }
}
