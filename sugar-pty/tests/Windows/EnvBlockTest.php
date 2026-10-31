<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Windows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\PtyException;
use SugarCraft\Pty\Windows\EnvBlock;
use SugarCraft\Pty\Windows\WideString;

/**
 * {@see EnvBlock} — the `lpEnvironment` buffer, asserted as BYTES.
 *
 * ## Why the assertions decode rather than `assertStringContainsString`
 *
 * The failure modes of this format are all about where the NULs are, and a
 * substring test on a UTF-16 buffer cannot see a NUL: `"A=1"` appears inside a
 * block whose entries have no separator between them at all. So every structural
 * claim here goes through {@see entries()}, which splits the buffer the way the
 * child's CRT does — on a NUL code unit — and returns the decoded list. A block
 * that forgot its second terminator shows up there as a trailing empty entry or a
 * short count, which is a nameable failure; against `assertStringContainsString`
 * it would be invisible.
 *
 * ## The rows that are load-bearing
 *
 *  - the double-NUL tail, asserted as the last two code units of the buffer;
 *  - case-insensitive ordering, with `b` between `A` and `C`, because an ASCII
 *    byte sort puts `A` before `b` before `C` only by accident of the case, and a
 *    sort that is right by accident is not pinned at all — hence the
 *    lower-before-upper row, where a naive byte sort and the required order
 *    DISAGREE;
 *  - the empty map, which must still be a valid (empty) block rather than `''`:
 *    handing `CreateProcessW` a null pointer means "inherit", which is a different
 *    operation than the caller asked for.
 */
final class EnvBlockTest extends TestCase
{
    public function testEmptyMapIsStillATerminatedBlock(): void
    {
        // A zero-length buffer would be read by CreateProcessW as "no block was
        // supplied", i.e. inherit — the opposite of the caller's intent.
        self::assertSame(
            WideString::NUL_UNIT . WideString::NUL_UNIT,
            EnvBlock::encode([]),
            'an empty map must still be a valid empty block',
        );
        self::assertSame([], self::entries(EnvBlock::encode([])));
    }

    public function testOneEntryIsTerminatedAndTheBlockIsClosed(): void
    {
        $block = EnvBlock::encode(['PATH' => 'C:\bin']);

        self::assertSame(
            WideString::fromUtf8('PATH=C:\bin')
            . WideString::NUL_UNIT   // the entry's own terminator
            . WideString::NUL_UNIT,  // the block's
            $block,
        );
        // The exact byte layout above IS the terminator claim; a "does not end with
        // four NULs" probe would be wrong, because any entry whose last character
        // has a zero low byte (every ASCII one) already ends in 00 00 before the two
        // terminators. What is checked instead is that there are exactly two units
        // after the entry's own — which the assertSame covers — and that decoding
        // yields ONE entry, not one entry plus a phantom empty one.
        self::assertSame(['PATH=C:\bin'], self::entries($block));
    }

    public function testEntriesAreSeparatedByOneNulUnit(): void
    {
        $block = EnvBlock::encode(['A' => '1', 'B' => '2']);

        self::assertSame(
            WideString::fromUtf8('A=1') . WideString::NUL_UNIT
            . WideString::fromUtf8('B=2') . WideString::NUL_UNIT . WideString::NUL_UNIT,
            $block,
        );
        self::assertSame(['A=1', 'B=2'], self::entries($block));
    }

    public function testOrderIsCaseInsensitiveNotByteInsensitive(): void
    {
        // The row a byte sort passes by luck and a case sort passes on purpose:
        // bytes put every upper-case name before every lower-case one, so `b`
        // landing BETWEEN `A` and `C` can only come from folding the case first.
        $block = EnvBlock::encode(['c' => '3', 'A' => '1', 'B' => '2']);

        self::assertSame(['A=1', 'B=2', 'c=3'], self::entries($block));
    }

    public function testInputOrderDoesNotChangeTheBlock(): void
    {
        // The determinism claim, pinned: two maps, one array each, same contents,
        // opposite insertion order, byte-identical block.
        $first = EnvBlock::encode(['PATH' => 'x', 'HOME' => 'y', 'ZED' => 'z']);
        $second = EnvBlock::encode(['ZED' => 'z', 'HOME' => 'y', 'PATH' => 'x']);

        self::assertSame($first, $second);
    }

    public function testCaseCollidingNamesBothSurviveInADefinedOrder(): void
    {
        $block = EnvBlock::encode(['FOO' => '1', 'foo' => '2']);

        self::assertSame(['FOO=1', 'foo=2'], self::entries($block));
        self::assertTrue(EnvBlock::hasCaseCollisions(['FOO' => '1', 'foo' => '2']));
        self::assertFalse(EnvBlock::hasCaseCollisions(['FOO' => '1', 'bar' => '2']));
        // And re-deriving from the other insertion order must NOT swap them: the
        // tie-break is on the entry, so the child's winner is reproducible.
        self::assertSame($block, EnvBlock::encode(['foo' => '2', 'FOO' => '1']));
    }

    public function testValueContainingEqualsIsKeptIntact(): void
    {
        // Only the FIRST '=' splits, so a value that carries one is normal data —
        // `LD_LIBRARY_PATH`-style lists and connection strings among them.
        self::assertSame(['A=1=2=3'], self::entries(EnvBlock::encode(['A' => '1=2=3'])));
    }

    public function testNonAsciiNamesAndValuesRoundTrip(): void
    {
        $entries = self::entries(EnvBlock::encode(['CLÉ' => 'ünïcøde 🎉']));

        self::assertCount(1, $entries);
        self::assertSame('CLÉ=ünïcøde 🎉', $entries[0]);
    }

    public function testEqualsInsideANameIsRefused(): void
    {
        try {
            EnvBlock::encode(['BAD=NAME' => 'x']);
            self::fail('a name containing "=" was accepted');
        } catch (PtyException $e) {
            self::assertStringContainsString('=', $e->getMessage());
            self::assertStringContainsString('different name', $e->getMessage());
        }
    }

    public function testLeadingEqualsIsRefusedTooBecauseThatIsAName(): void
    {
        // The per-drive `=C:=` markers from a real Windows environment. Refused
        // here, dropped by fromProcess() — see the class doc-block for why a
        // refusal at the encode boundary is the narrower behaviour.
        $this->expectException(PtyException::class);
        EnvBlock::encode(['=C:' => 'C:\work']);
    }

    public function testEmptyNameIsRefused(): void
    {
        $this->expectException(PtyException::class);
        EnvBlock::encode(['' => 'x']);
    }

    public function testNulInEitherHalfIsRefused(): void
    {
        // NUL is the delimiter of this very format, so an entry carrying one does
        // not encode badly — it silently becomes two entries, and the second one
        // is attacker-shaped data.
        foreach ([["A\0B" => 'x'], ['A' => "x\0y"]] as $case) {
            try {
                EnvBlock::encode($case);
                self::fail('a NUL byte was accepted into an environment block');
            } catch (PtyException $e) {
                self::assertStringContainsString('NUL', $e->getMessage());
            }
        }
    }

    public function testNonStringValuesAreRefused(): void
    {
        $this->expectException(PtyException::class);
        EnvBlock::encode(['A' => 1]);
    }

    public function testFromProcessSnapshotIsEncodableAsIs(): void
    {
        // The composition the adapter step needs: whatever the host's environment
        // holds, the snapshot must survive encode() — which is where the `=C:`
        // drop-rather-than-throw rule earns its place, since a Windows host's
        // real environment carries exactly those entries.
        $snapshot = EnvBlock::fromProcess();

        self::assertNotEmpty($snapshot, 'getenv() returned nothing on this host');
        self::assertArrayHasKey('PATH', $snapshot);

        $block = EnvBlock::encode($snapshot);

        self::assertSame(WideString::NUL_UNIT . WideString::NUL_UNIT, substr($block, -4));
        self::assertGreaterThan(2, \count(self::entries($block)));

        foreach (self::entries($block) as $entry) {
            [$name] = \explode('=', $entry, 2);
            self::assertNotSame('', $name, 'an entry reached the block with no name');
            self::assertNotSame('=', \substr($name, 0, 1), 'a per-drive entry reached the block');
        }
    }

    public function testSnapshotDropsThePerDriveEntries(): void
    {
        // fromProcess() is documented to drop what encode() refuses. This host is
        // Linux and has no `=C:` entries to drop, so the rule is asserted against
        // the shape rather than the host: no name in the snapshot may be one
        // encode() would throw on.
        foreach (\array_keys(EnvBlock::fromProcess()) as $name) {
            self::assertStringNotContainsString('=', (string) $name);
            self::assertNotSame('', (string) $name);
        }
    }

    /**
     * Decode a block the way the child reads it: consume NUL-terminated strings
     * until one comes back EMPTY — that empty string is the block terminator, and
     * it is the same test that makes an empty block decodable as no entries rather
     * than as one entry whose name is ''.
     *
     * ## It walks CODE UNITS, and that is not pedantry
     *
     * The obvious implementation is `strpos($body, "\0\0")`, and it is wrong on a
     * host you would never think to test: a UTF-16LE unit whose LOW byte is zero is
     * a real character — U+0100 (Ā) is the bytes `00 01` — so the sequence ASCII 'a'
     * then Ā is `61 00 00 01`, which contains `00 00` at an ODD offset. A byte-level
     * scan calls that a terminator, slices mid-character, and reports an
     * odd-length remainder. Reading a wide string means stepping two bytes at a
     * time; the row pinning it is {@see testANameWithAZeroLowByteCharacterDoesNotFakeATerminator()}.
     *
     * @return list<string>
     */
    private static function entries(string $block): array
    {
        self::assertStringEndsWith(
            WideString::NUL_UNIT . WideString::NUL_UNIT,
            $block,
            'an environment block must close with two NUL code units',
        );

        $out = [];
        $current = '';
        $units = \str_split($block, 2);
        $count = \count($units);

        for ($i = 0; $i < $count; $i++) {
            if ($units[$i] !== WideString::NUL_UNIT) {
                $current .= $units[$i];

                continue;
            }

            if ($current === '') {
                // Two terminators in a row: this one closed an empty entry, which
                // in this format means the block itself is done.
                return $out;
            }

            $out[] = WideString::toUtf8($current);
            $current = '';
        }

        self::fail('an environment block ended without its terminator: ' . \bin2hex($block));
    }

    public function testANameWithAZeroLowByteCharacterDoesNotFakeATerminator(): void
    {
        // U+0100 is `00 01` in UTF-16LE, so 'a' + U+0100 is `61 00 00 01` — a byte
        // scan for "\0\0" finds a terminator at offset 1 and the entry decodes as
        // garbage. Walking units instead, the block survives.
        $block = EnvBlock::encode(['aĀ' => 'x', 'B' => '2']);

        self::assertSame(['aĀ=x', 'B=2'], self::entries($block));
        self::assertSame(
            ['aĀ=x', 'B=2'],
            self::entries(EnvBlock::encode(['B' => '2', 'aĀ' => 'x'])),
            'the case-folded order must not depend on insertion order',
        );
    }
}
