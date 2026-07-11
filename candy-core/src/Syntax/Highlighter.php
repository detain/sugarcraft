<?php

declare(strict_types=1);

namespace SugarCraft\Core\Syntax;

/**
 * A pure, style-agnostic source tokeniser.
 *
 * Implementations split source into an ordered, gap-free list of
 * {@see TokenSpan}s: every byte of the input belongs to exactly one span
 * (recognised tokens plus {@see TokenKind::Plain} runs), so concatenating
 * every span's text reproduces the input exactly. No colour, ANSI, or theme
 * concerns live here — a renderer decides how to present each kind.
 *
 * The default implementation is {@see RegexHighlighter} (a small, dependency
 * free regex lexer). A heavier backend — e.g. a `chroma`/Pygments-style
 * grammar engine — could implement this same contract later without changing
 * any caller; this interface is the seam that keeps that swap invisible.
 */
interface Highlighter
{
    /**
     * Tokenise $code as $language into a full-coverage span list.
     *
     * @param string $language Language id or alias (case-insensitive); an
     *                         unknown/empty language yields a single
     *                         {@see TokenKind::Plain} span over all of $code.
     *
     * @return list<TokenSpan>
     */
    public function tokenize(string $code, string $language): array;
}
