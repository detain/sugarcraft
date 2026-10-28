# Design block — C-AGT-5.g (wave 15)

Extracted verbatim from schedule.json steps['C-AGT-5.g'] for builder dispatch.

**title**: GitLab CI recipe around the signed phar
**area**: agt
**kind**: gap · effort S
**effort**: none
**phase**: normal
**item**: C-AGT-5 (sources: p2-miss: GitLab CI component)
**deps**: C-AGT-2.c, C-AGT-1.c (item order)
**serialized**: [docs:CI.md]
**ruling**: none
**risks**: the phar asset names must match `release-phar.yml`; the lane reads that workflow, not memory.
**files.create**: none
**files.modify**: `candy-code/docs/CI.md` (new section)
**tests**: none (docs-only; the YAML is illustrative)
**docs/guards**: `docs/CI.md`
**approach**: Add a `docs/CI.md` "GitLab CI" section: a `.gitlab-ci.yml` job that downloads the `candy-code/v*` release phar, verifies sha256 and the pinned-key minisig (the `release-phar.yml` assets), and runs `-p --output-format json --permission-mode plan` with `jq` over the envelope. It repeats the fork/MR and prompt-injection doctrine. Docs only.

## Obey
- plan §1 General instructions, §2 collision/rolling laws, §6 rulings (all §6.3 answered c766fc547).
- Stay inside the declared files/regions above; one cohesive commit on branch cc/C-AGT-5.g.
- Author law split-var, %ae/%ce separate, zero trailers; never stage suite-figure.json / durations.tsv / dist / stray root run-state.json.
- In-step guard law: every touched guard moves in the same commit.
- Test-literal hazards: no glob-shaped literals (GlobDialect corpus 131,765), no denial-vocabulary-shaped literals; DuplicatedTestHelperDrift byte-identical-or-rename.
- Gates targeted only, plain pipe, env -u LINES -u COLUMNS, cwd candy-code; PtyRunToolTest NEVER in filter; vitest --no-file-parallelism if web.
- REPORT.md <=30 lines verdict-first at prompt_kit/findings/comparison-1026/lanes/C-AGT-5.g/REPORT.md listing NEW candy-code *Test.php files for the welder durations duty. NEVER push.
