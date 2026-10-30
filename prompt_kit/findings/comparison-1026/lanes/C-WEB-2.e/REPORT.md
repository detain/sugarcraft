# C-WEB-2.e — `/help` and `/budget` host bodies — DONE

## Verdict
Shipped on `cc/C-WEB-2.e`, single commit **1ad91f22a** (`git cat-file -t` = commit, %ae/%ce both detain@interserver.net via separate greps, zero Co-Authored trailers; `git log -1 --name-only` = exactly the 8 files below). `/help` and `/budget` now carry host bodies, so `runsIn` flips to `server` by construction (`CommandMethods` admission reads `BuiltInCommands::forSpelling($name)?->hostCommand !== null`). Full targeted gate green, tree-wide drift guards green, protocol + command-docs generators `--check` rc=0 with zero drift.

## Files (8, all under candy-code — PHP-only lane, no npm/dist touched)
- create `src/Host/Commands/HelpHostCommand.php` — renders `CommandRegistry::slashCommands()` grouped one heading per category (heading order = first-appearance, the property the TUI pins), plus the workspace's file commands read the way the retired `CommandMethods::files()` did (direct `workspace->commandLoader->loadAll(projectRoot, projectCommandsTrusted)` filtered `isFileBased() && slashVisible`; a file row replaces a built-in of the same name, else appends). Marks built-in rows without a host body `(terminal only)` (`host.help.terminal_only`); name column mirrors the TUI's (NAME_COLUMN 24) with the same spill shape; no column clipping, no keys-hint trailer (headless has no keymap to advertise).
- create `src/Host/Commands/BudgetHostCommand.php` — bare form answers `SpendLedger::statusLine($tracker, $cap)` (ledger resolved like `SessionHost::ledger()`: `workspace->service(SpendLedger::class)` else `SpendLedger::new()`; tracker from `CommandContext->spendTracker`, falling back to a fresh one so an absent tracker reads "not reported", never `$0.0000`). Amount forms (`7`, `$2.50`) and `off|none|clear` **refuse out loud** with `host.budget.server_cap` (serve cap is the launch's `--max-cost`, shared by every session); junk → `host.budget.usage`. Never mutates state; `effects` empty.
- create `tests/Host/Commands/HelpHostCommandTest.php` (9T) — host body present; echo+single assistant row; one heading per category (derived from the registry, order+dups pinned); terminal-only marked set exact (substr_count == count of body-less rows); host-bodied rows not marked; file commands listed under Custom unmarked; listing not clipped (longest description verbatim); wide-hint spill row shape.
- create `tests/Host/Commands/BudgetHostCommandTest.php` (8T) — host body present; bare shows spend vs cap; unpriced session reads `≥$…` + the lower-bound sentence (SES-1 law — real field `TokenTracker::hasUnpricedUsage()` verified on both sides, no key-name desync); `/budget 7` refusal byte-exact + `assertSame([], $result->effects)` + cap unchanged 5.0; `off` and `$2.50` refusals; junk loop (banana/0/1e309/-4) → usage line, cap unchanged; no-tracker → "not reported"; workspace-registered ledger is the one answering.
- modify `builtin-commands/1100-help.php`, `builtin-commands/2200-budget.php` — appended `->withHostCommand(...)` only; no other rows touched.
- modify `lang/en.php` — `host.help.heading`, `host.help.terminal_only`, `host.budget.server_cap`, `host.budget.usage` (sorted placement; same commit as the `Lang::t()` call sites per §1.8; LangParityTest green).
- modify `tests/Server/AttachRemoteCommandsTest.php` — in-step guard flip: the `help` roster pin asserted `'client'` ("help is the window's own page") — exactly the pre-fix state this admission change moves; flipped to `'server'` with a dated comment (TUI-local page still answers off-serve).

## Gates (cwd=candy-code, `-c phpunit.xml`, `env -u LINES -u COLUMNS` plain pipe; PtyRun expect-miss never in a filter)
- `tests/Host/Commands/HelpHostCommandTest.php tests/Host/Commands/BudgetHostCommandTest.php` → **OK (17 tests, 115 assertions)**.
- Red-before-fix proof: with the two spec rows checked out to base (cp-backup, restore md5-verified `7086bfa9…`/`48a05ddb…`), `--filter CarriesTheHostBody` → **FAILURES 2/2** ("Failed asserting that null is identical to …BudgetHostCommand").
- `BuiltInCommandsTest + LangParityTest + CommandDocsDriftTest + SlashDispatchTest + WebTwoHostCommandsTest + tests/Protocol/` → **OK (254 tests, 6104 assertions)** (SlashDispatchTest one-heading-per-category property survives the host body; COMMANDS.md drift guard green as designed).
- `tests/Server/ConformanceTest.php tests/Server/AttachRemoteCommandsTest.php` → **OK (27 tests, 163 assertions)** after the flip.
- `DuplicatedTestHelperDriftTest + GlobDialectDifferentialTest + TreeWideGuardRosterTest` → **OK (44 tests, 4407 assertions)** — new test files trip none.
- `php scripts/gen-protocol-schema.php --check` → "up to date" rc 0; `php tools/gen-protocol-docs…gen-command-docs.php --check` → "command docs: up to date" rc 0. No regen/restore needed — runsIn is runtime data on the `command.list` event; the schema carries the field shape only. Forbidden files (`suite-figure.json`, durations tsv, `dist/`, protocol trio) never written; `git status` clean at commit.

## NEW test files (welder mints durations rows)
- `candy-code/tests/Host/Commands/HelpHostCommandTest.php` (9T)
- `candy-code/tests/Host/Commands/BudgetHostCommandTest.php` (8T)
Net suite-figure drift: **+17T/+115A** (gate re-pins; expected staleness pair only).

## Deviations / findings
- Design's "CommandContext gains an optional `spend` TokenTracker" was **already satisfied** (C-SES-9 landed `spendTracker` + `spendCap`, and `SessionHost::commandContext()` already passes both, cap = `$workspace->maxCostUsd`). No CommandContext/SessionHost edit — the hot-file serialization was not entered. `Chat::commandContext()` does not pass them; harmless (TUI `/budget` handler unchanged, and the host body's fresh-tracker fallback reads "not reported").
- `CommandMethods::files()` no longer exists (C-MCP-2.b retired it); file rows were read the same way from the workspace loader directly. MCP-prompt command rows are **not** listed (the host's merged map isn't reachable from a bare `HostCommand`; the loader route reproduces the block's declared scope: "the workspace's file commands").
- `dormantRoster.spec.ts` (C-WEB-3.g) **exists and is unaffected**: it keys on `METHOD_SCOPES` (protocol methods), not command roster rows — `/help`/`/budget` admission adds no new schema method, and the web's command-exec path already has callers. Nothing to add/remove; reported per the dormant-law ask (no web file touched).

## Seam refusals
None — everything needed lived in the block's declared file set.

## Follow-up flag (from the design's risks, not a blocker)
A **per-session tighten-only spend cap from the web** is deliberately out of scope: today the serve cap is the launch's `--max-cost` shared by every session, and the amount/`off` forms refuse rather than set-but-ignore. If the owner wants per-session tightening (lower-only, never above launch cap), that needs a `CommandEffectKind` spend-cap case + SessionHost wiring — separate step.
