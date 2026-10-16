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
- Generic-word survivors that MUST survive: `skateboard`/`ice_skate` emoji keys (candy-shine GithubEmoji), `skateboarder` ansi descriptions, `charmbracelet/skate` port citations, `$skate` PHP variable (no BIN hit, but rewriting README/Store docblock examples to `$store` for hygiene), `skate_ttl_` YAML export format token.
- `.vhs/` carries 5 stale-brand committed GIFs (basic/binary/glob/multidb/reverse-order) → followups line (CI re-renders).

## R4 decision ledger (bare `skate` inventory)

- REWRITE to `candy-kv`: bin docblock usage lines; ArgParser "the skate CLI"; Export/ImportCommand `` `skate export|import` `` (BIN-flagged); README usage fences (:68-74,82,90-91,104-107); CALIBER_LEARNINGS invocation examples; Lang NAMESPACE 'skate'→'kv' + docblock; tests argv[0] fixtures 'skate'→'candy-kv' (CliArgParserTest ×~30) + docblock; temp-dir prefixes `skate-*-demo`/`skate-ie-test-`/`skate-store-test-`/`skate-cli-test-`→`candy-kv-*`; `$skate`→`$store` in README+Store docblock examples.
- KEEP (truth/compat): `skate_ttl_` data format (ExportCommand/YamlImporter/README:110/tests — rename would corrupt YAML round-trip compat; precedent B3 soft_serve_* filenames); `~/.config/skate` paths + docblocks describing them; upstream `charmbracelet/skate` citations; research docs (Rust sample, upstream SKATE_DB_KEY/MASTER_KEY proposals — bare SKATE_ is not an R5 prefix).

## Steps

- (pre-R1) tag-prep: `@rename-keep` on prompt_kit/CONTEXT.md :876/:910 historical lines + rename-baseline.md:82 row.
- R1 dry-run: 93 files / 11 paths (R1=74 R2=31 R3=235 R5=1 R7=46 + R6=1). Eye-review PASS: no generic-vocab files in plan (ansi `skateboarder`, candy-shine emoji keys untouched); R6 auto-rewrites Lang NAMESPACE; root composer.json auto (require+repositories), composer.lock excluded -> R5 update; leftover/phase-10-apps/step-35-skate-*.md path keeps bare word (not a rule token) and its sugar-skate content refs rewrite; no .sh examples -> bash-fn-name law N/A; oc.7z preflight-forced /tmp hold (413MB, /tmp/oc.7z.b7-hold).
- R2 remote rename: sugarcraft/sugar-skate -> candy-kv CLEAN first try (no topics-422); verified name=candy-kv, old URL 301 -> new; upstream-port description wording intact on the remote record.
- R3 tool run:
- R4 followups:
- R5 wiring:
- R6 gates:
- R7 commit:
- R8 remote-done:
- R9 packagist:
- Tracker:
