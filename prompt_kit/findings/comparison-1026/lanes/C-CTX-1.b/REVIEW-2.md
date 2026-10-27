# C-CTX-1.b round 2 — VERDICT: CLEAN (0 critical / 0 major), static review (gates harness-denied; re-run by welder)
- MAJOR cured: lang/en.php:1361-1362 hint re-worded to the honest frontmatter-remedy (rule "stays off until `alwaysApply: false` is removed from its frontmatter"); {count} parity across hint/hint_one kept.
- Cure pins: tests/Commands/RulesCommandTest.php:176/:193 hint assertions flipped in-step to the re-worded text.
- MINOR cured: src/Support/FrontmatterKeyAudit.php:59-62 docblock truth-flipped — patterns ⇒ enabled+path-scoped, pattern-less ⇒ disabled at parse (old AND-fold prose retired).
- docs/MEMORY.md:770-773 Cursor mapping rows stand (reviewer-checked against the landed Rule.php semantics).
- Design wording (design-prv-ctx.md:123) overridden by lead doc-truth ruling; session-toggle re-admission of disabledBy===alwaysApply packs stays the recorded follow-up seam.
- r2 fix scope (3cedf52a0): exactly lang/en.php + RulesCommandTest + FrontmatterKeyAudit docblock, +6/−5 ✓.
- lead-composed from reviewer reply, gates re-run by welder (all green per your runs).
