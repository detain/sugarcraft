# B6 sugar-spark → candy-ansi-inspect — execution notes <!-- @rename-keep -->

Entry: tools/renames.json batch-B line 13. kind=rename, type=app, ns Spark→AnsiInspect,
bins bin/sugarspark→bin/candy-ansi-inspect (GLUED compact bin spelling). <!-- @rename-keep -->
Base: master 4f8427290 == origin; foreign untracked oc.7z at root. <!-- @rename-keep -->

## R0 recon findings
- Baseline (rename-baseline.md:83): 278T/1412A/0F/0E/0S/0W — tagged @rename-keep in tag-prep.
- Sibling dirs/packages prefixed `sugar-spark`: NONE (`ls -d *spark*` = only `sugar-spark/`). <!-- @rename-keep -->
  NO sugar-sparkline lib exists (brief premise checked): sparkline code lives INSIDE sugar-dash
  (`src/Plot/Chart/Sparkline*.php`, `examples/sparkline*.php`) and candy-sql (TimeSeriesCell.php prose) —
  all SAFE: R3 needs the full `sugar-spark` literal, R2 is Pascal `SugarSpark`, R4 is glued `sugarspark`;
  `Sparkline`/`sparkline` matches none. Zero `spark*` paths must appear in `git diff --stat` outside the lib.
- PROTECT auto-derivation inert (no sugar-spark* sibling entries in renames.json — 1 hit total).
  Reverse-direction law RE-LOGGED: batch-E `candy-ansi` → sugar-ansi-parser must PROTECT `candy-ansi-inspect`
  (R3 suffix lookahead + R5 CANDY_ANSI_ prefix would otherwise mangle CANDY_ANSI_INSPECT_BIN / paths).
- 9 tracked paths outside lib carry the slug (docs/_data pair, docs/lib page, repo_map pair, research doc,
  findings/plan_sugar-spark.md, media+docs/img icons pngs) + ansi/sugar-spark/ (162 art files) — all moved by C.2.
- Composer REQUIRE consumers: root composer.json only (require :94 + repositories :471). NO sibling lib requires it.
  Docblock/example mentions: candy-core/README.md:239, sugar-bits/examples/item-list.php, README.md:85,
  MATCHUPS :85/:206 — tool rewrites mechanically.
- Env: ZERO getenv/$_ENV/putenv reads in sugar-spark/src+bin. `SUGARSPARK_BIN` exists ONLY in
  examples/inspect.sh (example script, not PHP) → tool R5 rewrites to CANDY_ANSI_INSPECT_BIN per plan note;
  NO LegacyEnv shim (confirmed by reading where it is "read" — nowhere in code). <!-- @rename-keep -->
- User data paths: grep XDG/.config/.local/.cache over src+bin → EMPTY. LegacyPaths: honest none.
- BRAND CLASS: none — all src classes domain-named (Inspector/StreamingInspector/AnsiHandler/Segment/
  SequenceSegment/TextSegment/C0C1/Lang). No Spark* compounds anywhere (grep `Spark[A-Z]` outside ns = zero).
- Glued blindspot sweep: `_sugarspark`/`SUGARSPARK` content outside .ansi art: examples/inspect.sh ONLY <!-- @rename-keep -->
  (+ bin path literals covered by bins map, CliTest `/no/such/sugarspark/...` covered by R4 with `/` lookbehind OK).
  `.ansi` art CONTENT is C.3-excluded (wordmark `sugarspark` painted inside art → followups). `_sugar-spark`
  underscore path refs: docs/repo_map filenames moved by tool (pathContext); content refs swept in R4 if any.
- CliTest.php:134 `tempnam(sys_get_temp_dir(), 'spark')` — manual → 'inspect-' (B5-g pattern).
- examples/inspect.sh: R4 will mint the INVALID bash fn name `candy-ansi-inspect()` — manual run_ansi_inspect()
  (def + export -f + 5 pipe-call sites) (B5-h law). <!-- @rename-keep -->
- Lang: src/Lang.php NAMESPACE='spark' → R6 rewrites to 'ansiinspect' mechanically; NO LangTest exists in the
  suite (11 test files, none asserts domain) — only MANUAL docblock prose fix Lang.php:13 `'spark'` literal (B1 law).
- ansi/: 162 art files paint the sugar-spark wordmark → rename-followups.md line (never redraw here);
  ansi/README.md is generated (gen-ansi-readme) — regenerate+--check; ansi.json key + 2 description strings
  carry the brand (R2/R3 rewrite → README regen keeps them consistent).
- vhs.yml: sugar-spark present in BOTH hand-lists (:147 all=, :225 matrix lib:) — tool auto-rewrites (B1 verified).
- MATCHUPS row :85 already under `## Apps` (:77); index.html tile :708 under `#apps` (:649) → R4.8 MOOT.
- PROJECT_NAMES.md: ZERO spark mentions (unlike B4/B5) — no rationale rewrite owed. Honest record.
- Capital-hyphen `Sugar-spark` prose form: grep → ZERO occurrences → nothing to hand-fix this entry.
- Generic-word 'spark' landscape: NO Apache Spark / spark-sql citations anywhere; bare `spark`/`sparks` words
  live in ansi/README.md + ansi.json art DESCRIPTIONS (forge/spark imagery) — no rule fires on bare lowercase
  words; audit executed at R6 (B4/B5 method).
- codecov.yml rows (:80-81 flag, :310-315 component) auto-rewritten by R3 (tracked text, not excluded).

## R1 dry-run
(pending)

## R2 remote rename
(pending)

## R3 tool run
(pending)

## R4 manual followups
(pending)

## R5 wiring
(pending)

## R6 verification
(pending)

## R7 commit
(pending)

## R8 push + remote-done
(pending)

## R9 packagist
(pending)

## Tracker
(pending)
