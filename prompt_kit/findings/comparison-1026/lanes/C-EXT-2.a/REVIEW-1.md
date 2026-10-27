# REVIEW-1 — C-EXT-2.a (cc/C-EXT-2.a @ 118f8100f, base b24e81154)

[lead-persist: reviewer sandbox denied writes; delivered verbatim in reply @1002@. Gates NOT run (php denied) — weld re-runs --filter 'SkillTool|SkillLoader' (claim 131T/383A), 'ReadPathCensus|PromptStability' (36T/1020A), five-guard pair-only; DUR row duty: tests/Tools/BuiltIn/SkillToolAssetTest.php.]

## Verified clean
1. Security spine — loadSkillAsset jail (SkillLoader.php:1074–1114) sound end-to-end: first-component gate scripts|references|assets; ContainedPath::within (:1105) realpaths both sides; traversal/symlink (pinned SkillLoaderTest:763)/absolute/NUL refuse. In-skill links load but never listed (:785/:850) — deliberate, documented, pinned. Listing reads zero content (DirectoryIterator names only), SORT_STRING, ASSET_LIST_SCAN_CEILING=200 bounds walk before ASSET_LIST_LIMIT=10 slice; cap test pins edge names (file7 in, file8/9 out).
2. invokedIn skip (:424–427) same trim door as execute asset branch; whitespace-asset parity pinned (SkillToolAssetTest:220). Removal adds 'beta' → red.
3. Substitution manifest-safe — headers minted via SkillPromptLine::heading()/baseDirLine() (field()-escaped) around body; substituteSkillDir writes body position only; body untrusted by classification; ''-sourcePath returns body unchanged (:516).
4. §1.8/§1.9 — ReadPathCensus row Skills/SkillLoader.php|new DirectoryIterator second honest NAMES_ONLY verdict (:926–933); no glob-shaped/denial literals; suite-figure/durations untouched. PromptStability no-re-pin statically confirmed (test file references no SkillTool; byte figures ride stub schemas :3345).
5. Scope — git show --stat: exactly brief file set + mandated new test; [docs:SKILLS.md] honored and truthful.
6. Author law — %ae=%ce=detain@interserver.net separate greps, zero trailers.

## VERDICT: FINDINGS
- [MAJOR step-local] SkillTool.php:257 — asset branch lacks the `sourcePath === ''` guard its siblings apply (listing :312, substitution :516). loadSkillAsset('', 'scripts/x') anchors dirname('')='.' = process CWD; a manifest (no-source) skill call serves any project scripts|references|assets file under the '## Skill:' heading (baseDirLine renders ''). Execute comment (:254–256) claims loadSkillAsset realpath refusal answers it — false when CWD holds such a subdir (this repo has scripts/). Fix: in asset branch return error for $skill->sourcePath === '' before loader call, correct comment, pin asset-on-manifest (lane's manifest fixture :189 covers only body+substitute).
- [MINOR step-local] SkillTool.php:263 + SkillLoader.php:1083/1106 — refusals interpolate model-supplied $relativePath raw into result content; newline-bearing value forges extra lines in error text. Inert for header-anchored readers/invokedIn but off the audit-15d-08 SkillPromptLine::field() idiom; wrap $relativePath in the two loader messages or state exemption in branch comment.
- [NIT step-local] SkillLoaderTest listing edge: substr($path, strlen($skillDir)+1) mis-cuts if sourcePath ever carries doubled slash; cosmetic only (load re-jails). No action.

Non-findings: hard-link visibility = ContainedPath standing limit (git can't carry hard links); schema-prefix one-time move = brief-disclosed accepted risk.
