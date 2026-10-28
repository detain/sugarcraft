# C-SEC-6.a REVIEW-1 (read-only fresh review, reviewer session 2026-10-28)

Lane: `/home/sites/cc-cmp-C-SEC-6.a`, branch `cc/C-SEC-6.a`, ONE commit `da90aceb0` on base `e4a705244`.
Spec: `BRIEF.md` + builder `REPORT.md` in this directory.

## VERDICT: FINDINGS — 1 MAJOR, 1 MINOR (step-local), 2 NIT. Otherwise clean; approve after the MAJOR.

## Phantom-first — ALL PASS
- `git branch --show-current` = `cc/C-SEC-6.a`; `git log --oneline master..HEAD` = exactly 1 (`da90aceb0`); `git status --porcelain` empty.
- `%ae` and `%ce` grepped separately = `detain@interserver.net` both; `%an`/`%cn` = Joe Huss; `git log --format=%B | grep -ci 'co-authored\|signed-off'` = 0.

## Declared-set diff — PASS
9 files, all named in the brief, nothing outside: NEW `src/Permissions/SavedRules.php`; M `PermissionPrompt.php` (persistRules → 3-line delegate; dropped `Chat`/`SettingsTier` imports — verified `Chat` survives only in FQN docblock cites and `Lang`/`SettingsWriter` are still used, so no dangling import); M `PermissionsCommand.php` (+saved/+forget arms); M `lang/en.php`; M `docs/PERMISSIONS.md` (exactly one hunk at the @@911 region); M `docs/COMMANDS.md` (regenerated hint row); M `SettingsWriterCensusTest.php` (in-step roster flip); NEW `tests/Permissions/SavedRulesTest.php` (13T) + `tests/Host/Commands/PermissionsSavedRulesCommandTest.php` (9T). 812+/62−.

## Semantics walk
- **Grant-door reuse (§6.1/C-SEC family law): HOLDS, structurally.** `SavedRules` never builds a writer and never touches the filesystem itself (whole file read: zero `fopen`/`file_get_contents`/`AtomicJsonFile`); it receives the injected `?SettingsWriter`. `SettingsWriterCensusTest::testTheSettingsWriterIsBuiltOnlyByTheLaunch` pins construction to `Cli/LaunchContext.php::settingsWriter` (token-derived), and the producers roster is derived by a token walk over src files naming `SettingsWriter` counting `->write|grantTrust|revokeTrust` — the in-step `Permissions/PermissionPrompt.php::persistRules => 1` + `Permissions/SavedRules.php::append|remove => 2` rows match the real call sites exactly. No parallel trust door.
- **append() byte-identity: CONFIRMED.** The moved body is the old `persistRules` block verbatim (allow-pattern dedupe, `$next === $existing ? targetPath : write`, the two `chat.permission.*` notices, `catch Throwable`); the `door()` message "this session has no settings write door" keeps the legacy notice text identical.
- **forget target validation.** `substr($arg, 6)` is provably exact: the verb arm's first whitespace-delimited token is `forget`/`FORGET` (6 ASCII bytes) and `$arg` is pre-trimmed, so `words[0]` starts at offset 0. Numeric-vs-pattern ambiguity (a hand-edited rule whose *pattern* is literally `"3"`) resolves to the positional form; harmless NIT-level edge, `<n>` is documented as "a number from /permissions saved".
- **Sanitization.** `savedBody` routes both action and pattern through the existing `reportField` (`Sanitize::untrusted` + `\n\r\t` escapes) — the one-line-per-fact law of the file's own docblock is preserved on the new multi-line surfaces.

## Gates reproduced (plain pipe, `env -u LINES -u COLUMNS`, cwd `candy-code`, `-c phpunit.xml`; PtyRunToolTest never in any filter)
- New pair `--filter 'SavedRulesTest|PermissionsSavedRulesCommandTest'`: **OK (22 tests, 88 assertions)** — EXACT builder claim.
- Combined set: **OK (82 tests, 5257 assertions)** (builder's 80T/5246A is a filter-shape delta; green either way).
- Guard family (GlobDialect, DocFigureProseDrift, ReadmeRosterDrift, EnvRosterDrift, TrustKey, ConfigWriteProducer, ReadPathCensus, StderrEmitterCensus, CommandDocsDrift, PermissionsIntro, SettingsWriterCensus, PermissionFsmExtractionParity, PermissionsCommandTest, KeyBindingDrift, SavedRules): **615T/72742A, Failures: 1 — ONLY `PermissionsIntroDriftTest::testThePatternToolRosterIsTheWholeCatalog`**, i.e. the disclosed single-red shape.

## Disclosed base-red — PROVEN INHERITED (standing entry, NOT a finding)
`PermissionsIntroDriftTest::DOC` resolves inside the worktree. The roster assertion compares `ToolCatalog::names()` against backtick tokens in the paragraph "The tool names a pattern can start with". That paragraph is byte-identical between `e4a705244` and tip (both dumped); the lane's only `docs/PERMISSIONS.md` hunk is the @@911 bullet; the lane touches zero `src/Tools` files. Both assertion inputs are identical at base and tip ⇒ red at base. `Schedule` entered `ToolCatalog` via `5cd68af7e` (C-AGT-5.a) without the in-step doc row — owed by that era, builder's git-show claim accurate.

## Non-vacuity re-reason M1–M5
- M1/M2 (no-writer refusals): a neutered door check turns the refusals into a crash/empty-list path — pins name the exact strings.
- M3 family (door-knock counter for the miss/no-change case): the builder's note "write() short-circuits unchanged values" is imprecise — `SettingsWriter::write` only short-circuits an EMPTY delta (`$set === [] && $unset === []`), so a same-content rewrite DOES knock the door and produces byte-identical file content: the byte pin alone is blind exactly as disclosed, and the `userConfigDoor` knock-counter is load-bearing. Their reasoning survives its wording; remove()'s `$gone !== []` guard is what the counter pins (probe-confirmed pin would fail if the guard fell).
- The list/append/remove pins drive real config fixtures through a real writer — no glob-shaped or `**Refused:**`-shaped literals (GlobDialect + DenialPrefixRoster family green).
- Bare `/permissions forget` teaching reply mirrors the existing revoke convention (usage reply, not error flag) — consistent; and the `forget_usage`/`saved_hint` texts both state NEXT-launch effect and point at `/permissions mode`, satisfying the brief's stated risk.

## FINDINGS
1. **MAJOR — `src/Host/Commands/PermissionsCommand.php` (forgetRule, `$target === ''` usage branch; same shape at the `forget_none` branch).** The usage branch calls `savedBody()` OUTSIDE any try, while `savedRules()` wraps the identical call. PROVEN by probe (fresh `SettingsWriter` on a corrupt `config.json`, `/permissions forget` typed bare): `run()` RAISES `RuntimeException: …/config.json holds a JSON list, not an object; fix it by hand first` out of the host command — while `/permissions saved`, `/permissions forget 7` and `/permissions forget zzz` on the same config all answer designed `errorReply` refusals. The throw escapes `Chat::runHostCommand` → `handlePermissionsCommand` (no catch) into the program loop: a typed slash command crashes instead of refusing, in a realistic corrupt-config state. Fix: wrap the usage-branch `savedBody($rules)` in the same try → `CommandResult::errorReply($text, Lang::t('host.permissions.read_failed', ['error' => self::reportField($e->getMessage())]))` (or hoist ONE guarded body read and reuse it for usage/none/forgot), and apply the same guard to the `forget_none` re-read. Add the one missing pin: bare `/permissions forget` against unreadable config must assert the refusal, never a throw.
2. **MINOR (step-local) — `src/Permissions/SavedRules.php::list()`.** A row whose `action` is missing/invalid is shown as `allow`, and non-string patterns as `''`, while the launch loader `Bootstrap::permissionRules()` (src/Cli/Bootstrap.php:7637+) SKIPS exactly those entries with a `cli.warn.rule_no_*` complaint. `saved` can therefore over-count and print an action that will never be tried — against the report's own "tried first-match-wins from the next launch" header and the file's derived-never-restated honesty law. Fix: partition in one place — have `list()` and `remove()`'s `<n>` numbering share a single predicate of loadable rows (`is_array && is_string($e['pattern'] ?? null) && PermissionAction::tryFrom($e['action'] ?? null) !== null`) so the list and the gate cannot drift; do not fabricate `allow`.
3. **NIT — REPORT.md says "8 keys"; `lang/en.php` adds 9** (`host.permissions.{saved,no_saved,saved_hint,forget_usage,forget_none,forgot,no_writer,read_failed,write_failed}`). Report prose only.
4. **NIT — M3 wording** (if copied into the pin docblock): "write() short-circuits unchanged values" → "write() short-circuits only an empty delta".

## Deviations judged — all sound
substr-over-regex (GlobDialect corpus law re-checked: a preg literal with wildcards and no space/`;`/`$` is harvested as a glob-shaped string and would drift the PathGlob figure — the substr form avoids corpus admission and is provably exact, guard green); reportField on the action column; bare-forget teaching reply; helper named `ask()` (`TestCase::run()` is final); `new SavedRules` over `::new()` matches the Permissions-ns neighborhood (11 `new` vs 2 `::new`); added >120-char line is within the file's own base tolerance (5→6, PSR12 ruleset enforces no max).

## Weld duties
- `scripts/parallel-tests-durations.tsv`: 2 rows — `candy-code/tests/Permissions/SavedRulesTest.php`, `candy-code/tests/Host/Commands/PermissionsSavedRulesCommandTest.php` (unlisted files run in zero shards).
- en-only lang keys → §6.3-h 11-locale LAST carry does NOT apply.
- No suite-figure/census figures move beyond the in-step SettingsWriterCensus flip (already in the commit).
- Carry the standing `PermissionsIntroDriftTest` base-red entry to the C-AGT-5.a owner, not this lane.
