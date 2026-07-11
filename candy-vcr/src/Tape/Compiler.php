<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tape;

use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Tape\Ast\ArrowDirective;
use SugarCraft\Vcr\Tape\Ast\BackspaceDirective;
use SugarCraft\Vcr\Tape\Ast\CtrlDirective;
use SugarCraft\Vcr\Tape\Ast\Directive;
use SugarCraft\Vcr\Tape\Ast\EnterDirective;
use SugarCraft\Vcr\Tape\Ast\EnvDirective;
use SugarCraft\Vcr\Tape\Ast\EscapeDirective;
use SugarCraft\Vcr\Tape\Ast\HideDirective;
use SugarCraft\Vcr\Tape\Ast\OutputDirective;
use SugarCraft\Vcr\Tape\Ast\ParseError;
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
    private float $typingSpeed = 50.0;
    private int $cols = 80;
    private int $rows = 24;
    private string $theme = 'TokyoNight';
    /** @var array<string, string> */
    private array $env = [];
    private ?float $playbackSpeed = null;
    private ?int $fontSize = null;
    private ?string $fontFamily = null;

    private float $currentTime = 0.0;

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
                continue;
            }
            $this->compileNode($node);
        }

        $header = new CassetteHeader(
            version: CassetteHeader::CURRENT_VERSION,
            createdAt: date('c'),
            cols: $this->cols,
            rows: $this->rows,
            runtime: 'SugarCraft/Vcr',
            timestampMode: CassetteHeader::TIMESTAMP_MODE_ABSOLUTE,
            env: $this->env,
            typingSpeed: $this->typingSpeed,
            theme: $this->theme,
            playbackSpeed: $this->playbackSpeed,
            fontSize: $this->fontSize,
            fontFamily: $this->fontFamily,
        );

        return new Cassette($header, $this->events);
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
        $this->cols = 80;
        $this->rows = 24;
        $this->theme = 'TokyoNight';
        $this->env = [];
        $this->playbackSpeed = null;
        $this->fontSize = null;
        $this->fontFamily = null;
        $this->currentTime = 0.0;
        $this->events = [];
        $this->outputPath = null;
        $this->currentSourcePath = '';
        $this->sourceDepth = 0;
        $this->sourceStack = [];
        $this->strict = false;
        $this->warnings = [];
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
            $node instanceof EnvDirective => $this->env[$node->key] = trim($node->value, '"\' '),
            $node instanceof TypeDirective => $this->compileType($node),
            $node instanceof EnterDirective => $this->emitInputBytes("\r"),
            $node instanceof TabDirective => $this->emitInputBytes("\t"),
            $node instanceof BackspaceDirective => $this->emitInputBytes("\x7f"),
            $node instanceof ArrowDirective => $this->compileArrow($node),
            $node instanceof CtrlDirective => $this->compileCtrl($node),
            $node instanceof SpaceDirective => $this->emitInputBytes(' '),
            $node instanceof EscapeDirective => $this->emitInputBytes("\x1b"),
            $node instanceof SleepDirective => $this->currentTime += $node->seconds,
            $node instanceof WaitDirective => $this->currentTime += $node->seconds,
            $node instanceof HideDirective => $this->emitEvent(EventKind::Hide, []),
            $node instanceof ShowDirective => $this->emitEvent(EventKind::Show, []),
            $node instanceof SourceDirective => $this->compileSource($node),
            $node instanceof ScreenshotDirective => $this->compileScreenshot($node),
            default => null,
        };
    }

    private function compileSet(SetDirective $node): void
    {
        match ($node->key) {
            'Width' => $this->cols = (int) $node->value,
            'Height' => $this->rows = (int) $node->value,
            'Theme' => $this->theme = trim($node->value, '"\' '),
            'TypingSpeed' => $this->typingSpeed = $this->parseTypingSpeed($node->value),
            'PlaybackSpeed' => $this->playbackSpeed = $node->value !== '' ? (float) $node->value : null,
            'FontSize' => $this->fontSize = (int) $node->value,
            'FontFamily' => $this->fontFamily = trim($node->value, '"\' '),
            // Padding and Margin are accepted but not enforced (documented no-ops)
            default => null,
        };
    }

    private function parseTypingSpeed(string $value): float
    {
        if (preg_match('/^(\d+(?:\.\d+)?)\s*ms$/i', $value, $m)) {
            return (float) $m[1];
        }
        if (preg_match('/^(\d+(?:\.\d+)?)\s*s$/i', $value, $m)) {
            return (float) $m[1] * 1000.0;
        }
        return (float) ($value ?: 50.0);
    }

    private function compileType(TypeDirective $node): void
    {
        $chars = mb_str_split($node->text);

        foreach ($chars as $char) {
            $byte = $this->charToByte($char);
            if ($byte !== null) {
                $this->emitInputBytes($byte);
            }
            $this->currentTime += $this->typingSpeed / 1000.0;
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
        $bytes = match ($node->direction) {
            'Up' => "\x1b[A",
            'Down' => "\x1b[B",
            'Left' => "\x1b[D",
            'Right' => "\x1b[C",
            default => '',
        };
        if ($bytes !== '') {
            $this->emitInputBytes($bytes);
        }
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
        $this->emitInputBytes(chr($ctrlCode));
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
        $message = "Source include {$reason}: {$path}";
        if ($this->strict) {
            throw new \RuntimeException("Parse error: {$message}");
        }
        $this->warnings[] = $message;
    }

    private function compileSource(SourceDirective $node): void
    {
        $baseDir = dirname($this->currentSourcePath ?: '.');
        $fullPath = $baseDir !== '' && $baseDir !== '.'
            ? $baseDir . '/' . $node->path
            : $node->path;

        // Attempt realpath resolution; use the string path as the canonical
        // stack key for cycle detection (realpath can return false for files
        // that are currently being parsed via a sibling Source directive).
        $realPath = realpath($fullPath);
        if ($realPath === false) {
            $realPath = realpath($node->path);
        }
        // Canonical stack key — always use the string path
        $stackKey = $fullPath;

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

        // Cycle guard: skip if this string path is already being compiled
        if (isset($this->sourceStack[$stackKey])) {
            return;
        }

        // Depth guard
        if ($this->sourceDepth >= self::MAX_SOURCE_DEPTH) {
            throw new \RuntimeException("Source include depth exceeded (max " . self::MAX_SOURCE_DEPTH . "): {$fullPath}");
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

    private function emitEvent(EventKind $kind, array $payload): void
    {
        $this->events[] = new Event(
            $this->currentTime,
            $kind,
            $payload,
        );
    }

}
