# C-WEB-2.d — Skills in the web `/` menu (`command.list` `source:"skill"`)

**VERDICT: DONE.** Server lists one `source:"skill"` `runsIn:"server"` row per
user-invocable skill, exactly the rows admission would dispatch; schema enum
gains `skill`; web `CommandInfo.source` union widened; 9 new wire-level tests
green; all targeted gates green. Trio + generated.ts + dist regen is a
**WELDER CARRY** (verified minimal, restored in-lane — see §CARRY).

Base cut: master `484f313a9`. Master re-derived at finish: moved (welder); this
pick applies on any post-cut master (`CommandMethods::list()` tail,
`MethodSchemas` command.list enum, `session.ts` interface, two spec fixtures —
C-WEB-2.f touches none of these lines).

BRIEF.md absent in this lane dir — worked from the design block
`design-web.md:204-219` directly (noted per instruction).

## Contract landed

`command.list` items gain rows `{name, description, argumentHint:null,
source:"skill", runsIn:"server"}` for each `SkillRegistry::getUserInvocable()`
entry (registered, not disabled, `userInvocable:true`). Clash rule: a built-in
or command-file name wins (`isset($items[...])`), and — the admission-faithful
half — a name vetoed by `SkillSlashInvocation::draft('/'.$name, $skills)` never
appears, which is precisely SessionHost::admit()'s own gate incl. its
case-insensitive built-in re-check and the slash-shape trust bound
(`^/[A-Za-z][\w:-]*$`). A skill is never a `command.exec` target (server refusal
pinned over the wire); selecting a row = client sends typed `/name args` via
`session.send`, host rewrites.

Trust bounds: hostile display strings cannot enter the roster — a forged
OSC-8/PUA-sentinel name is refused by the draft veto (pinned); web rows render
through `{{ }}` interpolation (no v-html on this surface — audited: v-html only
in DOMPurify'd MessageMarkdown/DiffView/FileViewer).

## Files

- M `candy-code/src/Protocol/Methods/CommandMethods.php` — skill loop + rule comment in `list()`.
- M `candy-code/src/Protocol/Schema/MethodSchemas.php` — source enum `builtin|file|mcp|skill`.
- M `candy-code/docs/SERVER.md` — hand-written prose paragraph (outside generated markers; `--write` left markers byte-identical, proven).
- A `candy-code/tests/Protocol/CommandListSkillRowsTest.php` — NEW, 9T/146A (welder: durations row owed).
- M `candy-code-web/src-web/stores/session.ts` — `CommandInfo.source` union (adds `mcp` too: pre-existing lie — generated.ts already emitted `mcp` since the mcp-prompt pick).
- M `candy-code-web/src-web/components/__tests__/components.spec.ts` — skill row completes in `/` menu.
- M `candy-code-web/src-web/stores/__tests__/session.spec.ts` — skill row routes via `session.send`, never `command.exec` (design's "sends via session.send" pin lands here — the routing test lives in the store spec).

## Gates (as-run, worktree state)

- `--filter CommandListSkillRowsTest` → 9T/146A green (2 PHPUnit deprecations = house-shared: `ServedSkillSlashTest` alone reports the same 2).
- `--filter 'CommandListSkillRows|CommandMethodsMcpPrompts|ServedSkillSlash|SkillSlashInvocation|ServerProtocolDocumentationDrift'` → 38T/389A 0F.
- `--filter 'ReadmeRosterDrift|SettingsSchemaDocDrift|EnvRosterDrift'` → 66T/40527A 0F (no new settings/env vars → roster laws N/A, guards confirm).
- `ProtocolSchemaDriftTest` → 1F **by design while uncommitted** (schema.json lacks `skill` until the welder commits the regen pair). Red at pure base? NO — see CARRY.
- vitest `--no-file-parallelism components.spec.ts session.spec.ts` → 2 files / 39T passed.
- cold `vue-tsc --build --force` → 21 errors, **all foreign** (contextPanel×8+1, contextSources×4, statusBar×2, reducer×4, contextTrustRouting×1, dormantRoster×1). ZERO in lane files. Matches C-TUI-3.b weld baseline 21.

## Mutation proofs (cp + md5sum -c restores, never git-checkout-dirty)

- M1 neuter veto guard (`if(false)`) → exactly 3F: builtin-shadow, file-shadow, hostile-name. Restore OK.
- M2 empty the skill loop → exactly 5F: listed, disabled-arm(with positive control), admission-walk (non-vacuous), merged-order, exec-refusal (list arm). Restore OK.
- W flip skill row `runsIn:'client'` in Composer fixture → exactly 1F (completion test). Restore OK (md5-verified).

## CARRY for the welder (regen-verify-then-restore, per law)

`scripts/gen-protocol-schema.php --write` paired with `npm run gen:protocol` + dist,
in the same commit as this pick. Verified deltas, minimal and exact:
- `docs/protocol/candy-code.v1.schema.json`: +2/−1 (the `"skill"` enum entry).
- `docs/SERVER.md` generated markers: zero change.
- `src-web/protocol/generated.ts`: one line — `source: "builtin" | "file" | "mcp" | "skill"`.
- `candy-code-web/dist`: rebuild owed by welder (never touched in-lane).

**ERRATUM vs spawn brief:** the "known pre-existing schema drift at base
(815ba17a0, ~25+/3−)" is **NOT present at my cut `484f313a9`**: with lane dirt
stashed, `gen-protocol-schema --check` prints "protocol schema: up to date"
while 815ba17a0 is verified an ancestor. Some earlier weld already refreshed
the trio. So the base-red ProtocolSchemaDrift I inherited is attributable
solely to my own uncommitted enum, and closes with the welder's regen pair —
no foreign fix needed.

## Disclosures / collisions

- session.ts union adds `mcp` alongside `skill` (fixes a stale hand-type; runtime routing unchanged — non-`builtin` always took the `session.send` path).
- Overlap watch (reported, not edited): C-WEB-2.c (NonInteractive `/skill-name` twin) and C-WEB-2.f (`/effort`) both touch skill/command surfaces; none of my touched lines collide with their stated files. GAP-CMD-02 (`CommandMethods::files()`) untouched — different method.
- No new settings, env vars, or `Lang::t()` strings → §1.8/EnvRoster laws N/A; `protocol` serialization respected (no concurrent generated-file commits from this lane).
