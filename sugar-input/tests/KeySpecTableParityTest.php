<?php

declare(strict_types=1);

namespace SugarCraft\Input\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SugarCraft\Core\KeyType;
use SugarCraft\Input\EscapeDecoder;
use SugarCraft\Input\Event\KeyEvent;

/**
 * Cross-library partition pin: sugar-input's kitty functional table and
 * sugar-core's KeyType::kittyFunctionalKeys() MUST agree on exactly which
 * codepoints at 0xE000+ name which keys. Before this lane the two libs
 * disagreed at 57399-57402 (sugar-input claimed arrows; the spec and sugar-core
 * both say Kp0..Kp3) — the split itself was the review's C1 finding.
 *
 * The tables are compared LIVE (both reflected from their sources), so a drift
 * on either side reddens here rather than silently diverging again.
 */
final class KeySpecTableParityTest extends TestCase
{
    /**
     * sugar-core KeyType member name -> sugar-input house key name.
     * Identical spellings are absent and pass through.
     */
    private const NAME_TRANSLATION = [
        'Left' => 'ArrowLeft',
        'Right' => 'ArrowRight',
        'Up' => 'ArrowUp',
        'Down' => 'ArrowDown',
        'Kp0' => 'KP0',
        'Kp1' => 'KP1',
        'Kp2' => 'KP2',
        'Kp3' => 'KP3',
        'Kp4' => 'KP4',
        'Kp5' => 'KP5',
        'Kp6' => 'KP6',
        'Kp7' => 'KP7',
        'Kp8' => 'KP8',
        'Kp9' => 'KP9',
        'KpDecimal' => 'KPDecimal',
        'KpDivide' => 'KPDivide',
        'KpMultiply' => 'KPMultiply',
        'KpSubtract' => 'KPSubtract',
        'KpAdd' => 'KPAdd',
        'KpEnter' => 'KPEnter',
        'KpEqual' => 'KPEqual',
        'KpSeparator' => 'KPSeparator',
        'KpLeft' => 'KPLeft',
        'KpRight' => 'KPRight',
        'KpUp' => 'KPUp',
        'KpDown' => 'KPDown',
        'KpPageUp' => 'KPPageUp',
        'KpPageDown' => 'KPPageDown',
        'KpHome' => 'KPHome',
        'KpEnd' => 'KPEnd',
        'KpInsert' => 'KPInsert',
        'KpDelete' => 'KPDelete',
        'KpBegin' => 'KPBegin',
    ];

    /**
     * Extract our hoisted const (private to the class by design — the parity
     * guard and the decoder read the SAME array, so the test reads the
     * decoder's own table, never a copy).
     *
     * @return array<int, string>
     */
    private static function ourTable(): array
    {
        $table = (new ReflectionClass(EscapeDecoder::class))->getConstant('KITTY_FUNCTIONAL_KEYS');
        self::assertIsArray($table, 'EscapeDecoder must hoist its functional table to a const');

        return $table;
    }

    /**
     * sugar-core's table is spelled '\SugarCraft\Core\KeyType::Name' — take
     * the member names, translated to our vocabulary.
     *
     * @return array<int, string>
     */
    private static function coreTable(): array
    {
        $raw = KeyType::kittyFunctionalKeys();
        $out = [];
        foreach ($raw as $code => $member) {
            // sugar-core hands back KeyType enum cases; take the case name.
            $name = $member instanceof \UnitEnum ? $member->name : (string) $member;
            $out[$code] = self::NAME_TRANSLATION[$name] ?? $name;
        }

        return $out;
    }

    public function testTheTwoFunctionalTablesCoverTheSameCodepoints(): void
    {
        $ours = self::ourTable();
        $core = self::coreTable();

        self::assertSame(
            array_keys($core),
            array_keys($ours),
            'kitty functional codepoint partition drifted from sugar-core KeyType',
        );
    }

    public function testEveryCodepointNamesTheSameKeyBothSides(): void
    {
        foreach (self::coreTable() as $code => $expected) {
            self::assertSame($expected, self::ourTable()[$code] ?? null, "codepoint {$code} disagrees");
        }
    }

    public function testTextRangeIsDisjointFromTheFunctionalBlock(): void
    {
        // The partition law: everything the spec puts below 0xE000 (text and
        // the shared control codes) must NOT appear in either functional
        // table — that shadowing was the old table's mis-naming engine.
        $ours = self::ourTable();
        foreach ([1, 6, 9, 11, 13, 24, 27, 32, 34, 127, 0x0400, 0xDFFF] as $code) {
            self::assertArrayNotHasKey($code, $ours, sprintf('0x%04X is text space', $code));
        }

        foreach (array_keys($ours) as $code) {
            self::assertGreaterThanOrEqual(0xE000, $code);
            self::assertLessThanOrEqual(0xE0AE, $code, 'table reaches past the spec block (57454)');
        }
    }

    public function testTheDecoderEndToEndAgreesWithItsOwnTable(): void
    {
        // Belt: every table row must actually arrive as a KeyEvent with that
        // name through decode() (const wired, not merely declared), and the
        // release leg keeps the name under its Release prefix.
        $decoder = new EscapeDecoder();
        foreach (self::ourTable() as $code => $name) {
            $events = $decoder->decode("\x1b[{$code};1u");
            self::assertCount(1, $events, "code {$code} did not decode");
            self::assertInstanceOf(KeyEvent::class, $events[0]);
            self::assertSame($name, $events[0]->key, "code {$code} named wrong");
        }

        $events = $decoder->decode("\x1b[57352;1:3u");
        self::assertSame('ReleaseArrowUp', $events[0]->key);
    }

    public function testBoundaryCodepointsAreSpecTrueInBothDirections(): void
    {
        $decoder = new EscapeDecoder();
        // Lower boundary: 57343 is below the block -> counted drop; 57344 Escape.
        self::assertSame([], $decoder->decode("\x1b[57343;0u"));
        self::assertSame('Escape', $decoder->decode("\x1b[57344;0u")[0]->key);
        // Arrow/keypad seam: the historic misfire sat exactly here.
        self::assertSame('ArrowDown', $decoder->decode("\x1b[57353;0u")[0]->key);
        self::assertSame('PageUp', $decoder->decode("\x1b[57354;0u")[0]->key);
        self::assertSame('Menu', $decoder->decode("\x1b[57363;0u")[0]->key);
        self::assertSame('F1', $decoder->decode("\x1b[57364;0u")[0]->key);
        self::assertSame('F35', $decoder->decode("\x1b[57398;0u")[0]->key);
        self::assertSame('KP0', $decoder->decode("\x1b[57399;0u")[0]->key);
        self::assertSame('IsoLevel5Shift', $decoder->decode("\x1b[57454;0u")[0]->key);
        self::assertSame([], $decoder->decode("\x1b[57455;0u"));
    }
}
