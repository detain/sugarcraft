# WELD-1030Q — in progress (rolling)

Start master: 9fb981c5c (live re-read; run-state said fa49d6115 with 2 commits of 1030P riders on top).

## Step 0.4(a) PermissionsIntroDriftTest cure
- Reproduced red on master: testThePatternToolRosterIsTheWholeCatalog fails — docs/PERMISSIONS.md roster paragraph (:813) lacks FOUR catalog names, not one: `Advisor` (C-PRV-3.d 8defce213, the W23-loop finding) PLUS `Schedule`, `SkillWorkshop`, `skills_search` (live catalog = 40 names; these three cited elsewhere on the page at :168-172 but never in the guard's paragraph window). Disclosed deviation from brief (brief named only Advisor).
- Cure: append the four names to the "also" list of the roster paragraph. Gates: guard green + DocFigureProseDriftTest.
