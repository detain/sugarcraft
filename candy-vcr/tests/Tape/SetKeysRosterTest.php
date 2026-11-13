<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Tape;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Encode\TapeToGif;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Player;
use SugarCraft\Vcr\Render\FrameStream;
use SugarCraft\Vcr\Tape\Compiler;
use SugarCraft\Vcr\Tape\Lexer;
use SugarCraft\Vcr\Tape\Parser;
use SugarCraft\Vterm\Terminal;

/**
 * The Set-key allowlist widened to upstream's 21, enforce-or-warn
 * (finding 008): every upstream-legal `Set <key>` lexes, parses, and either
 * changes behaviour (Framerate → the render fps ladder) or is loudly booked
 * as "accepted, not enforced". Silence is the failure mode this pins out.
 */
final class SetKeysRosterTest extends TestCase
{
    /**
     * Upstream's 21 `Set` sub-keywords, transcribed as fixture data from the
     * measured contract roster (candy-code/tests/VhsTapeContractTest.php
     * KEYWORDS, the "Set sub-keywords" block — S05 law: the data comes here,
     * the candy-code target is never edited from this lane).
     */
    private const UPSTREAM_21 = [
        'BorderRadius', 'CursorBlink', 'FontFamily', 'FontSize', 'Framerate',
        'Height', 'LetterSpacing', 'LineHeight', 'LoopOffset', 'Margin',
        'MarginFill', 'Padding', 'PlaybackSpeed', 'Shell', 'Theme',
        'TypingSpeed', 'WaitPattern', 'WaitTimeout', 'Width', 'WindowBar',
        'WindowBarSize',
    ];

    public function testRosterIsExactlyUpstreamsTwentyOne(): void
    {
        $this->assertCount(21, self::UPSTREAM_21);

        $accepted = array_keys(
            (new ReflectionClass(Parser::class))->getConstant('SET_KEYS') ?? [],
        );
        sort($accepted);
        $expected = self::UPSTREAM_21;
        sort($expected);

        // Drift in either direction is a hard fail: a narrowing re-silences
        // legal tapes, an unmeasured widening invents keywords.
        $this->assertSame($expected, $accepted);
    }

    public function testEveryUpstreamSetKeyLexesAndParses(): void
    {
        $lexer = new Lexer();
        $parser = new Parser();

        foreach (self::UPSTREAM_21 as $key) {
            $source = "Set {$key} 10\n";
            $ast = $parser->parse($lexer->tokenize($source));
            $errors = array_filter($ast, static fn ($n) => $n instanceof \SugarCraft\Vcr\Tape\Ast\ParseError);
            $this->assertCount(0, $errors, "upstream-legal `Set {$key}` must not be a ParseError (finding 008)");
            $this->assertCount(1, $ast);
        }
    }

    public function testUnenforcedKeysWarnInsteadOfVanishing(): void
    {
        foreach (['LetterSpacing', 'LineHeight', 'LoopOffset', 'MarginFill', 'CursorBlink', 'BorderRadius', 'WindowBar', 'WindowBarSize', 'WaitPattern', 'WaitTimeout', 'Padding', 'Margin'] as $key) {
            $compiler = new Compiler();
            $ast = (new Parser())->parse((new Lexer())->tokenize("Set {$key} 2\n"));
            $compiler->compile($ast, '/set-keys.tape');

            $named = false;
            foreach ($compiler->warnings() as $warning) {
                if (str_contains($warning, "Set {$key}") && str_contains($warning, 'accepted, not enforced')) {
                    $named = true;
                }
            }
            $this->assertTrue($named, "`Set {$key}` must name its unenforced status, never compile silent");
        }
    }

    public function testUnenforcedKeysSurviveStrictMode(): void
    {
        // These are upstream-legal directives — the EFFECT is the delta, not
        // the syntax. Strict mode aborts on illegal tape, so it must ride
        // these through (the warning channel stays non-throwing here).
        $compiler = new Compiler();
        $ast = (new Parser())->parse((new Lexer())->tokenize("Set LetterSpacing 2\nSet WaitTimeout 5\nType \"x\"\n"));
        $cassette = $compiler->compile($ast, '/set-keys-strict.tape', true);

        $this->assertGreaterThanOrEqual(1, $cassette->eventCount());
        $this->assertCount(2, $compiler->warnings());
        $this->assertCount(0, $compiler->droppedDiagnostics(), 'an accepted setting loses no tape content');
    }

    public function testFramerateIsHonouredAndCarriedToTheRenderLadder(): void
    {
        $compiler = new Compiler();
        $ast = (new Parser())->parse((new Lexer())->tokenize("Set Framerate 10\nType \"x\"\n"));
        $compiler->compile($ast, '/framerate.tape');

        $this->assertSame(10.0, $compiler->framerate());
        $this->assertSame([], $compiler->warnings(), 'Framerate is enforced — it must not warn');

        $silent = new Compiler();
        $silent->compile((new Parser())->parse((new Lexer())->tokenize("Type \"x\"\n")), '/no-fps.tape');
        $this->assertNull($silent->framerate());
    }

    public function testGarbageFramerateNamesItselfKeepsDefaultDoor(): void
    {
        $compiler = new Compiler();
        $ast = (new Parser())->parse((new Lexer())->tokenize("Set Framerate fast\nType \"x\"\n"));
        $compiler->compile($ast, '/bad-fps.tape');

        $this->assertNull($compiler->framerate());
        $this->assertStringContainsString('Unparseable Framerate', $compiler->warnings()[0] ?? '');

        $strict = new Compiler();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unparseable Framerate');
        $strict->compile($ast, '/bad-fps-strict.tape', true);
    }

    public function testFpsPrecedenceLadder(): void
    {
        $this->assertSame(30.0, TapeToGif::resolveFps(null, null));
        $this->assertSame(10.0, TapeToGif::resolveFps(null, 10.0));
        $this->assertSame(12.0, TapeToGif::resolveFps(12.0, 10.0), 'an explicit CLI --fps keeps its seat over the tape');
        $this->assertSame(30.0, TapeToGif::resolveFps(null, NAN), 'a corrupt knob cannot smuggle NaN past the door');
        $this->assertSame(30.0, TapeToGif::resolveFps(null, -1.0));
    }

    public function testSetFramerateObservablyChangesTheFrameInterval(): void
    {
        // The acceptance, proven on the real cadence: the SAME one-second
        // cassette halfs its frame count when the fps comes from
        // `Set Framerate 5` instead of `Set Framerate 10`.
        $events = [];
        for ($i = 0; $i < 10; $i++) {
            $events[] = new Event((float) $i / 10.0, EventKind::Output, ['b' => "frame {$i}\n"]);
        }
        $cassette = new Cassette(new CassetteHeader(
            version: CassetteHeader::CURRENT_VERSION,
            createdAt: '2026-05-22T00:00:00+00:00',
            cols: 80,
            rows: 24,
            runtime: 'test',
        ), $events);

        $countAt = static function (float $fps) use ($cassette): int {
            $stream = new FrameStream(new Player($cassette), Terminal::new(80, 24), $fps);

            return count(iterator_to_array($stream->getIterator()));
        };

        $ten = $countAt(TapeToGif::resolveFps(null, 10.0));
        $five = $countAt(TapeToGif::resolveFps(null, 5.0));

        $this->assertGreaterThanOrEqual(10, $ten);
        $this->assertGreaterThanOrEqual(5, $five);
        $this->assertGreaterThan($five, $ten, 'a doubled framerate must not render the same frame budget');
        $this->assertGreaterThanOrEqual(2 * $five - 3, $ten, 'frame counts must scale with the interval, not drift');
    }
}
