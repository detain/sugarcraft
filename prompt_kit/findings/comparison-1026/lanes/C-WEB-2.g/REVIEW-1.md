# C-WEB-2.g REVIEW-1 (round 1)

VERDICT: CLEAN — 0C/0M/0m

## Scope / traceability
- Branch `cc/C-WEB-2.g`, single commit `dfdac7512` atop `aa5ddf638`; porcelain = only the seeded untracked `prompt_kit/findings/comparison-1026/plan_comparison.md`. Author/committer detain@interserver.net, zero Co-Authored trailers.
- Diff = exactly 4 files, +416/−2, matching the design block's files.create (ReviewHostCommand.php, ReviewHostCommandTest.php) and files.modify (builtin-commands 2840/2845, `->withHostCommand` additive, `withHandler` byte-preserved, comments-only otherwise). No out-of-scope edits; ARCHITECTURE:155 "65 dispatch arms" untouched and unmoved (handlers/Chat dispatch unchanged — zero `hostCommand` reads in src/Chat.php).

## P1 production reachability — CONFIRMED
- Typed web draft: `submit()` → `command()` → `dispatchCommand()` → `spec->instantiateHostCommand()` (SessionHost.php:1848) → `ReviewHostCommand::run()` → SubmitPrompt queued → after echo rows land, `$this->submit($queuedPrompt)` (:2021) → non-slash draft falls through command dispatch → `admitPrompt(resolveMentions=true)` — same route InitHostCommand already ships (WebTwoHostCommandsTest pins the derivation).
- RPC `command.run`: `CommandMethods::runBuiltIn` → `runCommand()` (:1100): refused ⇒ never `applyCommandResult` ⇒ no rows, no turn, no billing; `runsIn()` derives server-side from `hostCommand !== null` at runtime.
- Refusal paths hand-traced: bad ref → `ReviewCommand::refError()` (isDiffRef round trip + bounded per-side `rev-parse --verify ^{tree}`, timeout fail-closed) → `cmd.review.bad-ref`, no effect; read-only → `host.command.read_only` naming the typed spelling (`CommandText::tokens($text)[0]`, colon spelling normalizes to `/review`); `--pr`/junk args → argument fails the validator, honest loud refuse (design pins the 2-arg `prompt($ref,$security)` shape; seam disclosed in class docblock + REPORT).
- TUI unchanged: specs keep `withHandler('handle*Command')`; Chat never consults the hostCommand field. `-p` headless has no slash dispatch (design risk note).

## P2 @diff attachment — CONFIRMED, pins non-vacuous
- Design's four pins all present and real: both-spellings-carries-class; bad-ref refuses + `servedUserRows()` empty + "is not a git revision" text; served `/review` over a REAL git repo asserts the resolved attachment (`path === '@diff'`, data contains `+uncommitted`, prompt content ends on the mention); `base..HEAD` range attaches committed diff (`@diff:base..HEAD`, `+second`). Extra pins: security body, colon spelling, both-side refuse, echo-before-effect, read-only both spellings.
- Builder mutation claim re-derived on disk (no strip executed): removing 2840's `withHostCommand` makes dispatch answer `clientOnly` (error non-null, so `isRefused()` true) — reddens exactly spelling + bad-ref (error-text assert) + both review attach tests = 4/10. Note (no action): `testARangeWithAnUnresolvableSideRefusesOnTheServePath` asserts only refused+not-busy, so it survives that single-file strip — covered by the spelling pin; not step-local duplication.

## P3 generated/forbidden surfaces — untouched
- Commit touches no suite-figure.json, durations.tsv, dist, protocol schema. Static zero-drift proof: schema embeds only the `runsIn` server|client ENUM in the command.list result SHAPE (:2251-2266), no per-command values; derivation pinned by WebTwoHostCommandsTest:86. GEN-PROTOCOL-SCHEMA --CHECK NOT RUN — shell permission denied the invocation in this reviewer map; static proof above + the green WebTwoHostCommands/Conformance/AttachRemoteCommands gates stand in (DISCLOSED).

## P4 guards — not triggered
- No new command/settings/env → ReadmeRoster/EnvRoster/KeyBinding drift idle (ReadmeRosterDriftTest carries no hostCommand/runsIn column). BuiltInCommandsTest host-body guard satisfied (implicit no-arg ctor). Gates re-run: ReviewHostCommandTest 10/61; `BuiltInCommands|Host\Commands|WebTwoHostCommands|InitHostCommand|PrHostCommand` 232T/3158A; review-adjacent `FastCommand|GenerateCommand|OutputStyle|CommandMethodsMcpPrompts|AttachRemoteCommands|Conformance|NoRawAnsiInTranscript` 102T/557A (builder said 99/543 — both green, small filter-overlap wobble, no failure in the set). 2 deprecations = PRE-EXISTING AccessorDeprecationTest private providers (reproduced under InitHostCommandTest alone; not this lane). PtyRunToolTest excluded from every broad filter.
- DuplicatedTestHelperDriftTest: sole failure is the standing 4-entry roster-era const red (OPEN/CLOSE/STUB_CANDIDATE/HEAVY_CHARS, none naming lane files) = green-equivalent. New file's helpers (gitIn/serveHost/drainServeHost/servedUserRows/wipeTree/requireGitServe + 3 repo fixtures) uniquely named; `mkdir 0o777` git-fixture idiom matches sibling ReviewCommandTest.

## P5 lang
- No new key: `cmd.review.bad-ref` (en.php:1635) and `host.command.read_only` (:2481) pre-exist and are reused — all-locale law untriggered. Design's "answering cmd.review.bad-ref" honored exactly.

## Conventions / laws
- strict_types, final, one type/file, PSR-12, Lang::t, why-comments, InitHostCommand shape mirrored. E646 clean (bounded git probe rides the existing DIFF_TIMEOUT_SECONDS, no blanket LLM timeout, no new timers). No new proc_open/fork sites (tests use exec on git, autoload-dev, no roster row). Dormant code: none removed.

## Weld carries
- durations.tsv row to mint at weld: `candy-code/tests/Host/Commands/ReviewHostCommandTest.php` — 10 tests, ~2.1s (builder medians 4.121/2.122/1.225).
- suite-figure.json + README re-pin: +10T (staleness pair red at tip by design).
- Cherry-pick: additive, zero conflict expected; regen delta: none owed (see P3 disclosure).
