# REPORT — C-EXT-4.b (wave 16) — LANDED
Tip: ca7fd9f470fe71726cbc95e29551bca56646d7c6 on cc/C-EXT-4.b (base e4a705244). 10 files, +2008/−14. Author/committer detain verified (%ae,%ce separate greps =1), zero Co-Authored, never pushed.

## Shipped
`bundles` verbs: marketplace add/list/remove/update, install <plugin>@<marketplace>, update [bundle], search <q>, recommend — Subcommands.php +1067 lines (13 methods, bundlesUsage envelope, bundlesGitSha, bundlesStageEntry shared by install/update). Completion roster + cli.bundles.usage + cli.completion.verb.bundles updated. No new spawn site — child-lifetime roster UNCHANGED (tools/check-child-lifetimes.php rc=0, 33 sites/9 accounted). PLUGINS.md Marketplaces section + README :97/:2212 rows. en.php 28 new cli.bundles.* keys (en-only per §6.3; parity wave LAST).

## Gates (cwd candy-code, plain pipe)
- tests/Bundles: **OK (116 tests, 482 assertions)** (22 new: 14 marketplace + 8 update; +1 install grammar test).
- StderrEmitterCensus|ReadPathCensus|TreeWideGuardRoster: **OK (131 tests, 25587 assertions)** (37→38 flip; +4 read rows).
- ReadmeRosterDrift|DocFigureProseDrift|GlobDialect|DuplicatedTestHelperDrift|LangParity: **OK (135 tests, 7610 assertions)**.
- Red-before: new tests vs base src+lang → 22F/23; pure-base ProjectTier → 2F (inheritance proven).

## NEW *Test.php — welder durations duty
tests/Bundles/BundleMarketplaceCliTest.php, tests/Bundles/BundleUpdateTest.php.

## FOREIGN-INHERITED seams (welder/closeout; NOT introduced here)
ProjectTierRefusalInventoryTest ends 2F, base already 2F: (1) 9 foreign unclassified dot-path occurrences across 7 files (Marketplace.php|.claude-plugin/marketplace.json, MarketplaceRegistry.php|.candy-code/marketplaces/.marketplaces.json x2 — C-EXT-4.a; FormatAutoTable.php|.bin/prettier — C-TOOL-7.c, REPOSITORY by site evidence; LspInstaller, AgentMemory x2, MacAltNotice, ScheduleTool — older waves); (2) my literal 'FIFTY-SEVEN distinct' names src/Cli/Bootstrap.php:2286 docblock (says FORTY-EIGHT; truth 57; held by sibling C-SEC-7.d — did not edit). Adjudicate the 9 rows, then flip Bootstrap prose (TWENTY-FOUR→FIVE-AND-TWENTY if prettier lands REPOSITORY + assertCount(24→25)).

## Deviations
Scratch placement: add clones under bundles root; marketplace update swaps same-dir beside checkout. Extra keys: marketplace.{needs_action,unknown_action,checkout_lost,renamed,none}. install-gate sentence printed directly. update scan answers OK on no-record dirs; named operand FAILURES. TreeWideGuardRoster: new test files name no package root (ROOT_ANCHOR) → correctly NO roster rows. ParsedArgs::SUBCOMMAND_FLAGS unchanged.
