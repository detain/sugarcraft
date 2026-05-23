<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Tape;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Tape\Compiler;
use SugarCraft\Vcr\Tape\Decompiler;

/**
 * vcr_use.md §2: "parse → compile → decompile → re-parse should be stable
 * for canonical inputs."
 *
 * For each canonical source string we run the full pipeline twice:
 *   Lexer → Parser → Compiler → Cassette → Decompiler → newSource
 *   → Lexer → Parser → Compiler → Cassette2
 * Then assert `Cassette::events` matches event-for-event (timestamp, kind,
 * payload).
 */
final class RoundTripTest extends TestCase
{
    public function testTypeHelloRoundTrips(): void
    {
        $this->assertRoundTripsCleanly('Type "hello"');
    }

    public function testEnterRoundTrips(): void
    {
        $this->assertRoundTripsCleanly("Enter");
    }

    public function testSleep1sRoundTrips(): void
    {
        $this->assertRoundTripsCleanly("Type \"a\"\nSleep 1s\nType \"b\"");
    }

    public function testSetThemeTokyoNightRoundTrips(): void
    {
        $this->assertRoundTripsCleanly("Set Theme \"TokyoNight\"\nType \"x\"");
    }

    public function testSetThemeDraculaRoundTrips(): void
    {
        $this->assertRoundTripsCleanly("Set Theme \"Dracula\"\nType \"x\"");
    }

    public function testCtrlCRoundTrips(): void
    {
        $this->assertRoundTripsCleanly("Ctrl+C");
    }

    public function testArrowsRoundTrip(): void
    {
        $this->assertRoundTripsCleanly("Up\nDown\nLeft\nRight");
    }

    public function testBackspaceRoundTrips(): void
    {
        $this->assertRoundTripsCleanly("Backspace");
    }

    public function testTabRoundTrips(): void
    {
        $this->assertRoundTripsCleanly("Tab");
    }

    public function testEnvRoundTrips(): void
    {
        $this->assertRoundTripsCleanly("Env FOO \"bar\"\nType \"x\"");
    }

    public function testCombinedTapeRoundTrips(): void
    {
        $source = <<<TAPE
Set Theme "Dracula"
Set Width 100
Set Height 30
Env CANDY_VCR_DEMO "1"
Type "echo hi"
Enter
Sleep 500ms
Up
Down
Ctrl+L
Backspace
Tab
Type "done"
Enter
TAPE;
        $this->assertRoundTripsCleanly($source);
    }

    public function testDecompiledOutputReParsesIdempotently(): void
    {
        $source = "Type \"hello\"\nEnter\nSleep 1s\nCtrl+C";
        $first = $this->compile($source);
        $intermediate = (new Decompiler())->decompile($first);
        $second = $this->compile($intermediate);
        $intermediate2 = (new Decompiler())->decompile($second);

        $this->assertSame(
            $intermediate,
            $intermediate2,
            'Second decompile pass should be identical to the first (fixpoint reached).',
        );
    }

    public function testDecompilerEmitsTrailingNewline(): void
    {
        $cassette = $this->compile('Type "hi"');
        $output = (new Decompiler())->decompile($cassette);
        $this->assertStringEndsWith("\n", $output);
    }

    public function testEmptyEventsWithNonDefaultHeaderRoundTrips(): void
    {
        $source = "Set Theme \"Dracula\"\nSet Width 132";
        $first = $this->compile($source);
        $this->assertSame(0, $first->eventCount());
        $intermediate = (new Decompiler())->decompile($first);

        $this->assertStringContainsString('Set Theme "Dracula"', $intermediate);
        $this->assertStringContainsString('Set Width 132', $intermediate);

        $second = $this->compile($intermediate);
        $this->assertSame('Dracula', $second->header->theme);
        $this->assertSame(132, $second->header->cols);
    }

    public function testSpaceBetweenWordsFoldsIntoTypeGroup(): void
    {
        $source = 'Type "hello world"';
        $cassette = $this->compile($source);
        $intermediate = (new Decompiler())->decompile($cassette);

        $this->assertStringContainsString('Type "hello world"', $intermediate);
    }

    public function testEscapeRoundTrips(): void
    {
        $this->assertRoundTripsCleanly("Escape");
    }

    /**
     * The Lexer doesn't currently parse backslash-escaped quotes inside
     * `Type "..."` — the non-greedy regex stops at the first inner quote.
     * Decompiler still escapes quotes/backslashes defensively so future
     * Lexer support is forwards-compatible. This test pins the escaping
     * choice rather than the (unsupported) lexer round-trip.
     */
    public function testDecompilerEscapesQuotesAndBackslashes(): void
    {
        $bytes = 'a"b\\c';
        $event = new \SugarCraft\Vcr\Event(0.0, \SugarCraft\Vcr\EventKind::Input, ['b' => $bytes]);
        $header = new \SugarCraft\Vcr\CassetteHeader(
            version: \SugarCraft\Vcr\CassetteHeader::CURRENT_VERSION,
            createdAt: '2026-05-22T00:00:00+00:00',
            cols: 80,
            rows: 24,
            runtime: 'test',
        );
        $cassette = new Cassette($header, [$event]);
        $output = (new Decompiler())->decompile($cassette);

        $this->assertStringContainsString('Type "a\\"b\\\\c"', $output);
    }

    /**
     * Drives the full round-trip pipeline and asserts both Cassettes have the
     * same event stream.
     */
    private function assertRoundTripsCleanly(string $source): void
    {
        $first = $this->compile($source);
        $intermediate = (new Decompiler())->decompile($first);
        $second = $this->compile($intermediate);

        $this->assertEventsEqual(
            $first,
            $second,
            "Round-trip failed for source:\n{$source}\n\nDecompiled to:\n{$intermediate}",
        );
    }

    private function compile(string $source): Cassette
    {
        $result = Compiler::parseSource($source);
        $this->assertSame([], $result['errors'], 'Source had parse errors: ' . $source);
        return (new Compiler())->compile($result['ast'], '/test.tape');
    }

    private function assertEventsEqual(Cassette $a, Cassette $b, string $message = ''): void
    {
        $this->assertSame(
            $a->eventCount(),
            $b->eventCount(),
            $message !== '' ? $message : 'event count mismatch',
        );

        foreach ($a->events as $i => $eventA) {
            $eventB = $b->events[$i];
            $this->assertSame(
                $eventA->kind,
                $eventB->kind,
                "kind mismatch at event {$i}" . ($message !== '' ? "\n{$message}" : ''),
            );
            $this->assertEqualsWithDelta(
                $eventA->t,
                $eventB->t,
                1e-6,
                "timestamp mismatch at event {$i}",
            );
            $this->assertSame(
                $eventA->payload,
                $eventB->payload,
                "payload mismatch at event {$i}",
            );
        }
    }
}
