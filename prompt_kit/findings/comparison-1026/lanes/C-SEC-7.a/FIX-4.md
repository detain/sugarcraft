# C-SEC-7.a — FIX-4 (round-5 cure ledger)

Base of this round: reviewer r5 REVIEW-5 (6564 B, main tree, tip 35cd18d53):
0 CRITICAL / 0 MAJOR / 5 MINOR / 2 NIT. All five MINORs cured in this round,
plus both foldable NITs. One cure commit on cc/C-SEC-7.a (new tip — see the
closeout return report, cat-file'd; the SHA cannot ride inside its own commit).

## Cure map (per finding)

- M-1 docs/DOCKER.md:183-188 — "The bypass banner" section rewritten: the row
  now prints only for a launch that RESOLVES to bypass (locally spawned `/bg`
  daemon or an explicitly named mode); TUI and `-p` stated to start in the
  asking `default` and print no such row; server-spawn inherits the admitted
  mode (unchanged substance). Old claim ("prints on every launch that runs
  without an approver, TUI and `-p` alike" / "`-p` and `/bg` runs start in
  bypass") removed.
- M-2 docs/SERVER.md — "`bypass-permissions` that `-p` keeps" → "that `/bg`
  daemons keep".
- M-3 docs/SERVER.md — both mislabels fixed: "rather than the headless
  default" → "rather than the background-surface default"; "keeps the
  documented headless default" → "keeps the documented background
  (`bypass-permissions`) default"; :856 label "the headless
  `bypass-permissions` default" → "the background `bypass-permissions`
  default" (substance was already adjudicated true in r4).
- M-4 src/Background/BackgroundSessionRunner.php docblock (COMMENT ONLY,
  zero behavior touched) — "the launch's headless one (`-p`'s,
  `bypass-permissions` ...)" → "the launch's background-surface one (`/bg`'s,
  `bypass-permissions` ...)". Code at Bootstrap.php:228/:7231-7232 re-read at
  this tip: daemon → BACKGROUND_DEFAULT_PERMISSION_MODE (bypass), headless →
  Default — unchanged, doc now matches.
- M-5 lang/en.php — `cli.headless.permission_hint` moved from its unsorted
  slot (between cli.noninteractive.json_schema_not_object and
  .json_schema_unapplied) to directly after `cli.doctor.unknown` (:272).
  Proof: derived contiguous cli.* run now 585 keys, strcmp violations = 0
  (was: one lane-introduced violation); neighbors
  cli.doctor.unknown | cli.headless.permission_hint | cli.help.screen.
  No new red either way (CliLangCatalogue filter runs green 8T/208A at tip).
- N-1 docs/PERMISSIONS.md:1179 — "(then the TUI's default, still the console
  paths')" → "(then the TUI's default, then the console paths' too)"
  (incident-era narrative tense fixed, one-line).
- N-2 lang/en.php — "(C-SEC-7.a)" campaign jargon stripped from the
  user-facing cli.headless.permission_hint value (folded with the M-5 move;
  value otherwise byte-identical).

## Residue grep method (the r5-identified gap, closed)

Round 4's grep was forward-only and line-based. This round's scan
(/tmp/opencode/cc-cmp-csec7a/residue-scan.py) is wrap-aware (per-file
whitespace collapse) and carries SEVEN claim classes in both word-orders:
p-keeps-bypass, bypass-kept-by-p, p-starts-in-bypass, headless-label-bypass,
headless-default-name, start-in-bypass-bare, headless-one-p-possessive,
banner-p-asks-nothing, p-alike-bypass. Historical-tense exemption
("used to|formerly|previously|then the|once was") mirrors the r5 adjudication
that ProtectFilesHook/ConfirmRemoveHook/WebSearch/ArgvParser prose is honest
history. Scan set: README.md + every docs/*.md + lang/en.php + the five code
files owning the surface logic.
- POST-FIX result: TOTAL 0 (empty).
- DISCRIMINATION: the same patterns run against the pre-fix (git HEAD at
  35cd18d53) texts flag all four M-1..M-4 sites verbatim
  ("`-p` and `/bg` runs start in bypass", "headless one (`-p`",
  "`bypass-permissions` that `-p` keeps", "the documented headless default",
  "headless `bypass-permissions` default"). Scan not vacuous.
- Left untouched per brief: docs/TROUBLESHOOTING.md:89 (valid-value
  vocabulary), docs/HOOKS.md:1156, src/Cli/ArgvParser.php:1303,
  docs/ENVIRONMENT.md:46 tail clause (all already dispositioned).

## Gates (cwd=candy-code, env -u LINES -u COLUMNS, -c phpunit.xml, plain pipe)

- Guard quintet `SettingsSchemaDocDrift|EnvRosterDrift|HeadlessDefaultPermissionMode|BypassLaunchBanner|BootstrapPermissionGate`:
  137T / 40408A OK — EXACT reproduction of r5's figures (2 PHPUnit deprecations, same as reviewer).
- `LangParity|DocFigureProseDrift`: 99T / 7347A OK — exact.
- `CliLangCatalogue|ReadmeRosterDrift|PermissionsIntroDrift`: 23T/309A/1F —
  sole failure = PermissionsIntroDriftTest::testThePatternToolRosterIsTheWholeCatalog
  (rostered standing red: Schedule-era tool roster drift). BASE-PROVEN: with
  docs/PERMISSIONS.md reverted to HEAD via single-file stash, the same test
  still fails 1F; edits restored (git stash pop, diff re-verified 5 files
  20+/17-). CliLangCatalogue isolated: 8T/208A GREEN.
- r1-MAJOR guard re-run alone: `SettingsSchemaDocDriftTest` 19T/188A OK —
  generated SETTINGS row still matches.
- `php tools/gen-settings-doc.php --write`: rc=0, SETTINGS.md diff EMPTY →
  no-op proven.
- `php -l`: lang/en.php, src/Background/BackgroundSessionRunner.php — no
  syntax errors (docs need no lint; en.php heredoc/carriage-pin widths
  unchanged — only one array line moved and its text shortened).
- No filter matched tests/Tools/PtyRunToolTest (exclusion moot, same as r5).
- Standing reds rostered in the brief were not chased; none was introduced or
  widened by this round (net lang-sort violation count: was 2 → 1 foreign).

