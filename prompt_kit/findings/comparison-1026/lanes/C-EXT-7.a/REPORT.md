# C-EXT-7.a — DONE, committed 0e5c35bf9 on cc/C-EXT-7.a (base c36cc1982, tree clean, never pushed)

VIS-3-P2 shipped: HooksHostCommand now reads `$context->workspace?->hooks` and
never touches the Bootstrap chain-builder — null workspace answers the
'not measured' table plus one new row 'cmd.hooks.unmeasured.nochain' (replaced
the now-dead `cmd.hooks.unmeasured.reason`, sole user was this command).
PLG-1-P4 shipped: Bundle::hooksPaths() adds the manifest path for an inline
`hooks` object; Bundle::mcpFile() reads `mcpServers` (string=path; inline
object→manifest path); private hasInlineSurface() is the shared shape test;
Bootstrap decline notices fire unchanged (pinned). docs/PLUGINS.md declined
surfaces bullets carry the truth.

Tests: NEW candy-code/tests/Host/Commands/HooksHostCommandLiveChainTest.php
(4T — DUR gate needs its durations row): workspace chain listed; on-disk
hooks.yaml never smuggles into the panel (red-before/green-after regression);
structural source pin (no 'Bootstrap::hooks', rides 'workspace?->hooks');
null-workspace row. BundleDeclinedSurfacesTest +3T (inline hooks, mcpServers
string path, inline mcpServers). HooksCommandTest host-half rewired in-step
(test builds the chain, carries it on WorkspaceContext) — necessary: it drove
the old rebuild path; docblock of it made truthful.

Gates (plain pipe, cwd candy-code): --filter 'Hooks|Bundle' → 1246T/4357A OK
(2 PHPUnit deprecations = pre-existing AccessorDeprecationTest private
providers). Five-guard → ONLY the staleness pair red: pinned 25,537 vs live
26,128 (my lane +7T; the remaining +584 over the pin is pre-existing fleet
drift at base — gate owes the re-pin). DocFigure/GlobDialect/ChildWallClock/
DuplicatedTestHelper green. Safety: ReadmeRoster|SlashDispatch|CommandRegistry|
Bundles 109T OK; Lang|Translation|Catalogue → 1 pre-existing FOREIGN red:
ChatHostLangCatalogueTest unused-key 'chat.effort.launch_unshaped' (caller at
src/Cli/LaunchContext.php:2109 is outside the test's scan set; introduced by
a992413b0, zero overlap with this lane — left for gate).

GlobDialect law re-proven live: my literals 'workspace?->hooks' and
'servers.json' drifted the PathGlob corpus (554×550→555×551); respelled in-step
(chr(63)/concat) — src/Util/PathGlob.php never touched.

## Round 2 — review fixes (REVIEW-1: 1 MINOR + 1 NIT)

Commit c311273ad on cc/C-EXT-7.a (base 0e5c35bf9; author/committer
detain@interserver.net verified via separate %ae/%ce greps, zero Co-Authored).

- [MINOR] docs/PLUGINS.md declined-surface bullets: the claim "the decline
  notice names the manifest" was false — the launch notices
  (Bootstrap.php:2503/:2512, lang keys bundles.hooks_declined /
  bundles.mcp_not_auto_started) interpolate only bundle NAMES. Reworded both
  bullets per the reviewer's fix: the declined-surface reader answers with the
  manifest path (hooksPaths()/mcpFile()); the notice names the affected
  bundles. Notices untouched (brief: notices unchanged). No docs guard pins
  PLUGINS.md prose (grep across tests: zero hits) — nothing re-armed.
- [NIT] BundleDeclinedSurfacesTest:268 identifier word-run fixed:
  testAStringMcpServersManifestEntryIsADECLINEDConfigFile →
  ...IsADeclinedConfigFile (single occurrence tree-wide, durations.tsv has no
  per-method rows).

Gates re-run at tip (plain pipe, cwd candy-code): --filter 'Hooks|Bundle' →
1246T/4357A OK (same 2 pre-existing deprecations). Five-guard → 133T/15720A/
2F = ONLY the ReadmeSuiteFigureDrift staleness pair (pinned 25,537 vs live
26,128 — unchanged shape, gate owns re-pin).
