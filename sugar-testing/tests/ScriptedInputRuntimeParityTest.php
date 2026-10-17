<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\InputReader;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\PasteEndMsg;
use SugarCraft\Core\Msg\PasteMsg;
use SugarCraft\Core\Msg\PasteStartMsg;
use SugarCraft\Testing\Input\ScriptedInput;
use SugarCraft\Testing\ProgramSimulator;

/**
 * ScriptedInput's terminal-shaped input must produce exactly what the live
 * runtime's {@see InputReader} produces — a paste that passes a simulated
 * test has to pass on a real terminal too.
 */
final class ScriptedInputRuntimeParityTest extends TestCase
{
    public function testPasteSanitizesEmbeddedEscapesLikeTheRuntime(): void
    {
        $content = "hi\x1b[31mX\x1b]52;c;ZXZpbA==\x07Y";

        $messages = ScriptedInput::new()->paste($content)->build();

        $this->assertEquals(
            (new InputReader())->parse(ScriptedInput::PASTE_START . $content . ScriptedInput::PASTE_END),
            $messages,
        );
        $this->assertStringNotContainsString("\x1b", $this->pasted($messages));
    }

    public function testPasteNeutralizesZoneSentinels(): void
    {
        $messages = ScriptedInput::new()->paste("a\u{E000}b\u{E001}c")->build();

        $this->assertStringNotContainsString("\u{E000}", $this->pasted($messages));
        $this->assertStringNotContainsString("\u{E001}", $this->pasted($messages));
    }

    public function testWithSanitizePasteFalseDeliversPayloadVerbatim(): void
    {
        $content = "hi\x1b[31mX";

        $messages = ScriptedInput::new()->withSanitizePaste(false)->paste($content)->build();

        $this->assertSame($content, $this->pasted($messages));
    }

    public function testOversizedPasteArrivesInChunksThatReassembleExactly(): void
    {
        $content = str_repeat("0123456789abcdef", (InputReader::MAX_PASTE_BYTES * 2 + 4096) / 16);

        $messages = ScriptedInput::new()->paste($content)->build();

        $this->assertInstanceOf(PasteStartMsg::class, $messages[0]);
        $end = $this->indexOf($messages, PasteEndMsg::class);
        $this->assertNotNull($end);
        $chunksBeforeEnd = array_filter(
            array_slice($messages, 1, $end - 1),
            static fn (Msg $m): bool => $m instanceof PasteMsg,
        );
        $this->assertNotEmpty($chunksBeforeEnd, 'a paste past MAX_PASTE_BYTES surfaces chunks before PasteEndMsg');
        $this->assertInstanceOf(PasteMsg::class, $messages[array_key_last($messages)]);
        $this->assertSame($content, $this->pasted($messages));
    }

    public function testModelThatReplacesOnEachPasteMsgLosesChunksAsInTheRuntime(): void
    {
        // A model treating every PasteMsg as the whole paste is correct for a
        // small paste and wrong for a chunked one; the simulator must expose it.
        $content = str_repeat('x', InputReader::MAX_PASTE_BYTES + 8192);
        $sim = ProgramSimulator::for(new RecordingModel());
        foreach (ScriptedInput::new()->paste($content)->build() as $msg) {
            $sim = $sim->send($msg);
        }

        $trace = $sim->run()->view;

        $this->assertStringStartsWith('[(', $trace);
        $this->assertSame(2, substr_count($trace, '('), 'one chunk inside the envelope, the remainder after PasteEndMsg');
    }

    public function testEndMarkerInsideContentClosesThePasteEarly(): void
    {
        $messages = ScriptedInput::new()->paste('ab' . ScriptedInput::PASTE_END . 'q')->build();

        $this->assertInstanceOf(PasteStartMsg::class, $messages[0]);
        $this->assertInstanceOf(PasteEndMsg::class, $messages[1]);
        $this->assertSame('ab', $messages[2]->content);
        $this->assertInstanceOf(KeyMsg::class, $messages[3]);
        $this->assertSame('q', $messages[3]->rune, 'text after an embedded end marker is typed, as on a terminal');
    }

    public function testBytesClosesAPasteWhoseEndMarkerStraddlesTwoReads(): void
    {
        $messages = ScriptedInput::new()
            ->bytes("\x1b[200~split\x1b[20", "1~k")
            ->build();

        $this->assertCount(4, $messages);
        $this->assertInstanceOf(PasteStartMsg::class, $messages[0]);
        $this->assertInstanceOf(PasteEndMsg::class, $messages[1]);
        $this->assertSame('split', $messages[2]->content);
        $this->assertSame('k', $messages[3]->rune);
    }

    public function testBytesClosesAStalePasteAndHandsTheKeyboardBack(): void
    {
        $messages = ScriptedInput::new()
            ->bytes("\x1b[200~lost marker\x1b[2")
            ->bytes('z')
            ->build();

        $this->assertInstanceOf(PasteStartMsg::class, $messages[0]);
        $this->assertInstanceOf(PasteEndMsg::class, $messages[1]);
        $this->assertSame("lost marker", $messages[2]->content, 'the held-back marker prefix is pasted text once the marker is gone');
        $this->assertInstanceOf(KeyMsg::class, $messages[3]);
        $this->assertSame('z', $messages[3]->rune);
        $this->assertCount(4, $messages);
    }

    public function testBytesPromotesALoneEscapeAfterSilence(): void
    {
        $messages = ScriptedInput::new()->bytes("\x1b")->build();

        $this->assertCount(1, $messages);
        $this->assertSame(KeyType::Escape, $messages[0]->type);
    }

    public function testBytesDecodesKeysThroughTheRealReader(): void
    {
        $messages = ScriptedInput::new()->bytes("a\x1b[A\r")->build();

        $this->assertEquals((new InputReader())->parse("a\x1b[A\r"), $messages);
        $this->assertSame(KeyType::Up, $messages[1]->type);
    }

    public function testIncompleteSequenceCarriesIntoTheNextCall(): void
    {
        $messages = ScriptedInput::new()->bytes("\x1b[")->bytes('A')->build();

        $this->assertCount(1, $messages);
        $this->assertSame(KeyType::Up, $messages[0]->type);
    }

    public function testBranchesFromASharedPrefixStayIndependent(): void
    {
        $base = ScriptedInput::new()->bytes("\x1b[");

        $up = $base->bytes('A')->build();
        $down = $base->bytes('B')->build();

        $this->assertSame(0, $base->count());
        $this->assertSame(KeyType::Up, $up[0]->type);
        $this->assertSame(KeyType::Down, $down[0]->type);
    }

    public function testPushAndTicksKeepParserState(): void
    {
        $messages = ScriptedInput::new()->bytes("\x1b[")->ticks(1)->key('k')->bytes('A')->build();

        $this->assertCount(3, $messages);
        $this->assertSame(KeyType::Up, $messages[2]->type);
    }

    /**
     * @param list<Msg> $messages
     */
    private function pasted(array $messages): string
    {
        $out = '';
        foreach ($messages as $m) {
            if ($m instanceof PasteMsg) {
                $out .= $m->content;
            }
        }
        return $out;
    }

    /**
     * @param list<Msg>    $messages
     * @param class-string $class
     */
    private function indexOf(array $messages, string $class): ?int
    {
        foreach ($messages as $i => $m) {
            if ($m instanceof $class) {
                return $i;
            }
        }
        return null;
    }
}
