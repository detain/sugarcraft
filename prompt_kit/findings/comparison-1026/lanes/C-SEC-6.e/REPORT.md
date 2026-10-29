# C-SEC-6.e — DONE · commit ee4789c04 · branch cc/C-SEC-6.e (base de0c54646, never pushed)

Safety verdicts now ride the turn span as `candy_code.safety_event` OTLP events.
No Runtime/fork change was needed — PermissionAsked/PermissionResolved/ToolFinished
already cross the encoder on all three fed paths (settled queue + live pump +
hosted fold, verified pre-design); STOP clause not triggered.

## Shape landed (OtlpEncoder)
- ToolFinished with `denial` → decision=deny, kind=`DenialKind::token()`, tool,
  `reason_token` = first word of the enum's own reason prefix only, emitted ONLY
  when the carried content actually starts with `DenialKind::value` (unprefixed
  carrier → token absent, body never leaks). Privacy law extended in class docblock.
- PermissionAsked → decision=ask, tool, source, mode.
- PermissionResolved non-approve → decision=reject / decision=cancel (docblock law
  kept: reject=Refused kind, cancel=Unanswered kind) + ask_id; approve emits nothing.
- Events omitted from the span payload when empty → no-verdict spans byte-identical.
- docs/ENVIRONMENT.md OTel row documents the vocabulary + privacy bound (lowercase
  tokens only; EnvRoster guards stayed green).

## Gates (cwd=candy-code, -c phpunit.xml, plain pipe)
- tests/Observability + tests/Host/SessionHostOtelFoldTest.php + tests/Cli/NonInteractiveOtelExportTest.php: **OK 87 tests, 353 assertions, exit 0**.
- Five-guard + EnvRoster filter: 171T, **3 failures, ALL BASE-INHERITED, disclosed**:
  (1,2) ReadmeSuiteFigureDrift staleness pair pin 25,537 vs live 27,900 (my +11T rides
  an already-red pair; main tree at b84ed2118+foreign-dirt shows 27,945 for the same
  pair) — closeout re-pin owns it; (3) GlobDialect corpus 310,245 (559x555) vs docblock
  309,132 — already cured on master by ed5fbffed (LATER than my base, other lanes'
  drift); my files add zero glob-shaped literals (verified). I did NOT touch
  PathGlob.php / suite-figure.json / durations.tsv (forbidden).
- Mutations cp+md5 clean (orig e653b81d…): M1 neuter absorb-call → exactly 9/11 red
  (all positive pins; negatives survive); M2 always-emit-events-key → exactly 3 red
  (the omitted-events pins). Restore green 87/353.
- 2 "PHPUnit Deprecations" in any --filter run trace to tests/Config/AccessorDeprecationTest
  non-public data providers (collection phase, file untouched by me; path-arg runs 0 D).

## Files
- M candy-code/src/Observability/OtlpEncoder.php (docblocks, SAFETY_EVENT_NAME const,
  absorb hook, openSpan events init, renderSpan omit-when-empty, addSafetyEvent,
  reasonPrefixToken)
- M candy-code/docs/ENVIRONMENT.md (OTel endpoint description cell)
- **NEW candy-code/tests/Observability/OtlpSafetyEventTest.php — 11 tests, 54 assertions
  → gate must add its durations.tsv row.**

No seams outside declared regions. Author/committer detain@interserver.net verified
separately (1/1), zero Co-Authored trailers, no caliber, worktree clean.
