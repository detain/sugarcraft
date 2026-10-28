# W15 WAVE-REVIEW-1 — master tip 045751c4483fa37bb287d98fc8b6cfef0e646a6f

VERDICT: FINDINGS — 0 CRITICAL / 1 MAJOR / 0 MINOR / 1 NIT

## 1. Pick verification (10/10 PASS)

All ten §4 marks cat-file resolve, are ancestors of master, and carry `ae=ce=detain@interserver.net` (separate greps) with zero Co-Authored trailers in `%B`:

| step | pick | weld |
|---|---|---|
| P-TABS.f | 0fb10ffbf0ca… | 6638d6415 |
| C-WEB-3.g | 770f8ee9bc75… | cbe74fb5a |
| C-TUI-2.b | e2bc4292dafc… | 6875a1d90 |
| C-WEB-4.a | 9e22d932fab8… | 945d4dd8f + regen 649f76f69 |
| C-AGT-5.c | ff23948e797c… | ea68b8eae + 045751c44 (tip) |
| C-AGT-5.e | 23e4dae805c2… | 945d4dd8f |
| C-AGT-5.g | 0cbd4e7c787b… | 945d4dd8f |
| C-AGT-6.d | b6369af78695… | 1c46570f2 + a7abfe61c |
| C-CTX-4.c | d361720d9092… | 846119228 + 579f8f12c |
| C-EXT-3.c | a1adc4f35bf1… | 0cebf6653? (row verified live) + 8d7817be0 |

MCP-3.b band (5cac857fc/37a4ef615/808859066) sits between picks but is W16 — excluded per brief. Tip weld declares "W15 fully welded" — the ledger claim is true except the MAJOR below.

## 2. MAJOR — C-AGT-6.d `/pr` roster flips never landed; 3 tests RED at tip

`env -u LINES -u COLUMNS vendor/bin/phpunit -c phpunit.xml --filter 'MenuBar|ShellMenu|PaletteCatalog|PaletteAction|SlashDispatch'` → **Tests: 130, Failures: 3**:
1. `tests/Commands/MenuBarLabelDerivationTest.php:90` — `DERIVED_LABELS` lacks `'pr' => 'Push the current'` (registry now derives it).
2. `tests/Tui/Menu/ShellMenuTest.php:71` — registry↔placement equality diff begins at `40 => 'pr'`: the pr row is NOT placed in `src/Tui/Menu/ShellMenu.php` (shells/schedules WERE placed since; pr never was).
3. `tests/Tui/Menu/ShellMenuTest.php:108` — unplaced pr forces the `sub:more` submenu to materialize, violating "shipped tree has no More".

Provenance: W14 closeout 96d36db45 assigned "W15 C-AGT-6.d /pr 4F roster flips" as that lane's carry; AGT-6.d WELD.md's gate filters (`PrCommandTest|…|CommandDocsDriftTest`, "ten REPORT names" battery) never included MenuBarLabelDerivationTest/ShellMenuTest, and the pick's file-set touches neither test nor ShellMenu.php. P-TABS.f weld 6638d6415 recorded the same family as foreign-standing; shells/schedules have since been cured — pr is the only remaining offender. Review r1 CLEAN missed it too.
**Cure:** add the pr row to ShellMenu's `automate` group (after `shells`/`schedules`, line ~129), add `'pr' => 'Push the current',` to `DERIVED_LABELS` after `security-review`, re-run the two files + MouseModalGuardTest until green. (Severity rationale: rows remain reachable via App▸More fallback — functionality intact — but the merged tree ships red on its own fail-closed placement law, so the band cannot be gated green as-is.)

## 3. Union-diff coherence — CLEAN

- `lang/en.php`: `grep -oE "'[a-z0-9_.-]+' *=>" | sort | uniq -d` empty at tip AND at band start (96d36db45) — no dropped/duplicated rows across the five lang-touching lanes' en.php unions. New keys present with correct §6.3 prefixes: `chat.tabs.close_no_deck`, `chat.schedule.heartbeat.alert` (+fired/spawn_failed/loop_queued), `cmd.pr.description|hint`, `cmd.review.bad-pr|hint`, `host.skills.new.drafted|default_description`. En-only per §6.3-h (11-locale strict parity owed LAST by C-TUI-6.h — not this band).
- Chat.php three claimed regions are disjoint and stacked clean: `presentSession`/deck routes (P-TABS.f, 8 hits incl. `deckHosted` docblock), `pumpBackgroundSessions` heartbeat suppression+ring (AGT-5.c, HEARTBEAT_OK trim at :19164, ring :19226), `handlePrCommand` canned-submit (AGT-6.d) — no hand-resolved deltas (all picks patch-id-identical per welds).
- ScheduleFactory.php/SchedulesCommand.php: AGT-5.b root-scoped delete docblock and AGT-5.c `--heartbeat` peel/kind stamp coexist; `KIND_HEARTBEAT` never bypasses (silence suppresses notice only; alert rings) — matches §6.3-b stamp.
- MenuBar/ShellMenu rosters: shells/schedules rows placed and green; only pr red (finding 2).
- `git status` clean on generated surfaces; all four generators report up-to-date rc0: gen-command-docs, gen-tool-docs, gen-settings-doc, scripts/gen-protocol-schema. `tools/check-one-type-per-file.php` + `tools/check-child-lifetimes.php` were DENIED by the reviewer permission map; substituted statically: Automation/Background deck files each declare exactly one `final class`; GhRunner uses the rostered `CapturesProcessOutput` trait (zero new proc_open sites), `locateOnPath` stat-walk, argv discipline documented (model text never reaches argv words), per-call 10.0s ceiling precedented.

## 4. Battery (merged tree)

- Guard set `DocFigureProseDrift|GlobDialectDifferential|LangParity|ReadmeRosterDrift|KeyBindingDrift|CommandDocsDrift|TreeWideGuardRoster|StderrEmitterCensus|DuplicatedTestHelperDrift`: **OK — 477T/34533A**, 0F (2 ambient PHPUnit deprecations = AccessorDeprecation standing).
- `Schedules|Shells|Heartbeat|Deck|Hosted`: 238T, single red = DreamPassTest:288 (standing roster, name-collide) — effectively green.
- ReadPathCensus: OK 19T/646A.
- sugar-diff: **OK (116 tests, 339 assertions)** — exact vs C-TUI-2.b promise.
- vitest serial `--no-file-parallelism` (dormantRoster + shutdownDormant + composerDropAndChips + paste + promptHistory): **5 files / 41 tests passed** — C-WEB-3.g spec live and fail-loud as designed.
- Not run by law: full serial, PtyRunToolTest never in any filter (honored).

## 5. Durations set-equality — PASS

All 11 new candy-code *Test.php from the 10 picks (DeckHostedSessionRoutes, HeartbeatSchedule, OutboundWebhookConfig, WebhookSigner, WebhookDispatcher, PrCommand, ReviewCommand, GhRunner, PrHostCommand, InstructionWalkPastRoot, SkillsNew) carry exactly ONE row in scripts/parallel-tests-durations.tsv; zero spec.ts leak; live count 1540 (brief's hash attributions for rows 1c46570f2/846119228/ea68b8eae verified; f0cf899f9 is the C-PRV-2.b row — brief pre-map error, disk truth rules).

## 6. Laws / security sweep — CLEAN

E646: only per-call ceilings (webhook CONNECT 2.0 / POST 10.0 via Browser::withTimeout with an explicit non-blanket docblock; PR_TIMEOUT 10.0) — no turn-total timers. Webhook URL law (https-only except literal loopback, no userinfo, redirect-refusal, user-tier-only read so a cloned repo cannot mint an endpoint) verified in OutboundWebhookConfig docblock+parser. Prompt-cache: no cache-key surface touched by the band. Dormant-roster: C-WEB-3.g spec green = no new uncalled RPCs slipped in.

## 7. NIT

- plan_comparison.md:1833 — C-TUI-2.b's §4 mark is the 9-char `e2bc4292d` while every other W15 mark is the full 40-char hash ("§4 full-hash mark" house style, per 045751c44's own message). Cure: replace with `e2bc4292dafc00444a13fc9687e4622507981fd3` in the closeout stamp.
