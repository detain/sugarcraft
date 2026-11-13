<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Tape;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Tape\Compiler;
use SugarCraft\Vcr\Tape\Decompiler;

/**
 * Round-trip honesty (finding 007 / S07): a cassette decompiled to tape text
 * must re-compile to the SAME bytes, and where the decompiler cannot speak a
 * dialect the Lexer can hear, it refuses loudly instead of emitting a tape
 * whose payload would silently truncate (the pre-S06 `Type "a\"b"` class).
 */
final class DecompilerRoundTripTest extends TestCase
{
    /**
     * Payloads the Decompiler can express: printable runs, quotes in every
     * single/double/backtick combination except all three at once,
     * backslashes in every position, and UTF-8.
     *
     * @return array<string, array{0: string}>
     */
    public static function payloadProvider(): array
    {
        return [
            'plain'          => ['hello world'],
            'backslash'      => ['a\\b'],
            'trailing-bs'    => ['ends in \\'],
            'double-quote'   => ['say "hi"'],
            'single-quote'   => ["don't"],
            'backtick'       => ['run `ls`'],
            'bs-and-dquote'  => ['a\\b"c'],
            'bs-and-squote'  => ["a\\b'c"],
            'd-and-squote'   => ['a "b" \'c\''],
            'dquote-backtick' => ['"b" `c`'],
            'squote-backtick' => ["'b' `c`"],
            'quote-at-start' => ['"first'],
            'quote-at-end'   => ['last"'],
            'utf8'           => ['héllo ✓ 世界'],
            'utf8-bs-quote'  => ['é\\è"ê'],
            'all-printable'  => ['!@#$%^&*()_+-=[]{};\',./<>?~`'],
        ];
    }

    /**
     * @dataProvider payloadProvider
     */
    public function testPayloadRoundTripsByteIdentical(string $payload): void
    {
        $tape = (new Decompiler())->decompile($this->typeCassette($payload));
        $this->assertSame($payload, $this->typedBytes($tape), 'tape text: ' . trim($tape));
    }

    /**
     * @dataProvider payloadProvider
     */
    public function testTapeTextIsStableAcrossASecondRoundTrip(string $payload): void
    {
        $first = (new Decompiler())->decompile($this->typeCassette($payload));
        $second = (new Decompiler())->decompile($this->compileTape($first));
        $this->assertSame($first, $second, 'decompile→compile→decompile must be a fixed point');
    }

    public function testQuotePreferenceOrderIsFirstAbsentDelimiter(): void
    {
        $decompiler = new Decompiler();

        // no quotes present → the house double quote.
        $this->assertStringContainsString("Type \"plain\"\n", $decompiler->decompile($this->typeCassette('plain')));
        // double quote present → single wrap, backslashes doubled (none here).
        $this->assertStringContainsString("Type 'say \"hi\"'\n", $this->plainTapeFor('say "hi"'));
        // only apostrophe → stays double-wrapped (it is still absent from the payload).
        $this->assertStringContainsString("Type \"don't\"\n", $this->plainTapeFor("don't"));
    }

    public function testAllThreeQuoteDelimitersRefuseLoudly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('refusing to emit a tape the Lexer cannot read back');
        (new Decompiler())->decompile($this->typeCassette('mix " \' ` all'));
    }

    public function testEnvValueRoundTripsThroughAdaptiveQuotes(): void
    {
        $decompiler = new Decompiler();
        foreach (['plain', 'say "hi"', "don't", 'back\\slash', 'é"ü'] as $value) {
            $tape = $decompiler->decompile($this->envCassette('FOO', $value));
            $this->assertSame(['FOO' => $value], $this->compileTape($tape)->header->env, 'env tape: ' . trim($tape));
        }
    }

    public function testEnvValueCarryingBothQuoteKindsRefuses(): void
    {
        // The Env grammar class is `"`/`'` only — handing it a backtick wrap
        // would be a dialect the reader cannot speak, so the loud refusal is
        // the honest answer (finding 091's grammar stays strict).
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('refusing to emit a tape the Lexer cannot read back');
        (new Decompiler())->decompile($this->envCassette('FOO', 'both " and \''));
    }

    /**
     * Backslash-adjacent-quote payloads are the old truncation class: `\"`
     * inside a double wrap used to end the token early. The adaptive pick
     * sidesteps the pair entirely for these shapes.
     */
    public function testEscapedQuoteSequenceClassRoundTrips(): void
    {
        foreach (['a\\"b', '\\"', '"\\', 'echo \\"hello\\" world'] as $payload) {
            $tape = (new Decompiler())->decompile($this->typeCassette($payload));
            $this->assertSame($payload, $this->typedBytes($tape), 'tape: ' . trim($tape));
        }
    }

    private function plainTapeFor(string $payload): string
    {
        return (new Decompiler())->decompile($this->typeCassette($payload));
    }

    private function typeCassette(string $payload): Cassette
    {
        $event = new Event(0.0, EventKind::Input, ['b' => $payload]);

        return new Cassette($this->header(), [$event]);
    }

    private function envCassette(string $key, string $value): Cassette
    {
        return new Cassette($this->header(env: [$key => $value]), []);
    }

    private function header(array $env = []): CassetteHeader
    {
        return new CassetteHeader(
            version: CassetteHeader::CURRENT_VERSION,
            createdAt: '2026-05-22T00:00:00+00:00',
            cols: 80,
            rows: 24,
            runtime: 'test',
            env: $env,
        );
    }

    private function compileTape(string $tape): Cassette
    {
        return (new Compiler())->compile(Compiler::parseSource($tape)['ast'], '/roundtrip.tape');
    }

    private function typedBytes(string $tape): string
    {
        $bytes = '';
        foreach ($this->compileTape($tape)->events as $event) {
            if ($event->kind === EventKind::Input) {
                $bytes .= (string) $event->payload['b'];
            }
        }

        return $bytes;
    }
}
