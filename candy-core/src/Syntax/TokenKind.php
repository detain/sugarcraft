<?php

declare(strict_types=1);

namespace SugarCraft\Core\Syntax;

/**
 * Lexical class a {@see TokenSpan} belongs to.
 *
 * A tokeniser classifies each span of source into exactly one of these.
 * {@see self::Plain} is the catch-all for gap/unclassified text between
 * recognised tokens, so a full-coverage span list (see {@see Highlighter})
 * always has a kind for every byte of the input.
 *
 * The `Keyword`/`Comment`/`Number` names mirror the classes a consumer
 * styles; `StringToken` avoids the PHP reserved word `String`.
 */
enum TokenKind
{
    case Keyword;
    case StringToken;
    case Number;
    case Comment;
    case Plain;
}
