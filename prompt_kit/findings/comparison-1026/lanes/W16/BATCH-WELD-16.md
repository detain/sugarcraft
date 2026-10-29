
## FINAL (survivor closeout 2026-10-29)
Meta commit lands: seam#1/#2 cures + ReadPathCensus cure row + 7 DUR rows (tsv 1611) + §3 W19/W20 flips + §4 6 step marks + C-WEB-4.e docs-cite cure (6c42f30ca) + all lane/W18/W19/W20 evidence + 6 WELD.md + 2 WAVE-CLOSE.md + run-state (welded 223, weld_pending cleared, waves [1..20]).
SEC-6.d r1 MINORs 1-2 verified fixed at branch tip (committed-blob diff); NIT recorded no-action. EXT-5.b seams b/c recorded-not-fixed. Rider c0bb77e7c carries protocol trio + dist.
Batch-17 (owner directive): C-SEC-6.b (cbe7d05df+403742811) + C-MCP-4.d (1ce61c449+3f7a2b93e) fresh picks onto final master; Subcommands.php:3798 comment NIT rider at discretion; DUR rows SubcommandsMcpImportTest + BootstrapProjectNarrowingRulesTest; run-state ->225; ff+push.

## BATCH-17 (same weld-16 branch; survivor after owner interrupt of duplicate)
- Wave-order picks onto 17e6b440e (chain rebased over mid-run vhs bot push 44ff31314..17e6b440e; pre-rebase picks b1bd3eb90/2e1d2b3bb/d0f2114eb/02553ff7a + meta 8df590b97 rewritten), zero conflicts: C-MCP-4.d 1ce61c449->74c980368, 3f7a2b93e->303c87928; C-SEC-6.b cbe7d05df->fecfe7b4e, 403742811->6598d32c2.
- Rider: candy-code/src/Cli/Subcommands.php importUsage docblock 'four doors'->'five doors' (comment-only NIT, r1 carry).
- DUR rows (tsv 1611->1614, tab format): Cli/SubcommandsMcpImportWriteTest 0.010/10; Permissions/ProjectNarrowingRulesTest 0.035/5; Cli/BootstrapProjectNarrowingRulesTest 0.035/9.
- plan §4 marks flipped (C-MCP-4.d line ~2799, C-SEC-6.b line ~2895); §3 W23/W24 stay open (not wave closes).
- Gates: 3 new files 24T/155A OK; Permission band 2298T/2F = ForeignAgentPresetWiring:472 + PermissionsIntroDrift (proven-at-base foreign standings); guard band 527T/8F = staleness pair + HelpTest service-tier + 5 KeyHelpTest (standing set). No census/DocFigure flips; no protocol/dist rider (no src/Protocol or .vue touches); gen-protocol-schema --check + gen-command-docs --check rc0 at meta tip.
- run-state: welded_count 225; C-MCP-4.d + C-SEC-6.b inflight->welded; batch17_note added.
- NIT doc riders (r1 carries): lang/en.php settings.permissionRules.help discloses the trusted-project deny/ask read (NIT-4); docs/PERMISSIONS.md tier-column sentence notes signature-match labeling (NIT-3); gen-settings-doc --write rider picked up stale notices.transcriptLimit row 49->50 (no help-string embedding in the table — verified).
- Evidence collected into lanes/C-SEC-6.b/ (BRIEF, FIX-1, REPORT, REVIEW-1, REVIEW-2) + lanes/C-MCP-4.d/ (BRIEF, FIX-1, REVIEW-1, REVIEW-2; REPORT.md already rode pick 74c980368 — deduped).
- Re-verified gates this window (band-1 Permission family 597T/2F exactly the two disclosed foreign standings; band-2 493T/7F = HelpTest CANDY_CODE_SERVICE_TIER + BinCandyCodeDispatch --worktree + 5 KeyHelpTest, all disclosed foreign; SubcommandsMcpImport* + DocFigure green).
- Disclosed to lead (no edit): WebFetch.php:635 redirect-veto consults user-tier rules only — reviewer ruling report-not-fix; BinCandyCodeDispatchTest --worktree completion gap = owner flag from MCP-4.d carry.

## BATCH-17 post-meta riders
- worktree held 3 unattributed hunks (killed-duplicate residue; proven absent from all lane branches):
  * docs/SETTINGS.md transcriptLimit 49->50 = LEGIT generator drift -> landed via `php tools/gen-settings-doc.php --write` (regen law: generated files by regeneration only).
  * lang/en.php permissionRules.help sentence + docs/PERMISSIONS.md signature sentence = hand prose, NOT code-truth-required: band 'Permission|Settings|DocFigure' fails identically (same 9F) at base 86f80fef8 and post-meta (2cce45a4e), so neither load-bearing. REJECTED; evidence /tmp/opencode/w16/mystery-dirt-REJECTED.diff.
- Standing foreign Settings-family reds (red-at-base, for lead/closeout): ApplySettingsTest x2, PermissionsIntroDrift roster, MediaSettingsTest provider-schema, SettingsWriterCensus producers, ForeignAgentPresetWiring:472, ToolCapSettings x2, SettingsEditorTest search-types. (PermissionsIntroDrift + ForeignAgentPresetWiring already on WELD.md foreign list.)

## Correction (concurrent-write reconciliation)
- meta-17 shipped a wrong DUR test-count: ProjectNarrowingRulesTest is 5T/14A (junit b17-new.xml; 14 = assertions). tsv row corrected to 0.035/5, §4 mark corrected to 5T. BootstrapProjectNarrowingRulesTest 9T/26A and SubcommandsMcpImportWriteTest 10T/115A verified correct as minted.
- run-state: C-AGT-4.c inflight record (review r1, tip 704e15fcf) adopted from concurrent staging — true live-lane data per lead registry.
- **FF+PUSH proof (stamp follow-up):** origin moved mid-window 44ff31314 -> 17e6b440e (vhs bot GIF regen, disjoint); chain rebased --onto 17e6b440e, meta re-amended d258ea4fa, pushed 17e6b440e..d258ea4fa; ls-remote origin master == d258ea4fa, ahead/behind 0/0. Lead live run-state ticks preserved at /tmp/opencode/lead-1029/run-state-pre-ff1{6,7}.json.
- **Prune ledger:** all 14 batch-16/17 lane commits + AGT-4.b c4fb3580f + SES-7.f 31691640f git-cherry "-" vs master; cc/C-* lane refs already absent (deleted upstream); weld-15 ref gone; weld-16 (02553ff7a) + weld-17 (d258ea4fa) branches deleted; worktree /home/sites/cc-cmp-weld-16 removed clean; stray dirs cc-cmp-C-AGT-4.b, cc-cmp-C-SES-7.f, /tmp/opencode/cc-cmp-lanes/C-SEC-6.b/base, cc-cmp-weld-15 all already gone (verified absent). Live lanes untouched: cc/C-MCP-3.d cc/C-PRV-3.b cc/C-CTX-3.c cc/C-AGT-4.c cc/C-EXT-5.c (+ staging worktrees AGT-4.e CTX-4.b PRV-4.b SES-7.g EXT-5c-pristine left as-is).
