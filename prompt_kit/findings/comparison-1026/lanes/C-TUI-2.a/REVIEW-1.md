# C-TUI-2.a REVIEW-1 — round 1 (read-only reviewer)

Branch `cc/C-TUI-2.a` tip `d73f06c61` — single master..branch commit, porcelain clean, author/committer Joe Huss <detain@interserver.net>, zero trailers. Diff = exactly the 5 declared files (sugar-core src+test, sugar-markdown src+README+test); no candy-code/src, no suite-figure.json, no durations.tsv, no dist.

## Phantom / scope — PASS
- All eight new KEYWORDS tables (rust/java/c/cpp/ruby/yaml/html/css), the eleven new ALIASES, RULES/DEFAULT_RULES/EXTENSIONS consts and `languageForPath()` verified present in the committed blob (git show, read on disk).
- RULES keys ⊆ KEYWORDS keys; EXTENSIONS values ⊆ KEYWORDS ∪ {json}; every mapped id re-recognised by tokenize (data provider asserts the string leg for every language).
- docs/_data/sugar-core.* and sugar-markdown.* carry ZERO RegexHighlighter/language prose (grep -c = 0) → no gen-docs regen owed; sugar-markdown README carries no suite figures. No stale "seven languages" prose anywhere in core README/site data.

## Legacy byte-identity — PROVEN EMPIRICALLY
Eval probe loading master's file under a renamed namespace vs HEAD reflection: `buildPattern(list, DEFAULT_RULES)` output BYTE-IDENTICAL to master's `buildPattern(list)` for all 7 legacy languages AND the json special list. Behavior log through HEAD tokenize: all 8 legacy ids still comment `//`, `#`, `/* */`, `<!-- -->`, `'`/`"`/`` ` `` strings and numbers — unchanged all-markers semantics. `??  DEFAULT_RULES` routing leaves php/js/ts/python/go/bash/sql/json off the RULES table. Cache key now includes rules → no cross-language pattern collision.

## languageForPath edges — PASS (measured)
`.bashrc`→ext 'bashrc'→null, `.env`→null, `''`→null, `archive.tar.gz`→'gz'→null, `x.`→''→null, `FILE.RS`→rust (strtolower), `a.JSON`→json, `dir/.hidden.rs`→rust, `src/main.rs`→rust. All match the pinned provider; 40-row path table incl. jsonc→json.

## Keyword-data spot check — PASS
rust: fn/mut/impl/unsafe present; ruby: def/end/require/require_relative; yaml: true/false/null (+1.1 yes/no/on/off, disclosed); c/c++/java standard sets plausible; html deliberately omits single-letter tags (rationale in code comment); css includes hyphenated properties (match under \b since `-` is a word boundary — hyphenated legs match their segments; cosmetic, no correctness issue).

## Non-vacuity
- M1 cure pin VERIFIED: `testDoubleSlashLanguagesAllProduceLineComments` positively asserts `'// trailing'` Comment for rust, java, c, cpp (4/4 as claimed). Re-reasoned: neutering any line-marker → assertContains fails for that language — discriminating.
- M2 drop-closing-quote: string branch requires terminal quote; providers assert exact closed string spans per language, so swallowing past the quote breaks the exact pairs — red-many plausible. Accepted by reasoning (mutation exec is builder-side; probe evidence: unterminated-string coverage pin exists).
- Negative pins strong: rust `#[derive]` no-comment pin, css `#fff` exactly-one-comment pin, html `// x` stays plain pin, rust backtick-not-string pin. Timing pins (20k adversarial ×2, <1.0 s) address the brief's backtracking risk; every repetition is unrolled/disjoint (no nested quantifier — verified by reading each generated branch).
- Gap (NIT below): ruby backtick=TRUE has no positive pin (only the rust negative); flipping it to false keeps the suite green.

## Gates reproduced (plain pipe, env -u LINES -u COLUMNS)
- sugar-core `--filter 'RegexHighlighter|Highlight'`: **OK (80 tests, 164 assertions)** — exact.
- sugar-markdown `--filter 'SyntaxHighlight'`: **OK (81 tests, 134 assertions)** — exact.
- candy-code `--filter 'Highlight|Syntax'`: **Tests: 62, Assertions: 605** (+2 PHPUnit deprecations — structurally pre-existing, lane touches zero candy-code files).

## Findings
1. MINOR (step-local) — `sugar-markdown/tests/SyntaxHighlighterTest.php:814-831`: the 1 MB-cap docblock was cut incorrectly — it now sits above `testRustHighlightsKeywordsStringsAndComments` (:831) while `testOversizedInputSkipsHighlightingAndFallsBack` (:882) lost it. Cure: move the docblock (:814-:830, `/** … near-instant" bound flaky rather than meaningful. */`) down to immediately precede :882.
2. NIT (step-local) — ruby backtick rule un-pinned on its positive side: `testRustBacktickIsNotAStringDelimiter` proves rust excludes backticks but nothing proves ruby includes them. Cure: add `assertContains([TokenKind::StringToken, "`y`"], …)` leg for 'ruby' (core test file) or extend the ruby provider row.

## Verdict rationale
No CRITICAL/MAJOR. Library laws hold (strict_types, final, one type/file, bare accessors, pure data — E646 n/a). Both findings are test-file hygiene; neither affects shipped behavior, doc truth, or the byte-freeze.

VERDICT: FINDINGS
- MINOR step-local sugar-markdown/tests/SyntaxHighlighterTest.php:814 misplaced 1MB-cap docblock (moves to :882)
- NIT step-local sugar-core/tests/Syntax/RegexHighlighterTest.php:~290 ruby backtick positive leg un-pinned
