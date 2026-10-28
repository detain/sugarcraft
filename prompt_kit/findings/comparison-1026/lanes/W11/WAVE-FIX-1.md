# W11 wave-fix 1 (wave-loop fixer, direct on master)

MINOR-1 CURED — `chat.attach.pdf_pages` landed.

- `candy-code/src/Attachments/FileMentions.php`: the over-limit PAGES refusal (:335-342 block,
  C-CTX-6.b) now routes through `Lang::t('chat.attach.pdf_pages', ['path'=>…, 'pages'=>…, 'limit'=>…])`
  (`use SugarCraft\Code\Lang;` added). Message bytes identical to the retired sprintf for the same
  inputs (en.php value carries the exact sentence with `{path}/{pages}/{limit}`; sugar-core
  `T::format` casts params and substitutes — int `%d` legs ride `(string)` cast, same output).
- `candy-code/lang/en.php`: key minted beside the `chat.attach.document_withheld` sibling,
  en-ONLY per the stamped §6.3 ruling — the 11 community locale files land whole-keyset at
  C-TUI-6.h; no other locale file touched.
- Guards: `FileMentionsTest::testAPdfWhoseEstimatedPagesPassTheCeilingIsRefused` pins the exact
  message — stays green byte-untouched (no in-step flip needed); DocFigureProseDrift's
  `was not attached:` refusal-count arm reads `ContextMentions::mcp()` only (:7125), unaffected.
- Gates (cwd candy-code, plain pipe, env -u LINES -u COLUMNS, PtyRunToolTest never in filter):
  `--filter FileMentions` 17T/61A OK; `--filter LangParity|GlobDialect|DocFigureProseDrift`
  112T/7,190A OK (review reproduce was 112T/7,189A — +1 assertion is the new key's placeholder
  arm, expected); `--filter MediaDocs|MediaStore` 27T/97A OK. The 2 PHPUnit deprecations in
  every run are environmental (present on unmodified sibling tests too, base-shape).
- Red-before proof (/tmp cp-harness, never git stash): with the key deleted from lang/en.php the
  pages pin fails with `+'code.chat.attach.pdf_pages'` (raw-key fallback); restore md5
  6bd7742cc4270026b1b712b9bb8725b4 → green. Pin discriminates.

## NIT dispositions (recorded, no code in this commit)

- NIT-2 — weld d95f2486c (C-TOOL-7.b) shipped with an empty body and no WELD.md/gate disclosure,
  unlike the 9 sibling welds. Backfill record: the WAVE-REVIEW-1 independently verified its gates
  green at tip (batch3 268T/1,257A OK incl. ToolTailFold/RunningToolTail). This row is the
  closeout ledger note the finding asked for.
- NIT-3 — fa14b82c6 (C-EXT-2.d lane) committed its own lanes/C-EXT-2.d/REPORT.md (evidence
  normally rides the weld). Record complete, zero duplication. Convention for future lanes:
  lane commits carry code+tests only; evidence files land in the weld commit.
- NIT-4 (carry, disclosed) — durations.tsv row SkillsEnableDisableTest reads 0.051s vs ~0.224s
  fresh-measured; re-measure candidate at the §7 closeout durations pass (root
  scripts/parallel-tests-durations.tsv is closeout-owned; fixer forbidden).
- §7 closeout must-do — root `tools/gen-docs.php --check` and `tools/gen-ansi-readme.php --check`
  were refused by the wave reviewer's bash permission map (disclosed limitation, WAVE-REVIEW-1
  §end); W11 touched no docs/_data/<slug>.* nor ansi/ surface, so drift risk nil, but the §7
  closeout battery (welder, full map) must run both --check to close the hole.

## §4 note

No re-mark needed: this fix commit rides under the W11 wave (steps stay marked at their welds).
Wave-reviewer tip judged: 7e97c9e4f (last W11 weld 13974e4b3; C-TUI-6.k weld rode ahead mid-review).
