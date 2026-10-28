# C-SES-3.d — FIX-1 (review round 1)

Verdict: MINOR cured (erratum wording only); NIT verified-true, left unfixed as instructed.
New tip: 7b47daa314798f46b1190174efea1c660dde8e45 (amended f899d0551, single commit,
12 files, +482/-48; AE=CE=detain@interserver.net via SEPARATE greps 1/1, zero trailers).

## MINOR — false "existing ShareSession row cap" premise (docblock wording only; NO cap minted)

Truth shipped into the prose: ShareSession's ctor filter is a VISIBILITY filter, not a
size bound; no row-count cap exists anywhere; the live path re-serializes the full
filtered transcript per request.

- `candy-code/src/Server/Http/SharePageController.php:35-41` — "the row bound,
  filtering" → "the row filter"; + sentences naming the filter non-bounding, no cap
  existing, and the design risk-line a false premise whose cap-minting is owner-ruling.
- `candy-code/src/Cli/Serve.php:408-412` — "so the same bound applies per request" →
  "The filter is not a size bound and no row cap exists, so every request re-renders
  the full filtered transcript — the cost the design's risk-line priced against a cap
  that never existed."
- `candy-code/src/Commands/ShareCommand.php:176-181` — "the row bound and the secret
  redaction" → "the row filter and the secret redaction"; + "not a count cap, and no
  cap exists: the full filtered transcript is re-serialized on every request."
- Lane `REPORT.md:23` (main tree, untracked) — "(the design's row-bound reuse)" →
  serializer-reuse wording + dated round-1 erratum naming the false premise.
- Tree-wide acceptance grep: no share-family "row bound|same bound|row cap" claims
  remain (all other hits are unrelated bound-families: BackgroundShellRegistry,
  Renderer preview, LaunchNotices, HttpClientDefaults, poll/timeout bounds...).

No guard-needle flip needed in-step: verified zero test needles pin the removed phrases
(no 'row bound'/'same bound'/'WHEN they run'/'re-serializes' arms in candy-code/tests/;
DocFigure/SymbolCitation have no share-family arms). No doc surface (COMMANDS.md,
lang/en.php live strings) carried the cap claim — checked, untouched, truthful.

## Out-of-scope erratum for closeout/weld

`prompt_kit/findings/comparison-1026/design-ses.md:274` (original false premise:
"Bound it with the existing `ShareSession` row cap...") is TRACKED in the main repo and
outside the step's 12 declared files → NOT edited here. Recommend a dated erratum stamp
at closeout (same pattern as plan-file errata). Row-cap MINTING (real bound for live
pages) stays owner-ruling territory — recorded, not taken.

## NIT — verified TRUE, not fixed (as instructed)

Serve.php's liveRenderer CONSTRUCTION wiring (loadTranscript → TranscriptStore::reviveRow
→ (new ShareSession(...FORMAT_HTML))->serialize closure) has zero test pin — pre-existing
pattern: SharePageTest injects its OWN closures via route(liveRenderer:) (:95/:104/:132/
:146/:165/:169/:176, helper :375-377), so the controller law is pinned but Serve's wiring
of it is only behavior-visible end-to-end. Welder may add a source-scan pin (house
pattern, e.g. bin/sugarcrush-style literal scan) at weld.

## Gates (cwd candy-code, plain pipe, env -u LINES -u COLUMNS, after amend)

- `--filter 'SharePage|ShareStore|ShareCommand|CommandDocs|CommandRegistry|LangParity'`
  → OK, 114 tests, 3468 assertions (exact; +2 pre-existing PHPUnit Deprecations).
- `php -l` clean on all three edited src files.
- PtyRunToolTest never in any filter; foreign SwallowingCatchSrcCensus base-red
  (SkillTool.php::execute) untouched and excluded. Not pushed; no stash; no forbidden
  files staged (suite-figure.json/durations.tsv/dist absent from the diff).
