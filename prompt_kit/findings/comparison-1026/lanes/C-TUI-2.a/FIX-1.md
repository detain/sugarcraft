# C-TUI-2.a FIX-1 — round 1 fixes

Amended d73f06c61 → **e746d3012** (single commit, same 5-file footprint, message unchanged).
Author/committer both detain@interserver.net (separate %ae/%ce greps), zero Co-Authored/Co-developed trailers. Never pushed.

## Finding 1 (MINOR) — misplaced 1 MB-cap docblock — FIXED
`sugar-markdown/tests/SyntaxHighlighterTest.php`: moved the docblock verbatim from above
`testRustHighlightsKeywordsStringsAndComments` to immediately precede
`testOversizedInputSkipsHighlightingAndFallsBack` (:882). Verified in the committed blob via
`git show HEAD:... | grep -n`: rust test at :813 carries no docblock; "1 MB cap" prose at :865,
oversized test at :882. Zero code/test-content change (pure comment move).

## Finding 2 (NIT) — ruby backtick positive leg — FIXED
`sugar-core/tests/Syntax/RegexHighlighterTest.php`: added
`self::assertContains([TokenKind::StringToken, '`y`'], $ruby, ...)` for `x = \`y\`` under 'ruby'
inside the existing pin, and renamed `testRustBacktickIsNotAStringDelimiter` →
`testBacktickStringRuleIsSetPerLanguage` so the name covers both polarities (old name had zero
references tree-wide, grep-verified). Kept in ONE method (not a new test) so the filter shape
stays 80 tests, +1 assertion leg, per the brief's expectation.

## Gates (plain pipe, env -u LINES -u COLUMNS)
- sugar-markdown `--filter SyntaxHighlighterTest`: **OK (81 tests, 134 assertions)** — exact expected shape.
- sugar-core `--filter 'RegexHighlighter|Highlight'`: **OK (80 tests, 165 assertions)** — 80T held, 164→165A (+1 leg).

## Mutation proof (finding 2)
Flipped `'ruby' => [... 'backtick' => true]` → `false` in src: filter run →
**Failures: 1 = testBacktickStringRuleIsSetPerLanguage exactly** (Tests: 80, Assertions: 165).
Restored from /tmp/opencode/cc-cmp-C-TUI-2a-fix1/RegexHighlighter.php.orig, `cmp` clean, re-run
OK 80T/165A. Pre-edit copies of both test files kept in the same dir.
