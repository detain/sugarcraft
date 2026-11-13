<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tape;

use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Tape\Ast\ArrowDirective;
use SugarCraft\Vcr\Tape\Ast\BackspaceDirective;
use SugarCraft\Vcr\Tape\Ast\CopyDirective;
use SugarCraft\Vcr\Tape\Ast\CtrlDirective;
use SugarCraft\Vcr\Tape\Ast\Directive;
use SugarCraft\Vcr\Tape\Ast\EnterDirective;
use SugarCraft\Vcr\Tape\Ast\EnvDirective;
use SugarCraft\Vcr\Tape\Ast\EscapeDirective;
use SugarCraft\Vcr\Tape\Ast\HideDirective;
use SugarCraft\Vcr\Tape\Ast\KeyDirective;
use SugarCraft\Vcr\Tape\Ast\ModifierDirective;
use SugarCraft\Vcr\Tape\Ast\OutputDirective;
use SugarCraft\Vcr\Tape\Ast\ParseError;
use SugarCraft\Vcr\Tape\Ast\PasteDirective;
use SugarCraft\Vcr\Tape\Ast\RequireDirective;
use SugarCraft\Vcr\Tape\Ast\ScreenshotDirective;
use SugarCraft\Vcr\Tape\Ast\SetDirective;
use SugarCraft\Vcr\Tape\Ast\ShowDirective;
use SugarCraft\Vcr\Tape\Ast\SleepDirective;
use SugarCraft\Vcr\Tape\Ast\SourceDirective;
use SugarCraft\Vcr\Tape\Ast\SpaceDirective;
use SugarCraft\Vcr\Tape\Ast\TabDirective;
use SugarCraft\Vcr\Tape\Ast\TypeDirective;
use SugarCraft\Vcr\Tape\Ast\WaitDirective;

/**
 * Compiles a directive AST into a Cassette with events.
 */
final class Compiler
{
    /**
     * VHS output-image defaults. `Set Width`/`Set Height` are PIXEL dimensions of
     * the rendered image (NOT terminal columns/rows); the terminal grid is derived
     * from them via the font cell size. Match charmbracelet/vhs defaults so a tape
     * that omits `Set Width`/`Set Height`/`Set FontSize` renders at the same size a
     * real `vhs` render would (1200x600 image, 22px font).
     */
    public const DEFAULT_WIDTH_PX = 1200;
    public const DEFAULT_HEIGHT_PX = 600;
    public const DEFAULT_FONT_SIZE = 22;

    private float $typingSpeed = 50.0;
    private int $widthPx = self::DEFAULT_WIDTH_PX;
    private int $heightPx = self::DEFAULT_HEIGHT_PX;
    private string $theme = 'TokyoNight';
    /** @var array<string, string> */
    private array $env = [];
    private ?float $playbackSpeed = null;
    private ?int $fontSize = null;
    private ?string $fontFamily = null;

    /**
     * Shell requested by a `Set Shell "<shell>"` directive. Null (default) means
     * the tape renders in the byte-identical echo path — keystrokes are rasterized
     * as typed characters and no subprocess runs. A non-null value opts the render
     * into exec mode ({@see \SugarCraft\Vcr\Render\FrameStream}), where the typed
     * commands are written to a real PTY and the program's output is captured into
     * the frames — mirroring charmbracelet/vhs `Set Shell`. Kept as a render-time
     * accessor ({@see shell()}) rather than a serialized header field, matching the
     * existing {@see outputPath()} idiom for compile-resolved, render-only values.
     */
    private ?string $shell = null;

    private float $currentTime = 0.0;

    /**
     * Pending keystroke modifiers armed by bare `Alt` / `Shift` keyword
     * directives (finding 005). Upstream lexes them as commands; they carry
     * no standalone encoding here, so each one merges into the NEXT
     * keystroke's bytes (Alt as the ESC meta lead sugar-core's InputReader
     * documents, Shift as the xterm `;2` parameter where the sequence has
     * one). A tape that ends with a flag unset is reported, never silent.
     */
    private bool $pendingAlt = false;
    private bool $pendingShift = false;

    /** @var list<Event> */
    private array $events = [];

    /**
     * Destination path requested by an `Output <path>` directive, confined to
     * the tape's own directory. Null when no (valid) Output directive was seen.
     */
    private ?string $outputPath = null;

    private string $currentSourcePath = '';

    private int $sourceDepth = 0;

    /** @var array<string, true> */
    private array $sourceStack = [];

    private const MAX_SOURCE_DEPTH = 10;

    /**
     * When true, a Source directive that escapes the tape's directory or cannot
     * be resolved/read raises a parse error (\RuntimeException) instead of being
     * silently skipped. Threaded in from compile()'s $strict argument so the
     * Source path matches the existing strict handling of ParseError nodes.
     */
    private bool $strict = false;

    /**
     * Non-fatal diagnostics accumulated during the most recent compile(): an
     * unresolvable or out-of-base Source include under non-strict mode records
     * a message here (strict mode throws instead). Callers surface these to
     * stderr so a skipped include is never silent.
     *
     * @var list<string>
     */
    private array $warnings = [];

    /** `Set Framerate <n>` (finding 008): honoured fps for the render path. */
    private ?float $framerate = null;

    /**
     * The content-LOSING subset of warnings (S09 / finding 024): ParseError
     * drops, unencodable Type chars, skipped cycles / depth overruns and
     * subinclude errors — every directive that did not reach the cassette.
     *
     * @var list<string>
     */
    private array $droppedDiagnostics = [];

    /**
     * @param list<Directive|ParseError> $ast
     */
    public function compile(array $ast, string $sourcePath, bool $strict = false): Cassette
    {
        $this->reset();
        $this->strict = $strict;
        $this->currentSourcePath = $sourcePath;

        foreach ($ast as $node) {
            if ($node instanceof ParseError) {
                if ($strict) {
                    throw new \RuntimeException("Parse error at line {$node->line}: {$node->message}");
                }
                // Non-strict still records the problem (finding 005's silence
                // close): a skipped unknown directive is a loud diagnostic,
                // not a vanishing keystroke.
                $this->recordDropped("Parse error at line {$node->line}: {$node->message}");
                continue;
            }
            $this->compileNode($node);
        }

        // A modifier armed at the end of a tape never reaches a keystroke to
        // merge into; report it rather than dropping the intent silently.
        if ($this->pendingAlt || $this->pendingShift) {
            $modifiers = $this->pendingAlt && $this->pendingShift
                ? 'Alt/Shift'
                : ($this->pendingAlt ? 'Alt' : 'Shift');
            $this->reportCompileProblem(
                "Unapplied {$modifiers} modifier: no keystroke follows it on the tape",
            );
            $this->pendingAlt = $this->pendingShift = false;
        }

        // Derive the terminal grid from the pixel image dimensions and the font
        // cell size, matching VHS: `Set Width`/`Set Height` are IMAGE pixels, and
        // the number of columns/rows is (image size / cell size). Resolved once,
        // after all directives are seen, so `Set FontSize` ordering vs
        // `Set Width`/`Set Height` in the tape does not matter.
        [$cellW, $cellH] = self::cellMetrics($this->fontSize ?? self::DEFAULT_FONT_SIZE);
        $cols = max(1, intdiv($this->widthPx, $cellW));
        $rows = max(1, intdiv($this->heightPx, $cellH));

        $header = new CassetteHeader(
            version: CassetteHeader::CURRENT_VERSION,
            createdAt: date('c'),
            cols: $cols,
            rows: $rows,
            runtime: 'SugarCraft/Vcr',
            timestampMode: CassetteHeader::TIMESTAMP_MODE_ABSOLUTE,
            env: $this->env,
            typingSpeed: $this->typingSpeed,
            theme: $this->theme,
            playbackSpeed: $this->playbackSpeed,
            fontSize: $this->fontSize,
            fontFamily: $this->fontFamily,
            widthPx: $this->widthPx,
            heightPx: $this->heightPx,
        );

        // The clock the directives advanced to (trailing Sleep/Wait included) is
        // the tape's true end — later than the final event's timestamp whenever
        // the tape closes with unemitted time (finding 003).
        return new Cassette($header, $this->events, $this->currentTime);
    }

    /**
     * Destination path requested by the tape's `Output <path>` directive,
     * resolved and confined to the tape's own directory. Null when the tape
     * had no Output directive, or when the requested path escaped the tape's
     * directory (in which case the caller should fall back to its own default
     * rather than honor an attacker-controlled traversal path).
     *
     * Reflects the most recent compile() call.
     */
    public function outputPath(): ?string
    {
        return $this->outputPath;
    }

    /**
     * Shell requested by the tape's `Set Shell "<shell>"` directive, or null when
     * the tape sets none. A non-null value opts the render into exec mode (real
     * PTY + captured program output); null keeps the byte-identical echo path.
     * Reflects the most recent compile() call. Mirrors charmbracelet/vhs Set Shell.
     */
    public function shell(): ?string
    {
        return $this->shell;
    }

    /**
     * Glyph cell size in pixels for a given font size, used both here (to derive
     * the terminal grid from the pixel image dimensions) and by
     * {@see \SugarCraft\Vcr\Encode\TapeToGif} (to size the rasterizer canvas). Kept
     * as the single source of truth so the compiled grid and the rendered canvas
     * always agree — otherwise `cols * cellW` would not line up with the requested
     * `Set Width` pixels.
     *
     * @return array{0: int, 1: int} [cellW, cellH] in pixels, each >= 1.
     */
    public static function cellMetrics(int $fontSize): array
    {
        $cellW = max(1, (int) floor($fontSize * 0.6));
        $cellH = max(1, $fontSize * 2);

        return [$cellW, $cellH];
    }

    /**
     * @return array{ast: list<Directive>, errors: list<ParseError>}
     */
    public static function parseSource(string $source): array
    {
        $lexer = new Lexer();
        $parser = new Parser();

        $tokens = $lexer->tokenize($source);
        $ast = $parser->parse($tokens);

        $errors = [];
        $directives = [];

        foreach ($ast as $node) {
            if ($node instanceof ParseError) {
                $errors[] = $node;
            } else {
                $directives[] = $node;
            }
        }

        return ['ast' => $directives, 'errors' => $errors];
    }

    private function reset(): void
    {
        $this->typingSpeed = 50.0;
        $this->widthPx = self::DEFAULT_WIDTH_PX;
        $this->heightPx = self::DEFAULT_HEIGHT_PX;
        $this->theme = 'TokyoNight';
        $this->env = [];
        $this->playbackSpeed = null;
        $this->fontSize = null;
        $this->fontFamily = null;
        $this->shell = null;
        $this->currentTime = 0.0;
        $this->events = [];
        $this->outputPath = null;
        $this->currentSourcePath = '';
        $this->sourceDepth = 0;
        $this->sourceStack = [];
        $this->strict = false;
        $this->warnings = [];
        $this->droppedDiagnostics = [];
        $this->framerate = null;
        $this->pendingAlt = false;
        $this->pendingShift = false;
    }

    /**
     * Non-fatal diagnostics from the most recent compile() — currently
     * unresolvable / out-of-base Source includes skipped under non-strict mode.
     * Empty when the tape compiled cleanly or ran under strict mode (which
     * throws on the first such problem instead of collecting).
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    private function compileNode(Directive $node): void
    {
        match (true) {
            $node instanceof OutputDirective => $this->compileOutput($node),
            $node instanceof SetDirective => $this->compileSet($node),
            $node instanceof EnvDirective => $this->env[$node->key] = $this->compileEnvValue($node->value),
            $node instanceof TypeDirective => $this->compileType($node),
            $node instanceof EnterDirective => $this->emitKey("\r", null, 'Enter'),
            $node instanceof TabDirective => $this->emitKey("\t", "\x1b[Z", 'Tab'),
            $node instanceof BackspaceDirective => $this->emitKey("\x7f", null, 'Backspace'),
            $node instanceof ArrowDirective => $this->compileArrow($node),
            $node instanceof CtrlDirective => $this->compileCtrl($node),
            $node instanceof KeyDirective => $this->compileNamedKey($node),
            $node instanceof ModifierDirective => $this->compileModifier($node),
            $node instanceof CopyDirective => $this->compileCopy($node),
            $node instanceof PasteDirective => $this->reportCompileProblem(
                'Paste requires a clipboard to read; the record-side compiler emits no bytes for it',
            ),
            $node instanceof RequireDirective => $this->reportCompileProblem(
                "Require {$node->module} is an upstream precondition loader; candy-vcr emits no bytes for it",
            ),
            $node instanceof SpaceDirective => $this->emitKey(' ', null, 'Space'),
            $node instanceof EscapeDirective => $this->emitKey("\x1b", null, 'Escape'),
            $node instanceof SleepDirective => $this->currentTime += $node->seconds,
            $node instanceof WaitDirective => $this->compileWait($node),
            $node instanceof HideDirective => $this->emitEvent(EventKind::Hide, []),
            $node instanceof ShowDirective => $this->emitEvent(EventKind::Show, []),
            $node instanceof SourceDirective => $this->compileSource($node),
            $node instanceof ScreenshotDirective => $this->compileScreenshot($node),
            default => null,
        };
    }

    /**
     * `Wait <duration>` advances the recorder clock. `Wait /re/` is a
     * CONDITION wait — upstream blocks the recorder until the output matches,
     * but this compiler is the record-side clock with no live output stream to
     * poll (the Player/Recorder render captured events; neither gates today).
     * So the pattern compiles to no event and no time, and the mismatch is
     * reported rather than swallowed: render-side condition matching is the
     * filed seam.
     */
    private function compileWait(WaitDirective $node): void
    {
        if ($node->pattern === '') {
            $this->currentTime += $node->seconds;

            return;
        }

        $this->reportCompileProblem(
            "Wait {$node->pattern} is a condition wait; the record-side compiler cannot match output, "
            . 'so it emits no event and advances no clock (render-side pattern waiting is the open seam)',
        );
    }

    /**
     * Shared strict/non-strict gate for compile-time problems: strict mode
     * throws (mirroring compile()'s handling of ParseError nodes), otherwise
     * the message lands in {@see warnings()} and compilation continues.
     */
    private function reportCompileProblem(string $message): void
    {
        if ($this->strict) {
            throw new \RuntimeException("Parse error: {$message}");
        }
        $this->warnings[] = $message;
    }

    /**
     * Report a problem that LOST tape content, and book it in
     * {@see droppedDiagnostics()} too (finding 024): callers that surface a
     * summary — the CLI's default-mode warning print, S08's accepted-not-
     * enforced channel — can ask specifically what vanished without
     * re-parsing the prose of every warning.
     */
    private function recordDropped(string $message): void
    {
        $this->droppedDiagnostics[] = $message;
        $this->reportCompileProblem($message);
    }

    /**
     * Content-losing diagnostics from the most recent compile() (see
     * {@see recordDropped()}). Under strict mode the run aborts at the
     * first one; a non-strict compile finishes and carries every drop here.
     *
     * @return list<string>
     */
    public function droppedDiagnostics(): array
    {
        return $this->droppedDiagnostics;
    }

    private function compileSet(SetDirective $node): void
    {
        match ($node->key) {
            // VHS semantics: Width/Height are the OUTPUT IMAGE size in PIXELS, not
            // terminal columns/rows. The grid is derived from these in compile().
            'Width' => $this->widthPx = max(1, (int) $node->value),
            'Height' => $this->heightPx = max(1, (int) $node->value),
            'Theme' => $this->theme = trim($node->value, '"\' '),
            'TypingSpeed' => $this->typingSpeed = $this->parseTypingSpeed($node->value),
            'PlaybackSpeed' => $this->playbackSpeed = $this->parsePlaybackSpeed($node->value),
            'FontSize' => $this->fontSize = (int) $node->value,
            'FontFamily' => $this->fontFamily = trim($node->value, '"\' '),
            // Opt-in exec mode: the tape's typed commands run in this shell and the
            // program's real output is captured into the frames. Absence keeps the
            // byte-identical echo path. Mirrors charmbracelet/vhs `Set Shell`.
            'Shell' => $this->shell = trim($node->value, '"\' '),
            // The one output-cadence knob upstream exposes: honoured through
            // TapeToGif's fps resolution (CLI --fps still wins). Finding 008.
            'Framerate' => $this->framerate = $this->parseFramerate($node->value),
            // Accepted but not enforced by this renderer: named on every
            // compile (finding 008's never-silence law), never a ParseError —
            // these are upstream-legal directives, their EFFECT is the delta.
            'Padding', 'Margin', 'LetterSpacing', 'LineHeight', 'LoopOffset', 'MarginFill',
            'CursorBlink', 'BorderRadius', 'WindowBar', 'WindowBarSize', 'WaitPattern', 'WaitTimeout'
                => $this->recordAcceptedNotEnforced($node->key, $node->value),
            default => null,
        };
    }

    /**
     * Loud, non-fatal honesty for settings the renderer parses but does not
     * act on: it rides warnings() directly because strict mode must NOT abort
     * on a legal upstream directive (contrast reportCompileProblem, which
     * throws there) and it stays out of droppedDiagnostics() because no tape
     * content was lost — only an effect not carried.
     */
    private function recordAcceptedNotEnforced(string $key, string $value): void
    {
        $where = $this->currentSourcePath !== '' ? ' in ' . $this->currentSourcePath : '';
        $this->warnings[] = "Set {$key} '{$value}' is accepted, not enforced by this renderer{$where}";
    }

    /**
     * `Set Framerate <n>` — must be a positive finite number (same numeric
     * door as the sibling speed knobs); garbage names itself and keeps the
     * documented default.
     */
    private function parseFramerate(string $value): ?float
    {
        $trimmed = trim($value, "\"' \t");
        if (is_numeric($trimmed)) {
            $fps = (float) $trimmed;
            if (is_finite($fps) && $fps > 0.0) {
                return $fps;
            }
            $this->reportCompileProblem("Unparseable Framerate value '{$value}' — using the render default");
            return null;
        }
        $this->reportCompileProblem("Unparseable Framerate value '{$value}' — using the render default");
        return null;
    }

    /**
     * The tape's `Set Framerate` if it carried one (finding 008's honoured
     * key), null when the tape stayed silent so the caller's own default
     * keeps its seat.
     */
    public function framerate(): ?float
    {
        return $this->framerate;
    }

    private function parseTypingSpeed(string $value): float
    {
        $value = trim($value, "\"' \t");
        if (preg_match('/^(\d+(?:\.\d+)?)\s*ms$/i', $value, $m)) {
            return (float) $m[1];
        }
        if (preg_match('/^(\d+(?:\.\d+)?)\s*s$/i', $value, $m)) {
            return (float) $m[1] * 1000.0;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        if ($value !== '') {
            // Garbage used to fall through to (float) 'abc' === 0.0, which
            // meant "type every character instantly" — the loudest possible
            // misread of a typo. Refuse it: warn (or throw under strict)
            // and keep the documented 50ms default.
            $this->reportCompileProblem("Unparseable TypingSpeed value '{$value}' — using default 50ms");
        }
        return 50.0;
    }

    private function parsePlaybackSpeed(string $value): ?float
    {
        $value = trim($value, "\"' \t");
        if ($value === '') {
            return null;
        }
        if (is_numeric($value) && (float) $value >= 0.0) {
            return (float) $value;
        }
        // Same family as TypingSpeed above: (float) 'abc' === 0.0 would
        // silently collapse the playback cadence to instant.
        $this->reportCompileProblem("Unparseable PlaybackSpeed value '{$value}' — using recorded timing");
        return null;
    }

    private function compileType(TypeDirective $node): void
    {
        // Finding 006's compile-side half: the Lexer scanned the quoted run
        // escape-aware and left the escapes in the value verbatim; here they
        // resolve to the bytes the tape meant, so `Type "a\"b"` types `a"b`
        // and the Decompiler's own `\"`/`\\` spellings round-trip honestly.
        $chars = mb_str_split(Lexer::unescapeValue($node->text));

        // A pending Alt/Shift (finding 005 modifiers) merges into the run:
        // Alt becomes the ESC meta lead byte per character — sugar-core's
        // InputReader decodes "Meta as ESC <bytes>" into one alt-flagged
        // key event — and Shift uppercases ASCII letters, which IS a
        // shifted keystroke's whole encoding for runes. An empty Type
        // leaves the flags armed for the next keystroke directive.
        $alt = $this->pendingAlt;
        $shift = $this->pendingShift;
        if ($chars !== []) {
            $this->pendingAlt = $this->pendingShift = false;
        }

        $dropped = [];
        foreach ($chars as $char) {
            if ($shift && strlen($char) === 1 && $char >= 'a' && $char <= 'z') {
                $char = chr(ord($char) - 32);
            }
            $byte = $this->charToByte($char);
            if ($byte !== null) {
                $this->emitInputBytes($alt ? "\x1b" . $byte : $byte);
            } else {
                // Finding 107's char class: an unencodable code point (C1
                // controls, invalid UTF-8) used to evaporate mid-run — the
                // rest of the Type continued as if nothing was lost. The
                // strict gate (S09) turns it into an abort, non-strict into
                // a counted summary naming the first offender.
                $dropped[] = $char;
            }
            $this->currentTime += $this->typingSpeed / 1000.0;
        }

        if ($dropped !== []) {
            $preview = bin2hex(mb_substr($dropped[0], 0, 4));
            $where = $this->currentSourcePath !== '' ? ' in ' . $this->currentSourcePath : '';
            $this->recordDropped(
                'Type directive dropped ' . count($dropped)
                . ' unencodable character(s), first 0x' . $preview . $where,
            );
        }
    }

    /**
     * Convert a character to its raw byte representation matching InputReader.decodeChar().
     * Returns the UTF-8 byte sequence for printable characters above the ASCII range
     * so non-ASCII Type strings (accents, CJK, box-drawing) reach the terminal.
     */
    private function charToByte(string $char): ?string
    {
        $code = mb_ord($char, 'UTF-8');
        if ($code === false) {
            return null;
        }

        if ($code === 0x09) {
            return "\t";
        }
        if ($code === 0x0d || $code === 0x0a) {
            return "\r";
        }
        if ($code === 0x7f || $code === 0x08) {
            return "\x7f";
        }
        if ($code === 0x20) {
            return ' ';
        }
        if ($code === 0x1b) {
            return "\x1b";
        }
        if ($code >= 1 && $code <= 26) {
            return chr($code);
        }
        if ($code >= 0x20 && $code < 0x7f) {
            return chr($code);
        }
        if ($code >= 0xa0) {
            return $char;
        }

        return null;
    }

    private function compileArrow(ArrowDirective $node): void
    {
        [$bytes, $shifted] = match ($node->direction) {
            'Up' => ["\x1b[A", "\x1b[1;2A"],
            'Down' => ["\x1b[B", "\x1b[1;2B"],
            'Left' => ["\x1b[D", "\x1b[1;2D"],
            'Right' => ["\x1b[C", "\x1b[1;2C"],
            default => ['', ''],
        };
        if ($bytes === '') {
            // Defensive: the lexer only ever builds the four directions.
            $this->reportCompileProblem("Unknown arrow direction '{$node->direction}'");
            return;
        }
        $this->emitKey($bytes, $shifted, $node->direction);
    }

    private function compileCtrl(CtrlDirective $node): void
    {
        $letter = $node->letter;
        $ord = ord($letter);
        if ($ord >= 65 && $ord <= 90) {
            $ctrlCode = $ord - 64;
        } elseif ($ord >= 97 && $ord <= 122) {
            $ctrlCode = $ord - 96;
        } elseif ($letter === '@') {
            $ctrlCode = 0;
        } elseif ($letter === '[') {
            $ctrlCode = 27;
        } elseif ($letter === '\\') {
            $ctrlCode = 28;
        } elseif ($letter === ']') {
            $ctrlCode = 29;
        } elseif ($letter === '^') {
            $ctrlCode = 30;
        } elseif ($letter === '_') {
            $ctrlCode = 31;
        } else {
            $ctrlCode = $ord & 0x1F;
        }
        $this->emitKey(chr($ctrlCode), null, 'Ctrl+' . $letter);
    }

    /**
     * Named-key encodings (finding 005): plain and `;2`-shifted spellings
     * for the five CSI keystrokes plus the SGR wheel pair sugar-input's own
     * decoder recognises (button 96/97 wheel up/down, +4 for shift).
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const NAMED_KEY_BYTES = [
        'Delete'     => ["\x1b[3~", "\x1b[3;2~"],
        'Insert'     => ["\x1b[2~", "\x1b[2;2~"],
        'PageUp'     => ["\x1b[5~", "\x1b[5;2~"],
        'PageDown'   => ["\x1b[6~", "\x1b[6;2~"],
        'End'        => ["\x1b[F", "\x1b[1;2F"],
        'ScrollUp'   => ["\x1b[<96;1;1M\x1b[<96;1;1m", "\x1b[<100;1;1M\x1b[<100;1;1m"],
        'ScrollDown' => ["\x1b[<97;1;1M\x1b[<97;1;1m", "\x1b[<101;1;1M\x1b[<101;1;1m"],
    ];

    /**
     * `Delete` / `Insert` / `PageUp` / `PageDown` / `End` / `ScrollUp` /
     * `ScrollDown` — the KEY roster arm from the Lexer (finding 005). The
     * sequences are the exact shapes sugar-core's InputReader decodes back
     * into KeyType cases, so a compiled tape round-trips as real keystrokes.
     */
    private function compileNamedKey(KeyDirective $node): void
    {
        $entry = self::NAMED_KEY_BYTES[$node->key] ?? null;
        if ($entry === null) {
            $this->reportCompileProblem("Named key '{$node->key}' has no byte encoding");
            return;
        }
        $this->emitKey($entry[0], $entry[1], $node->key);
    }

    /**
     * Bare `Alt` / `Shift`: arm the pending modifier. It merges into the
     * next keystroke directive (see {@see emitKey()}); if the tape ends
     * first, compile() reports the dangling flag.
     */
    private function compileModifier(ModifierDirective $node): void
    {
        match ($node->modifier) {
            'Alt' => $this->pendingAlt = true,
            default => $this->pendingShift = true,
        };
    }

    /**
     * `Copy "text"` — the terminal-native clipboard wire: OSC 52 set with a
     * base64-confined payload (the same encoding family finding 004 warns
     * hostile cassettes can forge — minting it from a tape directive is the
     * legitimate side of that door, and base64 keeps raw tape bytes out of
     * the escape sequence).
     */
    private function compileCopy(CopyDirective $node): void
    {
        $this->emitInputBytes("\x1b]52;c;" . base64_encode(Lexer::unescapeValue($node->text)) . "\x07");
    }

    /**
     * Resolve an `Env` value to the bytes it carries (finding 091): the
     * Lexer's escape-aware arm already stripped the delimiters, so the only
     * quoting left to honor is a legacy hand-written pair (strip ONE
     * matched surrounding pair — never a char-class trim, which would eat
     * into an escaped quote at either end), then unescape.
     */
    private function compileEnvValue(string $raw): string
    {
        $value = $raw;
        if (strlen($value) >= 2 && preg_match('/^([\'"`])((?:\\\\.|[^\\\\])*?)\1$/', $value)) {
            $value = substr($value, 1, -1);
        }

        return Lexer::unescapeValue(trim($value, " \t"));
    }

    /**
     * Merge any pending Alt/Shift flags into a keystroke emission and
     * consume them. Alt = the ESC meta lead (sugar-core documents "Meta as
     * ESC <bytes>", InputReader's alt-flag path); Shift = the directive's
     * xterm `;<mod>` spelling when one exists, otherwise the keystroke has
     * no distinct shifted byte and the gap is reported, not swallowed.
     */
    private function emitKey(string $bytes, ?string $shifted, string $name): void
    {
        if ($this->pendingShift) {
            if ($shifted !== null) {
                $bytes = $shifted;
            } else {
                $this->reportCompileProblem("Shift+{$name} has no distinct byte encoding; keystroke emitted unshifted");
            }
        }
        $alt = $this->pendingAlt;
        $this->pendingAlt = $this->pendingShift = false;
        $this->emitInputBytes($alt ? "\x1b" . $bytes : $bytes);
    }

    /**
     * Honor an `Output <path>` directive by recording where the rendered
     * artifact should be written. The path is confined to the tape's own
     * directory: a traversal (`..`) or absolute path that escapes the tape's
     * directory is ignored (leaving outputPath null) so an untrusted tape
     * cannot direct the render to overwrite an arbitrary file — the caller
     * then falls back to its own default destination.
     */
    private function compileOutput(OutputDirective $node): void
    {
        $confined = $this->confineOutputPath($node->path);
        if ($confined !== null) {
            $this->outputPath = $confined;
        }
    }

    /**
     * Resolve a tape-supplied output path against the tape's directory,
     * returning the confined absolute path or null when it escapes that
     * directory. Mirrors the base-dir confinement used for Source includes
     * and TapeToGif screenshot capture.
     */
    private function confineOutputPath(string $rawPath): ?string
    {
        if ($rawPath === '') {
            return null;
        }
        // Reject traversal segments, Windows separators, and embedded NUL bytes
        // outright. A NUL would otherwise only surface as a ValueError at write
        // time; reject it cleanly here alongside `..`/`\`.
        if (str_contains($rawPath, '..') || str_contains($rawPath, '\\') || str_contains($rawPath, "\0")) {
            return null;
        }

        $baseDir = dirname($this->currentSourcePath ?: '.');
        if ($baseDir === '') {
            $baseDir = '.';
        }
        $baseReal = realpath($baseDir);
        if ($baseReal === false) {
            return null;
        }

        $isAbsolute = str_starts_with($rawPath, '/')
            || (strlen($rawPath) >= 2 && ctype_alpha($rawPath[0]) && $rawPath[1] === ':');

        // The target file itself usually does not exist yet, so confine on its
        // parent directory (which must already resolve to somewhere under base).
        if ($isAbsolute) {
            $parentReal = realpath(dirname($rawPath));
            if ($parentReal === false || !$this->isUnder($parentReal, $baseReal)) {
                return null;
            }
            $finalPath = $rawPath;
        } else {
            $finalPath = $baseReal . DIRECTORY_SEPARATOR . $rawPath;
            $parentReal = realpath(dirname($finalPath));
            if ($parentReal === false || !$this->isUnder($parentReal, $baseReal)) {
                return null;
            }
        }

        // Symlink-escape guard (CWE-59): the parent dir resolving under base is
        // not enough — the target itself may be a pre-planted symlink pointing
        // outside it, and TapeToGif would then write the rendered artifact
        // *through* the link, landing it anywhere on disk. Reject any existing
        // symlink target outright (is_link() is true regardless of whether or
        // where the link resolves), matching (and exceeding) the realpath-based
        // strictness the Source-include path already applies. For any other
        // pre-existing target, re-canonicalize and re-verify it stays under base.
        if (is_link($finalPath)) {
            return null;
        }
        if (file_exists($finalPath)) {
            $targetReal = realpath($finalPath);
            if ($targetReal === false || !$this->isUnder($targetReal, $baseReal)) {
                return null;
            }
        }

        return $finalPath;
    }

    private function isUnder(string $path, string $base): bool
    {
        return $path === $base
            || str_starts_with($path . DIRECTORY_SEPARATOR, $base . DIRECTORY_SEPARATOR);
    }

    /**
     * Surface a Source-include problem instead of dropping it silently. Under
     * strict mode this throws a parse error (mirroring compile()'s handling of
     * ParseError nodes); under non-strict mode it appends to {@see warnings()}
     * so the caller can report it while compilation continues.
     */
    private function reportSourceProblem(string $path, string $reason): void
    {
        // Every Source problem is a dropped include — booked in the
        // dropped channel as well as warned (finding 024).
        $this->recordDropped("Source include {$reason}: {$path}");
    }

    private function compileSource(SourceDirective $node): void
    {
        $baseDir = dirname($this->currentSourcePath ?: '.');
        $fullPath = $baseDir !== '' && $baseDir !== '.'
            ? $baseDir . '/' . $node->path
            : $node->path;

        // Attempt realpath resolution; the cycle stack is keyed on realpath
        // (finding 105) so the same file reached under two spellings
        // (`a/./b` vs `a/b` vs a sibling Source re-include) is ONE stack
        // entry. The raw path is the fallback key only when realpath cannot
        // resolve yet (uncreatable paths route through the problem channel
        // below anyway).
        $realPath = realpath($fullPath);
        if ($realPath === false) {
            $realPath = realpath($node->path);
        }
        // Canonical stack key — realpath when known, else the string path
        $stackKey = $realPath !== false ? $realPath : $fullPath;

        // Base-dir confinement: reject paths that escape the tape's directory
        $baseReal = realpath($baseDir ?: '.');
        if ($realPath !== false && $baseReal !== false) {
            if (!str_starts_with($realPath, $baseReal . DIRECTORY_SEPARATOR)) {
                // Escaping the tape directory is never silent: raise a parse
                // error in strict mode, otherwise record a warning and skip.
                $this->reportSourceProblem($node->path, 'escapes the tape directory');
                return;
            }
        }

        // Cycle guard: skip if this path is already being compiled — but
        // SKIP IS NOT SILENT (finding 105): the strict gate aborts, and the
        // non-strict run records the drop instead of looping forever on a
        // mutual-`Source` pair whose second hop used to vanish.
        if (isset($this->sourceStack[$stackKey])) {
            $this->reportSourceProblem($node->path, 'cycle detected — skipped');
            return;
        }

        // Depth guard: same channel as the cycle (finding 105) — a deep
        // include chain is a malformed tape, not a crash the CLI owes.
        if ($this->sourceDepth >= self::MAX_SOURCE_DEPTH) {
            $this->reportSourceProblem($node->path, 'exceeded the include depth of ' . self::MAX_SOURCE_DEPTH);
            return;
        }

        // Size law (finding 032): a sub-include is a tape file too — route an
        // over-cap include through the same named-problem channel as the
        // unreadable ones instead of slurping megabytes of hostile input.
        $includePath = $realPath !== false ? $realPath : $fullPath;
        $includeSize = @filesize($includePath);
        if ($includeSize !== false && $includeSize > Lexer::MAX_TAPE_BYTES) {
            $this->reportSourceProblem($node->path, 'exceeds the ' . Lexer::MAX_TAPE_BYTES . '-byte tape cap');
            return;
        }

        $source = $realPath !== false
            ? @file_get_contents($realPath)
            : @file_get_contents($fullPath);

        if ($source === false) {
            // Unresolvable / unreadable include: raise a parse error in strict
            // mode, otherwise record a warning and skip.
            $this->reportSourceProblem($node->path, 'could not be resolved or read');
            return;
        }

        // Save state before recursion; restore in finally
        $savedSourcePath = $this->currentSourcePath;
        $this->sourceDepth++;
        $this->sourceStack[$stackKey] = true;

        try {
            $this->currentSourcePath = $realPath !== false ? $realPath : $fullPath;

            $subResult = Compiler::parseSource($source);
            // Findings 100/105: the include's own ParseErrors used to be
            // split out by parseSource() and evaporate — a broken line in a
            // sub-include neither aborted a strict compile nor surfaced a
            // warning. Route every dropped diagnostic through the strict
            // gate, naming the file it came from.
            foreach ($subResult['errors'] as $subError) {
                $this->recordDropped(
                    'Source include ' . ($realPath !== false ? $realPath : $fullPath)
                    . ": parse error at line {$subError->line}: {$subError->message}",
                );
            }
            foreach ($subResult['ast'] as $subNode) {
                if (!$subNode instanceof ParseError) {
                    $this->compileNode($subNode);
                }
            }
        } finally {
            unset($this->sourceStack[$stackKey]);
            $this->sourceDepth--;
            $this->currentSourcePath = $savedSourcePath;
        }
    }

    private function emitInputBytes(string $bytes): void
    {
        $this->events[] = new Event(
            $this->currentTime,
            EventKind::Input,
            ['b' => $bytes],
        );
    }

    private function compileScreenshot(ScreenshotDirective $node): void
    {
        $this->events[] = new Event(
            $this->currentTime,
            EventKind::Snapshot,
            ['path' => $node->path],
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function emitEvent(EventKind $kind, array $payload): void
    {
        $this->events[] = new Event(
            $this->currentTime,
            $kind,
            $payload,
        );
    }

}
