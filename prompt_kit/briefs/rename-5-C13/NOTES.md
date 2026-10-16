# rename-5-C13 NOTES — candy-fuzzy → sugar-fuzzy <!-- @rename-keep -->

Lane: C13 (batch-C line 33, ns Fuzzy→Fuzzy UNCHANGED, type=lib, bins {}). Branch `rename/c13` off weld `4d472bc62`, worktree `/home/sites/sugarcraft-c13`. NEVER push master; push branch only. No R8/R9.

## Pre-flight findings

- Baseline row verified: `prompt_kit/briefs/rename-baseline.md:39` = candy-fuzzy 1006T/35741A/0F/0E/0S/0W. <!-- @rename-keep -->
- **Batch-D `sugar-fuzz` hazard premise FALSE**: renames.json batch D = shine/mosaic/reel/flip/kit/bounce/palette — NO entry yields slug `sugar-fuzz`, and no `candy-fuzz`/`sugar-fuzz` dir exists tree-wide. Bare `fuzz` tokens live only in candy-core fuzz-corpus test names + ansi art (no rule matches them: R3 needs boundary `candy-fuzzy`, R2 needs `CandyFuzzy`, R4 needs `candyfuzzy`; ns-unchanged ⇒ R1 SKIPPED entirely).
- Façade safety (C6 candy-lister): `class_alias(\SugarCraft\Fuzzy\ScoringProfile::class, ...)` + `use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher` — FQNs carry the UNCHANGED namespace ⇒ alias targets resolve after weld. candy-forms `SugarCraft\Forms\Fuzzy\FuzzyMatcher` likewise untouched (prose `canonical candy-fuzzy SSOT` rewrites via R3 only). Verified pre-rename; will re-verify post.
- SmithWatermanMatcher canonical in candy-fuzzy/src/Matcher/ — class name carries no brand token; prose refs survive.
- No `src/Lang.php` in candy-fuzzy ⇒ R6 N/A. No bins ⇒ C.2 bins N/A. No CANDY_FUZZY_*/env reads (lib, not app) ⇒ no LegacyEnv shim — honest none.
- PascalCase `CandyFuzzy` occurrences (R2 → `SugarFuzzy`): PROJECT_NAMES.md:151, README.md:46, candy-fuzzy/README.md:1, docs/_data + generated pages (gen-docs re-derives).
- Identifier miss vs R2 lookbehind (manual fix): `candy-forms/tests/Fuzzy/AliasResolutionTest.php::testDelegatesToCandyFuzzySsot` — `o` before `CandyFuzzy` blocks R2; will rename method manually.
- Glued pointers `_candy-fuzzy` (R3 lookbehind blindspot, manual sweep): `findings/plan_candy-fuzzy.md` mentions in findings/findings_resume_plan.md ×4 + findings/plan.md:195. Paths themselves (docs/_data, docs/lib, media/icons, docs/img/icons, findings/*, ansi/candy-fuzzy/) handled by C.2.
- Consumers requiring sugarcraft/candy-fuzzy: candy-files, candy-forms, candy-git, candy-hermit, candy-kv, candy-lister, candy-scriptui, candy-ssh-launcher, sugar-crush.
- PROTECT list: no other old slug starts with `candy-fuzzy-` ⇒ empty.

## Procedure log

(append incrementally)
