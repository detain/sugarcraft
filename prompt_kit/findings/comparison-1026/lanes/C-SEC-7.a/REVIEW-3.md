# REVIEW-3 — C-SEC-7.a (round 3, final whole-lane scan)

Lane: cc/C-SEC-7.a @ 9d854a5b2 (cat-file-verified tip; base 410f5ac58; 4 commits on branch:
4306ec4b2 + cf482cf8f + 5a9e4ab41 + 9d854a5b2, author detain, no trailers).
Reviewer: read-only fresh round; only this file written.

VERDICT: FINDINGS — REVIEW-1's MAJOR and REVIEW-2's MINOR both CONFIRMED CURED; the ENVIRONMENT.md:46
cure is complete on both legs and line 45's past-tense sentence is NOT a truth violation. 3 step-local
MINOR doc-truth residuals remain (2 in lane-OWNED files that this step edited), 3 out-of-scope NIT/MINOR
residuals for the closeout sweep. Zero CRITICAL, zero MAJOR.

## 1. ENVIRONMENT.md:46 cure — CONFIRMED COMPLETE (both legs)

- Default column now: "else `default` in the TUI and `-p`, `bypass-permissions` for a locally spawned daemon";
  prose now: "the TUI and `-p` start in `default`, which asks ... only a locally spawned background-session
  daemon starts in `bypass-permissions`". Consistent with README:1553/1558/1865-1869/2632, PERMISSIONS.md:28-31,
  SETTINGS.md:1228 == PermissionSettings.php:50 defaultText. git diff 5a9e4ab41..HEAD = ENVIRONMENT.md 1+/1- +
  FIX-2.md only (no src/test change), so REVIEW-1/2's verified substance carries untouched.
- Gate re-run at tip (cwd=candy-code, env -u LINES -u COLUMNS, -c phpunit.xml, plain pipe):
  `--filter 'SettingsSchemaDocDriftTest|EnvRosterDriftTest|HeadlessDefaultPermissionModeTest|BypassLaunchBannerTest|BootstrapPermissionGateTest'`
  -> `Tests: 137, Assertions: 40408, OK, but there were issues! ... PHPUnit Deprecations: 2`
  (the 2 deprecations = known global-bootstrap pair, REVIEW-1 §5 class). Builder-lead's wider
  `EnvRosterDrift|DocFigureProseDrift|SettingsSchema` 195T/46906A OK claim is consistent.

## 2. Line 45 (SEARCH_ENDPOINT) past-tense sentence — NOT a truth violation

"the one that used to ship was a private host reached over cleartext http://, so every model-composed
query ... crossed the network unencrypted to a third party, unprompted under the default
`bypass-permissions`" — every verb is past-tense historical narration of the pre-reversal era. It is
accurate: WebSearch is `ToolPermissionClass::Ask` (src/Tools/BuiltIn/WebSearch.php:38) and under the
then-shipped console default of bypass-permissions those asks were auto-allowed. The sentence teaches no
current default. Cure verdict: KEEP as the fixer left it (optionally "then-default" wording = NIT only).

## 3. Findings (all step-local doc-truth; no guard reddens on any — verified no test pins these sentences)

### MINOR-2 (step-local, lane-OWNED file) — lang/en.php:491-494, inside the `cli.help.screen` heredoc (273-779)
`--permission-mode` help still says: "With none set, the TUI starts in default (it asks before writes and
shell commands); -p and background sessions start in bypass-permissions." — the reversed fact in present
tense on the exact flag's help surface, in the lang file the lane edited (added 2 keys + re-keyed mode_split).
No guard: HelpTest:4077 only checks the screen == catalogue entry; tests/ grep for the sentence: zero hits.
Cure: "the TUI and -p start in default (they ask before writes and shell commands); locally spawned
background daemons start in bypass-permissions." — keep lines inside the heredoc's existing column shape;
re-run `--filter 'HelpTest|CliLangCatalogueTest'` (the foreign tls.missing-vs-mismatch sort red stays, GREEN-EQUIVALENT).

### MINOR-3 (step-local, lane-OWNED file) — docs/PERMISSIONS.md:76-88, 45 lines below the lane's own fixed :28-31
"The console paths keep the permissive default on purpose. ... so an asking default would make an unattended
`-p` run in CI refuse its first edit." + "(The doctor roster itself reflects this: `-p` and background there
means the local console paths.)" — present-tense reversed fact + a doctor claim the lane's own 3-surface
mode_split made false; the lane edited this exact file (brief files.modify), so the page now self-contradicts.
Cure: paragraph → daemon-only permissive default (mirror :28-31; the rm-rf/bypass-cost content that follows
is fine for the daemon); replace the doctor parenthetical (roster now names three surfaces). Re-run
`--filter 'PermissionsIntroDriftTest'` — only the known foreign Schedule-row red may remain.

### MINOR-4 (out-of-scope file, same fact) — docs/TROUBLESHOOTING.md:268-270
"...or this is a `-p` or background run, whose default is `bypass-permissions`" — triage answer now teaches
the reversed default for `-p`. Cure or route to closeout doc sweep.

### NIT-5 (out-of-scope) — docs/HOOKS.md:1156 "(it still is for `-p` and background sessions; ...)" — assumes
-p-on-bypass; closeout sweep.
### NIT-6 (out-of-scope) — src/Cli/ArgvParser.php:1303 docblock cites permissionGate as "`default` in the TUI,
the permissive `bypass-permissions` for `-p`" — stale @see citation; closeout sweep.
### NIT-7 (base, foreign) — docs/ENVIRONMENT.md:46 tail "bypass-permissions is *identical* to having no gate"
(byte-unchanged base leg; contradicts PERMISSIONS.md:79 / README:2540 "decides *less* ... (2026-10-06 ruling)").
Pre-existing base drift, NOT introduced nor worsened by the lane — §7 closeout ledger row.

docs/CI.md:205 checked: "No implicit `--permission-mode default`" becomes TRUE post-reversal (the silent
default now asks) — no finding. docs/AGENTS_AUTHORING.md/SERVER.md/HOOKS.md bypass-preserving sections
describe explicit-mode/e2e semantics, not the default — clean.

## 4. Whole-lane final scan — clean

- Ruling match: plan §6.2 line 4126 "rv-sec-1 reversal: `-p` defaults to Manual/deny like Claude Code;
  locally spawned `/bg` daemons keep bypass" — implemented exactly (HEADLESS=Default via
  NonInteractive::consoleBackend() naming PERMISSION_SURFACE_HEADLESS; plain/background surface stays
  BypassPermissions; precedence walk and serve-daemon path untouched — REVIEW-1 §1-§4 verifications stand,
  fix commits changed no code).
- Census law: TRANSCRIPT_SEAM_CALL_SITES 32→33 + all ten PROSE_SITES sentences + StderrEmitter roster
  moved in-step (REVIEW-1 §3 re-derived live). FIX-1/FIX-2 added no emitters/sprintfs (doc-only +
  one new Lang::t consumer at HeadlessPermissionPrompt:522 — its string carries no denial-prefix or
  glob-shaped token; DenialPrefixRoster/GlobDialect stay clear of it).
- No stubs/TODOs; no fabricated pins; new file HeadlessDefaultPermissionModeTest drives the real
  consoleBackend (10 methods); E646 clean (no timers); no proc_open/fork sites; protocol trio/dist/
  suite-figure/durations untouched (lane never edits them — correct).
- Scope: Subcommands/PermissionSettings/README/PERMISSIONS/SETTINGS-numeral stretches all disclosed and
  guard-forced or lie-preventing per §1.8; nothing outside declared ownership was touched.

## 5. Weld carries (§4 of the return)

- durations.tsv: ONE new row — candy-code/tests/Cli/HeadlessDefaultPermissionModeTest.php (10 tests, ~9 s).
- Figure delta: +10 test methods net of renames (+13 added/−3 removed test functions; renames count-neutral);
  suite-figure.json + README staleness pair re-pin stays the standing §7 closeout duty (foreign-inherited).
- Lang rulings consulted: cli.doctor.mode_split re-keyed 2→3 slots (sole reader Subcommands, derived);
  cli.headless.permission_hint + appLaunch.notice.headless_default minted; candy-code lang = en.php-only.
- §6.2 ruling honored verbatim; rv-sec-1's surviving half (daemon default) disclosed in the notice comment.
- Recommend cure-before-weld of MINOR-2 + MINOR-3 in ONE follow-up commit (both lane-owned files, zero guard
  risk, re-runs above); MINOR-4..6 to the closeout doc-truth ledger.
