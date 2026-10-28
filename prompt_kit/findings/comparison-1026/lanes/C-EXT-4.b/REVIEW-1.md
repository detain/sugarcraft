# C-EXT-4.b REVIEW-1 (fresh read-only reviewer)

Lane: `cc/C-EXT-4.b` @ ca7fd9f470fe71726cbc95e29551bca56646d7c6, base e4a705244 (master 271ad0619 ancestor), worktree /home/sites/cc-cmp-C-EXT-4.b, porcelain clean.

## VERDICT: FINDINGS (0 CRITICAL / 0 MAJOR / 2 MINOR / 2 NIT) — lane sound; ship after weld-note fixes

## Reproduced gates (plain pipe, env -u LINES -u COLUMNS, cwd worktree candy-code)
- `tests/Bundles`: **OK (116 tests, 482 assertions)** — exact.
- `StderrEmitterCensus|ReadPathCensus|TreeWideGuardRoster`: **OK 131T/25587A** (+2 pre-existing PHPUnit deprecations; Stderr-alone shows same 2, Bundles filter zero) — exact.
- doc-guard set (ReadmeRosterDrift|DocFigureProseDrift|GlobDialect|DuplicatedTestHelperDrift|LangParity): **OK 136T/7613A** — builder said 135T/7610A; +1T/+3A provider-recount wobble, zero failures (NIT-1).
- `DescriptorInheritanceGuard|SpawnSpec|ProcessContainment`: **OK 18T/5141A**.
- `ProjectTierRefusalInventoryTest` at HEAD: **2F/15T**, both foreign-inherited (attribution below, honest).

## Trust walk (refuse-by-default etc.) — all PASS
- No pinned catalog entry ⇒ no install: `marketplace add` refuses a no-git-pin path source ENTIRELY (no row, no entries — pinned) and the gate itself refuses unknown marketplace / missing entry writing NOTHING (`entriesUnderRoot()==[]` pinned). Project manifest never read (registry is home/.candy-code/marketplaces.json only); no `--force` escape hatch anywhere in the diff.
- Update cannot introduce unsigned sources: `bundlesGitSha` emits ONLY a full 40-hex (bare-HEAD hex, `ref: refs/…` with `..` refused, loose ref, packed-refs suffix); renamed catalog refused BEFORE the swap (identity law, live checkout asserted intact); repin rides AFTER the swap.
- checkout_lost/renamed notices truthful vs actual operations — verified against method bodies (notice fires exactly on `checkoutDir()` null / fresh-name ≠ row-name).
- Swap law: aside-first (`/.swap-`), restore-back on second rename fail, `removeTree(aside)` LAST; `bundlesBundleRootNames` excludes dot-entries so scratch/swap never surface. Symlink-escape keystone (BundleUpdateTest:134-153): old tree's link planted, outside sentinel + nested canary survive, new tree carries no link — the no-follow walk is genuinely pinned.
- Spawns: diff `^+` lines contain ZERO proc_open/fork/shell_exec/system/popen/escapeshellarg sites — everything reuses the pre-existing `bundlesCloneDefault` (argv array via ProcessContainment::spawnSpec, `-c protocol.ext.allow=never -c core.symlinks=false clone --quiet --depth 1 --no-tags --`, poll-then-reap, bounded). No roster row owed; child-lifetimes rc0 claim accepted by static re-derivation (root tool skips candy-code; other libs untouched). NOTE: direct `php tools/check-child-lifetimes.php` was refused by my reviewer permission map (bare-`php` denial; NOT retried); re-derivation is the substitute, disclosed.
- No shell-injection surface: no shell path at all; URLs are argv-quoted; paths realpath/containment-checked (C-EXT-4.a doors preserved).

## Scope / traceability — PASS
Exactly the 10 declared files, +2008/−14 exact. Subcommands.php hunks are all inside the bundles region (use imports, bundles() docblock, verb dispatch arms, install @-grammar, the new bundles* method block, SUBCOMMAND_ACTIONS row). ParsedArgs/SUBCOMMAND_FLAGS untouched as declared. All user strings Lang::t (28 keys +1-claim drift cosmetic); rows printf. §1.8 census rosters moved in-step: Stderr 37→38 (single new failUsage in bundlesUsage — honest), ReadPath +4 rows (USER-classified .git/HEAD etc.), ProjectTier numeral moves 48→57 with the live derivation.

## ProjectTier attribution — HONEST, with a count erratum (MINOR-2)
Failing enumeration names **9** foreign unclassified occurrences (Bundles/Marketplace.php + MarketplaceRegistry.php×2 = C-EXT-4.a welds; FormatAutoTable = TOOL-7.c; LspInstaller, AgentMemory×2, MacAltNotice, ScheduleTool = older waves) — NONE in Subcommands.php; this lane's own two dot-paths ARE classified (arm passes their presence). Builder's "8" glues the two MarketplaceRegistry paths. Docblock arm: demands FIFTY-SEVEN vs Bootstrap.php:2286 "FORTY-EIGHT" — Bootstrap is not a lane file; C-SEC-7.d owns the prose flip; `assertCount(57)` + `assertCount(24)` PASS ⇒ 57 is live-derived truth incl. the lane's paths. Pure-base 2F: verified by construction (base test asserts 48; the 7 foreign files pre-date base ⇒ enumeration+count already red; docblock arm red at base too).

## Red-before 22F/23 — re-derived, NOT reproduced
Reviewer map refused the base-src swap harness (`git show … > file` output-redirect denied; nothing executed — porcelain clean after). Static re-derivation consistent: all 22 new tests assert EXIT_OK/new disk shapes unreachable at master (verbs absent → failUsage EXIT_CONFIG; no @-grammar); the 23rd run (BundleInstallTest scp-grammar test) passes at base by design (both legs hold there). Disclosed rather than re-run.

## Non-vacuity — PASS
Both new test files drive the REAL dispatch (ArgvParser→Subcommands::dispatch), assert two-sided polarities (added/refused), disk residue (`listDirNames/checkouts==['demo']`, `entriesUnderRoot`), JSON shapes decoded, pin moves, and scan-vs-named exit polarity. Fake clone rides the pre-existing production seam (ReflectionProperty, reset in tearDown) — hermetic, no fabricated success paths. GlobDialect green ⇒ no new glob-shaped fixtures.

## MINOR (step-local)
1. **BundleUpdateTest.php — two scan-mode legs unpinned**: (a) recorded entry ABSENT from catalog during a SCAN (`update` with no name) FAILs per the code — only the named case (:102) is pinned; (b) marketplace_gone during a SCAN skip-OKs per the code — only the named FAIL (:89) is pinned. Behavior matches the declared deviation, but a mutation flipping either scan leg keeps the suite green. Fix: add the two scan-mode cases (mirror :89/:102, drop the name operand, assert EXIT_OK + tree intact for the gone leg; EXIT_FAILURE for the gate leg).
2. **REPORT.md wording**: "8 foreign unclassified" is actually 9 occurrences (two MarketplaceRegistry paths counted once). Fix at weld: "9 occurrences across 7 files (Bundles/** = C-EXT-4.a, Format/** = TOOL-7.c, rest older waves)"; attribution list itself verified correct.

## NIT
1. Doc-guard figure 135T/7610A vs reproduced 136T/7613A — recount wobble, note for the weld run line.
2. lang/en.php adds 29 keys, REPORT says 28 — prose-only.

## Weld duties confirmed owed (per builder REPORT, accepted)
2 durations.tsv rows (BundleMarketplaceCliTest.php, BundleUpdateTest.php); suite-figure/README re-pin at weld (new tests shift live enumeration); C-SEC-7.d carries the Bootstrap.php docblock numeral flip; ProjectTier 9-path backlog stays standing foreign W16-era item (NOT this lane's debt — lane added its own paths classified).

## Authorship
`%an %ae | %cn %ce` = Joe Huss <detain@interserver.net> both; zero Co-Authored trailers. Single commit, subject clean. Never pushed (branch local only) — compliant.
