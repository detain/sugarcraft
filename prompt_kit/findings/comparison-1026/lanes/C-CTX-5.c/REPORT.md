# C-CTX-5.c — Idle fold timed to the prompt-cache TTL

VERDICT: DONE. Commit `585c465ef999dc1f55dac04203266cda918ef7f4` on `cc/C-CTX-5.c` (base cf0cfd0be), 13 files, +1020/−44. Author+committer `Joe Huss <detain@interserver.net>` verified via separate `%ae`/`%ce` greps; zero Co-Authored trailers. Worktree clean; never pushed.

## What shipped
- `CompactorConfig`: `IDLE_AUTO_OFF|HOUR|CACHE_TTL` mode consts + `idleAutoMode` (bool kept as consistent shadow so Chat.php/App.php compile untouched); `fromSettings` reads mode words case-insensitively (`'CacheTTL'` canonicalises) and legacy bools (`true→hour`, `false→off`); ambiguous values ignored; `withIdleAuto` pair-flips, new `withIdleAutoMode`.
- `IdleCompactionPolicy`: cache table 3600 marked / 300 implicit / never-when-uncached (`cacheTtlSeconds(Of)`, `shouldAutoCompactAtCacheTtl` with 60% floor < 85% tier, 60 s margin, strict `>`).
- Both hosts consult the policy: TUI tick intercept in `HandlesRunControl` (verbatim silence list, pinned structurally vs `Chat::handleIdleCompactTick`); served alarm in `SessionHost` arms `ttl−60+2 s` (never-arms for cacheless providers; hour path byte-unchanged).
- `ContextSettings` row → `SettingType::Enum`; gen-settings-doc `--write` → `docs/SETTINGS.md` (2 rows); `LayeredSettings.php` byte-unchanged by gen (key name same — expected). `lang/en.php` help replaces C-CTX-6.c's "No short cache-warm idle tier ships yet" sentence; `docs/CONTEXT.md` cacheTtl prose incl. the ~4-min risk line.

## Deviations (all disclosed)
- ctor VALIDATES the idleAuto/idleAutoMode pair (throws on hand-built half-pair) instead of normalizing — readonly promoted props cannot be rewritten in the ctor body; pinned by test.
- orphaned `boolSetting` deleted (sole caller replaced by `idleAutoModeSetting`).
- extra in-step test flip: `CompactionSettingsTest` schema-default `false → 'off'` + enumValues assert (guard for the changed row).
- `cacheTtlSecondsOf(null) = null` (no backend = no cache to time).

## Tests (targeted only; cwd candy-code, -c phpunit.xml, plain pipe)
- NEW `tests/Context/CacheTtlIdleFoldTest.php` — 14T (red before impl for the policy arms).
- `tests/Context` dir OK 1333T/5275A · band `tests/Host tests/Config/Settings tests/Chat/IdleAutoCompactionTest.php` OK 668T/11563A · `--filter Idle` OK 235T/20288A · SettingsSchemaDocDrift OK 19T/183A · gen-settings-doc --check rc0 · DocFigure/LangParity/ReadmeRoster/EnvRoster/ConfigWriteProducer/TrustKey guards green.
- Modified tests: IdleAutoCompactionTest +3T (17→20), SessionHostIdleAutoTest +4T (13→17, arm-delay 242.0/3542.0/3602.0 pins), IdleCompactionPolicyTest docblock.
- Gate owes: suite-figure/README re-pin (+21T; the 2 ReadmeSuiteFigureDriftTest staleness reds in tests/Config are mine, expected) + durations.tsv row for the NEW file `candy-code/tests/Context/CacheTtlIdleFoldTest.php`.
- Pre-existing base reds (proven by stash run at cf0cfd0be, foreign): ApplySettingsTest ×2 (autoReviewScope), PermissionsIntroDriftTest ×1, ReadmeJsonErrorContractDriftTest ×4.

## Incident (sibling-lane hazard)
The P-AGENT.b lane twice polluted this worktree via shared-repo stash operations (files of mine vanished; its WIP appeared). Foreign dirt snapshotted to `/tmp/opencode/cc-cmp-lanes/C-CTX-5.c/foreign-dirt-P-AGENT.b/` (patch + copies); my set recovered intact from `stash@{1}` (left in place as a safety net) and re-verified green pre-commit. Their lane should restore from the snapshot; a copy of my file also sat in `/tmp/opencode/cc-cmp-lanes/P-AGENT.b/foreign-stash-residue/`.
