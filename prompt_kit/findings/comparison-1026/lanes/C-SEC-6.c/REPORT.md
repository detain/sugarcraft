# C-SEC-6.c — DONE (tip 737d5dba5 on cc/C-SEC-6.c, never pushed)

permission.respond remember:"user" on a hosted question upgrades once to always and,
when the ask offers always, persists the question's patterns as allow-rules through
SavedRules (the TUI p set), echoing the notice in the result's new saved field; a
relayed background question still refuses with remember_user_unavailable. Patterns
derive server-side from the pending ask record, never from the respond payload; writes
go only through SettingsWriter's You-tier door to the server-fixed user config path.
permission.rules rows gain tier (user|config) and savedIndex for user-tier rows,
degrading to all-config (no Forget affordance invented) when the saved file is
unreadable — fail-closed. New permission.forget (Scope::Approve, sideEffects) removes
one saved rule by pattern or 1-based index (pattern-xor-index -32602, no-writer -32030,
failed remove -32099). MethodSchemas carries all three shapes; the generated trio and
dist are NOT in the commit — welder regen rider owed (trio law: 18-file stat has no
schema.json/generated.ts/dist).
Files: src/Protocol/Methods/PermissionMethods.php, src/Protocol/Schema/MethodSchemas.php,
docs/{PERMISSIONS,SERVER}.md (hand prose), tests/Cli/ProjectTierRefusalInventoryTest.php
(two census figures + one retired row — the path-naming sentence died in the rewrite),
tests/Protocol/PermissionSavedRulesMethodsTest.php (new), tests/Server/PermissionFirstWinsTest.php
(hosted remember:user leg moved into the new file; claims preserved),
tests/Server/Support/ProtocolFixture.php (optional permission-gate seam).
Web: PermissionCard Always-(save) verdict rides remember:"user"; RulesPanel Saved section
with Forget buttons, next-launch note, honest failure line and old-server degrade; all
three answer call sites and both stores carry the flag.

Gates re-derived at tip 737d5dba5 (round 1, plain pipe, env -u LINES -u COLUMNS,
cwd=candy-code, -c phpunit.xml):
- --filter 'PermissionSavedRulesMethods|PermissionFirstWins': 24T/101A OK (+2
  pre-existing PHPUnit deprecations) — CONFIRMED.
- Family --filter 'Permission|SavedRules': 2274T/12932A/2F. The earlier session-report
  band "1704T/5707A" does NOT reproduce at tip; the measured figures here are the truth
  (REVIEW-1 MINOR). The 2 reds are proven-foreign inherited base reds:
  PermissionsIntroDriftTest::testThePatternToolRosterIsTheWholeCatalog and
  ForeignAgentPresetWiringTest::testNoSourceFileOutsideAgentReadsAnAgentsPermissionMode
  (roster lacks src/Host/Commands/SchedulesCommand.php). Lane diff for both test files
  and SchedulesCommand.php is zero and the file exists at parent 4aa9ea204 — re-verified
  at round 1.
- Vitest serial (--no-file-parallelism, cwd=candy-code-web): rulesPanelSaved.spec.ts
  4T green; permissionCardExtras + stores/approvals + component/approvals specs 26T
  green — CONFIRMED.

Deviation register (per REVIEW-1):
(a) Six undeclared web files were touched beyond the brief's files.modify:
    SessionView.vue, ApprovalsDrawer.vue, SessionTile.vue, session.ts, approvals.ts,
    plus appends to two spec files (permissionCardExtras.spec.ts, approvals.spec.ts).
    Retained, not reverted: the wire flag needs a UI consumer — shipping remember:"user"
    without the card/panel/store plumbing is dormant code under the lane law, and the
    reviewer accepted this as required plumbing (its sole MAJOR is the weld-serialization
    action in (b), not a code pull-back).
(b) Serialization note: C-MCP-2.c (W23) claims the same
    [hot:candy-code-web/src-web/views/SessionView.vue] region. The two welds MUST
    serialize — land one, then re-resolve the other's SessionView hunk with a content
    re-check before applying (§2 one-lane-per-shared-file).

NITs from REVIEW-1 (SavedRules docblock deny-removal clause; "checked after
ask_not_found" commit/report phrasing precision) are welder/closeout carries — not
acted on in the code here. Welder carries also: durations row for
PermissionSavedRulesMethodsTest (~7s), +14T suite delta, trio regen + dist rider,
§4 flip 737d5dba5, foreign roster disclosure (PermissionsIntroDrift +
ForeignAgentPresetWiring, both proven at parent).
