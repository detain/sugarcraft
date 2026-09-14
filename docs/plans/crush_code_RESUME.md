# crush_code.md — RESUME HERE

**Single entry point for continuing the `sugar-crush` audit plan.** Read this file
first, then `docs/plans/crush_code_worklog.md` for the round-by-round record.
Nothing here depends on a prior conversation's context.

🔴 **START AT §0-NOW-81 BELOW. It carries the ROUND-79-CLOSED banner (phase-2 wave-3 complete: na shipped E701 — interactive OAuth authorization-code + PKCE (`sugarcrush mcp auth login <server>`: RFC 8414 discovery, RFC 7591 registration when needed, RFC 8252 loopback + RFC 7636 S256 exchange, complete `AuthEntry` persisted for E695 attachment; in-chat guidance-only, zero Chat.php edits; DocFigure BI/BJ/BK in-step; lane `a14f613ec`+`9d19bd2b5`+`44adf5fe0` → picks `8ed8ada1d`+`7b83a6454`+`1b8cc7ce8`; rv APPROVE-WITH-FIX 0C/0M/3MINOR healed `44adf5fe0`; nd design found the §4 refresh_token buffer-window defect pre-code); floor **11,837 / 170,424 / 0F / 0E / 1S / exit0** LINKED @cwd=sugar-crush @ weld `ca9aac147`), THE PHASE-2 QUEUE AT THE DECISION GATE (sole census survivor E699 — operator ruling: keep-as-is recommended ON RECORD, delete STOP-class, NOT a lane; else fresh audit sweep or bank), the trigger-watch roster incl. NEW `updateRegistration()` redirect-churn seam (nd §2.2) + /tmp/crush-mcp-auth-* tempnam hygiene candidate, the K=8 sharded gate, the SwallowingCatch gate law (held clean r78+r79), and the definition of "done".**
**Every superseded-history `0-NOW-<n>` section and the frozen §1–§11 reference blocks were trimmed
2026-09-12; the per-round history is recoverable via git ancestry of this file, and its surviving rules
were lifted VERBATIM into the appendices below — STANDING ORDERS (rules 1–10, stop-and-ask, rules 59–62,
the provenance quote), ADDENDA -66…-73 full texts, VENDOR & SUITE MACHINERY (§0-NOW-62 §3/§4/§4b/§8 +
the vendor-restore block), ENVIRONMENT & PHILOSOPHY (legacy §§3–8), and the CRAFT-RULES DIGEST.
Trimmed 2026-09-12 per resume-trim/PROPOSALS.md adjudication — full round history recoverable via git
ancestry of this file. Rules are durable; figures are not.

## 0-NOW-81. ROUND 79 CLOSED — PHASE-2 QUEUE AT THE DECISION GATE (E699 OPERATOR RULING IS THE ONLY RESIDUAL) — CURRENT

**Written 2026-09-14 at the round-79 closeout, on top of code tip `ca9aac147` (the re-pin weld; this
closeout's docs commits add the records above it). §0-NOW-80 is DELETED — every live claim it carried is
re-stated here; its machinery survives in the APPENDICES below: operating rules 1–10 VERBATIM (APPENDIX I),
addenda -66…-73 full texts (APPENDIX II — UNCHANGED this close), vendor & suite machinery (APPENDIX III),
the K=8 standard gate (APPENDIX II §1b). APPENDICES I/II/III/IV/V are UNCHANGED at this close.**

**The headline: ROUND 79 (phase-2 MCP wave-3, single code lane) CLOSED — E701 [CLOSED]: interactive OAuth
authorization-code + PKCE shipped by na — `sugarcrush mcp auth login <server>` (RFC 8414 discovery + RFC 7591
registration when needed + RFC 8252 loopback + RFC 7636 S256 exchange, 127.0.0.1-bound listener, state-checked,
deadline-bounded default 300 s, Ctrl-C-cancellable, stores NOTHING on failure arms) persisting a complete
`AuthEntry` that E695's request-time attachment consumes and refreshes; in-chat `/mcp auth login` guidance-only
with ZERO Chat.php edits; MCP.md prose armed IN-STEP by DocFigure arms BI/BJ/BK; +53T exact over 7 new test
files. Lane `a14f613ec`+`9d19bd2b5`+`44adf5fe0` → picks `8ed8ada1d`+`7b83a6454`+`1b8cc7ce8`; review r79-rv-na
APPROVE-WITH-FIX 0C/0M/3MINOR all healed at `44adf5fe0` (live clock seam, canonical RFC 7636 App-B vector,
hash_equals source pin). Design-first held: nd's §4 self-found DEFECT (buffer-window arm must branch on
`refreshToken !== ''`) fixed in tranche-1; its §2.2 `updateRegistration()` redirect-churn seam carried as
trigger-watch. Builder death salvaged per -72 (tranche-2 committed from the uncommitted working tree — audit
before redo, zero rework). Floor **11,837 / 170,424 / 0F / 0E / 1 skipped (McpClientTest canary) / EXIT 0** LINKED
@cwd=sugar-crush at weld `ca9aac147` (+53T exact vs r78 floor; serial green in ONE pass; K=8 CONSERVATION +0/+0;
durations 508→515, exactly +7). SwallowingCatch gate-law HELD CLEAN.**

**Lane naming (in force):** two-letter ids, FIRST letter = round-group (r79=n* closed; r80=o*). Branch
`lane/<id>`, sandbox `crush-lane-<id>`, artifacts `/home/sites/crush-r61-artifacts/<id>/`.

### 1. THE FLOOR — THE ANCHOR, AND ITS DOMAIN

| figure | value | domain |
|---|---|---|
| **ROUND-79 FINAL / NEW ANCHOR** | **11,837 / 170,424 / 0F / 0E / 1 skipped (`McpClientTest` canary) / EXIT 0** (serial green, plain-pipe shape) | **LINKED, cwd = sugar-crush, at `ca9aac147`**; the green serial IS the weld truth (r77 law); ±50 tree-scan wobble expected run-to-run |
| chain | `92a7e0136` (r78 closeout) → `8ed8ada1d`+`7b83a6454`+`1b8cc7ce8` (na picks, linear, all CLEAN) → `ca9aac147` (re-pin weld) → this closeout's docs (worklog+stamps+§0-NOW-81+pointer, then filemap regen) | NEVER pushed |
| companions | K=8 CONSERVATION +0/+0 at weld (fresh `--out`, plain pipe); five-guard 122T/7130A; DocFigure alone **80T** (77+3 BI/BJ/BK); Config window 859T/19201A/1S; citation+roster+swallow combined 152T; Glob pair green (corpus 131,765=365×361); `durations.tsv` **515 rows** (+7 na test files, set-diff exact); runtime config.json md5 `05480c743aff302fd6c06c5a4a4c2210` stable (start==end) | linked-domain figures — published-mode differ |

### 1b. THE STANDARD LOCAL GATE — unchanged (K=8 sharded, FAIL-CLOSED conservation, fresh `--out`, plain pipe; durations manifest must carry a row for every new test file BEFORE sharded conservation means anything; serial is the re-pin truth). Canonical command + regeneration recipe: APPENDIX II §1b. Pass `--against-json sugar-crush/tests/Config/Support/suite-figure.json` for the script's own CONSERVATION verdict line.

### 2. ACTIONABLE QUEUE — 1 SURVIVOR: THE E699 OPERATOR DECISION GATE

**(a) Row census = 1 survivor.** Command run against `docs/plans/crush_code_backlog_triage.md` at this
closeout (OPEN + PARTIAL section tables, status/evidence cells lead-scanned per the census rule):

```
$ awk -F'|' '/^## `OPEN`/{sec="OPEN"} /^## `PARTIAL`/{sec="PARTIAL"}
    /^## `STALE-CITATION`/{sec="STALE"} /^## `UNCERTAIN`/{sec="UNCERTAIN"}
    /^## `SUPERSEDED`/{sec=""} /^\| \*\*E/{gsub(/^ +| +$/,"",$4);
    if (sec!="" && $4 !~ /^\*\*CLOSED/) print sec" "$2}' docs/plans/crush_code_backlog_triage.md
OPEN  **E699** 
```

E701 is CLOSED-in-place (row rewritten to the 4-column closed shape, census rule now passes it); the backlog
heading flipped with its §10-citing CLOSED paragraph. Rows stay put (rounds 64-79 doctrine).

**(b) SURVIVOR + trigger-watch (watches are NOT rows):**

- **E699 — OPERATOR DECISION GATE — the ONLY phase-2 residual**: `ClaudeCodeMcpClient` dormant 983-line stdio
  spawner; keep-as-is recommended ON RECORD (unreachability pin holds); deletion is STOP-class — operator call,
  NOT a lane. Do NOT cut a lane without the operator's word.
- **E696-deny-residual** — if a round ever mints a `denyPatterns` config producer, the deny half must ship
  ENFORCED in the same change (never again "config that lies").
- **NEW: `updateRegistration()` redirect-churn seam** (design nd §2.2) — re-registering with a changed loopback
  redirect_uri can be rejected by servers that pin registrations; revisit if a real-world MCP server trips it.
- **NEW: tempnam hygiene candidate** — `/tmp/crush-mcp-auth-*` leak, 1,878 files observed from
  CommandTableRenderingTest at this closeout; a cleanup lane (or test tearDown unlink) is warranted on any
  hygiene sweep.
- **E309** — DenialKind fourth-case-vs-qualifier product decision; watch only (r70 verdict).
- **E611 tripwire** — reopen if a round ever ships out-of-ownership CODE edits UN-REPORTED.
- **E694 step-3 / E25 re-severity** — agent memory tool DECLINED until a re-severity review + corpus census justify it.
- **E655** — "KeyboardHandlerPaletteTest" PHANTOM stays VOID — do NOT re-issue without a real target.
- **LspClientDispatchPumpTest** — ~10s load-flake under shard contention: ambient, never a lane.

**(c) PHASE OPTIONS for the operator (the queue has no lanes left to cut):**

1. **Rule on E699** (keep-as-is closes it with a stamp; delete is STOP-class machinery work).
2. **Fresh audit sweep** — mint round-80 lanes from tree truth (the E-queue was mined dry at r76; product or
   audit work is the only forward road).
3. **Bank** — the floor stands green and self-guarding; every rule and recipe above is durable.

**(d) Rules unchanged** — operating rules 1–10 + addenda -66…-73 in force, plus the r77 law carried forward:
**every src-touching lane's brief gate list adds `SwallowingCatchCensusTest`** (held clean r78 AND r79 — keep
the gate, it is cheap). Evidence-to-files, land-verify-over-reports, resume-blank-forever,
fresh-recut-on-replay, ≤8 concurrent keep-filled, census-in-step, figures-cite-cwd+mode.

### RESTART INSTRUCTIONS — the new-session startup recipe

**Tip chain:** `92a7e0136` (r78 closeout) → `8ed8ada1d`+`7b83a6454`+`1b8cc7ce8` (na picks) → `ca9aac147`
(re-pin weld, floor anchor) → this closeout's docs (worklog+stamps+§0-NOW-81+pointer, then filemap regen).

1. Read this §0-NOW-81, the worklog `## ROUND 79` section (`docs/plans/crush_code_worklog.md`), and
   `docs/plans/crush_code_filemap.md`.
2. Pre-flight: `php scripts/refresh-deps.php --status` → linked **18/18 + 7/7** — FULL including candy-pty
   (an INCOMPLETE link deterministically poisons 5 InteractivePromptContainmentTest env reds: verify BEFORE
   briefing lanes, r76 lesson); `git status` clean at the tip; if any figure is doubted, run the floor gate
   FIRST: `bash scripts/parallel-tests.sh --durations scripts/parallel-tests-durations.tsv
   --against-json sugar-crush/tests/Config/Support/suite-figure.json` — K defaults to min(nproc,8), ~90s at
   11,837T, **through a PLAIN PIPE, not a PTY** (`-u LINES -u COLUMNS`) — a ctty-carrying harness
   deterministically reddens the `TerminalSizeFallbackIsolationTest` pair + the stdin-pin arms; serial is
   reserved for figure re-pins (long serials: `tmux new-session -d` with `< /dev/null` redirect INSIDE the
   tmux command — r75/r79 proven; setsid and nohup both die, never a PTY harness). Fresh lane sandboxes have NO
   root `vendor/` — run gates as `sugar-crush/vendor/bin/phpunit -c sugar-crush/phpunit.xml`. Residue check:
   the round-79 lane worktree/branch (`crush-lane-na`, `lane/na`) were removed at this closeout (cherry all
   '-'); `sugarcraft-cc` is foreign, leave it alone.
3. THE QUEUE CARRIES NO LANES. Pick a §2(c) phase option with the operator. If lanes are minted (fresh sweep),
   name them o* (r80) off the closeout tip: `git -C /home/sites/sugarcraft worktree add
   /home/sites/crush-lane-<id> -b lane/<id> <tip>` then `php scripts/refresh-deps.php --mode=linked` inside —
   verified working IN-WORKTREE (r73 env fact).
4. Per-bundle loop unchanged: implement (task+coder) → **verify claimed SHAs + worktree (rule 4,
   four-case triage)** → review (task+coder, verdict-first, WITH the guard filters + SwallowingCatch) →
   fix round → merged-suite green (PIPE, not PTY) → commit (author `Joe Huss <[EMAIL]>`). Never push.
   Blank report — builder OR reviewer → RESUME the same task_id (-70, -71); IDENTICAL replay → fresh
   re-cut, SALVAGE-FIRST step 0 (-72); taking over a DEAD session → audit master for unreviewed picks
   FIRST and review-after-merge (-73).
5. Close any future round exactly as r79 did: worklog entry + triage/backlog stamps + a new `§0-NOW-82` +
   filemap regen + `crush_code.md` pointer flip + durations/README/suite-figure re-pin when the lane set
   moves tests (census sextet) — actionable derived from the ROW CENSUS (recount the four section tables, do
   NOT chain; status prefixes must be exactly `**CLOSED`, `**CLOSED (FULL)`, `**CLOSED-VERDICT`, `**OPEN`,
   `**PARTIAL`, `**STALE-CITATION`, `**UNCERTAIN`); re-pin cadence per r78 facts — hand-bump the json tests
   field ONLY, green serial, refresher rewrites json+README pair in one weld commit. NEVER run unanchored
   `re.S` substitutions against the backlog ledger — scope per-section via `re.split(r'(?m)^(?=### E)')`,
   assert exactly-1, verify with numstat + a far-away canary grep (r77 lc wiped 3,837 lines to a git restore).
6. Honor rules 1–10 + the -66…-73 addenda throughout. STOP only at the four stop-and-ask items — plus the
   one live decision: the E699 disposition (keep-as-is recommended ON RECORD).
   one live decision: the E699 disposition (keep-as-is recommended ON RECORD).

---

## APPENDIX I — STANDING ORDERS (the surviving rule set, consolidated 2026-09-12)

The per-round restatements of this block (old §0-NOW-56…§0-NOW-71) were trimmed; the LIVE STANDING
ORDER is the one in §0-NOW-76 above. Everything below it in this appendix is the canonical verbatim
text those restatements pointed at (the superseded sections' line "full text in §0-NOW-69/70 above"
resolves here: rules 1–10 and stop-and-ask were canonical at §0-NOW-65 lines 867–913; rules 59–62 were
canonical at §0-NOW-62 lines 1589–1606).

### Provenance of the standing directive (verbatim user quote, 2026-08-18; was §1)

Stated 2026-08-18: *"do not stop anymore keep going until the plan is 100% completed
unless you cannot proceed further without a decision from me or i told you to pause"*.

### Stop-and-ask + Session operating rules 1–10 (verbatim from §0-NOW-65)

**STOP AND ASK only for these:**

- A decision of the **E639 class taken against the recommendation** (rounds 62–63 took the
  recommendation every time; a non-recommended pick asks first).
- Anything that would **remove** unfinished, dormant, unwired or unreachable code. Standing rule:
  *fix it or wire it, never delete it.* Move and consolidate are fine.
- A **`git push`**, or any change to `prompt_plan.md` / `prompt_resume.md` / `prompt_worklog.md` /
  `prompt_expand.md`.
- A **blanket total-request timeout on an LLM call.** Completions may legitimately run tens of
  minutes; `connect_timeout` is fine, a total cap is not. This is the E646 rule restated — contain
  with the LEASE frame, never with a cap.

#### Session operating rules (user directives — ALL TEN inherited VERBATIM from §0-NOW-64, restated with round-63 evidence)

1. Concurrency ceiling: EIGHT agents/lanes — **KEEP-FILLED**: keep ~8 slots alive with disjoint-lane
   builders / read-only probes rather than idling between phases. Lane sets must be file-DISJOINT
   (ownership maps verbatim into briefs; lanes refuse out-of-lane edits and report seams) — round 63
   proved the mechanism again: 10 lanes, 44 picks, **zero conflicts**.
2. Route ALL implementation through the Task tool with subagent_type `coder` (bash-capable) — **reviews
   too** (the reviewer-agent hard-fails via task). Read-only probes may use the explore agent via
   delegate.
3. Blank/truncated agent response → ALWAYS resume the same task_id and keep resuming until it answers.
   Never diagnose before resuming, never change the prompt, never restart the work. May take 10+
   resumes. (Held through round 63.)
4. Agents sometimes fabricate GREEN reports. Land-verify every claim against `git log` / read-only
   forensic probes — **probes over reports**. Fabrication signature: 3 identical reports → abandon the
   task and re-cut a fresh one. Round-63 proof of the pattern: one agent looped an identical fake
   GREEN ×5; the forensic probe + fresh finisher closed it.
5. At every round close: update worklog + §0-NOW + backlog stamps as supervisor-owned writes, AND
   regenerate `docs/plans/crush_code_filemap.md`; collision-check the filemap **before launching**
   any lane set.
6. **Census trio flips IN-STEP**: any change to a warn/write-site roster flips roster count + uppercase
   anchor word + NUMBER_WORDS map in the same commit. Round-63 extension: the pinned-README system makes
   the trio a **quartet** — suite-figure.json + README headline + artifact must move together (the
   `8c52b26e5` census fix is that lesson re-arming; a lane's OWN guard caught the stale merge figure).
7. **"Expose X on the result/event" ⇒ enumerate the implied DTO(s) in the brief touch-list** — field
   plumbing silently drags sibling files otherwise.
8. Evidence-driven in-scope files must be NAMED BEFORE building (briefs that say "measure, then
   decide" produce seams the merge inherits).
9. LSP diagnostics on `/home/sites/sugarcraft` are cross-lane noise — verify in the lane's own
   sandbox. Agent-internal cwd can reset mid-script — briefs demand absolute paths.
10. Round-64 lane ledger: α–ε (§2) + the E671 STEP-1 bundle + E681 supervisor decision. Sandboxes cut
    FRESH per lane at `8c52b26e5` and **verify the vendor links in each** (`refresh-deps.php --status`;
    the `*** WANTED PUBLISHED ***` banner on linked libs at pre-flight is cosmetic — linked 18/18 + 7/7
    is the gate). Evidence to `/home/sites/crush-r61-artifacts/<lane>/` — the artifacts root KEEPS its
    round-61 name across rounds (round61/62/63 trees live under it). Round-63 sandboxes are deleted
    (11 removed, 26G freed at this close).

### Rules 59–62 (verbatim from §0-NOW-62, "NEW STANDING RULES")

### NEW STANDING RULES

- **59 — name the cwd with every suite figure, and the skip count with it.** The prompt plan spent
  five days on a CI red nobody saw because its figures were measured from `sugar-crush/` without
  saying so. This plan's own skip canary carries the same unstated precondition. A figure without a
  cwd is not a measurement.
- **60 — a plan's own checkmarks are a claim, not a state.** `crush_code.md` under-stated its
  progress by twelve items and `.sugar-crush-build/feat-plan-progress.json` over-stated its remaining
  work by two whole waves, in the same tree, on the same day. Both were settled in minutes by
  grepping the source. Re-derive before scheduling; never schedule from a marker.
- **61 — a parked plan rots in its ENVIRONMENT, not only in its prose.** §0-NOW-61 correctly
  predicted its floor and line numbers would rot. What it did not predict is that the sandboxes and
  worktrees it described as merely stale would be **gone**. Before believing any environmental fact
  in a resume section, run the check rather than reading the sentence.
- **62 — prove the vendor closure BEFORE the suite, not after a red.** A tree can be short of
  required packages entirely, not merely swapped to Packagist, and it still produces a plausible test
  count and a "good" skip count. `refresh-deps.php --status` is the only instrument that sees it.
  A figure whose closure was not checked first is not a floor.

---

## APPENDIX II — RULE ADDENDA -66 … -73 (full texts; all in force)

The live §0-NOW-74 block restates each addendum one-line; the full texts follow. The -71 block was
PROMOTED VERBATIM from the §0-NOW-71 body at the round-70 closeout (that section's live block was
superseded by §0-NOW-72 — the text below is byte-carried from `4da36922a`'s copy of this file);
**the -72 block is MINTED at the same close** (the gb phantom-replay salvage — see the worklog
ROUND 70 process finding); **the -73 block is MINTED at the round-71 close** (the merge-after-the-fact
salvage — see the worklog ROUND 71 process finding). The -69, -70 and §1b blocks are kept
verbatim from §0-NOW-69/§0-NOW-70; the -66, -67 and -68/-68b blocks are PROMOTED verbatim from
§0-NOW-66/§0-NOW-67/§0-NOW-68 under the literal-promotion gate (items 20+21): their un-carried
literals — the rule-6 merge SEXTET enumeration, the CI K=min(nproc,4) rule with its refutation cite,
the ≥2×-CPU lease-race rationale, the `'(?:s)?'` corpus re-shape idiom, the PTY-PIPE keystone line
cite and its carried seam, the PROSE_SITES/BootstrapLaunchFormatConstantsTest detail, and the
BUILDER-GATE F4/by-path `4610f3580` cites — exist nowhere else in the trimmed document.
(The merge-agent session id was verified carried twice in the pre-promotion §0-NOW-71 body — its live
restatement now lives in §0-NOW-74's restart recipe step 4 and in the promoted -71 bullet below.)

**ADDENDA at -66:**

- **Rule 6 is now a SEXTET at merge**: the quartet (suite-figure.json + README headline + artifact +
  guard) plus `scripts/parallel-tests-durations.tsv` plus README — a new test file without a manifest
  row makes CI conservation go red. The final re-pin `e028f142c` moved 11,459→11,462 exact-predicted.
- **Every review brief includes the `SwallowingCatchCensus` + `OneSidedHomeSandbox` filter groups** —
  round 64 proved targeted filters miss tree-wide guards twice (ac's start-failure contracts; ad's own
  new tests reddening the censuses).
- **Never oversubscribe CI K** — K=min(nproc,4); ≥2× CPU pressure trips the lease/idle-ceiling races
  (K=8 refuted by measurement at `f67328f94`).


**ADDENDA at -67 (five):**

- **Rule 6 is a SEXTET at merge**: suite-figure.json + README headline + artifact + guard +
  `scripts/parallel-tests-durations.tsv` + README prose — both r65 re-pins (`74d16914e`, `0c61c0686`)
  flipped the sextet as one set; the durations manifest now carries 489 rows.
- **GUARD-FAMILY FILTER MANDATE (every builder AND reviewer brief)** — run, in addition to the task
  filter: `--filter '(SwallowingCatchCensus|OneSidedHomeSandbox|DuplicatedTestHelperDrift|GlobDialectDifferential|ChildWallClockBudget)'`.
  Three consecutive rounds of targeted-filter misses (r64 ac/ad; r65 wave-1 + bd) make this proven, not
  theoretical; the five-guard family stood 50T/4526A EXIT 0 at tip.
- **Never oversubscribe CI K** — K=min(nproc,4); ≥2× CPU pressure trips the lease/idle-ceiling races
  (unchanged from -66).
- **Re-shape the corpus BEFORE re-pin** — a new string literal that the harvesters read (glob dialects)
  moves guarded figures; prefer the token variant with identical meaning (`'(?:s)?'`, not `'s?'`) so the
  documented number stays TRUE; re-pinning a guard to your own residue converts a tripwire into a
  rubber stamp (bd review-2 `d26dd4377` is the pattern; final re-pin `0c61c0686` carried the legitimate
  131,040→131,765 corpus re-measure only after the re-shape).
- **Census PROSE_SITES rows legitimize out-touch-list numeral flips** — when a census scan obliges a
  numeral edit in a file outside your lane (bd: `Chat.php` docblock, `McpToolWiringTest`,
  `docs/SETTINGS.md`), flip it IN-STEP, numeral-only, zero behavior, and declare it in REPORT. And
  enumerate every census that scans a lane file BEFORE writing the brief — `BootstrapLaunchFormatConstantsTest`
  was the fifth Bootstrap census nobody predicted (any new `sprintf` there obliges it).


**ADDENDA at -68 (two):**

- **PTY-PIPE RULE for suites** — never run a full suite under a ctty-bearing harness: a PTY runner
  exports `LINES/COLUMNS` (measured 40×120 and 50×254 shapes) which deterministically reddens the
  lane-G keystone (`TerminalSizeFallbackIsolationTest:167` piped branch — the renderer env-fallback
  probe answers the ambient tty size, not the documented 60×200). Full suites run through a PLAIN PIPE
  (the CI shape). Carried seam: the keystone assumes `LINES`/`COLUMNS` unset when stdout is piped.
- **MEASURE-FIRST VERDICT DISCIPLINE (keep)** — every closeout stamp is adjudicated against git/tree
  evidence BEFORE it is written, and CLOSED requires cited SHAs/lines. Worked twice this round (cg and
  ch were zero-diff already-landed verdicts; E43 kept its PARTIAL because its routed carrier E682
  shipped a different rule than the adoption half). Write the evidence lines INTO the stamp text.

**ADDENDA at -68b (wave-1, two):**

- **BUILDER-GATE GUARD MANDATE** — every builder brief's gate must run, IN ADDITION to its own task filter
  and the five-guard family, the **Integration/MemoryPromptWiring-adjacent guards + DuplicatedTestHelperDrift**
  (F4 at `9f0c5db62` was the FOURTH full-suite-gate-miss of the era — an implied DTO edit invisible to every
  in-lane filter; the merged suite is the only honest gate).
- **by-path `require` = load-graph coupling** — moving a symbol that any sibling tool requires BY PATH breaks
  at LOAD time where no test filter runs (dc×df at `4610f3580`); grep consumers of moved symbols before the
  move, and require canonical instruments by path — never fork a third copy.


**ADDENDUM at -69 (one — the FABRICATION-VERIFICATION rule, expands rule 4):**

- **A claimed SHA is a hypothesis, not a fact.** Before a lane report triggers anything downstream
  (review queue, merge, stamp), run `git -C <sandbox> cat-file -e <sha>^{commit}` and confirm the worktree
  actually holds the commit (`git -C <sandbox> log --oneline -1`). If the SHA is missing: do NOT re-ask the
  agent for a re-report; dispatch a read-only forensic probe that asks OPEN questions ("what commits does
  this worktree hold?"), never questions that name the expected SHA. Landed-but-unreported → read the diff
  and review it; reported-but-not-landed → honest re-cut on a fresh sandbox (both r67 re-cuts came in clean
  — fabrication is agent-specific, not task-family).

**Carried from -68b (still binding):** the BUILDER-GATE GUARD MANDATE (Integration/MemoryPromptWiring-adjacent
+ `DuplicatedTestHelperDrift` in every builder gate) and by-path `require` = load-graph coupling.
**Carried from -67 (still binding):** the rule-6 merge SEXTET; never oversubscribe CI K (CI legs keep
K=min(nproc,4)); GUARD-FAMILY FILTER MANDATE in every builder AND reviewer brief
(`--filter '(SwallowingCatchCensus|OneSidedHomeSandbox|DuplicatedTestHelperDrift|GlobDialectDifferential|ChildWallClockBudget)'`
— 64T/4913A at tip); re-shape corpus literals before re-pin; census PROSE_SITES legitimize in-step numeral
flips; enumerate every census on a lane file before writing the brief. **Carried from -68:** the PTY-PIPE
rule (full suites through a plain pipe, never a ctty-bearing harness) and MEASURE-FIRST VERDICT DISCIPLINE.

**Lane naming (in force):** two-letter ids, FIRST letter = round-group, SECOND = lane within the round
(r64=a*, r65=b*, r66=c*, r67=d*, **r68=e***). Branch `lane/<id>`, sandbox `crush-lane-<id>`, artifacts
`/home/sites/crush-r61-artifacts/<id>/` (the artifacts root keeps its round-61 name across rounds).


### 1b. THE STANDARD LOCAL GATE IS NOW K=8 SHARDED (lane ks, `fb5078e6d`)

```sh
bash scripts/parallel-tests.sh [K] --durations scripts/parallel-tests-durations.tsv \
  --against-json sugar-crush/tests/Config/Support/suite-figure.json
```

Default K=min(nproc,8); conservation-vs-`suite-figure.json` is FAIL-CLOSED for tests/skips/errors/failures
(assertions tolerate ±50 data-provider wobble BY DESIGN — do not read an assertion delta as gate breakage).
**Fresh `--out` per timing run** — the default `/tmp/parallel-tests` carries `done-*` resume markers.
`durations.tsv` regeneration recipe (canonical): one serial `--log-junit` baseline → feed it back via
`scripts/parallel-tests.sh --junit <xml> --out <dir>` (make-shards writes `<dir>/durations.tsv`) → `cp` over
`scripts/parallel-tests-durations.tsv` → `refresh-suite-figure.php` in the same step as the README headline.
GOTCHA: `usage()` is `sed -n '2,43p' "$0"` — ANY edit to the header comment block must re-anchor the range.
Run the SERIAL suite only at figure re-pins; CI legs keep explicit K=min(nproc,4) (2–4 vCPU runners — the
E671 oversubscription refutation is runner-scoped).


#### Session operating rules (1–10 inherited VERBATIM from §0-NOW-65; the -66/-67/-68/-68b/-69 addenda all still in force — they are restated in §0-NOW-69 above this section)

**ADDENDUM at -70 (three):**

- **RESUME-DON'T-RESTART is PROVEN.** ea returned five blank/truncated reports; resuming the SAME task_id
  each time landed work that verified exactly as finally claimed (rule-3 counts to 10; land-verify once
  after the FIRST blank via read-only forensic probe, then keep resuming — do not re-spawn, do not change
  the prompt, keep other lanes moving).
- **`parallel-tests --durations` silently excludes NEW test files.** The committed durations manifest
  predates them, so shard sums under-count until a merge agent rows them (r68/ec: shard-sum 11,574 vs
  serial 11,591 — the two new files invisible to the plan). **SERIAL is the truth for conservation** until
  durations is regenerated; the merge agent owes durations before trusting any sharded conservation gate.
- **Perturbation-reverify pattern.** E107's closeout is the TEMPLATE for re-verifying trigger-missed
  guards: re-run the guard's OWN claimed mutations on the live tree one plant at a time, md5-verified
  revert, per-mutation table to an evidence file
  (`/home/sites/crush-r61-artifacts/closeout-r68/e107.md`, driver `e107.php`, progress
  `e107-progress.txt`). 8/8 families reddened → the guard is NOT blind; a CLOSED-without-evidence process
  item can be settled in one closeout afternoon.

**Carried from -69 (still binding):** the FABRICATION-VERIFICATION rule — a claimed SHA is a hypothesis;
`git cat-file -e` + worktree existence BEFORE review queueing; neutral probes never name expected SHAs.
**Carried from -68b (still binding):** the BUILDER-GATE GUARD MANDATE (Integration/MemoryPromptWiring-adjacent
+ `DuplicatedTestHelperDrift` in every builder gate) and by-path `require` = load-graph coupling.
**Carried from -67 (still binding):** the rule-6 merge SEXTET; never oversubscribe CI K (CI legs keep
K=min(nproc,4)); GUARD-FAMILY FILTER MANDATE in every builder AND reviewer brief
(`--filter '(SwallowingCatchCensus|OneSidedHomeSandbox|DuplicatedTestHelperDrift|GlobDialectDifferential|ChildWallClockBudget)'`);
re-shape corpus literals before re-pin; census PROSE_SITES legitimize in-step numeral
flips; enumerate every census on a lane file before writing the brief. **Carried from -68:** the PTY-PIPE
rule (full suites through a plain pipe, never a ctty-bearing harness — and unset/avoid ambient
LINES/COLUMNS: the ce 50x254 poisoning is now a stamped keystone precondition) and MEASURE-FIRST VERDICT
DISCIPLINE.

**Lane naming (in force):** two-letter ids, FIRST letter = round-group, SECOND = lane within the round
(r64=a*, r65=b*, r66=c*, r67=d*, r68=e*, **r69=f***). Branch `lane/<id>`, sandbox `crush-lane-<id>`, artifacts
`/home/sites/crush-r61-artifacts/<id>/` (the artifacts root keeps its round-61 name across rounds).

**ADDENDUM at -71 (three):**

- **PROCESS-GLOBAL SIZE-CACHE POLLUTER CLASS.** `Tui\Renderer::$terminalSize` is process-global; a tearDown
  leaving an EXPLICIT non-default size (ShellContrastTest's 120x40, fixed `c2b695867` by re-pinning to
  setSize(200,60)) is the loud variant of the lane-G null-cache family. Serial order masks it; any LPT
  reshard can co-bucket the keystone `TerminalSizeFallbackIsolationTest` after a polluter. **Future
  durations regens may expose more of this family — the fingerprint is a conservation-FAIL delta equal to
  exactly the keystone's remaining assertion count.**
- **BLANK-REPLAY extends to REVIEWERS.** ff's reviewer returned four blanks; resumed same task_id (rule 3),
  report stable 3 of 4 tries → treated as REPLAY and verified against the builder gate + merge gate before
  accepting. RESUME-DON'T-RESTART is not builder-only.
- Merge agent `ses_f71e7c5f8ffenNcgJuOVHRlMc7` now proven **8 rounds**.

**ADDENDUM at -72 (one — the PHANTOM-REPLAY / SALVAGE-FIRST re-cut law, expands rule 4 + -69):**

- **A REPLAYING session is dead weight — re-cut, and open the re-cut with SALVAGE.** Rule 3's
  keep-resuming is for BLANK/truncated answers. When a builder returns the IDENTICAL report on every
  resume (rule 4's three-identical-reports fabrication signature), stop carrying it: dispatch a fresh
  re-cut whose STEP 0 is salvage — `git cat-file -e` the phantom's claimed SHAs and read the worktree
  BEFORE redoing any work. Round-70 proof: gb's phantom had in fact LANDED `fdeddc1f3` (contrast r67
  di/dl, whose phantoms landed nothing); the re-cut verified the diff file-by-file against the brief,
  re-ran all three gate families green at the lane tip (43T/4231A targeted, five-guard 45T/4525A,
  StderrEmitterCensus 95T/6194A), and ACCEPTED the salvage with ZERO new commits — the reviewer then
  land-verified independently (report-replay canary clear). A replaying report is a REPORTING failure,
  not necessarily a work failure; the tree, not the session, is the authority.

**ADDENDUM at -73 (one — the MERGE-AFTER-THE-FACT rule, expands rule 4 + -72):**

- **On taking over a dead session, audit master for unreviewed picks BEFORE starting any new work —
  review-after-merge is a valid salvage path.** Round-71 proof: the merge session landed all four lane
  picks (`0c6820f39` gf E390, `571dcf85d` gi E325, `7924075c7` gg E686-t8, `9d05c5e9f` gh E493-consumer)
  plus the HOLD-FIX ratification `52121fcac`, then died mid-round — the per-lane reviews never ran and the
  round was never closed. The successor did NOT revert or re-implement anything: it identified every pick
  from `git log`, ran all four lane reviews against the MERGED tree (evidence
  `/home/sites/crush-r61-artifacts/r71-rv-{gf,gi,gg,gh}/REVIEW.md` — every verdict APPROVE), and healed the
  single MAJOR finding (gg arm AP's hand-typed eleven-layer roster — the `RuleLoader`→`RuleReader` M10
  mutation survived it) as a regular review-fix pick: lane gg2 `b123b1355` → master `00bab5d21`, the arm
  now DERIVES its roster from the ARCHITECTURE.md cites it polices (bidirectional cite↔roster legs,
  `interface_exists || class_exists`, the `(?:\(\))?` regex law). The sextet re-pin `328b14d91` then closed
  the floor. Review is a quality gate, not a transaction boundary — but an unreviewed pick is salvageable
  ONLY if the review actually runs afterward and its findings land as normal fix commits with mutation
  proofs.

---

## APPENDIX III — VENDOR & SUITE MACHINERY (surviving §0-NOW-62 sections + the vendor-restore block)

Declared still in force by every live header since. The other §0-NOW-62 sections (§1, §2, §5, §6, §7)
were round-61 state history and were trimmed; §8 is kept with its (b) list replaced by a pointer per
the 2026-09-12 adjudication.

### 3. 🔴 THE VENDOR CLOSURE WAS SILENTLY BROKEN, AND IT COST THIS SESSION A WHOLE SUITE RUN

**MEASURED 2026-09-10, before any of the figures above were trusted.** `sugar-crush/vendor/sugarcraft/`
held **14 entries where the manifest's closure needs 18**. `candy-forms`, `candy-focus` and
`candy-kit` — all three named directly in `sugar-crush/composer.json`'s `require` — were **absent
from vendor entirely**, and four more (`candy-fuzzy`, `candy-mouse`, `honey-bounce`, `sugar-veil`)
were real Packagist directories where the other ten were monorepo symlinks. `refresh-deps.php
--status` called that state **`mixed 10/14`**.

A full suite run in that state returned **11,239 tests / 104,843 assertions / 1,619 errors /
21 failures / 1 skipped**. **None of those numbers mean anything** — they are a report on a broken
autoloader, not on this tree. The user then refreshed the libs and the closure became
**`published 0/18`**: complete, all eighteen present, all resolved from Packagist `dev-master`.

Three things follow, and the third is the one that will bite a future round:

1. **`refresh-deps.php --status` is a PRE-FLIGHT, not a diagnostic.** Run it *before* the suite, every
   round, in the supervisor tree and in every lane. This session ran it after a red and lost a
   twelve-minute run. `CONTRIBUTING.md` and `AGENTS.md` both already say a bare `composer update`
   swaps symlinks for Packagist copies; what neither says is that the closure can end up **short**,
   with required packages missing outright, which is what happened here.
2. **A red suite is not evidence of a defect until the closure is proven.** 1,619 errors looked like
   catastrophe and was bookkeeping. Rule 18 already says derive a figure from a run; this adds:
   **derive the run's validity from `--status` first.**
3. ⚠️ **`published` and `linked` are DIFFERENT TREES and may give different figures.** §4b's lane
   recipe verifies **18 symlinks**, i.e. `linked`. The supervisor tree is currently `published`.
   Neither `prompt_resume.md`'s floor nor §0-NOW-61's names its mode. **So state the mode next to
   every figure from now on, alongside the cwd** — and before comparing a lane's figure to the
   supervisor's, confirm both are in the same mode. If they are not, the difference is not a finding.
4. 🔴 **`candy-pty` IS IN THE SAME STATE, AND ITS LOCK WAS REGENERATED TODAY.** MEASURED
   2026-09-10: `candy-pty` is `published 0/7` — zero symlinks, not the **7** §4b's recipe expects —
   and `candy-pty/composer.lock` is 115,257 B dated **2026-09-10 02:08**, i.e. rewritten during this
   session's dependency refresh. Backlog **E630** recorded that file at 113 KB / 2026-08-22 and warns
   that regenerating it is the action that makes CI's path-repo injection a no-op. It is `.gitignore`d
   (`candy-pty/.gitignore:1`), so this is **not** a policy breach and CI is unaffected — but any
   `candy-pty` figure measured locally from here is a `published`-mode figure, and §0-NOW-61's carried
   `644 / 1785 / 16 / 1 warning` predates all of it.


### 4. THE SKIP-COUNT CANARY IS CWD-DEPENDENT, AND THAT WAS NEVER WRITTEN DOWN

§0-NOW-61 predicted this canary would misfire and was right, but named the wrong mechanism. It is not
that the prompt plan added a skip. It is that **the two programs measure from different working
directories, and the skip count is a function of which one you use.**

- The rule every round-45..60 lane brief hardcodes — *"the skip count must stay exactly 1"*, the one
  skip being `tests/MCP/McpClientTest.php`, and *"a 2 means the vendor closure is gone and every
  figure since is void"* — was always **cwd `sugar-crush/`**. It never said so.
- `prompt_plan.md` measures **MASTER-DIRECT from the checkout root** and records **2 skipped** as its
  steady state across sixty-five steps. That plan's own hard-won rule is: **EVERY SUITE FIGURE MUST
  NAME THE CWD IT WAS MEASURED FROM** — it went five days on a red CI nobody saw for exactly this.
  ✅ **CONFIRMED 2026-09-10 by this session's own green run**: cwd = repo root, `published`, gives
  **2 skipped**. So both values are correct and neither is a fault — they are two different cwds.
- ⚠️ **And per §3 the canary's stated MEANING is now known to be unreliable in the loose direction.**
  This session's broken-closure run reported **1 skipped** — the "good" value — while three required
  packages were missing. **A skip count is a canary for one specific vendor fault, not a closure
  check.** `refresh-deps.php --status` is the closure check.
- So: **state the cwd, the vendor mode, and the expected skip count for that pair, in every round-61
  lane brief.** A brief that says "exactly 1" without naming `sugar-crush/` will send three lanes to
  conclude their sandbox is broken when it is not. Confirm all three at your own base before writing
  them.


### 4b. THE LANES ARE GONE — RE-CUT FROM SCRATCH, THERE IS NOTHING TO REFRESH

§0-NOW-61 says the three lane dirs are `cp -a` copies at `09139a807` and are "far behind". They are
not behind; **they do not exist.** MEASURED 2026-09-10 — `git worktree list` shows the main checkout
only, and `/home/sites/crush-lane-{a,b,c}` are absent, as are the `prompt-step-FU*` worktrees
`prompt_resume.md` records as "RETAINED DELIBERATELY". Re-cut from `3bf356bd6`, then verify per lane
before launch:

```sh
php scripts/refresh-deps.php --status | grep -E 'sugar-crush|candy-pty'   # RUN THIS FIRST, see section 3
php -r 'echo count(array_filter(glob("/home/sites/crush-lane-a/sugar-crush/vendor/sugarcraft/*"),"is_link"))."\n";'  # expect 18
php -r 'echo count(array_filter(glob("/home/sites/crush-lane-a/candy-pty/vendor/sugarcraft/*"),"is_link"))."\n";'     # expect 7
```

⚠️ **The `18` is a COUNT OF SYMLINKS, and this session measured a tree with eighteen entries and
ZERO symlinks that passes a naive count.** Assert both halves — eighteen entries AND eighteen links —
or the check passes on a `published` tree. Do NOT settle it with `ls`: `ls -l | grep -c '^l'` has
printed a correct-looking 18 twice while every entry was a real directory. **Never run
`composer install`/`update` in a lane root.** Re-derive the two expected counts if a sibling dep was
added; they are carried from §0-NOW-61 and the sugar-crush one is now independently confirmed at 18.


## ⚠️ VENDOR STATE IS NOW A THING TO CHECK, and it silently changes what "green" means

The mid-round 2026-08-19 incident is landed history; the durable fact survives verbatim — something
ran `composer update` and swapped `sugar-crush/vendor/sugarcraft/*`'s symlinks for published copies,
with no signal except a skip count moving 1 → 2 (`GitignoreAwarenessTest` self-skips when there are
no symlinks). **A 2-skip run means you are not testing the monorepo.**

Restore local wiring with the documented loop, and note it is `sugarcraft/*` scoped so third-party
versions do not move:

    php tools/check-path-repos.php --fix --strict-closure
    cd sugar-crush && composer update 'sugarcraft/*' --quiet
    cd .. && git checkout -- '*/composer.json'      # NEVER commit these
    php tools/check-path-repos.php --no-lib-path-repos   # must exit 0

`vendor/` is gitignored, so reverting the manifests keeps the symlinks AND a clean tree. **Tell
every agent not to run `composer install`/`update`** — it silently undoes this.

---

## (moved) §0-NOW-62 §8 — WHAT "COMPLETE" MEANS — THE PATH TO THE END

### 8. WHAT "COMPLETE" MEANS — THE PATH TO THE END

Three things stand between here and done. Work them in this order.

**(a) The backlog rounds.** ✅ **THE BACKLOG NOW HAS A DISPOSITION MARKER ON EVERY ENTRY.** The
"no open/closed marker" problem is SOLVED. A 22-agent triage sweep on 2026-09-10 read all 645
entries and re-verified each against the tree at `3bf356bd6`; every `### E<n>` heading now carries a
bracketed stamp, and the evidence lives in two new files:

- **`docs/plans/crush_code_backlog_triage.md`** — the ledger: disposition, confidence, file:line
  evidence and a per-entry note, grouped by disposition. **Read the note column before acting on any
  entry's Step.**
- **`docs/plans/crush_code_backlog_triage_reconciliations.md`** — the cross-batch conflicts the
  supervisor resolved, the duplicate entries that must be collapsed rather than scheduled twice, the
  entries that must be scheduled TOGETHER, and the scheduling unlocks found along the way.

| disposition | n | share |
|---|---:|---:|
| `CLOSED` | 334 | 52% |
| `NO-FIX` | 143 | 22% |
| `OPEN` | 122 | 19% |
| `PARTIAL` | 37 | 6% |
| `SUPERSEDED` | 7 | 1% |
| `UNCERTAIN` | 1 | <1% |
| `STALE-CITATION` | 1 | <1% |

**161 actionable (`OPEN` + `PARTIAL` + `UNCERTAIN` + `STALE-CITATION`); 484 need nothing.** So the
real remaining backlog is about a quarter of what its 645 entries implied.

🔴 **THREE THINGS THAT SURVIVE THE STAMPING, and none of them is a count.**

1. **A stamp says nothing about whether the entry's STEP is still correct — in EITHER direction.**
   `CLOSED` often means "fixed, but not the way this entry says" (E219 would be a REGRESSION if its
   Step were followed today; E7, E33, E120, E141, E178, E187, E211, E212, E239 share the shape), and
   an `OPEN` entry can carry a refuted prescription too (E417's fix was MEASURED not to work in
   round 54; E419's mechanism is corrected in the tree). **The note column is the authority, not the
   stamp.**
2. **The stamps were derived at `3bf356bd6` and rot the moment code lands.** Re-verify any entry
   whose files a round has moved, per rule 60. They are a starting point, not a standing truth.
3. **Structural defects in the file itself.** Headings are **not** in numeric order (`E333` sits
   between `E303` and `E304`; `E364` between `E332` and `E334`), so a numeric scan is not a complete
   scan. **`E57`-`E61` AND `E70`-`E72` are referenced with no heading — eight ids, not the three
   §0-NOW-61 recorded.** `E78` carries two extra sub-entries stamped `E78b`/`E78c`.
   **645 headings, 643 distinct ids, 651 ids referenced.**

(b) open plan items: see §0-NOW live section + docs/plans/crush_code_hardening_backlog.md — a plan's own checkmarks are a claim not a state (rule 60).

**(c) Two process attestations inherited from the closed `crush_feat_plan.md`,** neither of which is
code: **W4.S5**'s full-suite-vs-baseline confirmation has no *current* artifact (its recorded
3,653-test green run predates ~30 rounds of later work — §2's observed floor supersedes it), and the
plan's **manual real-terminal E2E pass** (kitty/iTerm2/sixel, mouse tab-switch, inline image,
`/doctor` protocol report) has no recorded result. It was explicitly never gated by a review agent.
Both need a human at a real terminal; neither blocks (a) or (b).


---

## APPENDIX IV — ENVIRONMENT & PHILOSOPHY (legacy §§3–8, unchanged; state claims in them froze and are
## superseded by §0-NOW-74 — the rules and facts are the durable part)

## 3. Sequencing rules

- **Functionality first.** Security/hardening and audit-instrument correctness are
  deferred to the end. **Defer the FIX, never the FINDING** — record every deferred
  item in `docs/plans/crush_code_hardening_backlog.md` with its probe, in the
  What/Where/Severity/Evidence/Step/Blocked-on format. The user's goal is to
  daily-drive sugar-crush while the security pass is still being worked.
- **Counts as functionality, fix it now:** frame-corruption bugs (over-wide rows —
  the diff renderer paints one line per row), automatic data loss, a confirmed RCE
  path.
- **Counts as deferrable:** path-containment gates, permission-surface tightening,
  tool-capability filtering, mutation registers / censuses / inventories.
- **Never remove dormant code — wire it or document it as an intentional seam.** The
  audit's own research agents made several "delete this" recommendations that were
  explicitly overridden. Honor that override.
- **Serialise anything touching the same file.** A *suite run* that loads a file
  another lane is editing shifts `file(__FILE__)` ranges against already-loaded
  reflection and produces phantom failures. Serialise the runs, not just the writes.

## 4. Environment facts

- Commit with a **plain `git commit`**. Never `-c core.hooksPath=/dev/null`, never
  `--no-verify` — the user wants a hook they add later to actually fire. There is no
  active git hook here (`.git/hooks/` is samples only, `core.hooksPath` unset).
- **Never run `caliber` anything.** The Caliber hooks were removed from
  `~/.claude/settings.json`; backup at `~/.claude/settings.json.bak-precaliber-removal`.
  The tracked `<!-- caliber:managed -->` blocks in CLAUDE.md/AGENTS.md are correct for
  machines that *have* Caliber and were deliberately left in place.
- **Never run a global `pkill`** — the `[p]hpunit` bracket trick still kills sibling
  agents' test runs. Kill only PIDs you started.
- Full suite: `vendor/bin/phpunit`, ~2m20s.
  **Allow a 600000ms Bash timeout** or a 2-minute default kills it with exit 143 and
  you will misread that as a failure.
- `failOnRisky`/`failOnWarning` are load-bearing: a warning-only kill is red *purely*
  via exit code while the banner still prints "OK, but there were issues!". Check `$?`,
  never the banner.
- `php-cs-fixer` is NOT installed here, and this lib is not cs-fixer-clean repo-wide.
  Don't report its absence; don't normalise unrelated files.
- Never commit a per-lib `composer.lock`; no `repositories[]` in a lib manifest.
  Verify with `php tools/check-path-repos.php --no-lib-path-repos` (must exit 0).

## 5. THE recurring defect — twenty-six rounds running

**A number or a claim must never travel without its domain.** A count, width, limit,
or behavioural claim that is true of one thing, written next to a different thing.
It has appeared in *every single round*, including inside the work of the agent
fixing the previous round's instance of it, and in the supervisor's own notes.

**Round 18 looked like progress and round 19 priced it.** B3's implementer self-caught
three instances, including a test whose NAME asserted a completeness its body did not
have — real progress. It then reported "28 mutations, 28 killed, 0 survivors". The
independent reviewer ran **55 mutations and found 9 survivors** plus 17 confirmed
findings. So self-catching does not substitute for the review round; it just moves where
the round's findings come from. **Never accept an agent's own mutation score as
coverage** — the number that matters is what a reviewer who did not write the code can
still break.

The single sharpest recurrence: `testAConnectExceptionIsTransient` passes through the
`TransferException` fallback, so replacing its named clause
(`$link instanceof NetworkExceptionInterface`) with `if (false)` survived 2863 tests. The
round before had the identical shape. **A test named after a clause is not a test of that
clause.**

Its companion, found repeatedly since: **tests pin the PRESENCE of a clause and not
its TRUTH.** One review ran 18 mutations — 13 died, and **all 5 survivors made a
clause false while keeping its keywords intact.** So:

- Measure before writing. Name the domain in the same sentence. Say the **unit**
  (this codebase has *estimated* chars/4 tokens vs *provider-counted* tokens, and
  they get confused constantly).
- Prefer deriving a value at runtime over writing a literal.
- A docblock clause nothing asserts is this defect in prose form. Either pin it
  behaviourally or name it as an honest gap — never add a presence check that looks
  like coverage.
- **Changing a fact falsifies every place that described the old one.** Sweep `src/`,
  `tests/`, `README.md` and `sugar-crush/docs/` — a past round's sweep missed `tests/`
  and shipped a stale claim.

## 6. Files that must not be touched by agents

`sugar-crush/phpunit.xml` (the supervisor's) · `/home/sites/sugarcraft/.sugar-crush/config.json`
(git-tracked; md5 must stay `05480c743aff302fd6c06c5a4a4c2210`) ·
`docs/plans/plans_cleaning.md` and `sugar-crush/python_port/` (the user's own work —
both are **git-TRACKED**, 18 files under `python_port/`; an earlier revision of this
line said "untracked", which was a wrong reason for a right rule and invited the
reading that edits there are invisible) · `docs/plans/crush_code_worklog.md` and this
file (supervisor-owned).

`crush_code.md` is the plan — edit its status block inline as items land; it IS
tracked, contrary to an earlier belief.

## 7. Sandbox recipe for mutation testing (hand this to every agent)

`cp -a` — **never `cp -al`**, which preserves relative `vendor/sugarcraft/*` symlinks
that then dangle into a phantom `Interface "SugarCraft\Core\Model" not found`.
Re-point each relative symlink **explicitly** at `/home/sites/sugarcraft/<lib>`;
naive "absolutising" produced self-referential links for three separate agents. Copy
`.sugar-crush/`, `.vhs/`, `examples/`, `bin/`, `workflows/` too or ~10 fixture tests
fail. Assert `ReflectionClass::getFileName()` is inside the sandbox before believing
a run. Judge a mutation by whether the **targeted test file** flips green→red via
`$?` — never by suite totals.

## 8. Known-stable test facts

- The **1 legitimate skip** is `McpClientTest::testLoadConfigReturnsEmptyArrayWhenFileGetContentsFails`
  ("Would require mocking built-in functions"), in **`tests/MCP/McpClientTest.php`** —
  two files share that class basename, so cite the path, not the class. Leave it.
- `SystemPromptWiringTest::testARealChatKeystrokeTurnDeliversBothHalves` is a
  **pre-existing timing flake**. Don't skip it, don't weaken its assertion, don't
  report it as a finding.
- `src/Support/ToolIpcFiles.php:79` `private const STAT_REGULAR_FILE = 0o100000;` is
  an **octal file mode** — a false positive for any `100000` grep-and-replace.
- **The `tests/Cli` hang is DIRECTORY-scoped only, and this matters a lot.**
  `vendor/bin/phpunit tests/Cli` hangs (>4min, backlog E29) — but a single FILE inside it
  runs in **0.054s at rc 0**, and `--filter` against a single file is ~0.02s. Corrected
  2026-08-19: the old blanket "never judge green from a directory-scoped run" was
  discouraging the only affordable mutation harness in this suite. Use single-file runs
  freely for mutation loops; only the FINAL green/red judgement needs the full configured run.
- `tests/Cli/BootstrapSkillSkipsTest.php` run **alone** is rc=1 (`OK, but there were issues!
  Risky: 2`) on a clean tree and contributes 0 risky in the full suite — order-dependent,
  pre-existing, backlog **E36**. Do not chase it as a regression. (round-68 lane ec ruled E36 CLOSED/not-reproducible — still: don't chase, don't gate on it).
- **Six test files did not clear the backend-selection env chain** until `6bc5218b`; with
  either shell-out variable ambient the suite showed 1 error + 10 failures. Now handled by
  `tests/Support/BackendSelectionEnvSandboxTrait.php`, which holds the chain ONCE. If you add
  a `SUGARCRUSH_*` variable to backend selection, add it to that trait's `CHAIN`, not to a
  tenth hand-written list.
- `Chat::shouldPromptIdleCompaction()` **deliberately** duplicates `Runtime`'s
  version ("where Runtime instance is not directly available"). Don't collapse it by
  making `Chat` reach for a `Runtime` it deliberately does not hold.
- **The suite baseline moves every round, including between a bundle's implement and
  fix rounds. MEASURE `HEAD`'s total before briefing; never quote a remembered
  figure.** Bundle B2's brief quoted 6918/70996 when B1's fix round had already moved
  it to 6931/71073, and the implementing agent had to stash, run, and pop to find out.
  That is the supervisor committing the same defect §5 describes — a number written
  next to the wrong domain, here "the baseline" meaning two different commits.
- Two numbers in `tests/Tools/BuiltInToolCorpusTest.php` are censuses over `src/`
  (file count and declaration count) and `BinSugarcrushWiringTest::crushSourceFiles`
  is a data provider over every `src/*.php` file — so **adding a source file changes
  the suite total by more than the tests you wrote**, and both censuses plus their
  prose copies need updating in the same diff.
- **`vendor/bin/phpunit tests/Cli` HANGS at baseline** — over 4 minutes, killed at 250s —
  while the full configured run passes in ~2m26s and every `tests/Cli/*.php` file passes
  individually in under a second. A cross-test leak that `defaultTimeLimit=60` does not
  abort. Measured 2026-08-19 by B3's reviewer, pre-existing and not from that bundle. The
  consequence for every future round: **do not judge green from a directory-scoped run.**
  Judge from the full configured run, or from a single targeted FILE. Mutation work in
  particular has to use file-scoped or curated multi-directory sets.
- **`BASE_BACKOFF_MICROSECONDS = 500_000` → `1` survives 3188 tests**, because every backoff
  assertion is relational rather than literal. That is the "derive, don't hardcode" rule
  working as intended — but it means the prose figures ("500ms doubling, ~1.5s total") have
  no reader and will rot silently if the constant moves.


---

## APPENDIX V — CRAFT-RULES DIGEST

Every rule below is lifted VERBATIM from the round-32…round-61 history blocks trimmed 2026-09-12
(items 27–28, 38, 40–43, 45–46 of the adjudication, plus the recommended final imperative-mood sweep of
old lines 2226–5400). Cited line numbers are positions in the pre-trim file (git ancestry).

### (a) E452 recovery — if the round is killed, do NOT use resumeFromRunId
*(was §0-NOW-55, lines 2027–2049)*

### 🔴 IF THE ROUND IS KILLED, DO NOT USE `resumeFromRunId` — SEE E452

Resume replays the longest unchanged PREFIX of `agent()` calls, and under `pipeline()` the call order is
set by completion times, so the prefix cannot be reproduced. Measured this round: it replayed the three
implements from cache and then started three REVIEW agents, including one for a lane whose fix had
already landed.

**The recovery that works:** read the round script as text, truncate at its `phase('Implement')` line,
rewrite `export const meta` → `const meta`, `require()` it, and call the real `fixPrompt` with the cached
stage results pulled out of `journal.jsonl`. That regenerates each prompt byte-identically (verified at
53,445 and 51,840 chars). Emit only the missing agents into a fresh script and leave completed lanes
alone. Preserve any uncommitted work with `git stash create` + `git update-ref refs/rescue/<tag>`, which
snapshots into git WITHOUT committing red and WITHOUT disturbing the working tree.

**The recipe was dry-run against round 55's own script on 2026-08-25, while the round was healthy and
nothing needed recovering** — a known-positive control (rules 15/25), because a recovery tool is exactly
the kind of instrument you cannot test at the moment you need it. It works: the truncate-and-`require`
step exports `fixPrompt`, `reviewPrompt`, `implementPrompt`, `LANES`, `COMMON`, `BASE`, `ROUND`, and
`fixPrompt(review, LANES[0], impl)` renders 23,995 chars with both stage results interpolated. Two things
to know before you run it: the lane objects key on **`key`**, not `id` (`LANES.map(l => l.key)` → `abc`),
and the script only parses if you wrap it — `node --check` on a workflow script fails with "await is only
valid in async functions", which is expected, since the body runs in an async context. Wrap it in
`async function __w(){ … }` and rewrite the trailing `return` before you check it.

### (b) /tmp does not survive a reboot; predecessor scratchpad files
*(was §0-NOW-55, lines 2051–2055 — pairs with rule 10's evidence path)*

🔴 **`/tmp` DOES NOT SURVIVE A REBOOT.** Everything under `~/.claude` did — lane commits, the round
script, the journal, per-agent transcripts. `/tmp` took the staged sweep script and every lane
scratchpad. Anything a round needs across a reboot belongs in git or under `~/.claude`. Note also that a
relaunched agent can find a PREDECESSOR's files in its scratchpad namespace (E427 spanning a kill), so a
bare `out.txt` read or an `until grep -q` wait there can be satisfied by work that is not yours.

### (c) on ANY stage failure, git log <base>..HEAD in that lane BEFORE re-running
*(was §0-NOW-53, lines 2179–2185 (E393))*

### 🔴 LANE A's FIX STAGE "FAILED" AND HAD ALREADY DONE THE WORK — SEE E393

`fix:a-core-fd` died on `403 Unable to verify organization membership` **after** applying every review
finding and committing four times. Re-running the stage — one `resumeFromRunId` call away — would have
handed a fresh agent an already-fixed tree and a findings list describing defects that no longer existed.
**On ANY stage failure, `git log <base>..HEAD` in that lane BEFORE deciding what to re-run.** Third
occurrence of "harness loses the report, work survives", third different proximate cause.

### (g) RULE 33 — guard offers an exemption row: ask first whether the code is correct
*(was §0-NOW-54, lines 2101–2106)*

🔴 **NEW STANDING RULE 33: WHEN A GUARD OFFERS YOU AN EXEMPTION ROW, ASK FIRST WHETHER THE CODE IS
CORRECT — IF IT IS, THE CLASSIFIER IS THE DEFECT.** The guard's failure text offered two blessed
resolutions (name the fds, or add an `ACCOUNTED_FOR` row). Both were wrong here. An exemption row written
for correct code is where the next real offender hides. The classifier learned the rule instead, keyed by
depth and pinned in BOTH polarities: closer in `finally` → short; closer in `if` inside `finally` →
unclassified; closer in `finally` inside a `foreach` → unclassified. Dropping the new arm reds 2 tests.

### (d) check git branch and git reflog before believing a lane lost anything
*(was round-49 block, lines 2395–2397)*

🔴 **Check `git branch` and `git reflog` before believing a lane lost anything**, and inspect a dirty tree
before reverting it — in this round two dirty trees were probes and two were real work, and only reading
the diffs told them apart.

### (e) commit before measuring; commit before mutating
*(was round-48/47 blocks, lines 2399–2401 and 2549–2553)*

**A `cd` followed by a relative path destroyed a file twice this session** (once `NonInteractive.php`, once
an over-greedy regex across two test files). Both were fully recoverable **only because the work was
committed first.** Commit before measuring; commit before mutating.



🔴 **A supervisor mutation must assert its own address before writing.** At this merge, `sed -i
"${L}s|.*|…|"` ran with `$L` EMPTY — the address vanished and the substitution hit **every line of
`src/Cli/NonInteractive.php`**, destroying the file. Recovered completely by `git checkout --` only
because the merge had been committed first. **Commit before mutating, and compute the target with a
`assert len(hits)==1` guard rather than a shell variable that can be empty.**

### (f) re-derive HEAD before believing any sha in a review or brief (E190 fix-agent rule)
*(was round-46 block, lines 2696–2700)*

E168 covers the agent that dies mid-MUTATION leaving dirt. **This is the agent that dies mid-REPORT
leaving committed work nobody has been told about.** The replacement handled it correctly without being
told to. **Put it in the fix-agent brief:** re-derive HEAD with `git log --oneline <base>..HEAD` before
believing any sha in the review or the brief; if commits exist the review does not mention, verify them by
mutation, do not redo them, and say so.

### (h) SALVAGE (unverified) wording discipline — dirty lane vs mutation
*(was round-47 block, lines 2595–2597)*

**The distinguishing test:** a mutation is incoherent on its face and sits in a file the lane does not own;
in-progress work is coherent and on-target. Commit it as `SALVAGE (unverified)`, saying plainly that the
supervisor committed it and that nothing about it has been checked.

### (i) E80 flake signature — re-run before diagnosing
*(was round-44/42 blocks, lines 3055–3057 and 3605–3610)*

`MultiAgentRefactorTest::testArchitectPlansTwoCodersImplementInParallelReviewerVerifiesLeadMerges` —
`pcntl_fork()` + SQLite `flock()` with a **capped** backoff. **If a full-suite run comes back rc 1 with
exactly one risky test and ~22 missing assertions, this is it — re-run before diagnosing anything else.**



🔴 **E80 IS A REAL FLAKE AND IT WILL BITE A FUTURE ROUND.**
`MultiAgentRefactorTest::testArchitectPlansTwoCodersImplementInParallelReviewerVerifiesLeadMerges`
aborted at 60 s on one run and passed on the next, same tree, nothing between them but machine load —
`pcntl_fork()` + SQLite `flock()` with a **capped** backoff. It did not fire in the merged run. **If a
future full-suite run comes back rc 1 with exactly one risky test and ~22 missing assertions, this is
it — re-run before diagnosing anything else.**

### (j) PHPUnit 10 counts …OrEqual as 2 assertions — ask what a delta PINS, never what it totals
*(was round-34/37 blocks, lines 4782–4809 and 3886)*

⚠️ **Assertion totals are NOT assert-call counts** — PHPUnit 10 counts the `…OrEqual` family as 2.



### ⚠️ THE ASSERTION TOTAL IS NOT COUNTING ASSERT CALLS — supervisor-verified

Every round of this plan quotes `tests / assertions` as its health figure. **PHPUnit 10 counts
`assertLessThanOrEqual()` and `assertGreaterThanOrEqual()` as TWO assertions each** (they are
composite `LogicalOr` constraints). Supervisor-verified directly, with a four-method probe run
against `sugar-crush/vendor/bin/phpunit`:

```
assertSame              -> OK (1 test, 1 assertion)
assertLessThanOrEqual   -> OK (1 test, 2 assertions)
assertGreaterThanOrEqual-> OK (1 test, 2 assertions)
assertLessThan          -> 1 assertion   (plain comparisons are NOT doubled)
```

**Scale, stated as two different things because they ARE two different things:**
- **Repo-wide the distortion is small.** `grep -rho "assert\(Less\|Greater\)ThanOrEqual("` over
  `sugar-crush/tests/` finds **142 static call sites**. If each ran exactly once that is +142 on
  92,144, i.e. **0.15%**. ⚠️ **That is a FLOOR, not the answer** — a static count cannot see loop
  iterations, and one such call inside a 281-iteration sweep contributes **562**.
- **Per-bundle it can be enormous.** P8.4 reported **+1488 assertions** for +23 tests; the real
  assert-CALL count is **~880**, a **~68% overstatement**, because 1344 of them came from two loops.

**How to read a bundle's assertion delta from now on:** a large delta is evidence of loop iterations,
not of properties pinned. P8.4's 724-assertion sweep buys **four** distinct properties, and its
band-floor check is monotone so only the minimum at cols=80 is load-bearing. **Ask what a delta
PINS, never what it totals** — and the proof is that the same 724 assertions failed to catch
`intdiv($cols,3)` → `round($cols/3)`, because they assert the SUM (`band + 1 + column == cols`),
which holds under any sizing policy.

### (k) prediction rule — tests additive, assertions a LOWER BOUND, predicate not scope
*(was round-44/46/48 blocks, lines 2940–2954, 2705–2717, 2441–2449)*

### 🔴 THE PREDICTION RULE HAS CHANGED — ASSERTION COUNTS ARE NOT ADDITIVE ACROSS LANES

Predicted **9215 / 127733**. Tests hit **9215 exactly** (two rounds running). Assertions came in at
**127,781 — +48 over prediction**, and the cause is structural, not a lane error:

**A lane that ships a census walking `src/` or `docs/` asserts per file and per paragraph, so a SIBLING
lane adding prose raises THAT lane's assertion count with no code change on either side.** Measured by
running lane a's three census files in both trees — same 93 tests, 21,747 assertions at merged master vs
21,699 at lane a's own HEAD. Attributed exactly: `GlobFigureDriftTest` +46, `SymbolCitationDriftTest` +2,
`EnvRosterDriftTest` 0.

**So: predict tests from the deltas as before. Predict assertions as a LOWER BOUND whenever any lane's
diff contains a guard that enumerates files or paragraphs — and say so when you state the prediction.**
The two round-43 rules still hold and still work (re-measure after the fix stage; run and reconcile
`git diff <base>..HEAD -- 'sugar-crush/tests/**' | grep -c '^+ *public function test'` in writing).



### THE PREDICTION RULE, CORRECTED — PREDICATE, NOT SCOPE (E191)

**Tests 9378, predicted exactly — fourth round running.** Assertions landed on **131610, exactly the
additive lower bound, and the supervisor had predicted a strict overshoot. That was wrong.**

**A new census inflates a merged total only when a sibling's additions fall inside its PREDICATE, not
merely inside its scan scope.** Lane b's new scanners walk all of `tests/` but assert per fork site and
per fixture; lane a's two new test files contain zero `pcntl_fork` calls, so nothing matched. Round 44's
stale-figure census asserted once per PARAGRAPH of every file, where every sibling addition necessarily
matches — that is why THAT one overshot by 48.

**Keep predicting tests additively and assertions as a lower bound. Do not promise an overshoot just
because a guard walks a directory a sibling touched.**



### THE PREDICTION RULE HELD A SIXTH ROUND — AND E191's OVERSHOOT WAS FINALLY OBSERVED

**Tests 9497, EXACT — sixth consecutive round** (9445 + 29 + 6 + 17). **Assertions predicted as a LOWER
BOUND of 133583 and landed at 133585, two over** — the first time the bound has been genuinely loose, and
the mechanism is exactly E191's: **lane c's new stderr site fell inside lane a's census PREDICATE, not
merely its scan SCOPE**, so the merged census makes assertions neither lane made alone. Round 47's bound
was tight because no lane's additions entered a sibling's predicate; round 48's is loose because one did.
**The rule is now confirmed in both directions. Keep saying which kind of prediction you are making, and
say WHICH sibling's predicate you expect to absorb WHAT.**

### (l) a figure without its generator is not a measurement
*(was round-41 block, lines 3550–3553)*

**A FIGURE WITHOUT ITS GENERATOR IS NOT A MEASUREMENT.** Lane `b` shipped `989 / 3,862` in a commit
message, reproducible from nothing, and its own review could not re-derive them. Every fuzz figure must
carry its seed, its alphabet, its length bound, its trial count, and the PHP/ICU version. This is the
same defect class as E69's "0 unexplained" over an alphabet containing no ZWJ.

### (m) the fuzz alphabet is part of the fuzz's coverage
*(was round-43 block, lines 3137–3139)*

🔴 **THE TRANSFERABLE RULE: THE FUZZ ALPHABET IS PART OF THE FUZZ'S COVERAGE, AND IT HAD BEEN WRITTEN TO
MATCH THE CASES ALREADY KNOWN.** Same family as E69's "0 unexplained" over an alphabet containing no ZWJ.
When a fuzz reports zero, ask what its alphabet cannot express before you believe it.

### (n) never add a skipped test to close a timing-dependent branch
*(was round-41 block, lines 3466–3470)*

**NEW — NEVER ADD A SKIPPED TEST TO CLOSE A TIMING-DEPENDENT BRANCH.** E61's all-bounded branch is
reachable only via per-hook `proc_open` overhead: four hooks each declaring 10ms **denied on some runs
and fitted on others**. The first cut called `markTestSkipped()` on the fitted case — which would have
put a second skip in the suite whose skip count is the closure alarm above. It is now pinned with a
`BoundedHookInterface` double. **A coin flip dressed as an assertion is worse than a test double.**

### (o) every width claim carries its PHP version (box is 8.3.6 only)
*(was round-41 block, lines 3477–3483)*

**E68 PROVES THE VERSION AXIS IS REAL. Put a PHP version on every width claim.** E68's recorded
mechanism was **inverted**, and its prescribed fix would have changed nothing: `Width::string()` was the
splitter, because `grapheme_str_split()` is **PHP 8.4+ and absent on this box's 8.3.6**. It was a
**PHP-8.3-only defect recorded as unconditional**, and CI runs `PHP_VERSIONS = ['8.3', '8.4']` — so a
lane can measure green here and go red in CI. **This box has only PHP 8.3.6.** Use ext-intl's
`grapheme_extract()`/`grapheme_strlen()` as an ICU oracle; prefer fixes that REMOVE a version-conditional
path over fixes that pick a branch.

### (p) the /tmp prohibition in every brief; cite SYMBOLS not line numbers; commit incrementally in-lane
*(was round-41 block, lines 3491–3494)*

**Keep the `/tmp` prohibition in EVERY brief:** never glob-delete `/tmp/sc_chat_tool_*` or
`/tmp/crush-hook-payload-*`; unique probe names, exact-path deletes only. **Agents commit INCREMENTALLY
in-lane.** **Cite SYMBOLS, not line numbers** — round 40 produced a fifth instance inside the lane that
had just been told about the pattern.

### (r) rewrite a stale justification; never delete it
*(was round-41 block, lines 3488–3489)*

**Rewrite a stale justification; never delete it.** WHAT IT SAID / WHAT IS TRUE NOW / WHY THIS STILL
EARNS ITS PLACE. Delete the reasoning and the next reader deletes the guard.

### (s) a hypothesis in a status block reads one round later like a measurement — mark hypotheses
*(was round-41 block, lines 3485–3486)*

**A hypothesis in a status block reads, one round later, exactly like a measurement.** Mark hypotheses
as hypotheses in this file.

### (t) a mutation harness whose restore is a later step in the same agent does not survive the agent dying (E168)
*(was round-45 block, lines 2824–2837)*

### 🔴 A MUTATION HARNESS WHOSE RESTORE IS A LATER STEP IN THE SAME AGENT DOES NOT SURVIVE THE AGENT DYING (E168)

Round 45's first launch died at a session limit with 5 of 9 agents mid-flight. Lane a's tree was left dirty
with `src/Chat.php` and `tests/Cli/BootstrapLaunchNoticeRoutingTest.php` carrying a figure rewritten to
"nineteen" in a paragraph stating `Bootstrap.php` holds sixteen calls — incoherent on its face, and
`TRANSCRIPT_SEAM_CALL_SITES` is 16, so it was an oracle probe, not fix work (`Chat.php` is not even in
lane a's file list). No backup existed. **Had the resume handed that tree to a fresh fix agent it would
have committed the nonsense or chased a red it did not cause.**

**Three parts, all of which lane c's `mut.sh` had and lane a's did not:** (1) the backup is written
BEFORE the mutation; (2) the restore is VERIFIED by `git status --porcelain` returning empty; (3) 🔴 **the
supervisor checks every lane tree for a dirty worktree BEFORE merging**, not only at the end. Lane c's
harness also exits 94 on a no-op and prints the actual `+`/`-` lines — it caught a no-op that would have
read as a survival, and an rc-255 PHP fatal that is neither a kill nor a survival.

### (u) measure the floor at the commit the lanes branch from, not the merge commit (E167); lane b revert technique
*(was round-45 block, lines 2796–2810)*

### 🔴 MEASURE THE FLOOR AT THE COMMIT THE LANES BRANCH FROM — NOT AT THE MERGE COMMIT (E167)

Round 45's brief carried `9215 / 127781`, measured at round 44's merge commit `98d59bfb`. **The lanes
branched from `06126017`, three commits later, and one of those three was the supervisor's own E131 prose
fix to `src/Config/LayeredSettings.php`.** `GlobFigureDriftTest` asserts once per paragraph of every
`.php` under `src/`, so that fix moved the assertion count by +1. True base: **`9215 / 127782`**.

Lane a reverted its diff and observed it; lane b reverted all four of its files and observed it; lane c
flagged it and **correctly refused to adjudicate** without a checkout that would dirty its tree. **The
brief was the outlier and the lanes were right.** Cut the lane copies, then measure the floor in one of
them (or at that exact commit) immediately before launching.

⚠️ Lane b's revert technique is the one to copy: `git show <sha>:<path> > <path>`, **never**
`git checkout <sha> -- <path>`, which STAGES — and a staged-but-restored file made `git diff --quiet` lie
once in lane a this round. Follow a pathspec checkout with `git reset -q HEAD -- <dir>`.

### (v) census literals are counters, not prose
*(was round-32 block, lines 5154–5158)*

**Census literals are COUNTERS** (`BuiltInToolCorpusTest` now **279**). "No new `src/` file ⇒ no
census collision" is FALSE — round 31's `lsp` bundle added no `src/` file and still moved
`ContainedPathInventoryTest` and added 7 `ReadPathCensusTest` rows, because those track read sites.
On a conflict confined to a count, **re-derive and continue**; STOP for anything else. See
`crush_code_concurrency.md` §5.2e.

### (w) sweep for derived values, not just the stale literal
*(was round-33 block, lines 4655–4660)*

- **A CORRECTED CONSTANT LEAVES CORRECTED-LOOKING ARITHMETIC BEHIND.** `7957b2be` swept five places
  that *quoted* the superseded 393,216 and missed three that were **derived from it**:
  `contextWindow()`'s tier figures `~275,251 / ~334,233 / ~373,555` are 70/85/95% of 393,216. A grep
  for the stale number cannot find a number computed from the stale number. The lane found them and
  corrected them to `~733,999 / ~891,284 / ~996,141`. **Sweep for the derived values, not just the
  literal.**

### (x) verify by domain, not by token
*(was round-32 block, lines 5082–5085)*

**THE RULE: verify by domain, not by token.** Every failure above is a true statement about the wrong
scope. Before trusting any `file:line` in the plan, confirm it still points at the thing it names — six
citations had rotted onto unrelated docblocks (the table is in `crush_code.md`). Grepping for a symbol
the plan named is necessary and **not** sufficient: the symbol can be gone while the defect remains.

### (y) a test over a hand-maintained list inherits the list's omissions — derive the list
*(was round-34 block, lines 4264–4265)*

**The rule: a test over a hand-maintained list inherits that list's omissions. Derive the list, or
the test only proves what someone remembered to type.**

### (z) re-open the file at the line range before repeating any citation you did not personally take
*(was round-33 block, lines 4603–4604)*

  **Rule: re-open the file at the line range before repeating any citation you did not personally
  take, including one from your own earlier document.**

### (aa) a correction is a claim and gets measured like any other; never edit the record straight from a lane's correction
*(was round-36 block, lines 4071–4080)*

**Rules:**
1. **A correction is a claim and gets measured like any other.** Round after round these briefs have
   said "report a wrong premise rather than routing around it" — this is the other half: *a reported
   wrong premise must itself be verified before the record is changed.*
2. **Never edit the record straight from a lane's correction.** Confirm it first, or route it through
   the reviewer.
3. Note the same lane's OTHER corrections in the same breath were **right** (`Chat.php:6478` sync
   `run()`, `:6390` dispatch, `:6459` `workflowRun()`; the recorded `:6479` was a genuine off-by-one).
   **A source that is right three times out of four still has to be checked the fourth time** — being
   mostly right is what makes a false correction dangerous.

### (ab) consensus among comments measures a shared ancestor, not the code
*(was round-33 block, lines 4606–4611)*

- **THE DOCBLOCKS CAN AGREE WITH EACH OTHER AND ALL BE WRONG.** Three separate places
  (`AgentManager.php:416`, `Bootstrap.php:914`, `docs/PERMISSIONS.md:193`) call the permission
  approver a "BLOCKING closure". The implementation it describes is a `Deferred` + TEA state machine
  that cannot be called from a `bool`-returning closure at all. Corroboration across files is not
  evidence — the three agree because they were written from the same intent, before the
  implementation diverged. **Consensus among comments measures a shared ancestor, not the code.**

### (ac) lane hygiene — keep the lane dirs until AFTER the merged floor is measured; check deletion order
*(was round-44 block, lines 3087–3093)*

`/home/sites/crush-lane-{a,b,c}` removed and `drain44-{a,b,c}` deleted — **but only after** confirming per
lane that every file in the lane's diff is byte-identical in master (the only two that differ are the
knowingly shared `docs/SETTINGS.md` and the backlog), and that `git branch --merged master` listed all
three. Do it in that order; a lane dir removed before the check is unrecoverable.

⚠️ **Keep the lane dirs until AFTER the merged floor is measured** — round 44 needed lane a's tree alive to
attribute the +48 assertion gap, and it would have been unrecoverable an hour later.

### (ad) E63 shared-temp false red — a run that fails ONLY on that assertion is not a red suite
*(was round-38 block, lines 3811–3815)*

⚠️ **NEW — E63 changes how a red suite is read.** `ChatTest::tearDownAfterClass` globs the SHARED temp
dir and attributes any concurrent process's `sc_chat_tool_*` files to itself, so two lanes running suites
at once can trip it. **A run that fails ONLY on that assertion is not a red suite** — re-run
`vendor/bin/phpunit --filter ChatTest` alone to disambiguate, and prefer to take floor measurements when
no lane is running a suite. It is false-positive-only: it cannot hide a leak, only invent one.

### (sweep-1) a row added to a list tests iterate inflates the delta with tests that are not yours
*(was round-35 block, lines 4012–4014 (final-sweep addition))*

**The rule: when a change adds a row to a list that tests iterate, the assertion delta includes tests
that are not yours and did not change.** More generally — *check whether two corroborating methods
share a premise before treating them as independent.*

### (sweep-2) securing the data a walk RETURNS is not securing the walk
*(was round-35 block, lines 4175–4177 (final-sweep addition))*

**The rule: securing the data a walk RETURNS is not securing the walk. Gate the traversal and the
values separately, and never let a census row answer "is this path safe?" with a sentence about the
string rather than about what it resolves to.**

### (sweep-3) a guard written from the sanitiser's byte class tests the sanitiser, not the surface
*(was round-35 block, lines 4202–4205 (final-sweep addition))*

**The rule: a guard test written from the sanitiser's byte class tests the sanitiser, not the
surface.** Write it from the property the surface needs — here, *a report has exactly the number of
lines the renderer intended, whatever the fields contain* — and it fails for any escape, including the
ones the sanitiser was never meant to cover.

### (sweep-4) a green suite is not a pinned invariant — mutate the clause or you have not pinned it
*(was round-41 block, line 3496 (final-sweep addition))*

**A green suite is not a pinned invariant. Mutate the clause, or you have not pinned it.**

### §B — the W1 LESSON trio (was round-32-era W1 block, lines 5882/5895–5899/5901–5902)
*(item 35)*

**Re-verify an agent's mutation table yourself, with your own edits, before believing a bundle.**

**And the reason those four resisted is the transferable insight:** all three `$labelRoom` mutations
were unkillable by ANY width assertion **by construction**, because fix round A's own `hardFit()`
truncates an over-wide tool row regardless of what the label arithmetic computed. **A fix can make its
neighbours' tests vacuous.** When a bundle adds a safety net, re-ask what the older assertions still
prove — often the answer is "nothing they used to".

Practical rule now in force: **write mutation definitions as the exact edit, verbatim, in the report.**
"MU11" is not a definition. `$labelRoom = … - Width::of($status) - 1;` → drop the `- 1` is.

### proven against the unfixed code rather than asserted (revert the fix; watch the new tests fail)
*(was W5 block, lines 5952–5955 (item 38))*

**Proven against the unfixed code rather than asserted:** with the three call sites reverted, 6 of
the 7 new tests fail. That exercise also caught a vacuous pass in my own new test —
`testTheFailureNoticeIsNeverAnAssistantTurn` indexed `$added[1]` without counting first, so against
the unfixed build (which appended nothing) it compared a role against an undefined key and passed

### a queue row is not evidence — every bundle's measure step re-derives the defect from the source
*(was B4 block, lines 6217–6222 (item 40))*

**The transferable rule, and it is the workflow's step 1 for a reason:** a queue row is not evidence.
This is the second time in this plan that "verify before writing" turned a bundle into a no-op — the
other is Phase 6 item 1's `__DIR__` bug, flagged the same way in the bundle table. **Every bundle's
measure step must re-derive the defect from the source, and a bundle whose defect has evaporated
must be reported as closed rather than re-implemented.** An agent handed "differentiate the prompts"
with no measure step would have rewritten six perfectly good prompts and called it progress.

### never git-mutate in a non-commit step
*(was THE WORKFLOW, line 6314 (item 41))*

- **Never** `git stash`/`checkout`/`reset`/`commit`/`clean` in a non-commit step. **Never**
  `composer install`/`update` (it silently replaces `vendor/sugarcraft/*` symlinks with Packagist copies
  — the only signal is the skip count going 1 → 2). **Never** a global `pkill`. **Never** `caliber`.

⚠️ **STANDING CHANGE — agents commit incrementally in-lane.** A network drop killed both round-38 agents
mid-flight, one holding **626 uncommitted insertions across 4 files**. Nothing was lost only because the
lane was snapshotted by **file copy + `git diff` patch** before anything else touched it. Never
`git checkout --`/`git restore`/`git stash` a lane to "clean up"; a resumed agent must be told its dirty
tree is its own work. Several small commits on a branch cost nothing — the supervisor squashes at merge.
*(was the round-38 STANDING CHANGE block, pre-trim line 3820 — bullet completion + this paragraph promoted
back VERBATIM at the 2026-09-13 E134 supervisor disposition: the trim had truncated the bullet mid-sentence
and dropped the dirty-tree law; the r64 lane-ab incident is this law's third instance — revert a mutation
with `cp` from a pre-mutation backup, never `git checkout` a dirty file.)*

### if a workflow run reports green and a personal run disagrees, believe the personal run
*(was THE WORKFLOW, lines 6282–6284 (item 41))*

agent, not me. **So after each workflow run returns, re-run the suite yourself and spot-check the
bundle's mutations before trusting the commit.** If a workflow run reports green and a personal run
disagrees, believe the personal run and treat the whole batch as suspect.

### the README:551 trap — a deliberate historical citation, do not "fix" it
*(was ORDER block, lines 6340–6345 (item 42))*

**Prepared edit: `/tmp/…/scratchpad/88-readme-figure.md`.** Read it first — it corrects an error in my
own earlier note here. That note said `README.md:551` carries a second stale figure, `4,337/12,587`.
**It is not stale and must not be touched**: it is introduced by "For scale rather than for accuracy:
the first figure to stand here …" and is a deliberate historical citation kept to show the drift.
Updating it destroys the point it makes. Only `:531`'s figure is live — plus its runtime and its
delta sentence, both of which are part of the measurement rather than decoration.

### the dead arm is the fail-CLOSED direction on a security gate — keep it, document it as belt-and-braces
*(was C3 block, lines 6368–6373 (item 43))*

1. `mcpClient()`'s untrusted branch is guarded `$canonicalRoot === false || !projectMcpIsTrusted(…)`,
   and the `false` arm is **unreachable** there (`is_file()` already succeeded on a path composed
   from `$canonicalRoot`). Same shape as the dead `stdClass` clause round A deleted — but here the
   dead arm is the fail-CLOSED direction on a security gate. **Decision: keep it, document it as
   deliberate belt-and-braces.** A later reader deleting it "because it is unreachable" is exactly
   how a gate acquires a hole, and the cost of keeping it is one branch.

### the five lessons of the C1/E21 era (item 45)
*(was lines 6479–6504)*

**The two lessons this session added, both from C1:**

0. **A carefully verified argument can answer the wrong question.** For C3 I reasoned that
   `unrestricted: true` was safe because every main-agent tool call rides the PreToolUse chain
   exactly as `Bash` does, wrote the reasoning down, and asked the implementer to verify it end to
   end. It verified. It was also irrelevant: that gate sees tool CALLS and never sees
   `proc_open()`. Before trusting a safety argument, ask what it does NOT cover — and check
   whether the project already has a boundary for this threat class rather than reasoning a new
   one from scratch.
0b. **Do not edit `docs/plans/*` while a round is live.** Two agents in a row have reported
   `git status` moving under them because I committed backlog edits mid-round. Either hold docs
   edits until the round returns, or tell the agent up front that `docs/plans/*` will move and
   is not part of its bundle.
1. **A reproduction fixture can fail to reproduce, and then the test passes on the broken
   code.** My SIGTERM fixture (`trap '' TERM; sleep 8`) put the trap in a script file, so
   `proc_open`'s direct child was the `sh -c <script>` wrapper — which does NOT ignore SIGTERM.
   It died in ~50ms, orphaned the trapping shell, and the bug became invisible. Always confirm
   the fixture reproduces the defect BEFORE writing the assertion against it.
2. **An overstatement passed along is indistinguishable from one invented.** I forwarded a
   reviewer's "no newline AND no carriage return, for any command whatsoever" without checking
   the CR half, which was false and which the reviewer's own next finding contradicted. Reading
   a finding is not verifying it.

Older but still live: **"survives the full suite" is not "is correct" — it is only "nothing
measures this"**, and **a fix lifted from a reviewer's mutation is still a mutation**, chosen
to probe coverage rather than to be right.

### C6 timeout exception — a language-server request timeout is NOT an LLM completion timeout
*(was §11, lines 6653–6655 (item 46))*

  `connect()`'s `float $timeout = 30.0` is a language-server request timeout, NOT an LLM
  completion timeout, so the no-blanket-timeout directive does not apply to it — say that in
  the brief so nobody "fixes" it. Full measurement in `/tmp/…/scratchpad/c1-measured.md`.

### E-row doctrine — DORMANT IS NOT UNGATED
*(was §11, lines 6667–6668 (item 46))*

  `Bootstrap` does. Also still true: nothing in `src/` constructs a `WorktreeManager`, so
  wiring it is part of the item ("DORMANT IS NOT UNGATED" is that file's own doctrine).

### concurrency: the authority pointer and the two surviving §0b rules (item 30, condensed)
*(was §0b, lines 5537–5538 and 5559–5562)*

**`docs/plans/crush_code_concurrency.md` is the authority.** Read its §0 and §1 and
nothing else unless you are changing the map. It carries the mode switch (ON, OFF, and



4. **No lane ever commits a `.vhs/*.gif`.** CI regenerates and pushes them after every
   batch of changes, so master drift is guaranteed, not occasional. Since GIFs are binary
   a rebase conflict there is not hand-mergeable — and since no lane writes them, it can
   never happen. `git pull --rebase` before every push is routine, not defensive.

---

## HISTORY POINTER

Full round-by-round history lives in `git log` of this repository, the round blocks in
`docs/plans/crush_code_worklog.md`, and this file's own git ancestry; nothing else. Rules are durable;
figures are not.
