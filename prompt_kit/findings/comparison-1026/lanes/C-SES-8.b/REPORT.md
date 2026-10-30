# C-SES-8.b — Ledger attribution by source + /usage advice — LANDED

Verdict: COMPLETE. Branch `cc/C-SES-8.b` @ /home/sites/cc-cmp-C-SES-8.b, 3 commits atop 9fb981c5c, tree clean, never pushed.

## Commits (all cat-file'd, author+committer detain@interserver.net, zero Co-Authored trailers)
- 7bd95ba29 — ledger columns, append/rollup by source, widened account seam (pre-inherited, landed)
- 6ea7c64b4 — thread settle-arm source through Chat, trait handlers, SessionHost (pre-inherited, landed)
- b433d9858 — (this session's tail) /usage sources view, advice block from ContextSources, hint + COMMANDS regen. 6 files, +402/−11:
  - src/Commands/UsageCommand.php +130/−1 (compose() view='sources' branch: SOURCES_ROW_FORMAT grid with delegated column, always-printed section headers → line budget stays a formula, LogicException door on an unvetted fact map, advice lines ride Chat::reportField()'s choke)
  - src/Host/Commands/UsageHostCommand.php +79/−3 (order-free `sources` operand; advice() from ContextSources::measure($context, null)->mcpServers public API only — ADVICE_MIN_TOKENS 1000 floor, 3-note cap, ~Nk formatting; rollupFacts gains 'sources' = third fold of the SAME snapshot)
  - lang/en.php +8/−3 (cmd.usage.advice.header/mcp, col.delegated/col.source, source, hint `[sources|7|30|all]`, description/usage prose)
  - tests/Commands/UsageCommandTest.php +183/−2 (7 new methods + 2 usage-line pin flips)
  - tests/Cli/UsageSubcommandTest.php +1/−1 (CLI JSON key roster grows 'sources')
  - docs/COMMANDS.md +1/−1 — REGEN via `php tools/gen-command-docs.php --write` (same commit as the hint change)

## Legacy attribution disposition (design check)
NULL-source rows (written before attribution) group together in usageSourceRollups and print the EXISTING `(unrecorded)` sentinel — no invented label; pinned byte-exact in testTheSourcesPageFoldsBySettleArmAndPrintsItsAdvice.

## Surfaces sharing rollupFacts (leak check)
UsageMethods RPC and InsightsHostCommand re-project days/models into their own shapes — the new 'sources' key never reaches their wires. CLI verb grows the document honestly (pin flipped).

## Gates (cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe)
- --filter 'UsageCommand|UsageSubcommand|UsageHost': Tests: 32, Assertions: 186, OK (UsageCommandTest 21T/103A; UsageSubcommandTest 9T/71A)
- --filter 'EnhancedSessionStore|SpendLedger|UsageRecords|UsageModelThreading' (landed commits' tests): Tests: 88, Assertions: 375, OK
- --filter 'LangParity|CommandDocsDrift|InsightsHostCommand|UsageMethods': Tests: 28, Assertions: 3395, OK (COMMANDS.md regen drift-clean)
- --filter 'DocFigureProseDrift': Tests: 92, Assertions: 4161, OK
- php -l clean ×5 tail files
- Note: every filter run carries "PHPUnit Deprecations: 2" — PRE-EXISTING private-data-provider deprecations in tests/Config/AccessorDeprecationTest.php whose dataset names match the filters (PHPUnit-internal, not failOn-deprecation, zero touches in my diffs; not my domain).

## Locales
candy-code ships lang/en.php ONLY (LangParityTest derives its locale set from the directory; en-source-of-truth passes) → "all 16 or none" satisfied trivially.

## StderrEmitterCensus
Not run: the tail adds zero error_log/emitter sites (diff-verified).

## Deviations
- None from the design block. Advice prices only the tool-schema half (null instruction-file census) — deliberate, documented in the method docblock: no new disk read on a ledger page; /context remains the itemising surface.
- Advice keys read through ContextSources public API only, honoring the P-CTX-BREAKDOWN reshape risk noted in the design.
