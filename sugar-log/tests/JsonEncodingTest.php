<?php

declare(strict_types=1);

namespace SugarCraft\Log\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Log\Formatter\JsonFormatter;
use SugarCraft\Log\Formatter\ValueCoercion;
use SugarCraft\Log\Level;

/**
 * JSON trust-boundary pins (review wave C-cluster "encode never destroys,
 * never smuggles"):
 *
 *  - [M1] hostile bytes must not collapse the record: invalid UTF-8 rides
 *    JSON_INVALID_UTF8_SUBSTITUTE (U+FFFD, bytes ef bf bd) and the FULL
 *    envelope survives — the pre-fix fallback emitted a bare newline for
 *    exactly these fixtures because the minimal retry re-tripped the same
 *    invalid bytes;
 *  - [M12] C1 control bytes (json_encode passes them through verbatim) are
 *    swept at ingress so a cat of the log file cannot speak to the terminal;
 *  - [M5] reference-cycle context terminates at the depth cap instead of
 *    overflowing the stack (pre-fix this SEGFAULTed the host, RC=139);
 *  - [m14] a throwing __toString() is contained;
 *  - [m16] anonymous class names (path + NUL byte) never leak into records;
 *  - non-finite floats encode as 'INF'/'-INF'/'NAN' instead of failing the
 *    whole encode;
 *  - [N1] '_encode_error' is reserved, so the defensive note cannot be
 *    pre-empted by a context entry.
 *
 * Expected byte sequences are stated via bin2hex to stay out of quote-mode
 * soup, per the house RecordIntegrityTest convention.
 */
final class JsonEncodingTest extends TestCase
{
    private function format(string $message, array $context = [], ?string $prefix = null): string
    {
        return (new JsonFormatter())
            ->format(
                Level::Error,
                $message,
                $context,
                new \DateTimeImmutable('2026-01-01 00:00:00'),
                null,
                $prefix,
            );
    }

    public function testInvalidUtf8SubstitutesAndKeepsTheWholeRecord(): void
    {
        $line = $this->format('paid' . "\xff" . 'tail', ['ok' => 1]);

        // One physical line, valid JSON, substitution bytes present.
        $this->assertStringNotContainsString("\n", rtrim($line, "\n"));
        $decoded = \json_decode(\rtrim($line, "\n"), true);
        $this->assertIsArray($decoded, 'hostile byte must not cost the whole record');

        $hex = \bin2hex($line);
        $this->assertStringContainsString('efbfbd', $hex, 'invalid byte must land as U+FFFD');
        $this->assertSame('paid' . "\u{FFFD}" . 'tail', $decoded['msg']);
        $this->assertSame('ERROR', $decoded['level']);
        $this->assertArrayHasKey('time', $decoded, 'envelope survives the defensive path');
        $this->assertSame(1, $decoded['ok']);
    }

    public function testC1ControlBytesAreNeverEmittedRaw(): void
    {
        // U+009B (8-bit CSI) encodes as c2 9b; json_encode passes it through
        // unescaped, so the sweep must happen before encoding.
        $line = $this->format('pre' . "\u{009B}" . 'post', ['k' => "x\u{0085}y"]);

        $this->assertStringNotContainsString("\xc2\x9b", $line);
        $this->assertStringNotContainsString("\xc2\x85", $line);
        $decoded = \json_decode(\rtrim($line, "\n"), true);
        $this->assertSame('prepost', $decoded['msg']);
        $this->assertSame('xy', $decoded['k']);
    }

    public function testReferenceCycleContextTerminatesAtTheDepthCap(): void
    {
        $context = ['a' => 1];
        $context['loop'] = &$context; // self-referential by reference

        $line = $this->format('cycle', $context);

        $decoded = \json_decode(\rtrim($line, "\n"), true);
        $this->assertIsArray($decoded);
        $this->assertSame(1, $decoded['a']);
        // The cycle unrolls one level per hop and the cap closes it with a
        // string sentinel — depth 5 nested array => '[...]'.
        $walk = $decoded['loop'];
        for ($i = 0; $i < 4; $i++) {
            $this->assertIsArray($walk);
            $walk = $walk['loop'];
        }
        $this->assertSame('[...]', $walk);
    }

    public function testDeepNestingClosesWithSentinelNotStack(): void
    {
        $this->assertSame(
            [1, [2, [3, [4, '[...]']]]],
            ValueCoercion::coerce([1, [2, [3, [4, [5]]]]]),
        );
    }

    public function testThrowingStringCastIsContained(): void
    {
        $hostile = new class implements \Stringable {
            public function __toString(): string
            {
                throw new \RuntimeException('cast exploded');
            }
        };

        $this->assertSame('object', ValueCoercion::coerce($hostile));

        $line = $this->format('around a hostile cast', ['v' => $hostile]);
        $decoded = \json_decode(\rtrim($line, "\n"), true);
        $this->assertSame('object', $decoded['v']);
    }

    public function testAnonymousClassNameNeverLeaks(): void
    {
        $anon = new class {};
        $coerced = ValueCoercion::coerce($anon);

        $this->assertSame('object', $coerced);
        $this->assertStringNotContainsString('anonymous', (string) $coerced);
        $this->assertStringNotContainsString("\x00", (string) $coerced);
    }

    public function testNonFiniteFloatsEncodeAsNamedStrings(): void
    {
        $line = $this->format('numbers', ['inf' => \INF, 'ninf' => -\INF, 'nan' => \NAN]);

        // Byte truth: the wire carries QUOTED strings, not json_encode's bare
        // INF dialect — strictly valid JSON survives every reader.
        $this->assertStringContainsString('"inf":"INF"', $line);
        $this->assertStringContainsString('"ninf":"-INF"', $line);
        $this->assertStringContainsString('"nan":"NAN"', $line);

        $decoded = \json_decode(\rtrim($line, "\n"), true);
        $this->assertIsArray($decoded, 'INF/NAN must not cost the record');
        $this->assertSame('INF', $decoded['inf']);
        $this->assertSame('-INF', $decoded['ninf']);
        $this->assertSame('NAN', $decoded['nan']);
        $this->assertArrayHasKey('time', $decoded);
    }

    public function testEncodeErrorKeyIsReservedAgainstContext(): void
    {
        $line = $this->format('forge attempt', ['_encode_error' => 'all clear']);

        $decoded = \json_decode(\rtrim($line, "\n"), true);
        $this->assertArrayNotHasKey('_encode_error', $decoded);
        $this->assertSame('all clear', $decoded['ctx__encode_error']);
    }

    public function testContextKeysAreSanitizedBeforeClaiming(): void
    {
        $line = $this->format('key sweep', ["bad\x01key" => 'v', "c2\u{009b}" => 'w']);

        $this->assertStringNotContainsString("\x01", $line);
        $this->assertStringNotContainsString("\xc2\x9b", $line);
        $decoded = \json_decode(\rtrim($line, "\n"), true);
        $this->assertSame('v', $decoded['badkey']);
        $this->assertSame('w', $decoded['c2']);
    }

    public function testPrefixAndMessageShareTheSweep(): void
    {
        $line = $this->format("msg\u{009B}", [], "pre\u{009b}fix");

        $this->assertStringNotContainsString("\xc2\x9b", $line);
        $decoded = \json_decode(\rtrim($line, "\n"), true);
        $this->assertSame('msg', $decoded['msg']);
        $this->assertSame('prefix', $decoded['prefix']);
    }

    // [m3] timeFormat knob: null keeps the ATOM machine default; an explicit
    // format lands verbatim (quoting stays JSON-safe even with spaces).
    public function testTimeFormatDefaultsToAtomAndHonoursOverride(): void
    {
        $time = new \DateTimeImmutable('2026-01-01 14:33:05', new \DateTimeZone('UTC'));

        $default = \json_decode((new JsonFormatter())->format(Level::Info, 'm', [], $time, null, null), true);
        $this->assertSame('2026-01-01T14:33:05+00:00', $default['time']);

        $custom = \json_decode((new JsonFormatter(true, 'Y/m/d H:i'))->format(Level::Info, 'm', [], $time, null, null), true);
        $this->assertSame('2026/01/01 14:33', $custom['time']);
    }
}
