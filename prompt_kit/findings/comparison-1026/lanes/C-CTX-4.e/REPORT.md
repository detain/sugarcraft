# Lane C-CTX-4.e — `/output-style` (output styles) — REPORT (live)

Worktree /home/sites/cc-cmp-C-CTX-4.e, branch cc/C-CTX-4.e off 4dd8c20ce.

## Commits (each cat-file-verified after landing)
- `39073913e` — STEP-0 skeleton: builtin-commands/1405-output-style.php (registry row, no paletteAction per 0350-effort precedent), Chat::handleOutputStyleCommand (NEW method only, inserted after handleRulesCommand ~:13945 — disjoint from co-tenant's chat:14104 region), OutputStyleHostCommand stub, lang/en.php keys (cmd.output-style.* + host.output_style.*, en.php is candy-code's ONLY locale file — RegistryTranslationTest only requires en.php parity), README roster + docs/COMMANDS.md regenerated via `php tools/gen-command-docs.php --write` (generators only, no hand-edit). %ae/%ce both detain@interserver.net, no Co-Authored-By.

## State
- Gates not yet run (tree intentionally stub until C2). php -l clean on all 4 touched/created PHP files.

## Planned commits
- C2: Rule.php `style` flag (ctor param + new() tail + mutate() carry + @param); RulesState activeStyle slot + setStyle/clearStyle/activeStyle() + effectiveRule style branch (one active; frontmatter-enabled wins; disabled-set never addresses styles); RuleLoader loadUserStyles()/loadProjectStyles() (public, mirror rulebooks twins; walk `~/.candy-code/styles/` + `<root>/.candy-code/styles/`), load() order insertion + null-state style guard (`?? ($rule->style ? $rule->withEnabled(false) : $rule)` — no selection machinery = styles not in prompt; listing goes through the dedicated methods, never load()), style param threaded loadFromDirectory→readRule→Rule::new. Full OutputStyleHostCommand (list / select / off; arity refusal; collision count; ob_start capture like RulesHostCommand).
- C3: docs/MEMORY.md rules-tiers paragraph + tests/Context/OutputStyleTest.php + gates.

## RISK TO REPORT (not fixed — [runtime] is forbidden to this lane)
Runtime::standingSlot (src/Runtime.php:5231-5241) keys the SessionPromptMemo standing slab on projectRoot + rulesState->disabled() + loader identity. A mid-session `/output-style X` mutates activeStyle, which standingSlot does NOT hash → the memo can serve the pre-switch slab (style absent, or the previous style) until /clear or another slot input changes. Correct one-line fix: include `$rulesState?->activeStyle()` in standingSlot. The same class of staleness already exists for /rules toggles? No — disabled() IS hashed. So this is style-specific. Docs will state that switching styles is a refresh point; welder/orchestrator should route the standingSlot fix to a [runtime] owner.

## DUR carry
- NEW test file: candy-code/tests/Context/OutputStyleTest.php (add durations.tsv row at gate).

## Deviations
- paletteAction omitted for /output-style (PaletteAction.php not in declared files; omission precedented by 0350-effort.php etc.).
- No `Commands\OutputStyleCommand` class (design block names only the host command; logic lives in OutputStyleHostCommand).
- `off` reserved word: a file named off.md is unselectable by command (documented in footer).
