# rename-4-B7 — sugar-skate → candy-kv (Procedure R) <!-- @rename-keep -->

State file, updated incrementally per step. History lines mentioning old vocabulary carry `@rename-keep` as written. <!-- @rename-keep -->

## Entry facts (verified)

- tools/renames.json line 14: kind=rename, sugar-skate → candy-kv, app, ns Skate→Kv, bins {"bin/skate":"bin/candy-kv"}, extra_literals {"bin/skate":"bin/candy-kv"}. <!-- @rename-keep -->
- Derived (RenameLib::derive): pascal SugarSkate→CandyKv, compact sugarskate→candykv, env prefixes SUGAR_SKATE_/SUGARSKATE_→CANDY_KV_, PROTECT auto = none (no `sugar-skate-*` entries). <!-- @rename-keep -->
- Baseline suite (verified current, pre-rename, plain pipe): **212T/466A OK exit0** == rename-baseline.md row. <!-- @rename-keep -->
- Bin runtime prints usage via `$bin = basename($argv[0])` + lang `{bin}` placeholders — zero hardcoded brand at runtime; only docblock usage lines carry it.

## Recon map

- CONSUMERS: NO PHP consumers of `SugarCraft\Skate\` outside the lib (docs/findings mentions only). `sugarcraft/sugar-skate` appears in root composer.json require(:93)+repositories(:464) and generated docs only. sugar-crush/sugar-dash NOT import it — no consumer suites owed beyond the tools/tests gate. <!-- @rename-keep -->
- `SugarCraft\Kv` pre-existence: ABSENT (only Rust `tinykv::Kv` code sample in docs/research — untouched by rules). PROTECT check: `ls -d *kv*` empty at root.
- ENV: zero reads of the old env-prefix family in src/bin/tests → LegacyEnv = honest none (the old SUGAR_*_KEY spelling lives only in docs/repo_map proposal sample; R5 rule auto-rewrites it). <!-- @rename-keep -->
- DATA PATH: `~/.config/skate` / `XDG_CONFIG_HOME/skate` (Store.php DEFAULT_SUBDIR + resolveBase) — UPSTREAM-COMPATIBLE, plan entry note says LEAVE IT. No LegacyPaths migration. Old dir == new dir; nothing to migrate.
- ansi/: NO sugar-skate wordmark art (the 'skateboarder' hits in ansi.json/README are generic-vocab descriptions — must survive). <!-- @rename-keep -->
- Generic-word survivors that MUST survive: `skateboard`/`ice_skate` emoji keys (candy-shine GithubEmoji), `skateboarder` ansi descriptions, `charmbracelet/skate` port citations, `$skate` PHP variable (no BIN hit, but rewriting README/Store docblock examples to `$store` for hygiene), `skate_ttl_` YAML export format token. <!-- @rename-keep historical provenance -->
- `.vhs/` carries 5 stale-brand committed GIFs (basic/binary/glob/multidb/reverse-order) → followups line (CI re-renders).

## R4 decision ledger (bare old-bin inventory) <!-- @rename-keep -->

- REWRITE to `candy-kv`: bin docblock usage lines; ArgParser "the skate CLI"; Export/ImportCommand `` `skate export|import` `` (BIN-flagged); README usage fences (:68-74,82,90-91,104-107); CALIBER_LEARNINGS invocation examples; Lang NAMESPACE 'skate'→'kv' + docblock; tests argv[0] fixtures 'skate'→'candy-kv' (CliArgParserTest ×~30) + docblock; temp-dir prefixes `skate-*-demo`/`skate-ie-test-`/`skate-store-test-`/`skate-cli-test-`→`candy-kv-*`; `$skate`→`$store` in README+Store docblock examples. <!-- @rename-keep -->
- KEEP (truth/compat): `skate_ttl_` data format (ExportCommand/YamlImporter/README:110/tests — rename would corrupt YAML round-trip compat; precedent B3 soft_serve_* filenames); `~/.config/skate` paths + docblocks describing them; upstream `charmbracelet/skate` citations; research docs (Rust sample, upstream SKATE_DB_KEY/MASTER_KEY proposals — bare SKATE_ is not an R5 prefix).

## Steps

- (pre-R1) tag-prep: `@rename-keep` on prompt_kit/CONTEXT.md :876/:910 historical lines + rename-baseline.md:82 row.
- R1 dry-run: 93 files / 11 paths (R1=74 R2=31 R3=235 R5=1 R7=46 + R6=1). Eye-review PASS: no generic-vocab files in plan (ansi `skateboarder`, candy-shine emoji keys untouched); R6 auto-rewrites Lang NAMESPACE; root composer.json auto (require+repositories), composer.lock excluded -> R5 update; leftover/phase-10-apps/step-35-skate-*.md path keeps bare word (not a rule token) and its candy-kv content refs rewrite; no .sh examples -> bash-fn-name law N/A; oc.7z preflight-forced /tmp hold (413MB, /tmp/oc.7z.b7-hold). <!-- @rename-keep historical provenance -->
- R2 remote rename: sugarcraft/candy-kv -> candy-kv CLEAN first try (no topics-422); verified name=candy-kv, old URL 301 -> new; upstream-port description wording intact on the remote record.
- R3 tool run: applied — 94 files, 11 path renames (R1=74 R2=31 R3=237 R5=1 R6=1 R7=46). Lang NAMESPACE auto->'kv' via R6. composer keyword skate (bare word) kept (upstream discoverability, invisible to BIN rule).
- R4 followups: bin docblock usage lines, ArgParser/Export/Import docblocks, README Formerly block + usage fences + $skate->$store, CALIBER invocations, tests argv[0]+skip msgs+temp-dir prefixes (candy-kv-*), examples tempdirs, update repo_map 6 proposal lines rewritten + Go-comparison tagged, upstream repo_map/Store skate.New() citations tagged (22 lines), PROJECT_NAMES: weak-table row removed + main rationale row added. KEEP: skate_ttl_ data format, ~/.config/skate paths, charmbracelet/skate citations. No .sh examples (bash-fn law N/A); no completion files for this lib (glued _skate sweep: zero hits). Post-R4 residue: 0.
- R5 wiring:
- R6 gates:
- R7 commit:
- R8 remote-done:
- R9 packagist:
- Tracker:
- R5 wiring: root composer update sugarcraft/candy-kv --no-install (lock: 0 old-slug refs, candy-kv entry present). refresh-deps --mode=linked --libs=candy-kv => 5/5 symlinked escaping=0 ok. gen-docs: 72 files, --check ok (61 pages + 11 stubs pre-R7). gen-ansi-readme --check ok 3595 pieces.
- R6 gates: candy-kv suite 212T/466A OK exit0 = baseline EXACT (plain pipe, watchdog, cwd=candy-kv). Zero PHP consumers -> no crush/dash suites owed. tools/tests 426T/1484A OK = floor EXACT (no dataset wobble). check-path-repos --no-lib-path-repos rc=0 (61 libs). durations.tsv/codecov.yml/vhs.yml/docs/MATCHUPS.md: 0 skate refs. diff --stat 97 files (+534/-531). NOTE: residue gate briefly showed RC=1 from MY notes line (backtick+skate shape in prose) - reworded, 0 findings before staging; check-residue.php filename slip produced a false RC=1 (real name tools/check-rename-residue.php).
- Bare-skate survivors (audited, intentional): ~/.config/skate + DEFAULT_SUBDIR + resolveBase (plan: upstream-compatible KEEP), skate_ttl_ YAML format tokens (data format KEEP), charmbracelet/skate citations + Go code refs (@rename-keep tagged or prose-invisible), generic vocab (skateboarder/ice_skate) elsewhere untouched, composer keyword skate (discoverability).
- R7 landed: commit 65065205d -> post-rebase 9d15c247d 'candy-kv: rename from sugar-skate' (109 files; renames.json line14 status=renamed; gen-docs minted 12th redirect stub docs/lib/sugar-skate.html; residue 0 &&-chained pre-staging; author %ae/%ce detain=1/1, no Co-Authored trailer). <!-- @rename-keep commit-subject + SHAs -->

## R8 remote-done — LANDED
- Pushed chain thru `1a5188905` (notes SHA-fix). origin/master == master.
- Split peek: sugarcraft/candy-kv master exists at `cab4bd4d0`; legacy/<old> + <old>-final refs not yet visible at peek time — CI SYNC mints them async (lag OK per law, B2-B6 precedent).
- renames.json line 14 flipped status -> "remote-done" (targeted sed on line, space-tolerant regex; JSON validated).
- Transient gotcha: two parallel bash calls (sed -i + read) raced and printed a bogus corrupted line; serial re-verify showed file clean/untouched — law: serial-verify after -i edits, never read in the same parallel batch.

## R9 packagist — LANDED
- create attempt 1: HTTP 406 (old registration held the redirected repo URL while split master was still pre-rename tip) — B3/B5 documented cure.
- forced `update sugar-skate` (job a77860bda) -> sleep 60 -> create retry: HTTP 202 clean. <!-- @rename-keep -->
- dev-master ABSENT through 2 polls (p2 empty, page existed) -> ONE forced bare-slug `update candy-kv` (job 25f1bc875) -> versions appeared (dev-master + dev-legacy branch).
- dev-master ref == split refs/heads/master == `c033af0c6` EXACT; composer name on the wire = sugarcraft/candy-kv.
- Continuity: refs/heads/legacy/sugar-skate + refs/tags/sugar-skate-final both at `cab4bd4d0`. <!-- @rename-keep -->
- Abandon checklist line appended (house format, @rename-keep, old pkg stays installable; UI abandon deferred Phase 10). renames.json -> packagist-done.
- Credentials exported via split-vars + unset after each invocation; never echoed.

## Followups + tracker — LANDED
- rename-followups.md ×3 lines: 5 stale .vhs GIFs (CI re-renders), PRE-EXISTING `candy-skate` mislabel in leftover step-09
  (verified present at pre-lane tip — not a rename artifact; do not auto-rewrite), step-35 bare-word filename benign.
- plan_rename.md tracker: B7 line appended after B6 (before **Done when: batch B**) with full chain SHAs + gates.
