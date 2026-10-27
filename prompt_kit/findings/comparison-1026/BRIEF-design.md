# Brief — design pass for the new candy-code comparison plan

Repo: `/home/sites/sugarcraft` (master).
- `candy-code/` is a PHP 8.3 TUI coding agent. Entry `bin/candy-code`, wiring hub `src/Cli/Bootstrap.php`, TUI `src/Chat.php` + `src/Chat/Handlers/*`, engine `src/Runtime.php`, server `src/Server/`, `src/Host/`, `src/Protocol/`.
- `candy-code-web/src-web/` is its Vue 3 client, used over `candy-code serve`.

**READ-ONLY:**
- Your only write is `prompt_kit/findings/comparison-1026/design-<area>.md` (name given in your prompt).
- Do not run test suites, composer, or the app.
- grep, ls, sed and `php -l` are fine.

## Goal

The owner picked items for the next plan. Turn each item assigned to you into **implementation steps sized for one agent lane** (a lane = one agent in its own git worktree, committing one cohesive change). The orchestrator assembles every design file into `plan_comparison.md`, organised in **waves of up to 10 concurrent lanes**.

What makes a good design:
- **exact file lists**, so lanes in the same wave never collide;
- **honest dependencies**, so prerequisites land in earlier waves.

## Owner ordering rule

"Save the large items for last; get everything else done first." These six items are scheduled in the FINAL waves:
- C-EXT-6 (HTTP/prompt hooks & hook log)
- C-MCP-5 (candy-code as MCP server)
- C-TOOL-4 (sandbox breadth & egress)
- C-TOOL-6 (new tool types)
- C-TUI-6 (Windows, i18n, a11y, voice)
- C-WEB-5 (remote serve TLS & push)

If one of them is yours:
- design it in full, but mark each of its steps `- phase: LATE`;
- never make a non-LATE step depend on a LATE step;
- if a LATE item has a cheap, independent sliver (e.g. the `/hooks log` ring, or web dictation), you MAY split that into its own non-LATE step only if it is S effort and needs no ruling. Mark that step `- phase: normal` and say why.

Every other step is `- phase: normal`.

## Inputs

- `prompt_kit/findings/comparison-1026/candidates.md`: your items' blocks. The `sources:` line names the evidence IDs.
- The evidence behind those IDs. Read only the blocks you need:
  - `GAP-*` blocks: `## Gaps` in `cc-audit-k1.md` / `k2` / `k3`.
  - `*-Pn` problems: `## Problems` in `plan-review-p1.md` / `p2`.
  - `p1-miss` / `p2-miss`: `## Missing from the plan` in the same files.
  - `CC-new#n`, `OC-new#n`, `CLAW-new#n`: `## What's new (for the plan)` in `comp-claude-code.md` / `comp-opencode.md` / `comp-openclaw.md`.
  - `k*-dorm`: `## Dormant code` in the audits.
- The live source tree is the authority for paths. Verify that every MODIFY path exists and every CREATE path does not.
- `plan_comparison.md` §1–§2 (the previous plan's general rules and serialization set). The new plan keeps those laws. Skim them; do not copy them.

## Project laws a step must respect

- **E646:** never a blanket total-request timeout on LLM calls. Connect timeouts are fine.
- **Dormant code:** wire it, never delete it. Moving or consolidating is fine.
- **Fork/child discipline:**
  - every new `proc_open`/fork goes through `ProcessContainment::spawnSpec`;
  - it gets a roster row in `tools/check-child-lifetimes.php`.
- **One type per PSR-4 file:** `tools/check-one-type-per-file.php`.
- **Strings:** user-facing strings go through `Lang::t()` with keys in `candy-code/lang/en.php`.
- **Drift guards:** they re-derive README/docs rosters (tools, slash commands, env vars, key bindings, settings, trust keys, config-write producers) from `src/`. A step that adds one of those MUST name the doc file and the guard test it moves in the same step.
- **Weld-only files:** a lane never edits `candy-code/tests/Config/Support/suite-figure.json` or the root `scripts/parallel-tests-durations.tsv`. Do list each NEW test file, because the weld adds it to the durations TSV.
- **Fix root causes**, with a regression test per fix.

## Serialization set (a step must declare every one it touches)

| Tag | Files |
|---|---|
| `[chat]` | `candy-code/src/Chat.php`. Name the region: dispatch arm / palette / pump / view / a method name. |
| `[bootstrap]` | `candy-code/src/Cli/Bootstrap.php`. Name the method(s). |
| `[renderer]` | `candy-code/src/Renderer.php`, `candy-code/src/Tui/Renderer.php` |
| `[runtime]` | `candy-code/src/Runtime.php` |
| `[lang]` | `candy-code/lang/en.php`. Soft: give your key prefix. |
| `[docs:<file>]` | `candy-code/README.md`, `candy-code/docs/*.md`. Name each page. |
| `[commands]` | the command registry/dispatch surface: `src/Commands/CommandRegistry.php`, `src/Commands/Specs/BuiltInCommands.php`, the READ_ONLY_COMMANDS roster in Chat.php, and edits to EXISTING `candy-code/builtin-commands/*.php` rows. A NEW builtin-commands file is per-command-disjoint and not serialized, but say so. |
| `[catalog]` | `src/Tools/Catalog/ToolCatalog.php` + the built-in tool position roster. |
| `[protocol]` | `src/Protocol/Methods/`, `Protocol/Schema/MethodSchemas.php`, the event schemas, and the gen chain → `docs/protocol/candy-code.v1.schema.json`, `docs/SERVER.md` → `candy-code-web/src-web/protocol/generated.ts`. Exactly ONE protocol lane per wave. |
| `[headless]` | `bin/candy-code`, `src/Cli/ArgvParser.php`, `src/Cli/ParsedArgs.php`, `src/Cli/NonInteractive.php`, `src/Cli/Subcommands.php`. |
| `[workflows]` | `.github/workflows/*` |
| `[hot:<path>]` | any other file you see more than one of your steps (or another area's likely steps) touching, e.g. `src/Host/SessionHost.php` or a central Vue component like `candy-code-web/src-web/App.vue`. Flag it so the orchestrator can serialize it. |

**Design to minimise serialized touches.** Split a feature into:
- a self-contained core step: new classes, services and tests, with no serialized files;
- a thin wiring step that touches Chat.php, Bootstrap.php or the protocol chain.

That lets cores run 10-wide and only the wiring steps serialize.

## Output: `design-<area>.md`

1. **`## Summary` table.** Columns: step id | title | effort | serialized tags | deps.
2. **One block per step, exactly this shape:**

```
### <STEP-ID> — <short title>
- item: <candidate or pick ID> (sources: <evidence IDs>)
- kind: fix | gap | dormant-wire | feature · severity (fixes) · effort S|M|L
- approach: <3–8 lines: root cause with file:line for fixes; the design for features; what the user sees>
- files.create: `candy-code/src/...`, `candy-code-web/src-web/...` (repo-root-relative; "none" if none)
- files.modify: `path` (method/region), … (each verified to exist)
- tests: create `candy-code/tests/...Test.php` (what it pins); modify `...`
- docs/guards: <doc pages + drift-guard tests moved in-step; child-lifetime roster row; "none">
- serialized: [chat:<region>] [bootstrap:<method>] [protocol] … or none
- deps: <STEP-IDs, or candidate IDs owned by other areas; "none">
- phase: normal | LATE
- ruling: none | <one-line question the owner must answer before this step can run>
- risks: <one line>
```

3. **Step IDs:** `<ITEM>.<letter>`, e.g. `C-SEC-1.a`, `C-SEC-1.b`, `P-TABS.a`.
4. **Step size:** S or M work for one agent. Split L items into 2–4 steps.
5. **Order:** order steps so each deps line points only to earlier steps or to other areas' items.
6. **Cross-area deps:** name them explicitly. The `## Suggested order` section in `candidates.md` lists the known ones.
7. **`## Notes for the orchestrator`:** a short list of shared hot files, rulings, and anything you could not verify.

## Final message

At most 8 lines: step count, the total of S/M/L, the serialized surfaces your steps touch most, and the path.
