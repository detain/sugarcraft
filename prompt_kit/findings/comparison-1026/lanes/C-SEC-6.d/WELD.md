# C-SEC-6.d — weld (batch-16, 2026-10-29)
Picks ae2da2543 -> 9806bb91e, bf4155efb -> d97938b93 (onto 86f80fef8, zero-conflict).
Protocol method permission.audit (MethodSchemas +15) — trio regen + dist rode rider c0bb77e7c.
DUR rows minted: candy-code/tests/Hooks/AuditLogReaderTest.php 0.033 15; candy-code/tests/Protocol/PermissionAuditMethodTest.php 0.585 6.
ReadPathCensus SELF_LOCATED fopen row in-branch (Hooks/BuiltIn/AuditLogReader.php).
r1 REVIEW-1 dispositions: MINOR-1 (AuditLogReader parseLine guarantee sentence) + MINOR-2 (RulesPanel loadAudit vice-versa overclaim) PROVEN FIXED AT BRANCH TIP — committed blobs diffed: parseLine says "for every line whose tool input contains no marker of its own"; loadAudit docblock discloses decisions-block-hidden-under-rules-failure gating. NIT (session-filter tail heuristic underfill) recorded, bounded-by-design, no action.
Gates: vitest serial rulesPanelAudit/rulesPanelSaved/rulesCatalog green within 147/147 batch; Permission band 2F foreign-only (PermissionsIntroDrift + ForeignAgentPresetWiring, red at base).
