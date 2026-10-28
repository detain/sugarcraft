# C-SEC-8.c — DONE

Tip: afe27bcc29233e0d896c1b916da24c4b22b3e020 on cc/C-SEC-8.c (base e4a705244). 2 files, +267, zero foreign content, %ae/%ce=detain (separate greps), zero trailers.

## What landed
- `sugar-core/src/Util/Sanitize.php`: `stripInvisibleFormatting(string, ?int &$removed=null)` + private `invisibleFormattingStripPattern()` reusing `invisibleFormattingMaps()` — strip and mark can never disagree. Two tiers as briefed; joiner right after a removed always-tier char goes too (matches marker-after-`>` rule); byte-level (no /u); prefix-monotonic; fail-closed on regex refusal.
- NEW test `sugar-core/tests/Util/SanitizeStripInvisibleFormattingTest.php` (35T/2187A): always tier per-cp removed, ZWJ in 👩‍💻 kept, pay‍pal stripped, counts, empty input, invalid-UTF8 keeps strip on, 1000-case strip≡mark(→flip) agreement battery, prefix test, no-out-param call. Red-before verified (35 errors pre-impl).

## Gates (plain pipe, env -u LINES -u COLUMNS, cwd sugar-core)
- New file: OK 35 tests, 2187 assertions.
- `--filter Sanitize`: OK 158 tests, 2616 assertions.
- Full sugar-core suite: 1528T/31351A/1F — the 1F is DescriptorSinkArgumentCensus `candy-code/src/App/App.php::close($key)` [VARIABLE] roster row: pre-existing standing red, fails identically on main tree 18b0a520f, zero candy-code files in my diff.
- php -l both files clean.
- Mutations 3/3 discriminating (neuter ASCII lookbehind → 12F; drop always leg from joiner lookbehind → 2F incl battery; remove lookbehind guard → 12F), restores md5-verified 1fcd607d…

## NEW test files (gate duty)
- sugar-core/tests/Util/SanitizeStripInvisibleFormattingTest.php — sugar-core lib-local; durations TSV is candy-code-only, NO durations row owed; no suite-figure touch.

## Deviations / notes
- C-SEC-8.a/.b evidence dirs not on disk (none landed yet at cut) — .c declares no deps, built standalone per brief.
- sugar-core/vendor was absent in worktree → ran `scripts/refresh-deps.php --mode=linked --libs=sugar-core` (plan §1.2).
- PCRE2 law learned: top-level lookbehind alternation must stay FLAT fixed-length literals (grouped branches → "not fixed length"); noted in code comment; sugar-core/CALIBER_LEARNINGS.md is outside declared files — gate collects if owner wants.
- Never pushed, never stashed.
