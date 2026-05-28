# Step 20 — Replace byte-loop ANSI parsers with candy-ansi

**Branch:** `ai/ansi-consumers`
**Depends on:** step-01 (candy-ansi), step-12 (candy-vt aliased — so no two-truth situation)
**Blocks:** —

## Goal

Replace the three known byte-loop ANSI parsers in `sugar-spark/src/Inspector.php` (551 lines), `candy-hermit/src/Hermit.php::highlightMatches()` (byte indexing on UTF-8), and `candy-freeze/src/AnsiParser.php` with calls into `candy-ansi`'s state machine. The 551-line Inspector shrinks dramatically; UTF-8 / grapheme bugs in candy-hermit go away.

Reference: §387.2 (reinvention), §15.2 (UTF-8 / grapheme breakage).

## Files expected to be modified

- `sugar-spark/composer.json`, `candy-hermit/composer.json`, `candy-freeze/composer.json` — add `sugarcraft/candy-ansi` via `path-repo-closure`.
- `sugar-spark/src/Inspector.php` — replace byte loop with a custom `Handler` registered against `\SugarCraft\Ansi\Parser\Parser`.
- `candy-hermit/src/Hermit.php` — `highlightMatches()` uses candy-ansi to walk the SGR-aware content (don't index bytes; use the parser's character events).
- `candy-freeze/src/AnsiParser.php` — refactor to delegate to candy-ansi's parser; if AnsiParser was specifically a custom domain extension, keep the domain logic and only replace the byte loop.

## Acceptance criteria

- [ ] sugar-spark Inspector replaces its byte loop; LoC drop noticeable (target ≥40 % reduction in Inspector.php).
- [ ] candy-hermit's UTF-8 / grapheme bugs in `highlightMatches()` fixed (regression tests with CJK + emoji content pass).
- [ ] candy-freeze still produces identical SVG/PNG output for existing fixtures (byte-snapshot).
- [ ] All three lib's existing tests pass.
- [ ] ≥95 % coverage maintained.
- [ ] `git status` clean on master.

## Coder brief

1. **path-repo closure**: add candy-ansi to the 3 libs.
2. **sugar-spark Inspector**: rewrite as a candy-ansi consumer. Build a `Handler` implementation that records the events the Inspector exposes; Parser drives it.
3. **candy-hermit highlightMatches**: parse the haystack via candy-ansi events, accumulate character positions (not byte positions), then apply highlighting at character boundaries. Add regression tests with CJK + emoji + ZWJ.
4. **candy-freeze AnsiParser**: keep the public AnsiParser class as a facade; internally use candy-ansi. SVG/PNG rendering paths consume the same internal AST candy-freeze already builds — just the parsing entry point changes.
5. Run phpunit in each touched lib + every dependent via `affected-libs.php`.

## Tester brief

- sugar-spark Inspector: every existing test green; add 1 test exercising a complex SGR + OSC hyperlink sequence to confirm the new code path.
- candy-hermit: tests with byte-indexed highlights of CJK strings now produce correct character-indexed highlights. Specifically: `highlightMatches('中文 match here', 'match')` should highlight only the byte-correct "match" without slicing through the multi-byte CJK chars.
- candy-freeze: byte-snapshot SVG output for the existing fixture corpus.

## Scribe brief

- Each affected lib's README: short note in `## Shared foundations` mentioning candy-ansi.
- CALIBER_LEARNINGS in each: "Don't byte-index UTF-8 content — go through candy-ansi's parser or candy-core's Width utility."

## Ship brief

- **PR title**: `sugar-spark + candy-hermit + candy-freeze: parse via candy-ansi`
- **PR body**:
  ```
  ## Summary
  - sugar-spark Inspector.php shrinks substantially by delegating to candy-ansi's state machine.
  - candy-hermit::highlightMatches no longer byte-indexes UTF-8; CJK / emoji highlighting now correct.
  - candy-freeze AnsiParser becomes a facade over candy-ansi; SVG/PNG output unchanged.
  - All three libs' existing tests pass; regression tests added for the previously broken UTF-8 cases.

  ## Test plan
  - [x] vendor/bin/phpunit in sugar-spark / candy-hermit / candy-freeze (≥95% each)
  - [x] vendor/bin/phpunit in every dependent (affected-libs.php)
  - [x] php tools/check-path-repos.php

  Refs: docs/repo_map_step_20.md, docs/repo_map_update.md §387.2, §15.2
  ```
- Commit subject: `sugar-spark + candy-hermit + candy-freeze: parse via candy-ansi`.
