# C-EXT-3.c — FIX-1

## round 1 cure (2026-10-28, fixer)

REVIEW-1 MINOR (step-local): the `/skills new` no-home refusal arm was
code-correct (requireRoot throws before any write) but unpinned at the
COMMAND surface in SkillsNewTest (store-level pin lived only in
ProposedSkillsTest::testWithNoOwnedHomeThereIsNoStore).

Cure shipped — one leg, one file (candy-code/tests/Host/Commands/SkillsNewTest.php):
`testNoOwnedHomeRefusesTheScaffoldAtTheCommandSurface` drives
`/skills new stranded-skill ...` through SkillsHostCommand::run() with the
home world-writable (chmod 0777 — the same lever the store pin pulls, since
HomeDirectory::owned() refuses the 0o002 bit; resolved() reads env per call
so a mid-test chmod lands), asserts the Role::System notice names
'no home directory' (the requireRoot message, via host.skills.error), and
asserts the skills-proposed tree is never created. Mirrors the file's
existing systemNotice + finally-restore arm styles; no new helpers.

Non-vacuity: red-before PROVEN by /tmp cp-harness neutering the
world-writable clause in src/Support/HomeDirectory.php::owned()
(`($perms === false || false)`) → --filter SkillsNew: exactly 1 failure,
the new leg, failing in the dangerous shape (command returned the
Assistant "Drafted the skill `stranded-skill`" reply where the refusal
must stand). Restore md5 396c409d54247fa2b66e6883116e9dac cmp-clean.
Green-after: OK 6T/34A (2 pre-existing deprecations).
Neighborhood SkillsHostCommand|SkillsEnableDisable|BuiltIn: 1397T/138898A 0F
(unchanged — the new leg is outside that filter). Guards
DuplicatedTestHelperDrift|GlobDialect: OK 28T/563A (no glob/denial-shaped
literals added).

Commit: amended IN PLACE, single-commit law held. Old tip 7dbe548f6 →
new tip 22099540eba72a72da7f3bbe46808f925b97eee2; diff vs old tip =
+19 lines, one file. Author/committer detain@interserver.net (separate
%ae/%ce greps = 1/1), zero trailers; blob-verify: pin name present in
`git show 22099540e` (count 1). NEVER pushed; no suite-figure/durations/
dist/master touches.
