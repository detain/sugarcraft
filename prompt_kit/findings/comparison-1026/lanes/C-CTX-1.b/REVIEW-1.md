# C-CTX-1.b round 1 — VERDICT: FINDINGS (1 MAJOR / 1 MINOR), static review (gates harness-denied; weld re-runs)
- [MAJOR] candy-code/lang/en.php:1361-1362 — hint promises "turn one on with `/rules <name>`", but counted packs are parse-enabled:false and RulesState::effectiveRule() (RulesState.php:183) short-circuits on !$rule->enabled, so the toggle can never re-admit them. Wording is verbatim from design-prv-ctx.md:123; lead adjudication: RE-WORD honestly (frontmatter-edit remedy), deviation recorded — false user promise cannot ship; wire-re-enable path noted as follow-up seam.
- [MINOR] candy-code/tests/Support/FrontmatterKeyAudit.php:56-59 docblock still describes old AND-fold — prose cure in fix commit.
- Scope: exactly 7 expected files; [hot:Rule.php] hunks minimal; mutate() carries disabledBy, only new() writes it ✓; LangParity-safe placeholders ✓; no glob-shaped/denial-shaped literals ✓.
- Commit law: single e681bd046, parent==base 85be78e05, %ae/%ce detain separate greps, zero trailers, porcelain clean ✓.
- Gates UNVERIFIED here: --filter 'Rule|Frontmatter' (~909T/4305A) + five-guard pair-only must be re-run on tip.
