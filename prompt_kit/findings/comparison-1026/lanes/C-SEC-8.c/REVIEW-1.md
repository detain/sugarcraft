# REVIEW-1 — C-SEC-8.c (Sanitize::stripInvisibleFormatting) @ afe27bcc2

VERDICT: CLEAN (0 CRITICAL / 0 MAJOR / 0 MINOR step-local; 2 closeout NITs below)

## Phantom-first — PASS
- Branch `cc/C-SEC-8.c`; `git log master..HEAD` = exactly 1 commit afe27bcc2 (parent e4a705244, which is the merge-base with the current worktree master 8fd05587a; spawn's 18b0a520f is also an ancestor of master — base honest).
- `cat-file -t` = commit. Worktree porcelain = 0. `%ae`/`%ce` both `detain@interserver.net`, `%an`/`%cn` Joe Huss; zero Co-authored/signed-off trailers (grep count 0).
- Diff vs declared files: EXACTLY the two — `sugar-core/src/Util/Sanitize.php` (+87, purely additive) and NEW `sugar-core/tests/Util/SanitizeStripInvisibleFormattingTest.php` (+180). No third file, no deletions.

## Traceability to BRIEF/design-sec.md — PASS
- Signature `stripInvisibleFormatting(string $s, ?int &$removed = null)` verbatim per spec; reuses `invisibleFormattingMaps()` (same static funnel as the marker — verified by reading both: pattern built from `preg_quote`d map keys at runtime, no duplicated table). Deps: none honored — standalone build correct (8.a/.b land in W24/W27 per plan §2, not at this cut). §6 rulings: none for this item.
- All briefed test points covered: always tier per-cp removed (12-row provider), ZWJ in 👩‍💻 kept, `pay\u{200D}pal` stripped, count, empty input.

## Semantics — hand-traced, PASS
- Tier agreement with the marker path: the mark path strtrs always-chars to ASCII then applies `(?:(?<![\x80-\xFF])|(?<=JOINER-BYTES))` to joiners; strip applies a single byte-level pass over raw bytes with `(?:(?<![\x80-\xFF])|(?<=ALWAYS|JOINER))`. The ALWAYS lookbehind leg is exactly the compensation for the missing `>`-of-marker context; every adjacency case (joiner at start, after ASCII, after always-char, mid-joiner-run with first surviving, after non-ASCII letter) resolves identically on both paths. Prefix-monotonic (backward-only rule) — pinned by test :165.
- Fail-closed: `preg` null → remove always-then-joiners via str_replace (superset of policy = fail-closed, mirrors mark's mark-all fallback). Byte-level, no `/u` — consistent with the class law; malformed input keeps the conditional answer "no" (test :117 with a raw 0xFF byte).
- PUA/zone safety: neither tier contains U+E000/E001/E002+ (maps are U+200B/202A–E/2060/2066–9/FEFF + U+061C/200C–F); none of their byte spellings overlap `\xEE\x80..` — strip never eats ImageOverlay markers or zone sentinels. BRIEF never proposed touching them; correct to keep out.
- PCRE2 flat fixed-length lookbehind alternation law (mixed 2/3-byte branches allowed as a FLAT alternation, grouped variable-length rejected) is honestly documented in the code comment.

## Gates — reproduced plain-pipe, env -u LINES -u COLUMNS, cwd worktree sugar-core
- New file: `OK (35 tests, 2187 assertions)` — builder figure EXACT.
- `--filter Sanitize`: `OK (158 tests, 2616 assertions)` — EXACT.
- Full sugar-core: `Tests: 1528, Assertions: 31351, Failures: 1, Skipped: 29` — EXACT. The 1F is DescriptorSinkArgumentCensus naming `candy-code/src/App/App.php::->close($key) [VARIABLE]`: the guard scans candy-code files, the lane diff touches zero candy-code files, so its HEAD verdict equals its parent-e4a705244 verdict — pre-existing standing red, builder's main-tree claim accepted by equivalence (main tree not disturbed).
- No candy-code durations row owed (lib-local test file; tsv is candy-code-only) ✓; suite-figure.json untouched ✓; generated/protocol surfaces untouched ✓.

## Tests quality — PASS
- The 1000-case strip≡mark(strtr-flip) battery with count equality is a genuine behavioral pin of "shared maps can't drift"; not vacuous (mutation reasoning below reddens it).
- Red-before is structurally sound: the method did not exist at base (additive diff), so all 35 tests error pre-impl as claimed.
- Glob-shaped/`Refused:`-shaped fixture literals: none.

## Mutations
- Execution blocked by the reviewer permission map (shell `cp`/`mkdir` and repo `edit` all denied) — verified the load-bearing one statically instead: dropping `(?<=ALWAYS` from the lookbehind leaves `é\u{200B}\u{200D}f` keeping the joiner (preceding byte \x8B is neither ASCII nor a joiner spelling) while mark marks it → test :84 direct pin AND the battery both fail; builder's "2F incl battery" is sound by construction. The ASCII-leg and guard mutations hit multiple direct pins (paypal ×5 dataset, start-of-text, kept-emoji ×5) — 12F figures plausible; one-flip discrimination of the central claim independently confirmed.

## Conventions — PASS
strict_types first line both files; final class; one type per file; docblocks explain WHY with the audit-15b-28 lineage and the sibling-method cross-references; no Mirrors citation (correctly absent per brief: SugarCraft original); no Lang::t needed (no user-facing strings).

## Deviations adjudicated
- 8.a/.b absent at cut: correct (deps: none; waves schedule them W24/W27).
- refresh-deps --libs=sugar-core: legitimate fresh-worktree vendor bootstrap per plan §1.2.
- CALIBER_LEARNINGS row (PCRE2 lookbehind law) reported as seam, not edited: exactly right — outside declared files.

## Closeout NITs (out-of-scope, for weld/docs lane)
- NIT-1: candy-code/COMPARISON.md:4530 cites `Sanitize.php:320-380`; the additive insert at ~:378 makes the range tail stale, and the cell should mention the strip twin when 8.d consumes it. Docs-lane duty.
- NIT-2: builder's REPORT gate line omits `Skipped: 29` in the summary figure (present in its own log) — cosmetic.
