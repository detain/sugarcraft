# C-TOOL-6.a — Browser-control and computer-use MCP presets (docs)

Wave W26 · effort S · phase normal · deps none · files: [docs:MCP.md]

Design authority: `prompt_kit/findings/comparison-1026/design-tool.md` § C-TOOL-6.a (heading at
line 723, "Browser-control and computer-use MCP presets (docs)"). READ THAT BLOCK YOURSELF and
implement exactly what it specifies — this lane is documentation-only per the plan's step table
(`| C-TOOL-6.a | Browser/computer-use MCP presets, docs only (phase: normal) | S | [docs:MCP.md] | none |`).

Owner ruling context: all §6.3 rulings are already answered; consult plan_comparison.md §6 when
cutting/landing this step and record nothing new.

Landing rules that matter for a docs lane in this repo:
- `candy-code/docs/MCP.md` is pinned by documentation-drift guards (`EnvRosterDriftTest`,
  `ReadmeRosterDriftTest`, `DocFigureProseDriftTest`, `TrustKeyDocumentationDriftTest` family).
  If your prose adds/changes a roster numeral, a tool/command name, an env var, or a sub-command
  count, the matching generator/roster must be updated IN-STEP in the same commit, and the guard
  must be re-run green.
- Do NOT hand-edit generated surfaces (`docs/lib/*.html`, `ansi/README.md`, the protocol trio
  `docs/protocol/*.json` / `schema.json` / `candy-code-web/src/generated.ts` / `dist`). If you
  believe a generated table must change, run its generator with `--write` and commit the output.
- Never touch `candy-code/tests/Config/Support/suite-figure.json` or
  `scripts/parallel-tests-durations.tsv` (closeout duty). If you add a test file, list it in your
  REPORT.md so the welder mints the durations row.
- Truth law: the plan's whole C-SEC/C-TOOL theme is that docs must match code. Verify every claim
  you write against the actual code path before committing it — a doc that teaches a fact the code
  contradicts is a CRITICAL in review here.

Targeted gates only (cwd=candy-code, `env -u LINES -u COLUMNS` plain pipe,
`vendor/bin/phpunit -c phpunit.xml --filter ...`). Never a full suite. Exclude
`tests/Tools/PtyRunToolTest::testExpectMissIsLoudAndTheScriptStillContinues` from any filter.
