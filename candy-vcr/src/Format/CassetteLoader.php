<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Format;

use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\Tape\Compiler;
use SugarCraft\Vcr\Tape\Lexer;
use SugarCraft\Vcr\Tape\Parser;

/**
 * Resolves a path to a {@see Cassette} by sniffing the file format.
 *
 * Detection rules (in order):
 *
 * 1. **Extension sniff** — `.tape` (VHS DSL), `.cas`/`.jsonl`/`.cassette`
 *    (JSONL cassette, possibly relative-timestamp), `.cast` (asciinema v3),
 *    `.yaml`/`.yml` (YAML cassette), `.gz` suffix triggers
 *    {@see CompressedJsonlFormat}.
 * 2. **Content sniff** — when the extension is missing or ambiguous, peek
 *    at the first non-blank, non-comment line: a leading `{` is JSON; an
 *    ASCII identifier in the known tape-directive set (`Set`, `Type`,
 *    `Enter`, `Sleep`, `Wait`, `Output`, `Hide`, `Show`, `Ctrl+`, …) is a
 *    tape source.
 *
 * Throws {@see \InvalidArgumentException} when neither sniff succeeds — the
 * caller is responsible for picking a sensible error code (the CLI maps it
 * to exit code 1).
 *
 * This is a thin convenience layer over the existing {@see Format}
 * implementations and {@see \SugarCraft\Vcr\Player::open()} — both stay
 * format-aware. Use this when the user passes a raw path on the CLI and
 * the command should accept either a tape or a cassette transparently.
 */
final class CassetteLoader
{
    /** Set of known tape-directive head tokens. Lowercased for case-insensitive sniffing. */
    private const TAPE_DIRECTIVE_HEADS = [
        'set', 'type', 'enter', 'sleep', 'wait', 'output', 'hide', 'show',
        'env', 'ctrl+', 'ctrl', 'alt+', 'alt', 'shift+', 'shift',
        'up', 'down', 'left', 'right',
        'space', 'tab', 'backspace', 'escape', 'esc',
        'screenshot', 'screen', 'require', 'source',
    ];

    /**
     * Load a cassette from disk, sniffing the format automatically.
     */
    public function load(string $path): Cassette
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException("candy-vcr: cassette/tape not found: {$path}");
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match (true) {
            $ext === 'tape' => $this->loadTape($path),
            $ext === 'cast' => (new AsciinemaFormat())->read($path),
            $ext === 'yaml' || $ext === 'yml' => (new YamlFormat())->read($path),
            $ext === 'gz' => (new CompressedJsonlFormat())->read($path),
            $ext === 'cas' || $ext === 'jsonl' || $ext === 'cassette' => $this->loadJsonl($path),
            default => $this->sniffAndLoad($path),
        };
    }

    /**
     * Return true when the given path resolves to a tape source rather than
     * a cassette. Mirrors the sniff that {@see load()} performs but exposed
     * so callers can pick the right downstream pipeline (e.g. dry-run mode).
     */
    public function isTape(string $path): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'tape') {
            return true;
        }
        if (in_array($ext, ['cas', 'jsonl', 'cassette', 'cast', 'yaml', 'yml', 'gz'], true)) {
            return false;
        }
        return $this->sniffIsTape($path);
    }

    private function loadTape(string $path): Cassette
    {
        $source = @file_get_contents($path);
        if ($source === false) {
            throw new \RuntimeException("candy-vcr: cannot read tape file: {$path}");
        }
        $tokens = (new Lexer())->tokenize($source);
        $ast = (new Parser())->parse($tokens);
        return (new Compiler())->compile($ast, $path);
    }

    /**
     * Pick between {@see JsonlFormat} and {@see RelativeFormat} by sniffing
     * whether events use `t` (absolute) or `dt` (relative). Header is on
     * the first line; the first event line decides.
     */
    private function loadJsonl(string $path): Cassette
    {
        $first = $this->firstEventLine($path);
        if ($first !== null && str_contains($first, '"dt"')) {
            return (new RelativeFormat())->read($path);
        }
        return (new JsonlFormat())->read($path);
    }

    private function sniffAndLoad(string $path): Cassette
    {
        if ($this->sniffIsTape($path)) {
            return $this->loadTape($path);
        }
        $first = $this->firstNonBlankLine($path);
        if ($first !== null && str_starts_with(ltrim($first), '{')) {
            return $this->loadJsonl($path);
        }
        throw new \InvalidArgumentException(
            "candy-vcr: cannot detect cassette format from extension or content: {$path}",
        );
    }

    private function sniffIsTape(string $path): bool
    {
        $line = $this->firstNonBlankLine($path);
        if ($line === null) {
            return false;
        }
        $trimmed = ltrim($line);
        if ($trimmed === '' || $trimmed[0] === '{' || $trimmed[0] === '[') {
            return false;
        }
        // First word, case-insensitive — accept `Set`, `Type`, `Ctrl+C`, etc.
        if (!preg_match('/^([A-Za-z][A-Za-z0-9+\-]*)/', $trimmed, $m)) {
            return false;
        }
        $head = strtolower($m[1]);
        return in_array($head, self::TAPE_DIRECTIVE_HEADS, true);
    }

    /**
     * Peek the first non-blank, non-comment (`#`) line of the file.
     */
    private function firstNonBlankLine(string $path): ?string
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return null;
        }
        try {
            while (($line = fgets($fh)) !== false) {
                $trimmed = trim($line);
                if ($trimmed === '' || $trimmed[0] === '#') {
                    continue;
                }
                return $line;
            }
        } finally {
            fclose($fh);
        }
        return null;
    }

    /**
     * Peek the first non-blank line PAST the header (line 2 for JSONL).
     */
    private function firstEventLine(string $path): ?string
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return null;
        }
        try {
            $sawHeader = false;
            while (($line = fgets($fh)) !== false) {
                if (trim($line) === '') {
                    continue;
                }
                if (!$sawHeader) {
                    $sawHeader = true;
                    continue;
                }
                return $line;
            }
        } finally {
            fclose($fh);
        }
        return null;
    }
}
