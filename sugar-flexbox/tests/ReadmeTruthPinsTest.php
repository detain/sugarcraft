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
        'legacy alias of',
        '27/27/26',
        'decorative',
    ];

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
