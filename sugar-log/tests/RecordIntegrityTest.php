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
        $this->assertSame(['level', 'msg', 'time', 'caller', 'prefix'], RecordKeys::RESERVED);
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
}
