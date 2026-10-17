# Step 20 — Replace byte-loop ANSI parsers with sugar-ansi-parser

**Branch:** `ai/ansi-consumers`
**Depends on:** step-01 (sugar-ansi-parser), step-12 (sugar-vterm aliased — so no two-truth situation)
**Blocks:** —

## Goal

Replace the three known byte-loop ANSI parsers in `candy-ansi-inspect/src/Inspector.php` (551 lines), `sugar-quickpick/src/Hermit.php::highlightMatches()` (byte indexing on UTF-8), and `candy-codeshot/src/AnsiParser.php` with calls into `sugar-ansi-parser`'s state machine. The 551-line Inspector shrinks dramatically; UTF-8 / grapheme bugs in sugar-quickpick go away.

Reference: §387.2 (reinvention), §15.2 (UTF-8 / grapheme breakage).

## Files expected to be modified

- `candy-ansi-inspect/composer.json`, `sugar-quickpick/composer.json`, `candy-codeshot/composer.json` — add `sugarcraft/sugar-ansi-parser` via `path-repo-closure`.
- `candy-ansi-inspect/src/Inspector.php` — replace byte loop with a custom `Handler` registered against `\SugarCraft\AnsiParser\Parser\Parser`.
- `sugar-quickpick/src/Hermit.php` — `highlightMatches()` uses sugar-ansi-parser to walk the SGR-aware content (don't index bytes; use the parser's character events).
- `candy-codeshot/src/AnsiParser.php` — refactor to delegate to sugar-ansi-parser's parser; if AnsiParser was specifically a custom domain extension, keep the domain logic and only replace the byte loop.

## Acceptance criteria

- [ ] candy-ansi-inspect Inspector replaces its byte loop; LoC drop noticeable (target ≥40 % reduction in Inspector.php).
- [ ] sugar-quickpick's UTF-8 / grapheme bugs in `highlightMatches()` fixed (regression tests with CJK + emoji content pass).
- [ ] candy-codeshot still produces identical SVG/PNG output for existing fixtures (byte-snapshot).
- [ ] All three lib's existing tests pass.
- [ ] ≥95 % coverage maintained.
- [ ] `git status` clean on master.

## Coder brief

1. **path-repo closure**: add sugar-ansi-parser to the 3 libs.
2. **candy-ansi-inspect Inspector**: rewrite as a sugar-ansi-parser consumer. Build a `Handler` implementation that records the events the Inspector exposes; Parser drives it.
3. **sugar-quickpick highlightMatches**: parse the haystack via sugar-ansi-parser events, accumulate character positions (not byte positions), then apply highlighting at character boundaries. Add regression tests with CJK + emoji + ZWJ.
4. **candy-codeshot AnsiParser**: keep the public AnsiParser class as a facade; internally use sugar-ansi-parser. SVG/PNG rendering paths consume the same internal AST candy-codeshot already builds — just the parsing entry point changes.
5. Run phpunit in each touched lib + every dependent via `affected-libs.php`.

## Tester brief

- candy-ansi-inspect Inspector: every existing test green; add 1 test exercising a complex SGR + OSC hyperlink sequence to confirm the new code path.
- sugar-quickpick: tests with byte-indexed highlights of CJK strings now produce correct character-indexed highlights. Specifically: `highlightMatches('中文 match here', 'match')` should highlight only the byte-correct "match" without slicing through the multi-byte CJK chars.
- candy-codeshot: byte-snapshot SVG output for the existing fixture corpus.

## Scribe brief

- Each affected lib's README: short note in `## Shared foundations` mentioning sugar-ansi-parser.
- CALIBER_LEARNINGS in each: "Don't byte-index UTF-8 content — go through sugar-ansi-parser's parser or candy-core's Width utility."

## Ship brief

- **PR title**: `candy-ansi-inspect + sugar-quickpick + candy-codeshot: parse via sugar-ansi-parser`
- **PR body**:
  ```
  ## Summary
  - candy-ansi-inspect Inspector.php shrinks substantially by delegating to sugar-ansi-parser's state machine.
  - sugar-quickpick::highlightMatches no longer byte-indexes UTF-8; CJK / emoji highlighting now correct.
  - candy-codeshot AnsiParser becomes a facade over sugar-ansi-parser; SVG/PNG output unchanged.
  - All three libs' existing tests pass; regression tests added for the previously broken UTF-8 cases.

  ## Test plan
  - [x] vendor/bin/phpunit in candy-ansi-inspect / sugar-quickpick / candy-codeshot (≥95% each)
  - [x] vendor/bin/phpunit in every dependent (affected-libs.php)
  - [x] php tools/check-path-repos.php

  Refs: docs/repo_map_step_20.md, docs/repo_map_update.md §387.2, §15.2
  ```
- Commit subject: `candy-ansi-inspect + sugar-quickpick + candy-codeshot: parse via sugar-ansi-parser`.
