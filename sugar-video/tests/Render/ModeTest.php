<?php

declare(strict_types=1);

namespace SugarCraft\Video\Tests\Render;

use PHPUnit\Framework\TestCase;
use SugarCraft\Video\Render\Mode;
use ValueError;

/**
 * Wire-vocabulary pins for the rendering-mode enum (SV-M36).
 *
 * @covers \SugarCraft\Video\Render\Mode
 */
final class ModeTest extends TestCase
{
    /**
     * @testdox wireName() round-trips through from() for every case
     */
    public function testTheWireNameRoundTripsThroughTheEnumParser(): void
    {
        foreach (Mode::cases() as $mode) {
            self::assertSame($mode, Mode::from($mode->wireName()));
        }
    }

    /**
     * @testdox The wire token is the lower-cased case name (the host-persistence alias)
     */
    public function testTheWireTokenIsTheFoldedCaseName(): void
    {
        foreach (Mode::cases() as $mode) {
            self::assertSame(strtolower($mode->name), $mode->wireName());
        }
    }

    /**
     * @testdox wires() is the full accepted vocabulary: auto first, then every case token
     */
    public function testWiresSinglesourcesTheAcceptedTokenList(): void
    {
        $wires = Mode::wires();

        self::assertSame('auto', Mode::AUTO_WIRE);
        self::assertSame(Mode::AUTO_WIRE, $wires[0]);
        self::assertCount(\count(Mode::cases()) + 1, $wires);
        self::assertSame($wires, array_values(array_unique($wires)), 'the vocabulary carries no duplicates');

        foreach (Mode::cases() as $mode) {
            self::assertContains($mode->wireName(), $wires);
        }

        self::assertNotContains('color', $wires, 'the historical ghost alias stays dead');
    }

    /**
     * @testdox auto is an honest wire cliff: a token the host accepts, a case from() refuses
     */
    public function testTheAutoTokenIsASelectorNotACase(): void
    {
        $caseValues = array_map(static fn (Mode $mode): string => $mode->value, Mode::cases());
        self::assertNotContains(Mode::AUTO_WIRE, $caseValues);

        $this->expectException(ValueError::class);
        Mode::from(Mode::AUTO_WIRE);
    }

    /**
     * @testdox The example host carries no hand-written token list (it validates against wires())
     */
    public function testTheExampleHostCarriesNoHandWrittenVocabulary(): void
    {
        $example = (string) file_get_contents(dirname(__DIR__, 2) . '/examples/play.php');

        self::assertStringContainsString('Mode::wires()', $example);
        self::assertStringContainsString('Mode::AUTO_WIRE', $example);
        self::assertStringNotContainsString("'ascii', 'ansi256'", $example);
        self::assertStringNotContainsString("['auto', 'ascii'", $example);
    }
}
