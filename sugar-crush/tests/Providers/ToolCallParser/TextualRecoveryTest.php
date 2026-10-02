<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers\ToolCallParser;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Providers\ToolCallParser\TextualRecovery;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * Audit 15a A8: cutting recovered envelopes out of the assistant's text.
 */
final class TextualRecoveryTest extends TestCase
{
    private const ENVELOPE = '<e><invoke name="read"/></e>';

    /**
     * @return array{0: string, 1: list<array{0: int, 1: int}>}
     */
    private static function contentWith(string $before, string $after): array
    {
        $start = \strlen($before);

        return [$before . self::ENVELOPE . $after, [[$start, $start + \strlen(self::ENVELOPE)]]];
    }

    public function testAccessorsReturnWhatWasRecovered(): void
    {
        $call = new ToolCall('c0', 'read', []);
        $recovery = TextualRecovery::new([$call], [[3, 9]]);

        $this->assertSame([$call], $recovery->calls());
        $this->assertSame([[3, 9]], $recovery->spans());
        $this->assertNull(TextualRecovery::new(null)->calls());
        $this->assertSame([], TextualRecovery::new(null)->spans());
    }

    public function testWithoutSpansTheContentIsReturnedUnchanged(): void
    {
        $recovery = TextualRecovery::new(null);

        $this->assertSame("prose\n\n", $recovery->contentWithoutEnvelopes("prose\n\n"));
        $this->assertSame("se\n\n", $recovery->contentWithoutEnvelopes("prose\n\n", 3), 'from still applies');
    }

    public function testTheEnvelopeGoesWithTheBlankLineInFrontOfIt(): void
    {
        [$content, $spans] = self::contentWith("Let me read it.\n\n", '');

        $this->assertSame('Let me read it.', TextualRecovery::new([], $spans)->contentWithoutEnvelopes($content));
    }

    public function testProseAfterTheEnvelopeIsKept(): void
    {
        [$content, $spans] = self::contentWith("I'll read.\n\n", "\n\nThen I'll summarise.");

        $this->assertSame(
            "I'll read.\n\nThen I'll summarise.",
            TextualRecovery::new([], $spans)->contentWithoutEnvelopes($content),
        );
    }

    public function testATrailingBlankLineAfterTheLastEnvelopeGoesToo(): void
    {
        [$content, $spans] = self::contentWith('Reading.', "\n\n");

        $this->assertSame('Reading.', TextualRecovery::new([], $spans)->contentWithoutEnvelopes($content));
    }

    public function testEveryEnvelopeIsCut(): void
    {
        $first = "A.\n\n" . self::ENVELOPE;
        $content = $first . "\nB.\n\n" . self::ENVELOPE;
        $secondStart = \strlen($first . "\nB.\n\n");
        $spans = [
            [4, \strlen($first)],
            [$secondStart, $secondStart + \strlen(self::ENVELOPE)],
        ];

        $this->assertSame("A.\nB.", TextualRecovery::new([], $spans)->contentWithoutEnvelopes($content));
    }

    /**
     * The streaming case: bytes before `$from` were already painted, so the
     * result starts at `$from` and nothing before it is reached for - not
     * even the whitespace in front of the envelope.
     */
    public function testNothingBeforeFromIsTouched(): void
    {
        [$content, $spans] = self::contentWith("Let me read it.\n\n", '');
        $from = \strlen("Let me read it.\n");
        $recovery = TextualRecovery::new([], $spans);

        // One of the two newlines was already painted; only the held one and
        // the envelope are cut.
        $this->assertSame('', $recovery->contentWithoutEnvelopes($content, $from));
        $this->assertSame('', $recovery->contentWithoutEnvelopes($content, \strlen($content)));
    }

    public function testASpanEndingBeforeFromIsIgnoredAndTheRestIsKeptVerbatim(): void
    {
        [$content, $spans] = self::contentWith('', "\n\nlater\n");
        $from = \strlen(self::ENVELOPE) + 2;

        $this->assertSame("later\n", TextualRecovery::new([], $spans)->contentWithoutEnvelopes($content, $from));
    }
}
