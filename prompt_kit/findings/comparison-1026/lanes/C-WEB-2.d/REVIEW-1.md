# C-WEB-2.d — REVIEW-1 (round-1 reviewer)

VERDICT: CLEAN
0 CRITICAL / 0 MAJOR / 1 MINOR (step-local) / 2 NIT

Lane tip 8d7615e798574277e9daed4cbb3095074f683f7b, base 484f313a9, 1 commit, 8 files (git diff --name-only verified; ZERO bytes of docs/protocol/*.json, generated.ts, dist, suite-figure.json, durations.tsv in the diff). Review at main tree /home/sites/sugarcraft (welder copies).

## P1 — trust boundary (PASS)
`SkillSlashInvocation::draft()` IS the real admission predicate, not a duplicate: called from Chat.php:11992 (TUI delegate), Host/SessionHost.php:1421 (serve admit funnel), and the list veto (CommandMethods.php:84). Same function, same inputs — list==dispatch by construction.
- Serve rewrite is live: TurnMethods::admit defaults resolveMentions=true; admit's gate order file→builtin→draft mirrors the list's isset→veto.
- Clash rule order-correct: builtins+files populate $items first, skill loop sits before ksort; isset is exact-case, matching resolveCustomCommand's case-SENSITIVE exact lookup (TurnController.php:426). Case-variant builtin shadowing is covered by draft's `forSpelling(name)||forSpelling(strtolower(name))` re-check, which walks ALL builtin spellings (not slashVisible-filtered, BuiltInCommands.php:130-143) — strictly conservative vs dispatch.
- Hostile names: shape `#^/([A-Za-z][\w:-]*)$#` rejects ESC/OSC-8/PUA/control/whitespace names byte-wise (UTF-8 of U+E000 = \xe3\x80\x80, none in [\w-]); pinned over the real wire in testANameTheSlashShapeRefusesIsNeverListed via a hand-built Skill (does not trust frontmatter screening). Disabled skills blocked twice (all() filters isDisabled; userInvocable() routes get() which checks isDisabled); non-userInvocable excluded by getUserInvocable(). Positive controls pinned.
- exec immunity: skill loop writes only the describe-list; command.exec resolves specs from CommandRegistry+customCommands — test pins `command_not_found` for a listed skill over the wire.
- `ns:sub` longest-first spelling shared with dispatch — admission-walk test asserts every listed skill row survives draft() itself (non-vacuous: assertNotSame([],names)).

## P2 — protocol truth (PASS, weld carry owed)
Row shape = exactly the 5 schema-required fields (describe() parity). runsIn is the constant 'server' — correct, not fabricated: a skill has no client executable; the action is host-side text (design mandates the constant; file/mcp rows pre-date with the same constant). No client-executable introduced: session.ts send() routes command.exec ONLY on source==='builtin'; skill→session.send, verified. Enum widened in MethodSchemas to builtin|file|mcp|skill.

## P3 — generated-artifact hygiene (PASS)
SERVER.md hunk at :691-702 — outside ALL markers (methods :479-563, events :593-633 — located, verified). No trio/dist/figure/durations files in the commit.
AS-RUN drift fact: `php candy-code/scripts/gen-protocol-schema.php --check` and `--write` invocation were DENIED by my permission map (both tools/ and scripts/ spellings). Proxy: ProtocolSchemaDriftTest regenerates in-memory and diffs — its failure excerpt shows the drift is EXACTLY the +`"skill"` enum line and nothing else. The builder's ERRATUM is CONFIRMED: the old 815ba17a0 (~25+/3−) base drift is gone at this cut; a prior weld refreshed the trio. So the welder's regen-PAIR is genuinely owed (drift is real, minimal): schema regen → generated.ts (`npm run gen:protocol` — welder env, not this reviewer) + dist rebuild, in the SAME commit as the pick. Lane tip ProtocolSchemaDriftTest 1F is by-design-until-weld (plan :3335 "weld regenerates schema → SERVER.md → generated.ts once").

## P4 — §1.8 / rosters (PASS)
No new settings key, env var, Lang string, or slash command — the diff adds none (grep-verified; no LAYERED_KEYS/getenv touches). EnvRosterDrift + ReadmeRosterDrift + SettingsSchemaDocDrift + ServerProtocolDocumentationDrift all green. ARCHITECTURE.md:155 on master reads "dispatch arms for 65" (git show master: verified) — lane adds no command, merged tree must land 65 UNCHANGED; DocFigureProseDriftTest live-counts slashCommands(), untouched by this diff. `mcp` in the session.ts union: the same field's one line being widened anyway and the server has emitted `mcp` since C-MCP-2.b — judged in-scope type-truth, not creep (runtime routing unaffected; only source==='builtin' branches).

## P5 — frontend pins (PASS)
components.spec: the /-menu assertion was TIGHTENED (exact toEqual gains '/shipit'), not loosened; session.spec: additive 3 asserts — routes via session.send, and a never-exec guard (`sent('command.exec').some(name==='shipit')===false`). Not tautological. NOTE: vitest and vue-tsc could NOT be run — permission map denied every form (`env -u… node_modules/.bin/vitest run …`, bare, with/without pipes; `node_modules/.bin/vue-tsc --build --force` also denied). Builder claims (vitest 2 files/39T; vue-tsc 21 foreign, 0 lane) stand UNVERIFIED by me; TS edits are 1-line union + fixture literals narrowed `as const`, low type risk.

## Gates I reproduced (cwd=candy-code, plain pipe, -c phpunit.xml)
- `--filter CommandListSkillRowsTest` → Tests: 9, Assertions: 146, 2 house PHPUnit deprecations, OK — EXACT match to contract.
- `--filter 'CommandListSkillRows|CommandMethodsMcpPrompts|ServedSkillSlash|SkillSlashInvocation|ServerProtocolDocumentationDrift|ProtocolSchemaDrift'` → 45T/691A, 1F = ProtocolSchemaDriftTest only (the by-design regen carry).
- `--filter 'DuplicatedTestHelperDrift|ReadmeRosterDrift|EnvRosterDrift|SettingsSchemaDocDrift|ServerProtocolDocumentationDrift'` → 86T/40784A, 1F = DuplicatedTestHelperDrift const polity HEAVY_CHARS (Chat/ResumeCostGuardTest vs Host/ResumeCostEstimateTest) — standing red roster, NOT lane-attributed.

## Findings
- MINOR (step-local): no listing-side pin for a CASE-VARIANT builtin shadow (skill `Keys` vs builtin `keys`): covered by draft's forSpelling(strtolower) veto but unpinned here. Cure: one row in testABuiltInSpellingKeepsItsRowAgainstAShadowingSkill's fixture (`'Keys' => 'x'`) asserting no second row; welder may skip (SkillSlashInvocation's own pins hold the behavior).
- NIT: new test's `diskRegistry` shares its name with ServedSkillSlashTest:72 (guard tolerates the divergence; rename to diskSkills would read cleaner).
- NIT: inline `\SugarCraft\Code\Skills\SkillSlashInvocation::draft` FQN in CommandMethods matches the Chat/SessionHost call sites — consistent, ignore.

## Welder carries (§4)
1. MINT durations row for `candy-code/tests/Protocol/CommandListSkillRowsTest.php`: measured 15.09s wall (9T) at this cut.
2. Figure pair +9T/+146A (plus tree-scan census wobble) — refresh per §7 cadence at the weld run.
3. Protocol regen PAIR in the pick's commit: `php candy-code/scripts/gen-protocol-schema.php --write` (+1-line skill enum expected, nothing else) → `npm run gen:protocol` → dist rebuild. Drift is REAL and minimal — this regen is owed.
4. ARCHITECTURE dispatch-arms stays 65 (no command added). No env/settings/lang roster work owed.
