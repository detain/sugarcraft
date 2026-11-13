<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Tape;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Tape\Compiler;
use SugarCraft\Vcr\Tape\Lexer;
use SugarCraft\Vcr\Tape\Parser;

/**
 * The drift gate for finding 005: upstream vhs v0.11.0 ships a MEASURED
 * 60-keyword token roster, extracted from `token/token.go`'s Keywords map
 * by the candy-code oracle sweep and pinned at
 * `candy-code/tests/VhsTapeContractTest.php:427-446`. That file's own
 * lesson — hand-written lists drift; a byte-exact fixture catches it — is
 * honored here: the roster is copied as data (NO cross-target dependency;
 * candy-code is a sibling app and this lib's suite must not reach into
 * it), and every command keyword must classify through candy-vcr's lexer
 * into a node the strict gate can see. A new upstream keyword added to the
 * fixture without a lexer arm goes red here.
 */
final class KeywordRosterParityTest extends TestCase
{
    /**
     * The 32 command keywords — verbatim copy of the oracle's first group.
     *
     * @var list<string>
     */
    private const COMMANDS = [
        'Alt', 'Backspace', 'Copy', 'Ctrl', 'Delete', 'Down', 'End', 'Enter',
        'Env', 'Escape', 'Hide', 'Insert', 'Left', 'Output', 'PageDown',
        'PageUp', 'Paste', 'Require', 'Right', 'Screenshot', 'ScrollDown',
        'ScrollUp', 'Set', 'Shift', 'Show', 'Sleep', 'Source', 'Space', 'Tab',
        'Type', 'Up', 'Wait',
    ];

    /**
     * The 21 `Set` sub-keywords — verbatim copy of the oracle's second
     * group. Candy-vcr accepts the whole group at the LEXER (they ride the
     * `Set <key> <value>` arm); the Parser's own SET_KEYS allow-list is a
     * separate, already-loud gate.
     *
     * @var list<string>
     */
    private const SET_SUBKEYWORDS = [
        'BorderRadius', 'CursorBlink', 'FontFamily', 'FontSize', 'Framerate',
        'Height', 'LetterSpacing', 'LineHeight', 'LoopOffset', 'Margin',
        'MarginFill', 'Padding', 'PlaybackSpeed', 'Shell', 'Theme',
        'TypingSpeed', 'WaitPattern', 'WaitTimeout', 'Width', 'WindowBar',
        'WindowBarSize',
    ];

    /**
     * The seven lowercase keywords (five unit suffixes, two booleans) —
     * verbatim copy of the oracle's third group. They are token-map
     * entries, never line-initial directives; here for the count pin only.
     *
     * @var list<string>
     */
    private const LOWERCASE = ['em', 'px', 'ms', 's', 'm', 'true', 'false'];

    /**
     * Representative one-line tape per command keyword — the spellings
     * candy-vcr's lexer must classify (argument-taking commands carry
     * their measured argument forms; the rest are bare words).
     *
     * @var array<string, string>
     */
    private const COMMAND_PROBES = [
        'Alt' => 'Alt',
        'Backspace' => 'Backspace',
        'Copy' => 'Copy "x"',
        'Ctrl' => 'Ctrl+A',
        'Delete' => 'Delete',
        'Down' => 'Down',
        'End' => 'End',
        'Enter' => 'Enter',
        'Env' => 'Env K "v"',
        'Escape' => 'Escape',
        'Hide' => 'Hide',
        'Insert' => 'Insert',
        'Left' => 'Left',
        'Output' => 'Output o.gif',
        'PageDown' => 'PageDown',
        'PageUp' => 'PageUp',
        'Paste' => 'Paste',
        'Require' => 'Require git',
        'Right' => 'Right',
        'Screenshot' => 'Screenshot s.png',
        'ScrollDown' => 'ScrollDown',
        'ScrollUp' => 'ScrollUp',
        'Set' => 'Set Width 100',
        'Shift' => 'Shift',
        'Show' => 'Show',
        'Sleep' => 'Sleep 1s',
        'Source' => 'Source other.tape',
        'Space' => 'Space',
        'Tab' => 'Tab',
        'Type' => 'Type "x"',
        'Up' => 'Up',
        'Wait' => 'Wait 1s',
    ];

    /**
     * The 60 names, byte-sorted — LC_ALL=C sort of the upstream map keys,
     * the same whole-set pin the oracle itself applies
     * (`candy-code/tests/VhsTapeContractTest.php` :2687-2694). The group
     * fixtures above keep the oracle's PRESENTATION order (commands,
     * sub-keywords, lowercase units); it is the sorted set that must never
     * drift.
     *
     * @var list<string>
     */
    private const SORTED_ROSTER = [
        'Alt', 'Backspace', 'BorderRadius', 'Copy', 'Ctrl', 'CursorBlink',
        'Delete', 'Down', 'End', 'Enter', 'Env', 'Escape',
        'FontFamily', 'FontSize', 'Framerate', 'Height', 'Hide', 'Insert',
        'Left', 'LetterSpacing', 'LineHeight', 'LoopOffset', 'Margin', 'MarginFill',
        'Output', 'Padding', 'PageDown', 'PageUp', 'Paste', 'PlaybackSpeed',
        'Require', 'Right', 'Screenshot', 'ScrollDown', 'ScrollUp', 'Set',
        'Shell', 'Shift', 'Show', 'Sleep', 'Source', 'Space',
        'Tab', 'Theme', 'Type', 'TypingSpeed', 'Up', 'Wait',
        'WaitPattern', 'WaitTimeout', 'Width', 'WindowBar', 'WindowBarSize', 'em',
        'false', 'm', 'ms', 'px', 's', 'true',
    ];

    public function testTheRosterIsUpstreamsWholeSixty(): void
    {
        $all = [...self::COMMANDS, ...self::SET_SUBKEYWORDS, ...self::LOWERCASE];

        $this->assertCount(32, self::COMMANDS);
        $this->assertCount(21, self::SET_SUBKEYWORDS);
        $this->assertCount(7, self::LOWERCASE);
        $this->assertCount(60, $all);
        $this->assertSame(count($all), count(array_unique($all)), 'roster fixture carries a duplicate');

        $sorted = $all;
        sort($sorted, SORT_STRING);
        $this->assertSame(self::SORTED_ROSTER, $sorted, 'roster drifted from upstreams whole sorted set');
    }

    /**
     * Every command keyword classifies through the lexer — no silent
     * UNKNOWN drop — and produces at least one parse node (Directive or
     * ParseError). The whole point of finding 005: upstream-legal lines
     * must be VISIBLE to the strict gate.
     */
    public function testEveryCommandKeywordClassifiesIntoANode(): void
    {
        $lexer = new Lexer();
        $missing = [];

        foreach (self::COMMANDS as $keyword) {
            $line = self::COMMAND_PROBES[$keyword];
            $tokens = $lexer->tokenize($line);
            if ($tokens === [] || $tokens[0]->type === Lexer::TOKEN_UNKNOWN) {
                $missing[] = "{$keyword} (probe: {$line})";
                continue;
            }
            $parser = new Parser();
            if ($parser->parse($tokens) === []) {
                $missing[] = "{$keyword} classified but parsed to nothing";
            }
        }

        $this->assertSame([], $missing, 'command keywords invisible to the pipeline');
    }

    public function testSetSubkeywordsRideTheSetArm(): void
    {
        $lexer = new Lexer();
        foreach (self::SET_SUBKEYWORDS as $key) {
            $tokens = $lexer->tokenize("Set {$key} 1");
            $this->assertSame(Lexer::TOKEN_SET, $tokens[0]->type, "Set {$key} head not classified");
        }
    }

    /**
     * Part (b) acceptance, non-strict: a typo'd directive warns through
     * warnings() instead of vanishing.
     */
    public function testTypoWarnsUnderNonStrict(): void
    {
        [$cassette, $warnings] = $this->compileTape("Typ \"x\"\n");

        $this->assertSame([], $cassette->events);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('Unknown directive: Typ', $warnings[0]);
    }

    public function testTypoAbortsUnderStrict(): void
    {
        // Production wiring (TapeToGif::render) hands compile() the parser's
        // node list WITH ParseError nodes; parseSource() would split them off
        // and the strict gate would never see the typo.
        $tokens = (new Lexer())->tokenize("Typ \"x\"\n");
        $ast = (new Parser())->parse($tokens);
        $compiler = new Compiler();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown directive: Typ');
        $compiler->compile($ast, '/tmp/roster-parity.tape', strict: true);
    }

    /**
     * The measured upstream phrase for an argument-less `Type` (oracle:
     * `Type Copy zzqq` answers exactly `Type expects string`, ONE error —
     * Copy is a keyword, so the word after Type is never its string).
     */
    public function testTypeWithoutArgumentAnswersUpstreamsPhraseOnce(): void
    {
        $result = Compiler::parseSource("Type Copy zzqq\n");

        $this->assertCount(1, $result['errors']);
        $this->assertSame('Type expects string', $result['errors'][0]->message);
    }

    public function testDeleteCompilesToARealKeystrokeEvent(): void
    {
        [$cassette] = $this->compileTape("Delete\n");

        $this->assertCount(1, $cassette->events);
        $event = $cassette->events[0];
        $this->assertSame(EventKind::Input, $event->kind);
        $this->assertSame("\x1b[3~", $event->payload['b']);
    }

    public function testAltMergesIntoTheNextKeystrokeAsMetaLead(): void
    {
        [$cassette] = $this->compileTape("Alt Enter\n");

        $this->assertCount(1, $cassette->events);
        $this->assertSame("\x1b\r", $cassette->events[0]->payload['b']);
    }

    public function testShiftParameterisesShiftCapableKeys(): void
    {
        [$delete] = $this->compileTape("Shift Delete\n");
        $this->assertSame("\x1b[3;2~", $delete->events[0]->payload['b']);

        [$up] = $this->compileTape("Shift Up\n");
        $this->assertSame("\x1b[1;2A", $up->events[0]->payload['b']);
    }

    public function testShiftOnPlainEnterWarnsAndEmitsUnshifted(): void
    {
        [$cassette, $warnings] = $this->compileTape("Shift Enter\n");

        $this->assertCount(1, $cassette->events);
        $this->assertSame("\r", $cassette->events[0]->payload['b']);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('Shift+Enter has no distinct byte encoding', $warnings[0]);
    }

    public function testShiftTypesAsUppercaseRunes(): void
    {
        [$cassette] = $this->compileTape("Shift Type \"ab\"\n");

        $this->assertCount(2, $cassette->events);
        $this->assertSame('A', $cassette->events[0]->payload['b']);
        $this->assertSame('B', $cassette->events[1]->payload['b']);
    }

    public function testDanglingModifierIsReportedNeverSilent(): void
    {
        [, $warnings] = $this->compileTape("Type \"a\"\nAlt\n");

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('Unapplied Alt modifier', $warnings[0]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unapplied Alt modifier');
        $this->compileTape("Type \"a\"\nAlt\n", strict: true);
    }

    public function testScrollUpCompilesToTheSgrWheelPair(): void
    {
        [$cassette] = $this->compileTape("ScrollUp\n");

        $this->assertSame("\x1b[<96;1;1M\x1b[<96;1;1m", $cassette->events[0]->payload['b']);
    }

    public function testCopyEmitsTheOsc52ClipboardSetWire(): void
    {
        [$cassette] = $this->compileTape("Copy \"hi\"\n");

        $this->assertCount(1, $cassette->events);
        $this->assertSame("\x1b]52;c;aGk=\x07", $cassette->events[0]->payload['b']);
    }

    public function testRequireAndPasteAreLoudNotSilent(): void
    {
        [$cassette, $warnings] = $this->compileTape("Require git\nPaste\n");

        $this->assertSame([], $cassette->events, 'neither Require nor Paste may fake keystrokes');
        $this->assertCount(2, $warnings);
        $this->assertStringContainsString('Require git', $warnings[0]);
        $this->assertStringContainsString('Paste requires a clipboard', $warnings[1]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Require git');
        $this->compileTape("Require git\n", strict: true);
    }

    public function testBareCopyAndRequireAnswerUpstreamsMeasuredPhrases(): void
    {
        $copy = Compiler::parseSource("Copy\n");
        $this->assertCount(1, $copy['errors']);
        $this->assertSame('Copy expects string', $copy['errors'][0]->message);

        $require = Compiler::parseSource("Require\n");
        $this->assertCount(1, $require['errors']);
        $this->assertSame('Require expects one string', $require['errors'][0]->message);
    }

    /**
     * @return array{0: Cassette, 1: list<string>}
     */
    private function compileTape(string $source, bool $strict = false): array
    {
        // Mirror the production wiring (TapeToGif::render): the parser's
        // node list, ParseError nodes INCLUDED, goes to compile() — the
        // split parseSource() does would hide exactly the nodes under test.
        $tokens = (new Lexer())->tokenize($source);
        $ast = (new Parser())->parse($tokens);
        $compiler = new Compiler();
        $cassette = $compiler->compile($ast, '/tmp/roster-parity.tape', $strict);

        return [$cassette, $compiler->warnings()];
    }
}
