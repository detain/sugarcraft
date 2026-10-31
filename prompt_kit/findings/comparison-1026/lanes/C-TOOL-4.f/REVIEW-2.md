# C-TOOL-4.f — Egress proxy core: REVIEW-2 (round-2 closure reviewer, fresh)

VERDICT: CLEAN — C=0 M=0 MINOR=0 NIT=0. WELD-ELIGIBLE.

Lane /home/sites/cc-cmp-C-TOOL-4.f @ fd274f225 (branch cc/C-TOOL-4.f, three picks 79e786867 + 4bbf922b2 + fd274f225 on base 9a0c569eb; porcelain 0; full diff master...HEAD = the declared 10 files, +2151/−0). Evidence re-statted before writing: REVIEW-1.md 11,334 B, FIX-1.md 5,195 B — both read fully. This reviewer's map denies `edit` (mutation-attempt refused; harness constraint disclosed like r1's).

## Q1 — MINOR-1 closed: YES

- Rename on disk: EgressProxyProcess.php:157 (was :152; the 5-line NIT-3 comment inserted above in the same commit shifted it) now reads `$this->discardScaffold();`; `discardDirectory` is dead tree-wide in the file (grep: sites :136/:157/:226 + def :238, zero typo hits). The proc_open-false leg reaches the RuntimeException through a declared method.
- Pin `testEveryInstanceCallInTheSpawnClassResolvesToADeclaredMethod` (EgressProxyProcessTest.php:124+): PhpToken walker over the reflection file — `$this` T_VARIABLE → `->`/`?->` → T_STRING → `(` — then `method_exists` per distinct name, blindness floor `>=4` over 5 live sites (3× discardScaffold + 2× stop, counted on disk).
- Mutation reasoning (UNVERIFIED-BY-RUN — edit denied by this map): (a) call renamed to anything undeclared → walker records it → assertSame([], $undefined) RED — this is the exact original defect shape, and the fixer's RUN RED-before printed `'discardDirectory at line 152'`, so the discriminating leg is evidenced by the fixer's run, not arithmetic; (b) definition renamed leaving a stale call → same RED; (c) call DELETED → 4 sites remain, floor holds, pin GREEN — but the pin's docblock claims only name-resolution ("every $this->method() call site … has to resolve"), and deletion is leak-free anyway: `__destruct` → `stop()` (:226) → `discardScaffold()` ladder verified on disk. Honest per its own claim, no finding.
- Scope honesty: covers ONLY EgressProxyProcess (reflection file); test name and docblock say "the spawn class". Not over-claimed to the other 3 src classes.
- My own anchored run at tip: `--filter 'EgressProxyProcessTest|EgressProxyTest|EgressPolicyTest|EgressBridgeTest'` → **Tests: 36, Assertions: 117, OK** (+ the 2 accepted AccessorDeprecationTest provider deprecations that ride EVERY run). Exactly r1's 35T/113A + the fixer's +1T/+4A.

## Q2 — Fix-pick regression sweep: CLEAN

`git show --stat fd274f225`: 2 files, +59/−2 — src = stdout-leg contract comment (5 lines) + the one-line rename (the 2 deletions are those replaced lines); test = +1 method + `PhpToken`/`ReflectionClass` imports. No assertion leg removed, no roster file in the pick, and across ALL three picks `candy-code/lang/**` is untouched — NIT-2 (Lang wire bodies) genuinely deferred to 4.g, not smuggled. §1.8 proof holds: `discardScaffold`/`discardDirectory` appear zero times in ReadPathCensusTest/DescriptorInheritanceGuardTest rows (rows key `fopen`/`require` and `::start`, none moved). Census-sink grep of added src lines: zero new sinks (pin's file_get_contents is tests/-side, outside the src/bin census domain).

## Q3 — TreeWideGuardRoster weld hazard: premise FALSE on disk — ZERO keys

- `grep -n 'Egress' tests/TreeWideGuardRosterTest.php` → no match; `git log master..HEAD -- candy-code/tests/TreeWideGuardRosterTest.php` → EMPTY: this lane NEVER touched TWGR, neither builder pick nor fix pick. The recalled "earlier scandir row for EgressBridgeTest.php" does not exist; EgressBridgeTest.php contains zero walk tokens (scandir/opendir/DirectoryIterator/GlobIterator/glob all grep-empty).
- Weld-ledger statement: the TWGR merged blob carries **zero** keys from this lane — the welder must merge NOTHING from 4.f for TWGR, so the feared duplicate-key/4-key UNION hazard cannot arise here. The lane's only roster rows are ReadPathCensusTest (`Tools/Sandbox/EgressProxyProcess.php|fopen` SELF_LOCATED + `|require` PROCESS_DERIVED) and DescriptorInheritanceGuardTest (`…::start` ACCOUNTED_FOR) — single-keyed, unchanged by the fix pick.
- Runs: TWGR BARE **17T/3873A OK**; DTH BARE **14T/204A OK**; ReadPathCensus+DescriptorInheritanceGuard pair **34T/5892A OK**; StderrEmitterCensus+ForkedChildExitConvention **99T/23195A OK** (= r1's 95/23135 + 4/60 exact).

## Q4 — Weld-readiness

Verified at tip by my own runs: lane family 36T/117A; both §1.8 rosters green; TWGR + DTH green (the two that must be); Stderr/ForkedChild green; ReadmeSuiteFigureDriftTest **2F** = the accepted staleness pair (weld re-pin carries +1T/+4A on top of r1's +35T/+113A ledger). Not re-run here (accepted-foreign per lead roster, untouched by any pick): keepWarm 1F, KeyHelp 5+risky, HelpTest tier, BinCandyCodeDispatch :1893, AttachPaging 2F, OneSidedHomeSandbox:435, GlobDialect 1F (no figures asserted from it), SettingsEditor:189, ChildStderrCapture 2F (r1 base-proven at 00fedf519), AccessorDeprecationTest :168/:202 deprecation pair (visible as `PHPUnit Deprecations: 2` under every run above), BootstrapLaunchFormatConstants doc-roster leg. The fix pick cannot have moved any of them (touches 2 lane files + zero rosters). §7 carry unchanged from r1 plus the corrected family figure 36T/117A for durations/suite-figure at battery.

**WELD-ELIGIBLE: YES.** No lane defects open; MINOR-1 cured with a load-bearing pin; scope = declared 10-file set; dormant-by-design boundary stands.
