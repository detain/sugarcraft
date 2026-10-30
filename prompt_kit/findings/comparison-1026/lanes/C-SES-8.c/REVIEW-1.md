# C-SES-8.c — REVIEW-1 (round 1, fresh reviewer)

**VERDICT: FINDINGS** — 0 CRITICAL / 0 MAJOR / 1 MINOR / 2 NIT. Materially sound; two one-line fixes.

Commit `e2759d081` (parent `cf0cfd0be`) re-verified: real object, one commit, 5 files +1216 — exactly the brief's create-set (2 src, 2 tests) + en.php modify; EnhancedSessionStore.php NOT touched; no suite-figure.json / durations.tsv / dist / PathGlob.php in diff. `%ae`/`%ce` both detain@interserver.net, zero Co-Authored trailers.

## Production reachability — PASS (by design)
`InsightsHostCommand` has zero src callers — correct: the brief's docs/guards line says "none (wiring is in C-SES-8.d)" and plan §4 C-SES-8.d (plan_comparison.md:3041, W25) owns `builtin-commands/2215-insights.php` + `Chat::handleInsightsCommand` + READ_ONLY_COMMANDS. Dormancy is a planned later step, not un-wired code. `InsightsCommand` composes via `Lang::t` on every user string; grid format byte-identical to `UsageCommand::ROW_FORMAT` (:57); spend data comes from the SAME `UsageHostCommand::rollupFacts(store,null,W,true)` (C-SES-9.a read), so ≥-law parity with /usage holds by construction.

## Vacuity pass on the 14 tests — PASS
Text: byte-exact 37-line page + `lineBudget()` equality AND `substr_count("\n")+1 === lineBudget` on 5 shapes (full/empty/blind/bound/forged); LogicException names each of the 5 required facts on BOTH faces; forgery pin proves `Chat::reportField` (Sanitize::untrusted + \n\r\t→visible literals, PermissionsCommand:485) reduces injected "Spend by model: 99 groups" to one-cell residue (`/^Spend by model/m` count 1, budget unchanged); foreign kind keeps its row. HTML: `htmlspecialchars(ENT_QUOTES|ENT_SUBSTITUTE)` pins — `<script>` escaped, malformed UTF-8 `"/bad\xFF\xFE/path"` renders U+FFFD not an empty cell; no `src="` (self-contained); none-sentinel colspan count 4. Host: REAL seeded sqlite store (branch fork, `import:` provider, backdated 2020 row via raw PDO, unpriced usage); asserts file exists at the exact `insights-<Ymd>.html` path the code builds, 0600 `fileperms`, reply starts with title and carries path; junk windows (`0`, `-5`, `nope`) === usage reply AND shelf stays at 1 file (refusals write nothing); no-store reply + empty shelf; FILE-as-dir blocker → page + `Report not written:` names path. All pins would fail without the code. No glob-shaped or `**Refused:**`-shaped literals in either test file (GlobDialect green confirms §1.8/§1.9).

## Privacy/security — PASS
Writes under `HomeDirectory::owned() . '/' . ShareCommand::EXPORTS_SUBDIR` — the same shelf as `/export`; mode 0600 enforced by `AtomicFileWriter` (chmod on temp BEFORE payload/rename, mode ≤0777 validated, mkdir 0700, rename replaces rather than follows a symlink, temp unlinked on throw). This is the correct mirror of the ExportCommand shelf pattern; the SubAgentTranscriptLog `is_link` door belongs to the append-log shape and is not applicable. Zero model/backend/network/timer references in both new src files — the "no billed side call" risk note honored; E646 clean. Report fields sanitized at the compose choke.

## §1.8 / figures — PASS
en.php +34 keys, contiguous, inserted between `cmd.init.hint` and `cmd.keys.description`; 34 unique `Lang::t` keys used by src == 34 added; LangParity green in-step. PathGlob untouched; builder's glob-cure claim green (GlobDialect arm passes at tip). Reproduced EXACT: guard trio StderrEmitterCensus|TreeWideGuardRoster|DocFigureProseDrift = 204T/29126A. Lane tests = 14T green; full `Insights|Usage` sweep 404T green.

## Findings
1. **MINOR (step-local)** `src/Host/Commands/InsightsHostCommand.php:84` — `$directory = ... : self::exportsDirectory()` runs OUTSIDE the try at :88; with no owned home the RuntimeException escapes `run()` before any page is returned, contradicting the class docblock's own promise ("A write failure keeps the page in the reply") and the ExportCommand pattern (which resolves the home inside its try). SessionHost dispatch catches Throwable, so production degrades to a generic host.command.error row — page lost. Fix: move the `$directory`/$stamp/$target computation inside the try block (one-line restructure; success return already follows the try, PHP function scope keeps `$target` visible).
2. **NIT (step-local)** `lang/en.php:1336-1337` — `cmd.insights.spend-heading` sorts before `cmd.insights.spend-day`; the brief promised alphabetical insertion and this is the single inversion in the block (no sort guard covers `cmd.*`, which is why it stays green). Fix: swap the two lines.
3. **NIT (report doc-truth)** `REPORT.md` — the "62T/3736A" gate figure could not be reproduced under any filter probed (closest families: 51T/3680A core guards, 65T/3751A with Usage adjacency; trio 204T/29126A exact). All named guards genuinely green; only the aggregate numeral's provenance is unconfirmed.

## Pre-existing / non-attributable (verified)
2 PHPUnit deprecations = private data providers in `tests/Config/AccessorDeprecationTest.php` (file absent from the lane diff) — builder's "house noise" claim TRUE by provenance. `CliLangCatalogue` (cli-block sort) and `ChatHostLangCatalogue` (dead chat./host. keys from other waves) fail identically with and without the insights keys; neither diff mentions cmd.insights — standing-red roster confirmed.

## For the welder (disclose in wave gate)
- durations.tsv rows owed for the 2 NEW files: `candy-code/tests/Commands/InsightsCommandTest.php`, `candy-code/tests/Host/Commands/InsightsHostCommandTest.php`.
- suite-figure.json/README carry: +14T (staleness pair red-at-tip is accepted precedent).
- plan §4 progress mark: `✅ e2759d081 C-SES-8.c`.
- If finding 1 is fixed in a follow-up commit on this branch, re-run the 14 lane tests + guard trio only.

Reviewer: cc-cmp round-1, 2026-10-28. Evidence commands run in `/home/sites/cc-cmp-C-SES-8.c/candy-code`, plain pipe, `env -u LINES -u COLUMNS`, `vendor/bin/phpunit -c phpunit.xml`.
