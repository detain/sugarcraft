# C-TUI-2.a — Highlighter language breadth + path→language
area: tui · kind: gap · effort M · phase: normal
deps: none
serialized: none
ruling: none
risks: the `MAX_TOKENIZE_BYTES` budget is unchanged; the new regexes must stay linear (no catastrophic backtracking on HTML).

## Files
- sugar-core/src/Syntax/RegexHighlighter.php
- sugar-core/tests/Syntax/RegexHighlighterTest.php
- sugar-markdown/README.md
- sugar-markdown/src/SyntaxHighlighter.php
- sugar-markdown/tests/SyntaxHighlighterTest.php

## files.create
none

## files.modify
`sugar-core/src/Syntax/RegexHighlighter.php` (KEYWORDS, ALIASES, comment rules, `languageForPath`); `sugar-markdown/src/SyntaxHighlighter.php` (docblock roster); `sugar-markdown/README.md` (L174)

## tests

- Modify `sugar-core/tests/Syntax/RegexHighlighterTest.php`: each new language tokenizes a keyword, a string and a comment; the `languageForPath` table.
- Modify `sugar-markdown/tests/SyntaxHighlighterTest.php`: a rust fence is highlighted.

## docs/guards
sugar-core and sugar-markdown suites; `sugar-markdown` ReadmeSnippetsTest

## Approach
RegexHighlighter ships seven keyword sets: php, js, ts, python, go, bash and sql (`sugar-core/src/Syntax/RegexHighlighter.php:42-96`).
- Add rust, java, c/cpp, ruby, yaml and html/css, with aliases (`rs`, `cc`/`h`/`hpp`, `rb`, `yml`) and per-language comment and string rules.
- Add a pure `RegexHighlighter::languageForPath(string $path): ?string` extension map. C-TUI-2.c uses it to highlight diff bodies.
- sugar-markdown's fence highlighting picks the new languages up through the shared tokenizer. Update its docblock and README language list (`sugar-markdown/README.md:174`).
What the user sees: rust, java, C, ruby and YAML fences are highlighted.
