# C-EXT-6.a — hook systemMessage → RuntimeNoticeSink human notice

**REAL TIP: 5f35a8957** on `cc/C-EXT-6.a` (exists, `cat-file -t` = commit; parent e6188662a).
Subject landed as "C-EXT-6.a: show hook systemMessage as a human notice" (see Deviations-1).

## Files (git show --name-only, exactly these five)
- candy-code/src/Hooks/ScriptHook.php — `recordSystemMessageNotice()` (Sanitize::untrusted first, whitespace collapse to one row, trim-clip at NOTICE_REASON_BYTES, blank-after-sweep records nothing); call-site in `fromEnvelope()` AFTER every malformed return and BEFORE every verdict; both docblocks truth-flipped (HONORED-BY-REMOVAL era ended).
- candy-code/lang/en.php — `hooks.notice.system_message` row.
- candy-code/docs/HOOKS.md — "suppressOutput and systemMessage are not read" → two honest rules + the JSON-keys table now carries both rows; serve-drain caveat per brief's risks line.
- candy-code/tests/Hooks/ScriptHookTest.php — the :651 pin flipped per brief (systemMessage now a notice, never model context); `runHookBounded` child arms the sink and drains `notices`; return-shape docblock + `assertIsArray` extended.
- candy-code/tests/Hooks/ScriptHookSystemMessageNoticeTest.php — NEW, 12 tests: happy row exact-pinned; deny/ask/stop all carry it; suppressOutput-keeps-notice; control sweep + one-row collapse; oversized = honest clipped row (ends with `clipSuffix()`, exactly `MAX_CHARS` chars, lead survives); blank/malformed/unhonorable/negative record nothing.

## Gates (cwd candy-code, env -u LINES -u COLUMNS, plain pipe, at committed tip)
- `vendor/bin/phpunit -c phpunit.xml --filter 'ScriptHook|RuntimeNoticeSink|Hooks'` → **OK 1276T/10520A** (2 PHPUnit deprecations — present identically at base).
- `--filter 'GlobFigureDrift|ReadmeRosterDrift|EnvRosterDrift|TrustKey|ConfigWriteProducer|KeyBindingDrift|HookEnvironmentDocumentationDrift'` → **OK 367T/44351A**.
- `php tools/gen-docs.php --check` rc0; `php tools/gen-ansi-readme.php --check` rc0.
- Mutation: removing the record call-site reddens **10** tests; restore md5-verified.

## Author law (git log -1)
`%ae` grep -c detain = 1; `%ce` grep -c detain = 1; `%B` co-authored count = 0. NOT pushed.

## Deviations
1. **Double-writer race.** The prior incarnation was still alive: its first gate run left 2 red test-side assertions (a 'bewware' over-expectation — the sweep rejoins the word — and a pin expecting ScriptHook's internal truncation marker to be the VISIBLE row tail, when the sink's own `MAX_CHARS` clip subsumes it at this size). I fixed the control-bytes test with a whole-row pin; it fixed the oversized test (mb-based sink contract) and a method-name typo concurrently; the commit it then made contains all of that. I verified the committed tree green (10520A re-run at @34) rather than amending for the brief's exact subject — hash stability over message cosmetics.
2. `ChatHostLangCatalogueTest::testEveryCatalogueKeyIsAskedForByChatOrHost` is **RED** — proven pre-existing by reverting src/Chat.php + lang/en.php to base content in place and re-running (still fails; the 5 listed chat.* keys are unrelated to this lane's hooks.* key).

## Carries (closeout/weld, lane-forbidden files)
- suite-figure.json + README staleness pair: live enum +12T (new file) — re-pin owed at batch close.
- scripts/parallel-tests-durations.tsv: needs a row for `ScriptHookSystemMessageNoticeTest.php` (unlisted = zero shards).
- ChatHostLangCatalogueTest pre-red for the owning lane (chat.* key roster drift, not this lane's).
- Brief's own risk line unchanged: served sessions surface the row only after C-CTX-2's hosted notice drain.
