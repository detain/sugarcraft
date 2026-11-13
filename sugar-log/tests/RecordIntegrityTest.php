<?php

declare(strict_types=1);

namespace SugarCraft\Log\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Log\Formatter\JsonFormatter;
use SugarCraft\Log\Formatter\LogfmtFormatter;
use SugarCraft\Log\Formatter\RecordKeys;
use SugarCraft\Log\Level;

/**
 * Record-boundary integrity pins (docs-review-1023 M3/M2):
 *
 * - logfmt values cannot close their quoted form early (backslash escaping)
 *   and cannot split a record across physical lines;
 * - context keys can never clobber the structural envelope (level/msg/time/
 *   caller/prefix) in either machine formatter — collisions are namespaced
 *   with the deterministic `ctx_` prefix.
 *
 * The forgery fixtures are assembled with chr(92) so the byte-level expected
 * output is stated in plain single-quoted literals without quote-mode soup.
 */
final class RecordIntegrityTest extends TestCase
{
    private const BS = "\\";

    private function logfmtLine(array $context): string
    {
        return (new LogfmtFormatter(false))
            ->format(Level::Info, 'test', $context, new \DateTimeImmutable('2026-01-01 00:00:00'), null, null);
    }

    /**
     * Minimal logfmt value parser honouring quoted forms and backslash
     * escapes — the decoder-side oracle proving the record boundary survives.
     *
     * @return array<string,string>
     */
    private static function parseLogfmt(string $line): array
    {
        $out = [];
        $line = \rtrim($line, "\n");
        $i = 0;
        $n = \strlen($line);
        while ($i < $n) {
            while ($i < $n && $line[$i] === ' ') {
                ++$i;
            }
            if ($i >= $n) {
                break;
            }
            $key = '';
            while ($i < $n && $line[$i] !== '=') {
                $key .= $line[$i];
                ++$i;
            }
            ++$i; // consume '='
            $value = '';
            if ($i < $n && $line[$i] === '"') {
                ++$i;
                while ($i < $n && $line[$i] !== '"') {
                    if ($line[$i] === self::BS && $i + 1 < $n) {
                        $next = $line[$i + 1];
                        $value .= match ($next) {
                            'n' => "\n",
                            'r' => "\r",
                            't' => "\t",
                            default => $next,
                        };
                        $i += 2;
                        continue;
                    }
                    $value .= $line[$i];
                    ++$i;
                }
                ++$i; // consume closing quote
            } else {
                while ($i < $n && $line[$i] !== ' ') {
                    $value .= $line[$i];
                    ++$i;
                }
            }
            $out[$key] = $value;
        }

        return $out;
    }

    public function testBackslashedQuoteCannotForgeATrailingField(): void
    {
        // Payload: evil \"y=forge — pre-fix this emitted as
        // x="evil \\"y=forge" which a real logfmt parser reads as value
        // `evil \` plus a FORGED field y=forge. Post-fix every data
        // backslash and quote is escaped, so the value round-trips whole.
        $forged = 'evil ' . self::BS . '"y=forge';
        $line = $this->logfmtLine(['x' => $forged]);

        $expected = 'level=INFO msg=test x="evil '
            . self::BS . self::BS . self::BS . '"'
            . 'y=forge"' . "\n";
        $this->assertSame($expected, $line);

        $parsed = self::parseLogfmt($line);
        $this->assertSame(['level' => 'INFO', 'msg' => 'test', 'x' => $forged], $parsed);
    }

    public function testEmbeddedNewlineCannotSplitTheRecord(): void
    {
        $line = $this->logfmtLine(['x' => "a\nb"]);

        // One physical line, newline rendered as a backslash sequence inside
        // a quoted value.
        $this->assertSame('level=INFO msg=test x="a' . self::BS . 'nb"' . "\n", $line);
        $this->assertSame(['level' => 'INFO', 'msg' => 'test', 'x' => "a\nb"], self::parseLogfmt($line));
    }

    public function testPlainValuesRemainUnquoted(): void
    {
        // Guard for the over-escaping direction: ordinary keys and values
        // must not gain quotes (logfmt consumers key on the raw token).
        $this->assertSame(
            'level=INFO msg=test user=alice n=42' . "\n",
            $this->logfmtLine(['user' => 'alice', 'n' => 42]),
        );
    }

    public function testContextCannotClobberTheJsonEnvelope(): void
    {
        $formatter = new JsonFormatter(false);
        $line = $formatter->format(
            Level::Info,
            'test',
            ['level' => 'FORGED', 'time' => '1999', 'ctx_level' => 'also', 'user' => 'alice'],
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            null,
            null,
        );

        $decoded = \json_decode(\trim($line), true);
        $this->assertIsArray($decoded);

        // The envelope survives untouched; collisions are namespaced.
        $this->assertSame('INFO', $decoded['level']);
        $this->assertSame('test', $decoded['msg']);
        $this->assertSame('FORGED', $decoded['ctx_level']);
        $this->assertSame('1999', $decoded['ctx_time']);
        $this->assertSame('also', $decoded['ctx_ctx_level']);
        $this->assertSame('alice', $decoded['user']);
        $this->assertArrayNotHasKey('time', $decoded);

        $keys = \array_keys($decoded);
        \sort($keys);
        $this->assertSame(['ctx_ctx_level', 'ctx_level', 'ctx_time', 'level', 'msg', 'user'], $keys);
    }

    public function testContextCannotClobberTheLogfmtEnvelope(): void
    {
        $line = $this->logfmtLine([
            'level' => 'FORGED',
            'time' => '1999',
            'ctx_level' => 'also',
            'user' => 'alice',
        ]);

        $this->assertSame(
            'level=INFO msg=test ctx_level=FORGED ctx_time=1999 ctx_ctx_level=also user=alice' . "\n",
            $line,
        );
        $this->assertSame('INFO', self::parseLogfmt($line)['level']);
    }

    public function testReservedEnvelopeNamesAreTheDocumentedList(): void
    {
        // Decoder contract: this exact set, documented in the README. Flipping
        // it is a record-shape change and must not happen silently.
        // [N1] '_encode_error' joined the list: it is a name the defensive
        // encode fallback writes, so it must be forge-proof like the rest.
        $this->assertSame(['level', 'msg', 'time', 'caller', 'prefix', '_encode_error'], RecordKeys::RESERVED);
        $this->assertSame('ctx_', RecordKeys::PREFIX);
    }

    public function testClaimRegistersKeysSoLaterCollisionsShiftAgain(): void
    {
        $used = [];
        $this->assertSame('user', RecordKeys::claim('user', $used));
        $this->assertSame('ctx_user', RecordKeys::claim('user', $used));
        $this->assertSame('ctx_ctx_user', RecordKeys::claim('ctx_user', $used));
        $this->assertSame(['user' => true, 'ctx_user' => true, 'ctx_ctx_user' => true], $used);
    }

    public function testEmptyContextKeyIsNamespacedLikeAReservedName(): void
    {
        // An empty key collides with no name but still addresses nothing:
        // claim() namespaces it through the same walk, and a real `ctx_`
        // entry queued behind it must shift again, not steal the slot.
        $used = [];
        $this->assertSame('ctx_', RecordKeys::claim('', $used));
        $this->assertSame('ctx_ctx_', RecordKeys::claim('', $used));
        $this->assertSame('ctx_ctx_ctx_', RecordKeys::claim('ctx_', $used));

        $this->assertSame(
            'level=INFO msg=test ctx_=value ctx_ctx_=later' . "\n",
            $this->logfmtLine(['' => 'value', 'ctx_' => 'later']),
        );
        $this->assertSame('value', self::parseLogfmt($this->logfmtLine(['' => 'value']))['ctx_']);

        $decoded = \json_decode(\trim((new JsonFormatter(false))->format(
            Level::Info,
            'test',
            ['' => 'value', 'ctx_' => 'later'],
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            null,
            null,
        )), true);
        $this->assertIsArray($decoded);
        $this->assertSame('value', $decoded['ctx_']);
        $this->assertSame('later', $decoded['ctx_ctx_']);
        $this->assertArrayNotHasKey('', $decoded);
    }

    public function testLineBoundaryCodepointsForceQuoting(): void
    {
        // [m13] U+2028/U+2029 are line boundaries to splitlines()/JS readers
        // although they are not ASCII whitespace: a value holding one must be
        // quoted so a downstream line-based parser still sees one record.
        $sep = "\u{2028}";
        $line = $this->logfmtLine(['sep' => "a{$sep}b"]);

        $this->assertStringContainsString("\"a{$sep}b\"", $line);
        $this->assertSame(1, substr_count($line, "\n"));

        $ls = "\u{2029}";
        $keyed = $this->logfmtLine(["k{$ls}v" => 'w']);
        $this->assertStringContainsString("\"k{$ls}v\"=w", $keyed);
    }

    // [m3] logfmt side of the timeFormat knob.
    public function testLogfmtTimeFormatHonoursOverrideAndKeepsAtomDefault(): void
    {
        $time = new \DateTimeImmutable('2026-01-01 14:33:05', new \DateTimeZone('UTC'));

        $default = (new LogfmtFormatter())->format(Level::Info, 'm', [], $time, null, null);
        $this->assertStringContainsString('time=2026-01-01T14:33:05+00:00', $default);

        $custom = (new LogfmtFormatter(true, 'H:i:s'))->format(Level::Info, 'm', [], $time, null, null);
        $this->assertStringContainsString('time=14:33:05', $custom);
        $this->assertStringNotContainsString('+00:00', $custom);
    }

    /**
     * [C-01] Split-spellings of every reserved envelope name. A context key
     * holding a control byte that Sanitize::untrusted() strips could pass the
     * reserved-name test raw, then collapse to the bare reserved name at
     * emission — forging a second `level=` (last-wins parsers read the forged
     * severity as real). The key must be sanitized BEFORE RecordKeys::claim
     * (JsonFormatter's order) so it is namespaced like the name it collapses
     * to. Each row: hostile key => the ctx_ name it must land under.
     *
     * @return array<string,array{string,string}>
     */
    public static function reservedNameSplittings(): array
    {
        return [
            'level' => ["lev\x0bel", 'ctx_level'],
            'msg' => ["ms\x0bg", 'ctx_msg'],
            'time' => ["\x0ctime", 'ctx_time'],
            'caller' => ["call\x0ber", 'ctx_caller'],
            'prefix' => ["pref\x0eix", 'ctx_prefix'],
            '_encode_error' => ["_encode\x0b_error", 'ctx__encode_error'],
        ];
    }

    /**
     * @dataProvider reservedNameSplittings
     */
    public function testSplitSpelledReservedKeysNeverEmitABareEnvelopeToken(string $hostileKey, string $namespaced): void
    {
        $parsed = self::parseLogfmt($this->logfmtLine([$hostileKey => 'FORGED']));

        // The record carries exactly the real envelope plus the namespaced
        // entry — no bare reserved token is ever written twice.
        $this->assertSame(['level' => 'INFO', 'msg' => 'test', $namespaced => 'FORGED'], $parsed);
    }

    public function testTwoHostileSplittingsThatCollapseToTheSameKeyStayApart(): void
    {
        // "lev\x0bel" and "level" sanitize to the same name; the collision
        // walk must namespace them apart, in order, not let the first slip
        // out as a bare forged field.
        $parsed = self::parseLogfmt($this->logfmtLine(["lev\x0bel" => 'A', 'level' => 'B']));

        $this->assertSame(
            ['level' => 'INFO', 'msg' => 'test', 'ctx_level' => 'A', 'ctx_ctx_level' => 'B'],
            $parsed,
        );
    }
}
