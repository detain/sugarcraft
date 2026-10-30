# C-SES-8.a — REVIEW-2 (round 2, fresh, chain landed)

VERDICT: FINDINGS — 0 CRITICAL / 0 MAJOR / 2 MINOR (both step-local) / 1 NIT. Ship-ready; weld carries stand as listed. No fabrication found: every builder claim reproduced on disk (one wording imprecision, NIT-1).

## Re-anchor (individual rev-parses, 2026-10-29)
- worktree /home/sites/cc-cmp-C-SES-8.a, branch `cc/C-SES-8.a`; tip `git -C ... rev-parse HEAD` = 06352783a4f4414f54bec47ab27c2b92f13b3049; `git rev-parse master` = 07e49e87234c9e04dc336bb8977bac26cb3f53a2; `git merge-base master HEAD` = 574e69f1d (base at cut, ancestor of both). `git status --porcelain` clean.
- chain master..HEAD = b95a0e181 (UsageMethods.php + Dispatcher register + MethodSchemas row) + 06352783a (UsageMethodsTest + UsagePanel.vue + SidePanels mount + panels.ts + usagePanel.spec.ts). 8 files, +763/−1. REVIEW-1.md's honest-zero predates this chain; superseded.
- Authors: `%ae` grep detain = 2/2 and `%ce` grep detain = 2/2 (separate greps); `%(trailers)` empty — zero Co-Authored-By.

## Categories

### (1) Files == declared set — PASS
Diff set exactly the 4 creates + the code/roster modifies of design-ses.md §C-SES-8.a. Generated trio (schema.json / SERVER.md / generated.ts) byte-untouched (`git diff --name-only master...HEAD | grep -c` trio+dist+lang+README+forbidden = 0) — the §1.8 regen-verify-then-restore protocol, weld-regen owed. `generated.spec.ts` unmodified — MINOR-2 below (disclosed deviation). No out-of-set edits, no hub-region violations.

### (2) Rollup law end-to-end + production-reachable — PASS
- RPC: `usage.rollup` registered on the real `Dispatcher::methods()` roster (Dispatcher.php +1 use, +1 register line); Scope::Read; `sideEffects` false — all pinned by testTheMethodIsAdvertised (real registry, not a mock).
- One-snapshot law: single `UsageHostCommand::rollupFacts()` read feeds days+models; totals DERIVED from the per-model fold in `answer()` (sum of calls/totalTokens/costUsd) — structurally cannot straddle a settle; same derivation UsageCommand::compose uses (docblock cites it; shape verified).
- Scope split: `scope` required, enforced in code (`Params::enum` with no default throws; also verified `ServerContext::store(): ?EnhancedSessionStore` returns null for a plain SessionStore, so the no-store reason branch covers the design's "plain store" case exactly). sessionId via `SessionMethods::sessionId` (charset-validated) only for scope=session; ignored for all (pinned).
- Unpriced bound HONEST: `unpriced = anyUnpriced(days) || anyUnpriced(models)`; dollar figures stay 0 for unpriced rows — test asserts `costUsd 0.5, calls 2` with the flag carrying the gap: "the flag, not an invented rate" (e92a249cf WEB-5/SES-1 parity law holds). Panel renders every figure through `spendLabel(usd, unpriced)` = `≥$…` on the bit, answer-level bit for totals, row-level bit per row — pinned both polarities in usagePanel.spec.
- retentionDays = `Bootstrap::sessionRetentionDays()` read-only, pinned via env=45; zero write path (RPC + panel: Settings link only when the EXISTING settings.schema store says the key is editable — `settings.editabilityOf('sessionRetentionDays').editable`, store method verified present).
- Panel mounts: SidePanels.vue `v-else-if="panels.active === 'usage' && sessionId"` last in the chain (guard parity with siblings); panels.ts 'usage' appended LAST in PANELS + PANEL_LABELS — additive-only, serialize-law satisfied for P-CTX-BREAKDOWN/C-WEB-3 stacking behind.
- dormantRoster caller PROVEN: `connection.request('usage.rollup', ...)` at UsagePanel.vue:62 matches the spec's call-idiom regex (grep -E equivalent reproduced the `\brequest\(\s*'usage\.rollup'` hit). On the committed (restored-trio) tree METHOD_SCOPES lacks the method so the roster is trivially green; on the regen'd tree the caller leg is the discriminator — both shapes verified green by run (below) + static proof.

### (3) Targeted gates (lane tree, restored trio) — REPRODUCED
- `env -u LINES -u COLUMNS vendor/bin/phpunit -c phpunit.xml --filter UsageMethods` → **Tests: 9, Assertions: 49** OK (10.2s). The 2 PHPUnit Deprecations are BASELINE: `--filter ShellsMethods` on untouched files shows the same 2.
- `--filter 'ProtocolSchemaDrift|ServerProtocolDocumentationDrift'` → **13T/1F**: the single F is ProtocolSchemaDriftTest:39 committed-schema.json staleness — its diff OUTPUT shows the generator emitting the full usage.rollup result block (day/model rows, six closed keys), proving the MethodSchemas row is well-formed end-to-end. EXPECTED §1.8, NOT charged. Every schema-helper form in the new row has committed sibling precedent (Schema::ref(D::SESSION_ID), arrayOf(…, open:false), number(0), boolean); `Schema::enum` is type-agnostic (Schema.php:51), so the int-enum [7,30] is generator-safe.
- five-guard filter → **134T/2F, only** the ReadmeSuiteFigureDrift staleness pair (25,537 pinned vs 28,458 live — standing, weld re-pin owed; +9T from this lane rides that carry).
- vitest serial (`npx vitest run --no-file-parallelism usagePanel.spec dormantRoster.spec generated.spec panels.spec`) → **4 files, 24/24 passed** (no stale-cache false red in my run; broader OR-filter also 17 files/123 tests all green).
- vue-tsc (`npx vue-tsc --build`, restored-trio tree): 59 errors — 38 are ALL UsagePanel.vue `keyof Methods` staleness consequences of the UNCOMMITTED trio (they vanish the moment gen:protocol reruns, which the builder proved at M2), 21 sit in files the lane never touched (contextSources.ts, contextPanel specs, reducer, statusBar, contextTrustRouting, dormantRoster:59 noUncheckedIndexedAccess-on-unchanged-code) — count decomposes exactly to the builder's 21-base set. NOT charged (§1.8 shape).
- generated.spec.ts pair-parity leg green on both trio shapes (old-pair matches itself; regen-pair matches) → builder's "no edit needed" is mechanically sound for byte-parity; MINOR-2 is about the missing TABLE pin the design named.

### (4) Pin vacuity (mutation-reason) — PASS with MINOR-1
Reasoned without running mutations (read-only): scope-narrow pin reddens if sessionId ignored (models would fold both sessions); all-scope pin reddens on narrowing regression; unpriced pin reddens if the bound is dropped or a rate is invented; no-store pin reddens on silent-empty or reason-with-fold; invalid-window pins (15 and '7') redden if WINDOWS is loosened or int-check removed; shape pin (six keys exact + day-row keys exact) reddens on any schema drift; retention pin reddens if env is ignored. Web: latch spec (two stale answers + CURRENT) reddens on generation removal; Settings-link specs are a genuine polarity pair (writable true/false + absent-schema) — not vacuous; ≥-leg asserts BOTH presence (`≥$0.00` row) and absence (priced row `not.toContain('≥')`).
- **MINOR-1 (step-local): the days FORWARDING leg is not discriminated by the lane's own test.** `testTheWindowIsSevenOrThirty…` seeds only today's rows, so `UsageMethods.php:74` passing `null` instead of `$windowDays` into `rollupFacts` keeps both 7/30 legs green (calls==1 either way). Cure: seed one back-dated usage row (the InsightsHostCommandTest "yesterday" idiom already pins the window filter upstream, so this is belt-braces) or reflection-pin the forwarded arg. Not blocking: the behavior is pinned one hop up.

### (5) E646 / timers — PASS
Zero timer/poll/setTimeout/setInterval/sleep hits across all four new lane files (grep exit 1). Panel reload is a manual button; spec uses no fake timers. No LLM call sites added.

### (6) §1.8 in-step law — PASS
Trio regen-verified then restored; committed drift is weld-carry (REPORT CARRY 2). No doc rows, rosters or census figures moved that should have moved in-step: panels.ts/dormantRoster coherent (additive + caller present); SERVER.md is regen-only by design; lang untouched.

### (7) Conventions — PASS
`declare(strict_types=1)`, final class, private ctor + static register mirroring sibling Methods classes; docblocks carry WHY (law paragraphs, not narration); PSR-12. Vue panel follows the sibling-panel idiom (setup store, plain {{ }} for server strings — no MessageMarkdown on untrusted content, ChangesPanel rule cited in header).

### (8) Forbidden files — PASS
suite-figure.json, scripts/parallel-tests-durations.tsv, candy-code-web/dist all untouched (diff 0). Verified absent-correctly: durations.tsv has NO UsageMethodsTest row yet (weld mints it), dist has no usage code (weld rebuilds), +9T re-pin carried (weld). All three carries + trio regen are listed in REPORT CARRY section — complete.

### (9) Rulings/plan §6 — PASS
Design says no new write path (retention): honored on both surfaces. `days` refusal-over-fold semantics pinned. Unpriced answer parity with the terminal page preserved by the shared-read law.

### (10) Fixtures/legality — PASS
No glob-shaped literals, no `**Refused:**`-shaped literals in any new fixture (checked: ROLLUP table, STALE-* sentinels, reason strings — none wildcard-shaped).

## Findings (chargeable set)
- **MINOR-1 (step-local)** `candy-code/tests/Protocol/UsageMethodsTest.php:150-160` — days-forwarding leg vacuous (see §4). Cure: back-dated seed or reflection pin; or accept with the upstream InsightsHostCommandTest coverage noted at weld.
- **MINOR-2 (step-local)** `candy-code-web/src-web/protocol/__tests__/generated.spec.ts` — design's "(scope pin)" modify line not landed; one line `expect(METHOD_SCOPES['usage.rollup']).toBe('read')` in the tables leg would pin the wire-scope on the web side (PHP side already pinned). Cure: add at trio-regen weld or record the builder's disclosed judgment (REPORT "no edit needed" argues byte-parity only).
- **NIT-1** `lanes/C-SES-8.a/REPORT.md` M3 line — "vue-tsc … 21 errors … zero from lane files" holds on the regen'd-trio shape; on the as-committed tree it is 21+38(UsagePanel staleness). Reword future journals to name the trio shape the gate ran against.

## Stat (disk, this review)
```
$ git diff --stat master...HEAD
 .../src-web/components/panels/SidePanels.vue       |   2 +
 .../src-web/components/panels/UsagePanel.vue       | 194 +++++++++++++++++
 .../components/panels/__tests__/usagePanel.spec.ts | 160 ++++++++++++++
 candy-code-web/src-web/stores/agents/panels.ts     |   3 +-
 candy-code/src/Protocol/Dispatcher.php             |   2 +
 candy-code/src/Protocol/Methods/UsageMethods.php   | 135 ++++++++++++
 candy-code/src/Protocol/Schema/MethodSchemas.php   |  37 ++++
 candy-code/tests/Protocol/UsageMethodsTest.php     | 231 +++++++++++++++++++++
 8 files changed, 763 insertions(+), 1 deletion(-)
```
Gate lines: UsageMethods 9T/49A OK | drift pair 13T/1F(expected §1.8) | five-guard 134T/2F(staleness pair only) | vitest 4 files 24/24 | vue-tsc 59 = 21 base-set + 38 restored-trio staleness.
