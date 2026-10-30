# C-SEC-7.e — REVIEW-1 (step reviewer, round 1)

VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR / 0 step-local MINOR. Carries below are weld duties, not lane defects.

Lane: cc/C-SEC-7.e @ f3ccb76c6 (base 9fb981c5c; rev-list 2: 2c8a0865e code 12 files +329/−29, f3ccb76c6 tracked lane REPORT). All SHAs cat-file-verified. Authors %ae+%ce = detain@interserver.net both commits, zero trailers (%b clean).

## Ruling + design conformance (option (3), t212/@287)
- Implicit-ON arm LIVE: Bootstrap.php:7465 `$autoReviewImplicit = $autoReview === null;`, :7468 `elseif ($autoReview === true || $autoReviewImplicit)` — SAME shared block as explicit true; no implicit-only construction. Design line honoured.
- Reviewer-identity pin (BootstrapPermissionGateTest, landed): reflection on ExecReviewer::backend → EngineBackend model + tools; explicit vs implicit shapes assertSame, and the explicit run asserts `LaunchNotices::reported(notice) === false` (explicit never announces). Construction identity is structural (one code path); the value-equality pin is the strongest practical assertion — accepted.
- Warning silencing: :7496 gates ONLY `auto_review_no_provider` with `&& !$autoReviewImplicit`; override-model warnings (not_string/bad_shape/unusable) stay shared — matches design (silences only the no-provider case). Explicit false never engages (:7468 excludes it) — pinned in BOTH test files.
- Notice: :7504-7505 after reviewer built (engagement, not trying). Once-per-session is per-process correct: warnPermissionConfigInTranscript = LaunchNotices::record (message-identity dedup, land:283-288) + warnPermissionConfigOnce (process-static store keyed by message, land:1067 helpers/reset law). Both channels: transcript (TUI) + stderr (headless) per house law — deviation (3) judged correct, same routing as headless_default.
- Stored schema default stays false (PermissionSettings docblock records ruling; SETTINGS.md generated table :1239 shows `false` — untouched); docs state announce + paid-call semantics (PERMISSIONS.md exec-reviewer paragraph, settings.autoReview.help, notice itself).

## §1.8 cascade — verified by LIVE re-derivation, not by trust
Seam 35→36 + StderrEmitter 47→48 across Chat.php docblock, Bootstrap :1043/:1750/:5701/:9178, RoutingTest(4 sites), McpToolWiringTest :1675, SETTINGS.md :983, seam-census NUMBER_WORDS +thirty-six, StderrEmitter roster :652-655 + docblock FORTY-EIGHT + NUMBER_WORDS +forty-eight. All census tests pass at tip ⇒ live counts agree. Bootstrap.php diff NOT add-only (numeral replacements) but inside the declared serialized region + mandated in-step cascade, precedented C-SEC-7.a/7.b.

## Gates run here (plain pipe, cwd candy-code, lane tree)
- AutoReviewScopeTest: `OK, but there were issues! Tests: 15, Assertions: 52` (2 deprecations = pre-existing foreign AccessorDeprecationTest providers).
- BootstrapPermissionGateTest: `OK, but there were issues! Tests: 68, Assertions: 154` (base 65+3, method grep 39→42).
- Seam/StderrEmitter/Routing/Period family: `OK ... Tests: 142, Assertions: 22965`.
- DocFigure|TrustKey|EnvRoster|SettingsSchema|LangParity: `OK ... Tests: 219, Assertions: 51272`.
- gen-settings-doc --check: could NOT run — reviewer permission map denies bare `php` (disclosed). Verified equivalently: lane SETTINGS.md edit at :983 sits ABOVE the generated-region marker (:1178), stored-default table unchanged, and the generator embeds no help strings (no lang refs in the tool).
- CliLangCatalogueTest 1F = ROSTERED standing red, base-proven: diff context shows the serve.tls.mismatch/missing swap and `git show 9fb981c5c:...en.php` carries it at :1105-1106. Not this lane.
- PermissionsIntroDriftTest 1F at tip = the base-time roster red (Advisor/Schedule/SkillWorkshop/skills_search missing) — cured on master by e6b088fa8; builder's "foreign, disclosed not chased" claim HONEST.

## Mutations
Reviewer harness denies php/file writes → the 3 claimed mutations were discriminated by inspection: neuter `|| $autoReviewImplicit` ⇒ implicit reviewer-not-null + identity pins red; drop `&& !$autoReviewImplicit` ⇒ store-size-0 + reported-false pins red; re-announce (identity-break) ⇒ before==after store pin red. Pins non-vacuous.

## WELD CARRIES (corrections + duties)
1. BRIEF'S COLLISION PREMISE IS WRONG: master's ONLY PERMISSIONS.md change since base is e6b088fa8 at :818 (pattern-tool roster); the lane's hunks are :559-591 — 200+ lines apart, 3-way fuses CLEAN, no UNION conflict. The ONLY expected conflict is tests/Cli/StderrEmitterCensusTest.php NUMBER_WORDS: both sides re-wrote the same forty-* line; master's version ALREADY carries 'forty-eight' ⇒ take MASTER's two lines, keep the lane's :652 Bootstrap 47→48 row + :233 docblock (master's hunks are :154/:812/:1049 — disjoint). Verify PermissionsIntroDriftTest + StderrEmitterCensus green at merged tree (merged Bootstrap.php == lane's: master untouched since base).
2. DUR: no new test files ⇒ no MINT; RE-TIME both rows (BootstrapPermissionGateTest 65→68T, AutoReviewScopeTest 12→15T measured here), JUNIT-SUM.
3. §7 figure carry +6T real (3+3 methods, no provider growth); assertions closeout-computes.
4. Lane worktree dirt = untracked prompt_kit/findings/comparison-1026/plan_comparison.md (NOT "the lanes dir" as briefed — that REPORT is tracked in f3ccb76c6). Never stages; picks carry commits only.
