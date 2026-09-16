<?php

declare(strict_types=1);

namespace SugarCraft\Stash;

use SugarCraft\Core\Syntax\RegexHighlighter;
use SugarCraft\Core\Syntax\TokenKind;
use SugarCraft\Core\Syntax\TokenSpan;

/**
 * Parse-side of the diff-view syntax highlighting (leftover-rollout step 10.06
 * Phase 4, "syntax highlighting in diff view").
 *
 * The 780920291 Phase-4 commit shipped the four rebase/stash/cherry-pick/
 * worktree surfaces with inline whole-line ANSI and explicitly deferred the
 * syntax pass ("sugar-glow integration deferred"). Step 10.24 has since
 * landed the highlighting pipeline; its reusable surface is candy-core's
 * pure {@see RegexHighlighter} lexer — the same primitive sugar-glow /
 * candy-shine render through (via Shine\SyntaxHighlighter's Theme slots) and
 * candy-freeze consumes directly (Freeze\CodeHighlighter). sugar-glow itself
 * exposes no embeddable highlight surface (it is the markdown pager CLI), so
 * sugar-stash follows the candy-freeze precedent: consume the shared lexer,
 * keep presentation in {@see Renderer}.
 *
 * This class carries NO colour/ANSI knowledge — it answers "which language
 * is this file" and "what tokens does this diff line contain". It never
 * invents tokens: when nothing would be accented it returns null so the
 * caller renders the line exactly as before (opt-in guarantee — unknown
 * languages round-trip byte-for-byte through the plain path).
 */
final class DiffHighlighter
{
    /**
     * Extension (lowercase, no dot) → RegexHighlighter language id.
     * Mirrors exactly the recognised set + aliases of the shared lexer
     * (php, js, ts, python, go, bash, sql, json) — no invented languages.
     *
     * @var array<string, string>
     */
    private const LANGUAGE_BY_EXTENSION = [
        'php'   => 'php',
        'js'    => 'js',
        'mjs'   => 'js',
        'cjs'   => 'js',
        'jsx'   => 'js',
        'ts'    => 'ts',
        'tsx'   => 'ts',
        'py'    => 'python',
        'go'    => 'go',
        'sh'    => 'bash',
        'bash'  => 'bash',
        'zsh'   => 'bash',
        'json'  => 'json',
        'jsonc' => 'json',
        'sql'   => 'sql',
    ];

    /**
     * Diff-metadata line prefixes that are never source content, however
     * they start ('--- '/'+++ ' shadow deleted/added body lines by design —
     * those exact forms are git's file headers, and body text is only ever
     * accented when it follows a single-prefix +/-/space).
     */
    private const HEADER_PREFIXES = [
        'diff ',
        'index ',
        '--- ',
        '+++ ',
        '@@',
        '\\',
        'old mode',
        'new mode',
        'new file',
        'deleted file',
        'similarity index',
        'rename from',
        'rename to',
        'copy from',
        'copy to',
        'binary files',
    ];

    private function __construct()
    {
    }

    /**
     * Language id for a repo-relative file path, '' when unrecognised.
     *
     * 'composer.json' resolves through its .json extension; dotfiles such as
     * '.gitignore' carry no usable extension and answer '' (plain render).
     */
    public static function languageForPath(string $path): string
    {
        $basename = basename($path);
        $dot = strrpos($basename, '.');
        if ($dot === false || $dot === 0) {
            return '';
        }
        $extension = strtolower(substr($basename, $dot + 1));

        return self::LANGUAGE_BY_EXTENSION[$extension] ?? '';
    }

    /**
     * Tokenise the CONTENT of one diff body line, or null when the line
     * must render exactly as the plain whole-line path does.
     *
     * Null is returned for: no language, header/metadata lines, the hunk
     * context marker with no payload, and body lines whose content holds no
     * accentable token (an all-Plain tokenisation is presentation-identical
     * to no tokenisation, so the caller keeps its single SGR wrap).
     *
     * @return list<TokenSpan>|null Span texts concatenate back to the line
     *                              content byte-for-byte (lexer contract).
     */
    public static function tokenizeLine(string $line, string $language): ?array
    {
        if ($language === '') {
            return null;
        }
        if ($line === '') {
            return null;
        }
        foreach (self::HEADER_PREFIXES as $header) {
            if (str_starts_with($line, $header) === TRUE) {
                return null;
            }
        }

        $marker = $line[0];
        if ($marker !== '+' && $marker !== '-' && $marker !== ' ') {
            return null;
        }

        $content = substr($line, 1);
        if ($content === '') {
            return null;
        }

        $spans = (new RegexHighlighter())->tokenize($content, $language);
        foreach ($spans as $span) {
            if ($span->kind !== TokenKind::Plain) {
                return $spans;
            }
        }

        return null;
    }
}
